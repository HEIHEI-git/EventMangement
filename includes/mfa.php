<?php
declare(strict_types=1);

/** Keep enrollment data separate from the temporary development-only switch. */
function mfaFeatureEnabled(): bool {
    global $config;
    return !(($config['app_env'] ?? '') === 'development' && ($config['mfa_enabled'] ?? true) === false);
}

function base32Encode(string $bytes): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $result = '';
    foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $result .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $result;
}
function base32Decode(string $encoded): string {
    if (!preg_match('/^[A-Z2-7]+$/D', $encoded)) throw new InvalidArgumentException('Invalid secret');
    $bits = ''; $result = ''; $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    foreach (str_split($encoded) as $char) $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $chunk) if (strlen($chunk) === 8) $result .= chr(bindec($chunk));
    return $result;
}
/** RFC 6238, SHA-1. Provisioning fixes six digits and a 30 second period. */
function totpCode(string $secret, int $counter, int $digits = 6): string {
    $mac = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), base32Decode($secret), true);
    $offset = ord($mac[19]) & 15;
    $number = unpack('N', substr($mac, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
function totpCounter(string $secret, string $code, int $last = -1, ?int $now = null): ?int {
    if (!preg_match('/^[0-9]{6}$/D', $code)) return null;
    $current = intdiv($now ?? time(), 30);
    foreach ([$current, $current - 1, $current + 1] as $counter) {
        if ($counter >= 0 && $counter > $last && hash_equals(totpCode($secret, $counter), $code)) return $counter;
    }
    return null;
}
function encryptMfaSecret(string $secret): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key = hash_hmac('sha256', 'fairboard-mfa-v1', applicationKey(), true);
    return base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, $key));
}
function decryptMfaSecret(string $cipher): string {
    $bytes = base64_decode($cipher, true);
    if ($bytes === false || strlen($bytes) < 41) throw new RuntimeException('Invalid MFA ciphertext');
    $secret = sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), hash_hmac('sha256', 'fairboard-mfa-v1', applicationKey(), true));
    if ($secret === false) throw new RuntimeException('MFA key mismatch');
    return $secret;
}
function mfaEnabled(PDO $pdo, int $uid): bool {
    $q = $pdo->prepare('SELECT 1 FROM user_mfa WHERE user_id=?'); $q->execute([$uid]);
    return (bool)$q->fetchColumn();
}
function newRecoveryCodes(): array {
    $codes = [];
    for ($i = 0; $i < 10; $i++) $codes[] = bin2hex(random_bytes(8));
    return $codes;
}
/** Caller owns the user row lock; lock order is users -> user_mfa throughout. */
function consumeSecondFactor(PDO $pdo, int $uid, string $code): bool {
    $q = $pdo->prepare('SELECT * FROM user_mfa WHERE user_id=? FOR UPDATE'); $q->execute([$uid]); $mfa = $q->fetch();
    if (!$mfa) return false;
    $counter = totpCounter(decryptMfaSecret($mfa['secret_cipher']), $code, (int)$mfa['last_counter']);
    if ($counter !== null) {
        $pdo->prepare('UPDATE user_mfa SET last_counter=? WHERE user_id=?')->execute([$counter, $uid]);
        return true;
    }
    if (!preg_match('/^[a-f0-9]{16}$/D', $code)) return false;
    $hashes = json_decode($mfa['recovery_hashes'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($hashes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $code))) {
            unset($hashes[$i]);
            $pdo->prepare('UPDATE user_mfa SET recovery_hashes=? WHERE user_id=?')->execute([json_encode(array_values($hashes)), $uid]);
            audit($pdo, 'mfa.recovery_used', $uid);
            return true;
        }
    }
    return false;
}
function verifyCurrentPassword(PDO $pdo, int $uid, string $password): bool {
    $q = $pdo->prepare('SELECT password_hash FROM users WHERE user_id=? AND is_active=1'); $q->execute([$uid]); $hash = $q->fetchColumn();
    return $hash && strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
}
function reauthenticate(PDO $pdo, string $password, string $code): bool {
    $uid = (int)currentUser()['user_id'];
    enforceActionLimit($pdo, 'reauth', 5);
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT auth_version FROM users WHERE user_id=? AND is_active=1 FOR UPDATE'); $q->execute([$uid]); $version = $q->fetchColumn();
        $valid = $version !== false && (int)$version === (int)$_SESSION['auth_version'] && verifyCurrentPassword($pdo, $uid, $password)
            && (!mfaFeatureEnabled() || !mfaEnabled($pdo, $uid) || consumeSecondFactor($pdo, $uid, $code));
        $pdo->commit();
        if ($valid) $_SESSION['reauthenticated_at'] = time();
        else securityEvent('auth.reauth_failed', $uid);
        return $valid;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function requireRecentAuthentication(): void {
    if ((int)($_SESSION['reauthenticated_at'] ?? 0) < time() - 600) {
        flash('Confirm your identity, then repeat the action.', 'error'); redirect('reauth.php');
    }
}
function secondFactorField(): string {
    if (!mfaFeatureEnabled()) return '';
    return '<label for="mfa-code">Authenticator or recovery code (if enabled)</label><input id="mfa-code" name="code" autocomplete="one-time-code" maxlength="16"><p class="fine-print">Each code works once. Wait for a new code if you just signed in.</p>';
}
