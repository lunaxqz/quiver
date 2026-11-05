<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Works for every role. require_login() lets someone with a temporary password reach this page (and only this page).
$user   = require_login();
$forced = (int) $user['must_change_password'] === 1;
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $current = post_raw('current_password');
    $new     = post_raw('new_password');
    $confirm = post_raw('confirm_password');
    $ip      = client_ip();

    if ($current === '') {
        $errors['current_password'] = 'Enter your current (or temporary) password.';
    } elseif (RateLimiter::isBlocked('login', (string) $user['username'], $ip)) {
        http_response_code(429);
        $errors['current_password'] = 'Too many wrong attempts. Please wait up to 15 minutes and try again.';
    } elseif (!AuthService::verifyPassword((int) $user['id'], $current)) {
        RateLimiter::recordFailure('login', (string) $user['username'], $ip);
        $errors['current_password'] = 'That is not your current password.';
    }

    if (mb_strlen($new) < 10)         { $errors['new_password'] = 'Use at least 10 characters.'; }
    elseif (mb_strlen($new) > 128)    { $errors['new_password'] = 'Use 128 characters or fewer.'; }
    elseif ($new === $current)        { $errors['new_password'] = 'Choose a password different from your current one.'; }
    elseif (strcasecmp($new, (string) $user['username']) === 0) { $errors['new_password'] = 'Your password cannot be the same as your username.'; }
    if ($new !== $confirm)            { $errors['confirm_password'] = 'The two passwords do not match.'; }

    if (!$errors) {
        AuthService::changePassword((int) $user['id'], $new);
        RateLimiter::recordSuccess('login', (string) $user['username'], $ip);
        session_regenerate_id(true);   // a fresh session id after a credential change
        flash_set('Your password has been changed.');
        redirect(home_path((string) $user['role']));
    }
}

_page_head('Change password', ['/assets/css/auth.css'], 'auth');
?>
<header class="auth-top">
  <a class="auth-brand" href="/">QUIVER</a>
  <span class="auth-tag"><?= e($user['full_name']) ?> · <form class="inline-form" method="post" action="/logout.php"><?= csrf_field() ?><button class="linklike" type="submit">Sign out</button></form></span>
</header>

<main class="auth-main auth-narrow">
  <h1><?= $forced ? 'Choose your own password.' : 'Change your password.' ?></h1>
  <p class="auth-lede">
    <?= $forced
        ? 'You signed in with a temporary password. Choose a new one before you continue. Only you should know it.'
        : 'Enter your current password, then choose a new one.' ?>
  </p>

  <section class="card" aria-labelledby="pw-title">
    <h2 id="pw-title"><?= $forced ? 'New password' : 'Update password' ?></h2>
    <?= error_summary($errors) ?>
    <form method="post" action="/change_password.php">
      <?= csrf_field() ?>
      <?= field('current_password', $forced ? 'Temporary password' : 'Current password', 'password', ['error' => $errors['current_password'] ?? '', 'autocomplete' => 'current-password', 'required' => true, 'maxlength' => 128]) ?>
      <?= field('new_password', 'New password', 'password', ['error' => $errors['new_password'] ?? '', 'hint' => 'At least 10 characters.', 'autocomplete' => 'new-password', 'required' => true, 'maxlength' => 128]) ?>
      <?= field('confirm_password', 'Confirm new password', 'password', ['error' => $errors['confirm_password'] ?? '', 'autocomplete' => 'new-password', 'required' => true, 'maxlength' => 128]) ?>
      <div class="actions">
        <button class="btn btn--primary btn--pill" type="submit">Save new password</button>
        <?php if (!$forced): ?><a class="auth-link" href="/">Cancel</a><?php endif; ?>
      </div>
    </form>
  </section>
</main>
</body></html>
