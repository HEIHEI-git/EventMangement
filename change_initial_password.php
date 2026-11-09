<?php
require __DIR__.'/includes/bootstrap.php';requireLogin();
if(empty(currentUser()['must_change_password']))redirect('account.php');
$error='';$uid=(int)currentUser()['user_id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
    enforceActionLimit($pdo,'initial-password',5);
    $password=postString('password');$current=postString('current_password');
    if(!passwordMeetsPolicy($password)||$password!==postString('password_confirm'))$error='New passwords must match: 12+ characters, uppercase, lowercase, a number and a symbol; maximum 72 bytes.';
    else{
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT password_hash,auth_version,must_change_password FROM users WHERE user_id=? AND is_active=1 FOR UPDATE');$q->execute([$uid]);$user=$q->fetch();
            if(!$user || (int)$user['auth_version']!==(int)$_SESSION['auth_version'] || !$user['must_change_password'])throw new DomainException('Account changed. Log in again.');
            if(strlen($current)>72||str_contains($current,"\0")||!password_verify($current,$user['password_hash']))throw new DomainException('Temporary password is incorrect.');
            if(password_verify($password,$user['password_hash']))throw new DomainException('Choose a password different from your temporary password.');
            $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=0,auth_version=auth_version+1 WHERE user_id=?')->execute([password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),$uid]);
            $pdo->prepare('DELETE FROM account_tokens WHERE user_id=?')->execute([$uid]);audit($pdo,'staff.initial_password_changed',$uid);$pdo->commit();
            $_SESSION['auth_version']++;$_SESSION['user']['must_change_password']=0;$_SESSION['reauthenticated_at']=time();session_regenerate_id(true);unset($_SESSION['csrf_token']);csrfToken();
            $needsMfa=mfaFeatureEnabled()&&!mfaEnabled($pdo,$uid);flash($needsMfa?'Your own password is saved. Complete authenticator setup to continue.':'Your own password is saved.');redirect($needsMfa?'mfa.php':'staff.php');
        }catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
$pageTitle='Choose your own password';require __DIR__.'/includes/header.php';?>
<section class="compact-panel"><p class="eyebrow">FIRST SIGN-IN</p><h1>Choose your own password.</h1><p>Replace the temporary password provided by your administrator before using your staff account.</p><?php if($error):?><p class="flash error" role="alert"><?=e($error)?></p><?php endif;?><form method="post"><?=csrfField()?><label for="current-password">Temporary password</label><input id="current-password" name="current_password" type="password" required maxlength="72" autocomplete="current-password"><label for="password">New password</label><input id="password" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><label for="confirm">Confirm new password</label><input id="confirm" name="password_confirm" type="password" required maxlength="72" autocomplete="new-password"><p class="fine-print">12+ characters, uppercase, lowercase, a number and a symbol. Maximum 72 bytes.</p><button class="wide">Save my password</button></form></section>
<?php require __DIR__.'/includes/footer.php';?>
