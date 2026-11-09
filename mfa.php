<?php
require __DIR__.'/includes/bootstrap.php'; requireLogin();
if (!mfaFeatureEnabled()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') abortRequest(403, 'MFA is temporarily paused for testing.');
    flash('MFA is temporarily paused for testing. Your authenticator settings are saved.');
    redirect('account.php');
}
$uid=(int)currentUser()['user_id']; $enabled=mfaEnabled($pdo,$uid); $required=in_array(currentUser()['role'],['admin','staff'],true); $error=''; $codes=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=postString('action');
    if ($action==='start' && !$enabled) {
        if (!reauthenticate($pdo,postString('password'),'')) $error='Current password is incorrect.';
        else $_SESSION['mfa_setup']=['secret'=>base32Encode(random_bytes(20)),'expires'=>time()+600];
    } elseif ($action==='enable' && !$enabled) {
        enforceActionLimit($pdo,'mfa-enroll',5);
        $setup=$_SESSION['mfa_setup']??null;
        $counter=$setup && $setup['expires']>=time() ? totpCounter($setup['secret'],trim(postString('code'))) : null;
        if($counter===null) $error='Invalid code or expired setup. Start again if needed.';
        else {
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare('SELECT auth_version FROM users WHERE user_id=? AND is_active=1 FOR UPDATE');$q->execute([$uid]);
                if((int)$q->fetchColumn()!==(int)$_SESSION['auth_version'] || mfaEnabled($pdo,$uid)) throw new DomainException('Account changed. Log in again.');
                $codes=newRecoveryCodes();
                $pdo->prepare('INSERT INTO user_mfa(user_id,secret_cipher,last_counter,recovery_hashes)VALUES(?,?,?,?)')->execute([$uid,encryptMfaSecret($setup['secret']),$counter,json_encode(array_map(fn($c)=>hash('sha256',$c),$codes))]);
                $pdo->prepare('UPDATE users SET auth_version=auth_version+1 WHERE user_id=?')->execute([$uid]);
                audit($pdo,'mfa.enabled',$uid);$pdo->commit();
                $_SESSION['auth_version']++;$_SESSION['mfa_verified']=true;$_SESSION['reauthenticated_at']=time();unset($_SESSION['mfa_setup']);session_regenerate_id(true);$enabled=true;
                notifyAccountSecurity($pdo,currentUser(),'Fairboard MFA enabled','An authenticator was added to your account. If this was not you, contact your administrator immediately.');
            } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
    } elseif (in_array($action,['disable','recovery'],true) && $enabled) {
        if($action==='disable' && $required) $error='Admin and staff accounts must keep MFA enabled.';
        elseif(!reauthenticate($pdo,postString('password'),trim(postString('code')))) $error='Password or verification code is incorrect. Wait for a new code if you just used it.';
        else {
            $pdo->beginTransaction();
            try {
                $q=$pdo->prepare('SELECT auth_version FROM users WHERE user_id=? AND is_active=1 FOR UPDATE');$q->execute([$uid]);
                if((int)$q->fetchColumn()!==(int)$_SESSION['auth_version'])throw new DomainException('Account changed. Log in again.');
                if($action==='disable') {$pdo->prepare('DELETE FROM user_mfa WHERE user_id=?')->execute([$uid]);$enabled=false;}
                else {$codes=newRecoveryCodes();$pdo->prepare('UPDATE user_mfa SET recovery_hashes=? WHERE user_id=?')->execute([json_encode(array_map(fn($c)=>hash('sha256',$c),$codes)),$uid]);}
                $pdo->prepare('UPDATE users SET auth_version=auth_version+1 WHERE user_id=?')->execute([$uid]);audit($pdo,'mfa.'.$action,$uid);$pdo->commit();$_SESSION['auth_version']++;session_regenerate_id(true);
                notifyAccountSecurity($pdo,currentUser(),'Fairboard security settings changed','Your authenticator or recovery codes changed. If this was not you, contact your administrator immediately.');
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
    } else abortRequest(400,'Invalid action.');
}
$setup=$_SESSION['mfa_setup']??null;if($setup && $setup['expires']<time()){unset($_SESSION['mfa_setup']);$setup=null;}
$pageTitle='Authenticator security';require __DIR__.'/includes/header.php';?>
<div class="page-head"><div><p class="eyebrow">PROTECT YOUR ACCOUNT</p><h1>Authenticator security.</h1></div><a class="button secondary" href="account.php">Your account</a></div>
<section class="compact-panel"><p>Use Google Authenticator, Microsoft Authenticator (Other account), or another TOTP app. Codes change every 30 seconds. Keep your phone's clock set automatically.</p>
<?php if($required):?><p class="notice">MFA is required for admin and staff access.</p><?php endif;?>
<?php if($error):?><p class="flash error" role="alert"><?=e($error)?></p><?php endif;?>
<?php if($codes):?><h2>Save your recovery codes</h2><p class="notice">These codes appear only now. Store them privately away from this device. Each works once. Any previous codes are invalid.</p><pre class="recovery-codes"><?=e(implode("\n",$codes))?></pre><a class="button" href="account.php">I have saved my codes</a>
<?php elseif($enabled):?><h2>MFA is enabled</h2><p>To replace a lost authenticator, sign in with a recovery code. Contact the administrator for assisted recovery if all factors are lost.</p><form method="post"><?=csrfField()?><label for="password">Current password</label><input id="password" name="password" type="password" required autocomplete="current-password" maxlength="72"><?=secondFactorField()?><button name="action" value="recovery">Replace recovery codes</button><?php if(!$required):?><button class="danger" name="action" value="disable">Turn off MFA</button><?php endif;?></form>
<?php elseif($setup):require_once __DIR__.'/includes/qr_generator.php';$uri='otpauth://totp/'.rawurlencode('Fairboard:'.currentUser()['email']).'?secret='.$setup['secret'].'&issuer=Fairboard&algorithm=SHA1&digits=6&period=30';?><h2>Scan, then confirm</h2><div class="mfa-qr"><?=renderEventQr($uri)?></div><p>Manual setup key: <code class="secret-key"><?=e($setup['secret'])?></code></p><p>Do not share this QR or key.</p><form method="post"><?=csrfField()?><input type="hidden" name="action" value="enable"><label for="code">Six-digit authenticator code</label><input id="code" name="code" required pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><button class="wide">Enable MFA</button></form>
<?php else:?><h2>Add an authenticator</h2><form method="post"><?=csrfField()?><input type="hidden" name="action" value="start"><label for="password">Current password</label><input id="password" name="password" type="password" required autocomplete="current-password" maxlength="72"><button class="wide">Set up authenticator</button></form><?php endif;?></section>
<?php require __DIR__.'/includes/footer.php';?>
