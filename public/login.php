<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

// Already signed in? Go to your own home page.
if ($user = current_user()) {
    redirect(home_path($user['role']));
}

$notice   = null;
$login    = ['username' => '', 'error' => ''];
$reg      = ['first' => '', 'last' => '', 'username' => '', 'code' => '', 'privacy' => false, 'errors' => []];

if (!empty($_SESSION['signed_out_idle'])) {
    unset($_SESSION['signed_out_idle']);
    $notice = 'You were signed out after 30 minutes without activity. Please sign in again.';
}
if ($flash = flash_get()) {
    $notice = $flash['message'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = post_str('action');

    if ($action === 'login') {
        $login['username'] = post_str('username');
        $password = post_raw('password');
        if ($login['username'] === '' || $password === '') {
            $login['error'] = 'Enter your username and your password.';
        } else {
            $found = AuthService::attempt($login['username'], $password);
            if ($found !== null) {
                login_user($found);
                redirect(home_path($found['role']));
            }
            // One message for "no such user" and "wrong password", so nobody can guess which usernames exist.
            $login['error'] = 'That username and password do not match.';
        }
    }

    if ($action === 'register') {
        $reg['first']    = post_str('first_name');
        $reg['last']     = post_str('last_name');
        $reg['username'] = post_str('reg_username');
        $reg['code']     = post_str('join_code');
        $reg['privacy']  = post_str('privacy') === '1';
        $pw  = post_raw('reg_password');
        $pw2 = post_raw('reg_confirm');
        $err = [];

        if ($reg['first'] === '' || mb_strlen($reg['first']) > 60) { $err['first_name'] = 'Enter your first name (up to 60 letters).'; }
        if ($reg['last']  === '' || mb_strlen($reg['last'])  > 60) { $err['last_name']  = 'Enter your last name (up to 60 letters).'; }
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $reg['username'])) {
            $err['reg_username'] = 'Use 3 to 30 letters, numbers, dots, dashes or underscores.';
        }
        if (mb_strlen($pw) < 10)       { $err['reg_password'] = 'Use at least 10 characters.'; }
        elseif (mb_strlen($pw) > 128)  { $err['reg_password'] = 'Use 128 characters or fewer.'; }
        elseif (strcasecmp($pw, $reg['username']) === 0) { $err['reg_password'] = 'Your password cannot be the same as your username.'; }
        if ($pw !== $pw2)              { $err['reg_confirm'] = 'The two passwords do not match.'; }
        if (mb_strlen($reg['code']) > 12) { $err['join_code'] = 'That code is too long. Check it with your teacher.'; }
        if (!$reg['privacy'])          { $err['privacy'] = 'Please confirm that you have read the student privacy notice.'; }

        if (!$err) {
            try {
                $studentId = AuthService::registerStudent($reg['username'], $reg['first'] . ' ' . $reg['last'], $pw);
                $newUser = AuthService::findActiveById($studentId);
                login_user($newUser);

                if ($reg['code'] === '') {
                    flash_set('Your account is ready. Ask your teacher for a class code to join your class.');
                } elseif (EnrollmentService::joinByCode($studentId, $reg['code'])) {
                    flash_set('Your account is ready, and you have joined your class.');
                } else {
                    flash_set('Your account is ready, but that class code did not match. Check it with your teacher.', 'error');
                }
                redirect('/student/index.php');
            } catch (DuplicateUsernameException $e) {
                $err['reg_username'] = 'That username is taken. Try another one.';
            }
        }
        $reg['errors'] = $err;
    }
}

_page_head('Sign in', ['/assets/css/auth.css'], 'auth');
?>
<header class="auth-top">
  <a class="auth-brand" href="/">QUIVER</a>
  <span class="auth-tag">Classroom assessment, thoughtfully organised.</span>
</header>

<main class="auth-main">
  <h1>A quiet place to do your best work.</h1>
  <p class="auth-lede">Sign in to your classroom. Your account takes you to the right workspace.</p>

  <?php if ($notice !== null): ?>
    <div class="alert alert--notice" role="status"><?= e($notice) ?></div>
  <?php endif; ?>

  <div class="auth-grid">
    <div>
      <section class="card" id="signin" aria-labelledby="signin-title">
        <h2 id="signin-title">Welcome back</h2>
        <p class="card__intro">Students, teachers and administrators use the same sign-in.</p>

        <?php if ($login['error'] !== ''): ?>
          <div class="alert alert--error" role="alert"><?= e($login['error']) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php#signin">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="login">
          <?= field('username', 'Username', 'text', ['value' => $login['username'], 'autocomplete' => 'username', 'required' => true, 'maxlength' => 40, 'nocaps' => true]) ?>
          <?= field('password', 'Password', 'password', ['autocomplete' => 'current-password', 'required' => true, 'maxlength' => 128]) ?>
          <div class="actions">
            <button class="btn btn--primary btn--pill" type="submit">Sign in →</button>
            <a class="auth-link" href="#access">Forgot your password?</a>
          </div>
        </form>
        <p class="card__foot">Teacher accounts are created by an administrator. Ask your school for your username and temporary password.</p>
      </section>

      <section class="need-access" id="access" aria-labelledby="access-title">
        <h2 id="access-title">Need access again?</h2>
        <p>There is no email reset link. Students: contact your teacher or school administrator and give your username. Teachers: contact your administrator.</p>
        <p class="muted-sm">After your identity is checked, you will receive a temporary password in person or through your school's approved channel. You must change it at your next sign-in.</p>
      </section>
    </div>

    <section class="card" id="register" aria-labelledby="register-title">
      <h2 id="register-title">New student?</h2>
      <p class="card__intro">Create a student account, then join your class with the code from your teacher.</p>

      <?= error_summary($reg['errors']) ?>

      <form method="post" action="/login.php#register">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="register">
        <div class="row2">
          <?= field('first_name', 'First name', 'text', ['value' => $reg['first'], 'error' => $reg['errors']['first_name'] ?? '', 'autocomplete' => 'given-name', 'required' => true, 'maxlength' => 60]) ?>
          <?= field('last_name', 'Last name', 'text', ['value' => $reg['last'], 'error' => $reg['errors']['last_name'] ?? '', 'autocomplete' => 'family-name', 'required' => true, 'maxlength' => 60]) ?>
        </div>
        <?= field('reg_username', 'Choose a username', 'text', ['value' => $reg['username'], 'error' => $reg['errors']['reg_username'] ?? '', 'hint' => 'Use a unique school username. No email address required.', 'autocomplete' => 'username', 'required' => true, 'maxlength' => 30, 'nocaps' => true]) ?>
        <div class="row2">
          <?= field('reg_password', 'Create password', 'password', ['error' => $reg['errors']['reg_password'] ?? '', 'hint' => 'At least 10 characters.', 'autocomplete' => 'new-password', 'required' => true, 'maxlength' => 128]) ?>
          <?= field('reg_confirm', 'Confirm password', 'password', ['error' => $reg['errors']['reg_confirm'] ?? '', 'autocomplete' => 'new-password', 'required' => true, 'maxlength' => 128]) ?>
        </div>
        <?= field('join_code', 'Class join code', 'text', ['name' => 'join_code', 'value' => $reg['code'], 'error' => $reg['errors']['join_code'] ?? '', 'hint' => 'Optional now. You can join a class after registration.', 'maxlength' => 12, 'nocaps' => true]) ?>

        <p class="privacy">Student privacy: your teacher can see your work in their class. Other students cannot. Administrators manage accounts, not your answers or grades.</p>
        <div class="field">
          <label class="check" for="privacy">
            <input type="checkbox" id="privacy" name="privacy" value="1"<?= $reg['privacy'] ? ' checked' : '' ?><?= isset($reg['errors']['privacy']) ? ' aria-invalid="true"' : '' ?>>
            <span>I have read the student privacy notice.</span>
          </label>
          <?php if (isset($reg['errors']['privacy'])): ?><p class="field__error"><?= e($reg['errors']['privacy']) ?></p><?php endif; ?>
        </div>
        <button class="btn btn--primary btn--pill" type="submit">Create student account</button>
      </form>
    </section>
  </div>

  <p class="auth-foot">QUIVER · School-managed access · Keep your password private.</p>
</main>
</body></html>
