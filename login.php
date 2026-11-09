<?php
require __DIR__ . '/includes/bootstrap.php';
$next = safeEventNext($_POST['next'] ?? $_GET['next'] ?? null);
if (isLoggedIn()) redirect(loginRedirectFor(currentUser(), $next));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim(postString('email')));
    $password = postString('password');
    try {
        $retryAfter = loginRetryAfter($pdo, $email);
        if ($retryAfter > 0) {
            securityEvent('auth.login_limited');
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            $error = 'Too many login attempts. Please try again later.';
        } else {
            $user = false;
            // The same email validation used at registration also prevents collation aliases
            // (such as Unicode accents) from bypassing the normalised account limiter.
            if (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $statement = $pdo->prepare('SELECT user_id,name,email,password_hash,role,auth_version,is_active,must_change_password FROM users WHERE email=?');
                $statement->execute([$email]);
                $user = $statement->fetch();
            }
            // Verify a dummy hash for unknown accounts; do not expose account existence in errors.
            $hash = $user['password_hash'] ?? '$2y$12$GCW3vG.lIC1FyYOHhRnxHOB62k.MpvHbeFCgPq9JOkBY94BTOo37a';
            $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
            if ($user && (int)$user['is_active'] === 1 && $valid) {
                if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
                    $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=? AND password_hash=?')->execute([
                        password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['user_id'], $hash,
                    ]);
                }
                $pdo->prepare('DELETE FROM auth_rate_limits WHERE bucket_key=?')->execute([authBucketKey('login-account', $email)]);
                if (mfaFeatureEnabled() && mfaEnabled($pdo, (int)$user['user_id'])) {
                    resetSession();
                    $_SESSION['mfa_pending'] = ['uid'=>(int)$user['user_id'], 'version'=>(int)$user['auth_version'], 'expires'=>time()+300, 'next'=>$next];
                    csrfToken(); redirect('mfa_challenge.php');
                }
                establishLogin($user);
                audit($pdo,'auth.login',(int)$user['user_id']);
                flash('You are now logged in.');
                redirect(loginRedirectFor($user, $next));
            }
            securityEvent('auth.login_failed');
            $error = 'Invalid email or password.';
        }
    } catch (Throwable $exception) {
        serverError($exception);
    }
}
$pageTitle = 'Welcome back';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout">
 <div class="auth-art"><img src="assets/art/concert.svg" alt="Illustrated live music stage"><div><span class="eyebrow">GOOD PLANS START HERE</span><h2>Your next<br>great night.</h2><p>Find your people. Make a memory.</p></div></div>
 <div class="auth-panel"><a class="text-link" href="index.php">← Back to discover</a><p class="eyebrow">THE CITY IS WAITING</p><h1>Welcome back.</h1><p class="muted">Log in to pick up where you left off.</p>
 <?php if ($next): ?><p class="notice">Your event will be waiting after you log in.</p><?php endif; ?>
 <form method="post" class="auth-form">
 <?=csrfField()?><?php if($next):?><input type="hidden" name="next" value="<?=e($next)?>"><?php endif;?>
 <?php if($error):?><p class="flash error" role="alert"><?=e($error)?></p><?php endif;?>
 <label for="email">Email address</label><input id="email" type="email" name="email" required autocomplete="email" maxlength="254" placeholder="you@example.com" value="<?=e($_SERVER['REQUEST_METHOD']==='POST' ? $email : '')?>">
 <div class="label-row"><label for="password">Password</label><a href="forgot_password.php">Forgot password?</a></div>
 <div class="password-field"><input id="password" type="password" name="password" required autocomplete="current-password" maxlength="72"><button type="button" class="reveal" data-password-toggle="password" aria-label="Show password" aria-pressed="false">Show</button></div>
 <button class="button wide" type="submit">Log in <span aria-hidden="true">↗</span></button>
 </form><p class="auth-switch">New around here? <a href="register.php<?= $next ? '?next='.rawurlencode($next) : '' ?>">Create an account</a></p><p class="fine-print">Your account. Your plans. All in one place.</p>
 </div>
</section>
<?php require __DIR__.'/includes/footer.php'; ?>
