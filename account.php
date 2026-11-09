<?php
require __DIR__.'/includes/bootstrap.php';requireLogin();$error='';$uid=(int)currentUser()['user_id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=postString('action');
 $retry=consumeAuthLimits($pdo,[authBucketKey('account-action',(string)$uid)=>10]);
 if($retry){http_response_code(429);header('Retry-After: '.$retry);$error='Too many requests. Try again later.';}
 elseif($action==='verify'){
  if(isRole('staff'))$error='Your administrator manages your staff login ID.';
  elseif(!empty(currentUser()['email_verified_at']))$error='Your email is already verified.';
  else flash(issueAccountToken($pdo,currentUser(),'verify')?'Verification email sent.':'Delivery failed. Contact the administrator.','success');
  if(!$error)redirect('account.php');
 }elseif($action==='password'){
  $q=$pdo->prepare('SELECT password_hash FROM users WHERE user_id=?');$q->execute([$uid]);$hash=$q->fetchColumn();$password=postString('password');
  if(!reauthenticate($pdo,postString('current_password'),trim(postString('code'))))$error='Current password is incorrect.';
  elseif(!passwordMeetsPolicy($password)||$password!==postString('password_confirm'))$error='New passwords must match and meet the password requirements.';
  else{
   $pdo->beginTransaction();$q=$pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE user_id=? AND password_hash=?');$q->execute([password_hash($password,PASSWORD_BCRYPT,['cost'=>12]),$uid,$hash]);
   if($q->rowCount()!==1){$pdo->rollBack();$error='Password changed in another session. Log in again.';}
   else{$pdo->prepare("DELETE FROM account_tokens WHERE user_id=? AND purpose='reset'")->execute([$uid]);audit($pdo,'password.changed',$uid);$pdo->commit();notifyAccountSecurity($pdo,currentUser(),'Fairboard password changed','Your password was changed and existing sessions were signed out. If this was not you, contact the administrator immediately.');resetSession();flash('Password changed. Please log in again.');redirect('login.php');}
  }
 }else abortRequest(400,'Invalid action.');
}
$pageTitle='Your account';require __DIR__.'/includes/header.php';?>
<div class="page-head"><div><p class="eyebrow">YOUR SPACE</p><h1>Your account.</h1></div><span class="pill"><?=e(currentUser()['role'])?></span></div>
<div class="two-column"><section class="panel"><h2><?=e(currentUser()['name'])?></h2><p><?=e(currentUser()['email'])?></p><p class="status"><?=isRole('staff')?'Managed staff login':(currentUser()['email_verified_at']?'Email verified':'Email not yet verified')?></p><?php if(!isRole('staff')&&!currentUser()['email_verified_at']):?><form method="post"><?=csrfField()?><input type="hidden" name="action" value="verify"><button class="secondary">Resend verification email</button></form><?php endif;?><?php if(isRole('creator')):?><p><a href="billing.php">Manage creator plan →</a></p><?php endif;?></section>
<section class="panel"><h2>Change password</h2><?php if($error):?><p class="flash error"><?=e($error)?></p><?php endif;?><form method="post"><?=csrfField()?><input type="hidden" name="action" value="password"><label for="current">Current password</label><input id="current" name="current_password" type="password" required autocomplete="current-password"><label for="password">New password</label><input id="password" name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"><p class="fine-print">12+ characters, uppercase, lowercase, a number and a symbol. Maximum 72 bytes.</p><label for="confirm">Confirm new password</label><input id="confirm" name="password_confirm" type="password" required autocomplete="new-password"><?=secondFactorField()?><button>Update password</button></form></section></div>
<section class="panel"><h2>Security &amp; privacy</h2><?php if(mfaFeatureEnabled()):?><p><a href="mfa.php">Manage authenticator and recovery codes →</a></p><?php else:?><p class="notice">MFA is temporarily paused for testing. Your authenticator settings are saved.</p><?php endif;?><p><a class="button danger" href="delete_account.php">Delete my account</a></p></section>
<?php require __DIR__.'/includes/footer.php';?>
