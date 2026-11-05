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

teacher_start('Accounts and classes', 'accounts', 'admin');
?>
<p class="page-desc">Create teacher accounts, issue temporary passwords and review class records. Administrators manage accounts, not student answers or grades.</p>

<?php if ($issued !== null): ?>
  <div class="alert alert--notice" role="status">
    <strong><?= e($issued['heading']) ?></strong>
    <span class="table__sub">Username: <?= e($issued['username']) ?></span>
    <code class="secret"><?= e($issued['password']) ?></code>
    This password is shown <strong>once</strong>. Give it to the person in person or through your school's approved channel. They must change it the next time they sign in.
  </div>
<?php endif; ?>

<div class="admin-grid">
  <div>
    <section class="panel" id="users" aria-labelledby="users-title">
      <h2 id="users-title">User accounts</h2>
      <form class="filters" method="get" action="/admin/index.php#users" role="search">
        <label class="visually-hidden" for="q">Search by name or username</label>
        <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Search by name or username" maxlength="60">
        <label class="visually-hidden" for="role">Role</label>
        <select class="select" id="role" name="role">
          <option value="">All roles</option>
          <?php foreach (['teacher' => 'Teachers', 'student' => 'Students', 'admin' => 'Administrators'] as $v => $l): ?>
            <option value="<?= $v ?>"<?= $role === $v ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="status">Status</label>
        <select class="select" id="status" name="status">
          <option value="">Any status</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="disabled"<?= $status === 'disabled' ? ' selected' : '' ?>>Deactivated</option>
        </select>
        <button class="btn" type="submit">Apply</button>
      </form>

      <?php if (!$people['rows']): ?>
        <?= state_empty('No accounts match', 'Try a different search or filter.', 'Clear filters', '/admin/index.php#users') ?>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Last sign-in</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($people['rows'] as $row): $isMe = (int) $row['id'] === (int) $admin['id']; ?>
              <tr>
                <td><strong><?= e($row['full_name']) ?></strong><span class="table__sub"><?= e($row['username']) ?><?= $isMe ? ' (you)' : '' ?></span></td>
                <td><?= e(ucfirst((string) $row['role'])) ?></td>
                <td>
                  <?= $row['status'] === 'active' ? status_mark('complete', 'Active') : status_mark('neutral', 'Deactivated') ?>
                  <?php if ($row['status'] === 'active' && (int) $row['must_change_password'] === 1): ?>
                    <span class="table__sub">Must change password</span>
                  <?php endif; ?>
                </td>
                <td><?= $row['last_login_at'] ? e(to_manila($row['last_login_at'], 'j M Y, g:i A')) : 'Never' ?></td>
                <td class="table__actions">
                  <?php if (!$isMe): ?>
                    <a class="btn btn--sm" href="/admin/index.php?reset=<?= e(rawurlencode((string) $row['username'])) ?>#reset">Reset password</a>
                    <form class="inline-form" method="post" action="/admin/index.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                      <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'disabled' : 'active' ?>">
                      <input type="hidden" name="back" value="<?= e($back) ?>">
                      <button class="btn btn--sm<?= $row['status'] === 'active' ? ' btn--danger' : '' ?>" type="submit"><?= $row['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
          $from = ($people['page'] - 1) * AccountService::PAGE_SIZE + 1;
          $to   = min($people['total'], $people['page'] * AccountService::PAGE_SIZE);
          $link = fn(int $p) => admin_url(['q' => $q, 'role' => $role, 'status' => $status, 'cq' => $cq, 'page' => (string) $p], '#users');
        ?>
        <nav class="pager" aria-label="User pages">
          <span>Showing <?= $from ?> to <?= $to ?> of <?= $people['total'] ?></span>
          <span class="pager__links">
            <?php if ($people['page'] > 1): ?><a class="btn btn--sm" href="<?= e($link($people['page'] - 1)) ?>">Previous</a><?php endif; ?>
            <?php if ($people['page'] < $people['pages']): ?><a class="btn btn--sm" href="<?= e($link($people['page'] + 1)) ?>">Next</a><?php endif; ?>
          </span>
        </nav>
      <?php endif; ?>
    </section>

    <section class="panel" id="class-records" aria-labelledby="classes-title">
      <h2 id="classes-title">Class records</h2>
      <form class="filters" method="get" action="/admin/index.php#class-records" role="search">
        <?php foreach (['q' => $q, 'role' => $role, 'status' => $status] as $k => $v): if ($v !== ''): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>">
        <?php endif; endforeach; ?>
        <label class="visually-hidden" for="cq">Search classes or teachers</label>
        <input class="input" id="cq" name="cq" type="search" value="<?= e($cq) ?>" placeholder="Search by class or teacher" maxlength="60">
        <button class="btn" type="submit">Search</button>
      </form>
      <?php if (!$classes): ?>
        <?= state_empty('No classes yet', $cq !== '' ? 'No class or teacher matches that search.' : 'Classes appear here as soon as teachers create them.') ?>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Class</th><th>Teacher</th><th>Students</th><th>Join code</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($classes as $c): ?>
              <tr>
                <td><strong><?= e($c['name']) ?></strong><span class="table__sub"><?= e(trim(($c['subject'] ?? '') . ' ' . ($c['section'] ?? '') . ' · ' . $c['school_year'], ' ·')) ?></span></td>
                <td><?= e($c['teacher_name']) ?></td>
                <td><?= (int) $c['students'] ?></td>
                <td><?= (int) $c['code_enabled'] === 1 ? status_mark('active', 'On') : status_mark('neutral', 'Off') ?></td>
                <td><?= $c['archived_at'] === null ? status_mark('complete', 'Active') : status_mark('neutral', 'Archived') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <p class="panel__note">Showing the 10 most recent classes. Archiving a class is added on Day 6; student records are always kept.</p>
    </section>
  </div>

  <aside>
    <section class="panel" id="create" aria-labelledby="create-title">
      <h2 id="create-title">Create teacher account</h2>
      <?= error_summary($createErrors) ?>
      <form method="post" action="/admin/index.php#create">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_teacher">
        <?= field('full_name', 'Full name', 'text', ['value' => $create['full_name'], 'error' => $createErrors['full_name'] ?? '', 'required' => true, 'maxlength' => 120, 'autocomplete' => 'off']) ?>
        <?= field('username', 'Username', 'text', ['value' => $create['username'], 'error' => $createErrors['username'] ?? '', 'required' => true, 'maxlength' => 30, 'nocaps' => true, 'autocomplete' => 'off']) ?>
        <?= field('temp_password', 'Temporary password', 'text', ['error' => $createErrors['temp_password'] ?? '', 'hint' => 'Leave blank and QUIVER will generate a strong one. The teacher must change it at first sign-in.', 'maxlength' => 128, 'autocomplete' => 'off']) ?>
        <button class="btn btn--primary" type="submit">Create account</button>
      </form>
    </section>

    <section class="panel" id="reset" aria-labelledby="reset-title">
      <h2 id="reset-title">Reset temporary password</h2>
      <p class="muted-sm">For a teacher or student who cannot sign in. Check who they are first. QUIVER makes a new temporary password and they must change it at their next sign-in. It also lifts any sign-in lock.</p>
      <?php if ($resetError !== ''): ?><div class="alert alert--error" role="alert"><?= e($resetError) ?></div><?php endif; ?>
      <form method="post" action="/admin/index.php#reset">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <?= field('reset_username', 'Account username', 'text', ['value' => $resetValue, 'required' => true, 'maxlength' => 40, 'nocaps' => true, 'autocomplete' => 'off']) ?>
        <button class="btn btn--primary" type="submit">Issue temporary password</button>
      </form>
    </section>
  </aside>
</div>
<?php teacher_end();
