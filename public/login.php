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
        $ip = client_ip();
        if ($login['username'] === '' || $password === '') {
            $login['error'] = 'Enter your username and your password.';
        } elseif (RateLimiter::isBlocked('login', $login['username'], $ip)) {
            // Too many recent mistakes: do not even check the password, and do not count this attempt.
            http_response_code(429);
            $login['error'] = 'Too many sign-in attempts. Please wait up to 15 minutes and try again. If you still cannot sign in, ask your teacher or administrator for a temporary password.';
        } else {
            $found = AuthService::attempt($login['username'], $password);
            if ($found !== null) {
                RateLimiter::recordSuccess('login', $login['username'], $ip);
                login_user($found);
                redirect(home_path($found['role']));
            }
            RateLimiter::recordFailure('login', $login['username'], $ip);
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

/* Open the registration tab & exact step automatically if a student submitted registration with errors */
$showRegister = !empty($reg['errors']) || (($_POST['action'] ?? '') === 'register');
$regInitialStep = 1;
if (!empty($reg['errors'])) {
    if (isset($reg['errors']['first_name']) || isset($reg['errors']['last_name'])) {
        $regInitialStep = 1;
    } elseif (isset($reg['errors']['reg_username']) || isset($reg['errors']['reg_password']) || isset($reg['errors']['reg_confirm'])) {
        $regInitialStep = 2;
    } else {
        $regInitialStep = 3;
    }
}

_page_head('Sign in', ['/assets/css/auth.css'], 'auth');
?>
<header class="auth-top">
  <a class="brand-lockup" href="/">
    <span class="brand-mark" aria-hidden="true">Q</span>
    <span>QUIVER</span>
  </a>
  <div class="auth-tag">
    <span>Calm, school-managed classroom quizzes</span>
    <?= _theme_toggle_btn() ?>
  </div>
</header>

<main class="auth-main">
  <div class="auth-hero">
    <h1>Welcome to your classroom</h1>
    <p class="auth-lede">Sign in to open your classes, or create a student account in three quick steps.</p>
  </div>

  <?php if ($notice !== null): ?>
    <div class="alert alert--notice" role="status"><?= e($notice) ?></div>
  <?php endif; ?>

  <!-- Sliding Clay Pill Switcher: Most Common Task (Sign in) First -->
  <div class="auth-switcher">
    <div class="pill-tabs" role="tablist" aria-label="Sign in or create student account" data-pill-group="auth">
      <span class="pill-tabs__slider" aria-hidden="true"></span>
      <button class="pill-tab" role="tab" type="button" aria-selected="<?= $showRegister ? 'false' : 'true' ?>" data-tab-target="panel-signin">Sign in</button>
      <button class="pill-tab" role="tab" type="button" aria-selected="<?= $showRegister ? 'true' : 'false' ?>" data-tab-target="panel-register">I'm a new student</button>
    </div>
  </div>

  <!-- Panel 1: Returning Sign-In (Students, Teachers & Staff) -->
  <section class="card" id="panel-signin" aria-labelledby="signin-title"<?= $showRegister ? ' hidden' : '' ?>>
    <h2 id="signin-title">Sign in to your account</h2>
    <p class="card__intro">Students, teachers, and school administrators all use this same sign-in.</p>

    <?php if ($login['error'] !== ''): ?>
      <div class="alert alert--error" role="alert"><?= e($login['error']) ?></div>
    <?php endif; ?>

    <form method="post" action="/login.php#signin">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <?= field('username', 'Your school username', 'text', [
          'value' => $login['username'],
          'placeholder' => 'e.g. maya.santos',
          'hint' => 'Given to you by your school, or chosen when you signed up.',
          'autocomplete' => 'username',
          'required' => true,
          'maxlength' => 40,
          'nocaps' => true,
      ]) ?>
      <?= field('password', 'Your password', 'password', [
          'autocomplete' => 'current-password',
          'required' => true,
          'maxlength' => 128,
      ]) ?>
      <div class="actions actions--full">
        <button class="btn btn--primary btn--pill" type="submit">
          <span>Sign in to classroom</span>
          <span class="btn__arrow" aria-hidden="true">&rarr;</span>
        </button>
        <a class="auth-link" href="#access">Forgot your password?</a>
      </div>
    </form>
    <p class="card__foot">New teacher? Your school administrator creates teacher accounts and gives you a starter password.</p>
  </section>

  <!-- Panel 2: Guided 3-Step Student Sign-Up -->
  <section class="card" id="panel-register" aria-labelledby="register-title"<?= $showRegister ? '' : ' hidden' ?>>
    <div id="register"></div>
    <?= error_summary($reg['errors']) ?>

    <form method="post" action="/login.php#register" data-step-form data-initial-step="<?= $regInitialStep ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="register">

      <div class="step-head">
        <div class="step-head__row">
          <span data-step-label>Step 1 of 3 · Your name</span>
          <span class="step-head__meta" data-step-pct>33%</span>
        </div>
        <div class="progress-track" aria-hidden="true">
          <div class="progress-fill" data-step-fill></div>
        </div>
      </div>

      <!-- Step 1: Real Name -->
      <div class="form-step is-active" data-step="1" data-step-title="Your name">
        <h2 id="register-title">First, what's your name?</h2>
        <p class="card__intro">Use your real name so your teacher recognises you on the class list.</p>
        <div class="row2">
          <?= field('first_name', 'First name', 'text', [
              'value' => $reg['first'],
              'error' => $reg['errors']['first_name'] ?? '',
              'placeholder' => 'e.g. Maya',
              'autocomplete' => 'given-name',
              'required' => true,
              'maxlength' => 60,
          ]) ?>
          <?= field('last_name', 'Last name', 'text', [
              'value' => $reg['last'],
              'error' => $reg['errors']['last_name'] ?? '',
              'placeholder' => 'e.g. Santos',
              'autocomplete' => 'family-name',
              'required' => true,
              'maxlength' => 60,
          ]) ?>
        </div>
        <div class="step-nav step-nav--end">
          <button class="btn btn--primary btn--pill" type="button" data-goto-step="2">
            <span>Next: Pick a login</span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </button>
        </div>
      </div>

      <!-- Step 2: Username & Password -->
      <div class="form-step" data-step="2" data-step-title="Pick your login">
        <h2>Choose your username &amp; password</h2>
        <p class="card__intro">No email address is needed—just choose a username and password you will remember.</p>
        <?= field('reg_username', 'Pick a username', 'text', [
            'value' => $reg['username'],
            'error' => $reg['errors']['reg_username'] ?? '',
            'placeholder' => 'e.g. maya.santos',
            'hint' => '3 to 30 letters, numbers, or dots.',
            'autocomplete' => 'username',
            'required' => true,
            'maxlength' => 30,
            'nocaps' => true,
        ]) ?>
        <div class="row2">
          <?= field('reg_password', 'Create a password', 'password', [
              'error' => $reg['errors']['reg_password'] ?? '',
              'autocomplete' => 'new-password',
              'required' => true,
              'maxlength' => 128,
          ]) ?>
          <?= field('reg_confirm', 'Type password again', 'password', [
              'error' => $reg['errors']['reg_confirm'] ?? '',
              'autocomplete' => 'new-password',
              'required' => true,
              'maxlength' => 128,
          ]) ?>
        </div>
        <div class="pw-rules" aria-live="polite">
          <span class="pw-chip" id="pw-chip-len">○ At least 10 characters</span>
          <span class="pw-chip" id="pw-chip-match">○ Both passwords match</span>
        </div>
        <div class="step-nav">
          <button class="btn btn--quiet" type="button" data-goto-step="1">&larr; Back</button>
          <button class="btn btn--primary btn--pill" type="button" data-goto-step="3">
            <span>Next: Join your class</span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </button>
        </div>
      </div>

      <!-- Step 3: Optional Class Code & Plain-Language Privacy -->
      <div class="form-step" data-step="3" data-step-title="Join your class">
        <h2>Join your class</h2>
        <p class="card__intro">Enter the short code from your teacher, or leave it blank and join later.</p>
        <?= field('join_code', 'Class code (optional)', 'text', [
            'name' => 'join_code',
            'value' => $reg['code'],
            'error' => $reg['errors']['join_code'] ?? '',
            'placeholder' => 'e.g. NET-4829',
            'hint' => 'You can also join more classes anytime from your home screen.',
            'maxlength' => 12,
            'nocaps' => true,
        ]) ?>

        <div class="privacy-well">
          <strong>Your privacy in plain words:</strong> Only your class teacher sees your quiz answers and scores. Other students cannot see your work, and school administrators only manage accounts.
        </div>

        <div class="field">
          <label class="check" for="privacy">
            <input type="checkbox" id="privacy" name="privacy" value="1"<?= $reg['privacy'] ? ' checked' : '' ?><?= isset($reg['errors']['privacy']) ? ' aria-invalid="true"' : '' ?>>
            <span>I have read and understood how my class work is shared.</span>
          </label>
          <?php if (isset($reg['errors']['privacy'])): ?><p class="field__error"><?= e($reg['errors']['privacy']) ?></p><?php endif; ?>
        </div>

        <div class="step-nav">
          <button class="btn btn--quiet" type="button" data-goto-step="2">&larr; Back</button>
          <button class="btn btn--primary btn--pill" type="submit">
            <span>Create student account</span>
            <span class="btn__arrow" aria-hidden="true">&rarr;</span>
          </button>
        </div>
      </div>
    </form>
  </section>

  <!-- Friendly Help Box for Stuck Users -->
  <aside class="help-box" id="access" aria-labelledby="access-title">
    <span class="help-box__icon" aria-hidden="true">?</span>
    <div>
      <h3 id="access-title">Forgot your password or locked out?</h3>
      <p>For student safety, QUIVER does not send password reset links by email. Tell your <strong>teacher</strong> or <strong>school administrator</strong> your username—after checking who you are, they will give you a one-time starter password in person so you can choose a new one.</p>
    </div>
  </aside>

  <p class="auth-foot">QUIVER · Keep your password private.</p>
</main>
</body></html>
