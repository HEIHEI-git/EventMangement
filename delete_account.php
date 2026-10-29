<?php
require __DIR__.'/includes/bootstrap.php';requireLogin();require_once __DIR__.'/includes/account_deletion.php';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(postString('confirmation')!=='Delete')$error='Type Delete exactly to confirm.';
    elseif(!reauthenticate($pdo,postString('password'),trim(postString('code'))))$error='Password or verification code is incorrect.';
    else try{deleteOwnAccount($pdo,(int)currentUser()['user_id'],(int)$_SESSION['auth_version']);resetSession();flash('Your account was deleted. You have been signed out.');redirect('index.php');}catch(DomainException $e){$error=$e->getMessage();}
}
$pageTitle='Delete your account';require __DIR__.'/includes/header.php';?>
<section class="compact-panel delete-panel"><p class="eyebrow">ACCOUNT &amp; PRIVACY</p><h1>Delete your account?</h1><p class="flash error">This is permanent. Your profile and sign-in access will be erased. Upcoming bookings and waiting-list places will be cancelled.</p><p>Past attendance, event content and invoice records remain under an anonymous account. Backups expire under the site's retention policy. Cancel your upcoming events first if you are a creator. Admin accounts require a handover.</p>
<?php if($error):?><p class="flash error" role="alert"><?php if($error==='Type Delete exactly to confirm.'):?>Type <strong class="delete-confirm-keyword">Delete</strong> exactly to confirm.<?php else:?><?=e($error)?><?php endif;?></p><?php endif;?>
<form method="post" data-delete-account><?=csrfField()?><label for="confirmation">Type <strong>Delete</strong> to confirm</label><input id="confirmation" name="confirmation" required pattern="Delete" autocomplete="off" spellcheck="false"><label for="password">Current password</label><input id="password" name="password" type="password" required maxlength="72" autocomplete="current-password"><?=secondFactorField()?><div class="actions"><a class="button secondary" href="account.php">Cancel</a><button class="danger" data-delete-submit>Delete my account permanently</button></div></form></section>
<?php require __DIR__.'/includes/footer.php';?>
