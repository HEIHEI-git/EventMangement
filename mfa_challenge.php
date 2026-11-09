<?php
require __DIR__.'/includes/bootstrap.php';
if (!mfaFeatureEnabled()) redirect('login.php');
$pending = $_SESSION['mfa_pending'] ?? null;
if (!$pending || $pending['expires'] < time()) { unset($_SESSION['mfa_pending']); redirect('login.php'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)$pending['uid'];
    enforceActionLimit($pdo, 'mfa-login', 5, (string)$uid);
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM users WHERE user_id=? AND is_active=1 FOR UPDATE'); $q->execute([$uid]); $user = $q->fetch();
        if ($user && (int)$user['auth_version'] === $pending['version'] && consumeSecondFactor($pdo, $uid, trim(postString('code')))) {
            audit($pdo, 'auth.mfa_login', $uid); $pdo->commit();
            establishLogin($user); $_SESSION['mfa_verified'] = true;
            redirect(loginRedirectFor($user, $pending['next']));
        }
        $pdo->commit(); securityEvent('auth.mfa_failed', $uid); $error = 'Invalid or already used code. Wait for a new code, or use a recovery code.';
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
$pageTitle='Verify sign-in'; require __DIR__.'/includes/header.php'; ?>
<section class="compact-panel"><p class="eyebrow">ONE MORE STEP</p><h1>Verify it's you.</h1><p>Enter the six-digit code from your authenticator, or one unused recovery code.</p>
<?php if($error):?><p class="flash error" role="alert"><?=e($error)?></p><?php endif;?>
<form method="post"><?=csrfField()?><label for="code">Verification code</label><input id="code" name="code" required maxlength="16" autocomplete="one-time-code" autofocus><button class="wide">Verify sign-in</button></form><p><a href="login.php">Back to login</a></p></section>
<?php require __DIR__.'/includes/footer.php'; ?>
