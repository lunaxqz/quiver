<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$admin = require_role('admin');

/** Where to go back to after an action: same filters, same page. Only known keys are kept. */
function admin_url(array $src, string $anchor = ''): string
{
    $keep = [];
    foreach (['q', 'role', 'status', 'page', 'cq'] as $k) {
        if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '' && !($k === 'page' && $src[$k] === '1')) {
            $keep[$k] = $src[$k];
        }
    }
    return '/admin/index.php' . ($keep ? '?' . http_build_query($keep) : '') . $anchor;
}

$createErrors = [];
$create = ['full_name' => '', 'username' => ''];
$resetError = '';
$resetValue = isset($_GET['reset']) && is_string($_GET['reset']) ? trim($_GET['reset']) : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = post_str('action');

    if ($action === 'create_teacher') {
        $create['full_name'] = post_str('full_name');
        $create['username']  = post_str('username');
        $temp = post_raw('temp_password');

        if ($create['full_name'] === '' || mb_strlen($create['full_name']) > 120) { $createErrors['full_name'] = 'Enter the teacher\'s full name (up to 120 characters).'; }
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $create['username'])) { $createErrors['username'] = 'Use 3 to 30 letters, numbers, dots, dashes or underscores.'; }
        if ($temp !== '' && (mb_strlen($temp) < 10 || mb_strlen($temp) > 128)) { $createErrors['temp_password'] = 'Use 10 to 128 characters, or leave it blank to generate one.'; }

        if (!$createErrors) {
            try {
                $password = AccountService::createTeacher($create['full_name'], $create['username'], $temp);
                $_SESSION['issued'] = ['heading' => 'Teacher account created for ' . $create['full_name'], 'username' => $create['username'], 'password' => $password];
                redirect('/admin/index.php');
            } catch (DuplicateUsernameException $e) {
                $createErrors['username'] = 'That username is taken. Try another one.';
            }
        }
    }

    if ($action === 'reset_password') {
        $resetValue = post_str('reset_username');
        if ($resetValue === '') {
            $resetError = 'Enter the username of the account.';
        } else {
            $result = AccountService::issueTemporaryPassword($resetValue, (int) $admin['id']);
            if ($result['ok']) {
                $_SESSION['issued'] = ['heading' => 'Temporary password issued for ' . $result['name'], 'username' => $resetValue, 'password' => $result['password']];
                redirect('/admin/index.php');
            }
            $resetError = $result['error'];
        }
    }

    if ($action === 'set_status') {
        $error = AccountService::setStatus((int) post_str('user_id'), post_str('status'), (int) $admin['id']);
        $word = post_str('status') === 'active' ? 'reactivated' : 'deactivated';
        flash_set($error ?? ('Account ' . $word . '.'), $error === null ? 'notice' : 'error');
        $back = [];
        parse_str(post_str('back'), $back);
        redirect(admin_url($back, '#users'));
    }
}

// What the lists show
$q      = isset($_GET['q'])      && is_string($_GET['q'])      ? trim($_GET['q'])      : '';
$role   = isset($_GET['role'])   && is_string($_GET['role'])   ? $_GET['role']          : '';
$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status']        : '';
$page   = isset($_GET['page'])   && is_string($_GET['page'])   ? (int) $_GET['page']    : 1;
$cq     = isset($_GET['cq'])     && is_string($_GET['cq'])     ? trim($_GET['cq'])      : '';

$people  = AccountService::search($q, $role, $status, $page);
$classes = ClassService::adminList($cq, 10);

$issued = $_SESSION['issued'] ?? null;
unset($_SESSION['issued']);   // shown this once, then gone

$backFields = ['q' => $q, 'role' => $role, 'status' => $status, 'page' => (string) $people['page'], 'cq' => $cq];
$back = http_build_query(array_filter($backFields, fn($v) => $v !== '' && $v !== '1'));

/* Open "Give starter password" tab automatically when ?reset=... is clicked or a reset error occurred */
$openResetTab = ($resetValue !== '' || $resetError !== '') && empty($createErrors);
$createInitialStep = isset($createErrors['temp_password']) && !isset($createErrors['full_name']) && !isset($createErrors['username']) ? 2 : 1;

teacher_start(
    'People & starter passwords',
    'accounts',
    'admin',
    [],
    'Help locked-out students or teachers sign in, add new teachers, and check school classes.'
);
?>

<?php if ($issued !== null): ?>
  <div class="alert alert--notice" role="status">
    <div class="issued-head">
      <div>
        <strong class="issued-title"><?= e($issued['heading']) ?></strong>
        <span class="table__sub">Username: <strong><?= e($issued['username']) ?></strong></span>
      </div>
      <?= status_mark('complete', 'Shown once') ?>
    </div>
    <code class="secret"><?= e($issued['password']) ?></code>
    <div class="issued-foot">
      <span>Give this starter password to the person in person. They will choose their own private password as soon as they sign in.</span>
      <button class="btn btn--sm" type="button" data-copy-text="<?= e($issued['password']) ?>">Copy password</button>
    </div>
  </div>
<?php endif; ?>

<div class="admin-grid">
  <div>
    <!-- 1. Most Common Admin Task: Find a Person & Give a Starter Password -->
    <section class="panel" id="users" aria-labelledby="users-title">
      <div class="panel__head-row">
        <h2 id="users-title">Find a person</h2>
        <span class="muted-sm">Times are local Philippine Time</span>
      </div>

      <form class="filters" method="get" action="/admin/index.php#users" role="search">
        <label class="visually-hidden" for="q">Search by name or username</label>
        <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search by name or username..." maxlength="60">
        <label class="visually-hidden" for="role">Role</label>
        <select class="select" id="role" name="role">
          <option value="">All roles</option>
          <?php foreach (['teacher' => 'Teachers', 'student' => 'Students', 'admin' => 'Administrators'] as $v => $l): ?>
            <option value="<?= $v ?>"<?= $role === $v ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="status">Account status</label>
        <select class="select" id="status" name="status">
          <option value="">Any status</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="disabled"<?= $status === 'disabled' ? ' selected' : '' ?>>Turned off</option>
        </select>
        <button class="btn btn--sm" type="submit">Search</button>
      </form>

      <?= status_legend([
          ['complete',  'Active'],
          ['attention', 'Using starter password'],
          ['neutral',   'Turned off'],
      ], 'Account status:') ?>

      <?php if (!$people['rows']): ?>
        <?= state_empty('No matching people', 'Try a different name, username, or role filter.', 'Show everyone', '/admin/index.php#users') ?>
      <?php else: ?>
        <ul class="well-list">
          <?php foreach ($people['rows'] as $row):
              $isMe = (int) $row['id'] === (int) $admin['id'];
              $isActive = $row['status'] === 'active';
              $needsPwChange = $isActive && (int) $row['must_change_password'] === 1;
              $roleWord = match ((string) $row['role']) {
                  'admin'   => 'Administrator',
                  'teacher' => 'Teacher',
                  default   => 'Student',
              };
              $lastSeen = $row['last_login_at']
                  ? 'Last sign-in ' . friendly_manila((string) $row['last_login_at'])
                  : 'Never signed in';
          ?>
            <li class="well-row">
              <div class="well-row__main">
                <div class="well-row__top">
                  <span class="well-row__title"><?= e($row['full_name']) ?></span>
                  <span class="role-chip"><?= e($roleWord) ?></span>
                  <?php if (!$isActive): ?>
                    <?= status_mark('neutral', 'Turned off') ?>
                  <?php elseif ($needsPwChange): ?>
                    <?= status_mark('attention', 'Starter password') ?>
                  <?php else: ?>
                    <?= status_mark('complete', 'Active') ?>
                  <?php endif; ?>
                </div>
                <div class="well-row__meta">
                  @<?= e($row['username']) ?><?= $isMe ? ' · You' : '' ?> · <?= e($lastSeen) ?>
                </div>
              </div>
              <?php if (!$isMe): ?>
                <div class="well-row__actions">
                  <!-- ONE obvious safe action per row; risky Turn Off / Turn On sits safely inside ••• -->
                  <a class="btn btn--sm<?= $needsPwChange ? ' btn--primary' : '' ?>" href="/admin/index.php?reset=<?= e(rawurlencode((string) $row['username'])) ?>#reset">Starter password</a>
                  <details class="row-more">
                    <summary aria-label="More account actions for <?= e($row['full_name']) ?>" title="More options">•••</summary>
                    <div class="row-more__menu">
                      <form method="post" action="/admin/index.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="status" value="<?= $isActive ? 'disabled' : 'active' ?>">
                        <input type="hidden" name="back" value="<?= e($back) ?>">
                        <button class="btn btn--sm<?= $isActive ? ' btn--danger' : ' btn--primary' ?>" type="submit">
                          <?= $isActive ? 'Turn off account' : 'Turn account back on' ?>
                        </button>
                      </form>
                    </div>
                  </details>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php
          $from = ($people['page'] - 1) * AccountService::PAGE_SIZE + 1;
          $to   = min($people['total'], $people['page'] * AccountService::PAGE_SIZE);
          $link = fn(int $p) => admin_url(['q' => $q, 'role' => $role, 'status' => $status, 'cq' => $cq, 'page' => (string) $p], '#users');
        ?>
        <nav class="pager" aria-label="People pages">
          <span>Showing <?= $from ?> to <?= $to ?> of <?= $people['total'] ?> people</span>
          <span class="pager__links">
            <?php if ($people['page'] > 1): ?><a class="btn btn--sm" href="<?= e($link($people['page'] - 1)) ?>">&larr; Previous</a><?php endif; ?>
            <?php if ($people['page'] < $people['pages']): ?><a class="btn btn--sm" href="<?= e($link($people['page'] + 1)) ?>">Next &rarr;</a><?php endif; ?>
          </span>
        </nav>
      <?php endif; ?>
    </section>

    <!-- 2. Recent School Classes -->
    <section class="panel" id="class-records" aria-labelledby="classes-title">
      <div class="panel__head-row">
        <h2 id="classes-title">Recent classes</h2>
        <a class="btn btn--sm" href="/admin/classes.php">View all classes &rarr;</a>
      </div>
      <form class="filters" method="get" action="/admin/index.php#class-records" role="search">
        <?php foreach (['q' => $q, 'role' => $role, 'status' => $status] as $k => $v): if ($v !== ''): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>">
        <?php endif; endforeach; ?>
        <label class="visually-hidden" for="cq">Search classes or teachers</label>
        <input class="input" id="cq" name="cq" type="search" value="<?= e($cq) ?>" placeholder="Search by class name or teacher..." maxlength="60">
        <button class="btn btn--sm" type="submit">Search</button>
      </form>

      <?php if (!$classes): ?>
        <?= state_empty('No classes yet', $cq !== '' ? 'No class or teacher matches that search.' : 'Classes appear here as soon as teachers set them up.') ?>
      <?php else: ?>
        <ul class="well-list">
          <?php foreach ($classes as $c):
              $teacherName = (string) ($c['owner_name'] ?? $c['teacher_name'] ?? 'Unassigned');
              $stuCount    = (int) ($c['student_count'] ?? $c['students'] ?? 0);
              $codeSec     = trim(implode(' · ', array_filter([
                  (string) ($c['course_code'] ?? $c['subject'] ?? ''),
                  ($c['section'] ?? '') !== '' ? 'Section ' . $c['section'] : '',
              ])));
          ?>
            <li class="well-row">
              <div class="well-row__main">
                <div class="well-row__top">
                  <span class="well-row__title"><?= e($c['name']) ?></span>
                  <?php if ($codeSec !== ''): ?>
                    <span class="role-chip"><?= e($codeSec) ?></span>
                  <?php endif; ?>
                </div>
                <div class="well-row__meta">
                  Teacher: <?= e($teacherName) ?> · <?= e((string) ($c['school_year'] ?? '')) ?> · <?= $stuCount ?> student<?= $stuCount === 1 ? '' : 's' ?>
                </div>
              </div>
              <div class="well-row__actions">
                <?= (int) ($c['code_enabled'] ?? 0) === 1 ? status_mark('active', 'Code on') : status_mark('neutral', 'Code off') ?>
                <?= ($c['archived_at'] ?? null) === null ? status_mark('complete', 'Active') : status_mark('neutral', 'Archived') ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="panel__note">Showing the newest school classes. Student work and records are always preserved.</p>
    </section>
  </div>

  <!-- Right Column: Focused Quick-Action Card (Add Teacher OR Issue Starter Password) + Help Box -->
  <aside id="reset">
    <div id="create"></div>
    <div class="pill-tabs pill-tabs--full" role="tablist" aria-label="Account actions" data-pill-group="admin-tools">
      <span class="pill-tabs__slider" aria-hidden="true"></span>
      <button class="pill-tab pill-tab--grow" role="tab" type="button" aria-selected="<?= $openResetTab ? 'false' : 'true' ?>" data-tab-target="panel-create">Add teacher</button>
      <button class="pill-tab pill-tab--grow" role="tab" type="button" aria-selected="<?= $openResetTab ? 'true' : 'false' ?>" data-tab-target="panel-reset">Starter password</button>
    </div>

    <!-- Tab 1: Stepped "Add a Teacher" Form -->
    <section class="panel" id="panel-create" aria-labelledby="create-title"<?= $openResetTab ? ' hidden' : '' ?>>
      <?= error_summary($createErrors) ?>
      <form method="post" action="/admin/index.php#create" data-step-form data-initial-step="<?= $createInitialStep ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_teacher">

        <div class="step-head">
          <div class="step-head__row">
            <span data-step-label>Step 1 of 2 · Teacher details</span>
            <span class="step-head__meta" data-step-pct>50%</span>
          </div>
          <div class="progress-track" aria-hidden="true">
            <div class="progress-fill" data-step-fill></div>
          </div>
        </div>

        <div class="form-step is-active" data-step="1" data-step-title="Teacher details">
          <h2 id="create-title" class="panel__title-tight">Add a teacher account</h2>
          <p class="muted-sm panel__sublead">Enter the teacher's name and choose a simple school username.</p>
          <?= field('full_name', 'Teacher\'s full name', 'text', [
              'value' => $create['full_name'],
              'error' => $createErrors['full_name'] ?? '',
              'placeholder' => 'e.g. Elena Dela Cruz',
              'required' => true,
              'maxlength' => 120,
              'autocomplete' => 'off',
          ]) ?>
          <?= field('username', 'School username', 'text', [
              'value' => $create['username'],
              'error' => $createErrors['username'] ?? '',
              'placeholder' => 'e.g. elena.delacruz',
              'hint' => 'Letters, numbers, or dots (3–30 characters).',
              'required' => true,
              'maxlength' => 30,
              'nocaps' => true,
              'autocomplete' => 'off',
          ]) ?>
          <div class="step-nav step-nav--end">
            <button class="btn btn--primary btn--pill" type="button" data-goto-step="2">
              <span>Next: Starter password</span>
              <span class="btn__arrow" aria-hidden="true">&rarr;</span>
            </button>
          </div>
        </div>

        <div class="form-step" data-step="2" data-step-title="Starter password">
          <h2 class="panel__title-tight">Starter password</h2>
          <p class="muted-sm panel__sublead">Leave this blank to let QUIVER generate a strong starter password automatically.</p>
          <?= field('temp_password', 'Custom starter password (optional)', 'text', [
              'error' => $createErrors['temp_password'] ?? '',
              'placeholder' => 'Leave blank to auto-generate',
              'hint' => 'The teacher will choose their own password on first sign-in.',
              'maxlength' => 128,
              'autocomplete' => 'off',
          ]) ?>
          <div class="step-nav">
            <button class="btn btn--quiet" type="button" data-goto-step="1">&larr; Back</button>
            <button class="btn btn--primary btn--pill" type="submit">
              <span>Create teacher</span>
              <span class="btn__arrow" aria-hidden="true">&rarr;</span>
            </button>
          </div>
        </div>
      </form>
    </section>

    <!-- Tab 2: Give Starter Password (Reset) -->
    <section class="panel" id="panel-reset" aria-labelledby="reset-title"<?= $openResetTab ? '' : ' hidden' ?>>
      <h2 id="reset-title" class="panel__title-tight">Give a starter password</h2>
      <p class="muted-sm panel__sublead">For a student or teacher who forgot their password or is locked out. Check their identity first—this creates a one-time password and clears any sign-in lock.</p>
      <?php if ($resetError !== ''): ?><div class="alert alert--error" role="alert"><?= e($resetError) ?></div><?php endif; ?>
      <form method="post" action="/admin/index.php#reset">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <?= field('reset_username', 'Person\'s username', 'text', [
            'value' => $resetValue,
            'placeholder' => 'e.g. maya.santos',
            'required' => true,
            'maxlength' => 40,
            'nocaps' => true,
            'autocomplete' => 'off',
        ]) ?>
        <button class="btn btn--primary btn--pill btn--block" type="submit">
          <span>Create starter password</span>
          <span class="btn__arrow" aria-hidden="true">&rarr;</span>
        </button>
      </form>
    </section>

    <!-- Stuck / Safety Help Box -->
    <div class="help-box help-box--flush">
      <span class="help-box__icon" aria-hidden="true">✓</span>
      <div>
        <h3>Reset password vs. Turn off account?</h3>
        <p>If someone cannot sign in, always choose <strong>Starter password</strong>. Only use <strong>••• &rarr; Turn off account</strong> when someone leaves the school.</p>
      </div>
    </div>
  </aside>
</div>
<?php teacher_end();
