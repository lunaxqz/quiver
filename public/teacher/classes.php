<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];
$self      = '/teacher/classes.php';

/** Build a link back to this page keeping the current class, search and page. */
function cls_url(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return '/teacher/classes.php' . ($params ? '?' . http_build_query($params) : '');
}

// ------------------------------------------------------------------ actions (POST)
$create = ['name' => '', 'code' => '', 'section' => '', 'term' => ClassService::termOptions()[2] ?? '', 'errors' => []];
$terms  = ClassService::termOptions();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action  = post_str('action');
    $classId = (int) post_str('class_id');

    if ($action === 'create') {
        $create['name']    = post_str('name');
        $create['code']    = post_str('course_code');
        $create['section'] = post_str('section');
        $create['term']    = post_str('term');
        $err = [];
        if ($create['name'] === '' || mb_strlen($create['name']) > 120) { $err['name'] = 'Enter a class name (up to 120 characters).'; }
        if (mb_strlen($create['code']) > 30)    { $err['course_code'] = 'Use 30 characters or fewer.'; }
        if (mb_strlen($create['section']) > 60) { $err['section'] = 'Use 60 characters or fewer.'; }
        if (!in_array($create['term'], $terms, true)) { $err['term'] = 'Choose a school term from the list.'; }
        if (!$err) {
            $newId = ClassService::create($teacherId, $create['name'], $create['code'], $create['section'], $create['term']);
            flash_set('Class created. Share the join code with your students.');
            redirect(cls_url(['id' => $newId]));
        }
        $create['errors'] = $err;
    } else {
        // Every other action works on one class, and only a class you own.
        $class = $classId > 0 ? ClassService::findOwned($classId, $user) : null;
        if ($class === null) {
            abort_404();
        }
        $back = ['id' => $classId, 'q' => post_str('q'), 'rp' => (int) post_str('rp')];
        $isOpen = $class['archived_at'] === null;

        if ($isOpen) {
            switch ($action) {
                case 'regen':
                    ClassService::regenerateCode($classId);
                    flash_set('New join code created. The old code no longer works.');
                    break;
                case 'toggle_code':
                    $enable = post_str('enable') === '1';
                    ClassService::setCodeEnabled($classId, $enable);
                    flash_set($enable ? 'Join code enabled.' : 'Join code disabled. Nobody new can join with it.');
                    break;
                case 'add':
                    $u = trim(post_str('username'));
                    $res = $u === '' ? 'empty' : ClassService::addByUsername($classId, $u);
                    flash_set(match ($res) {
                        'added'     => $u . ' was added to the class.',
                        'already'   => $u . ' is already in this class.',
                        'empty'     => 'Enter a username to add.',
                        default     => 'No student account has that username.',
                    }, $res === 'added' ? 'notice' : 'error');
                    break;
                case 'remove':
                    ClassService::removeStudent($classId, (int) post_str('student_id'));
                    flash_set('Student removed. Their earlier work is kept.');
                    break;
                case 'reset_pw':
                    $temp = ClassService::issueTemporaryPassword($classId, (int) post_str('student_id'));
                    if ($temp === null) {
                        flash_set('That student is not in this class.', 'error');
                    } else {
                        // Shown once, to this teacher only, so they can pass it on in person.
                        $_SESSION['temp_pw'] = ['student_id' => (int) post_str('student_id'), 'password' => $temp];
                        flash_set('Temporary password created. It is shown once below.');
                    }
                    break;
                case 'archive':
                    ClassService::archive($classId);
                    flash_set('Class archived. Records, attempts and grades are kept.');
                    redirect(cls_url([]));
            }
        }
        redirect(cls_url($back));
    }
}

// ------------------------------------------------------------------ page data (GET)
$flash   = flash_get();
$tempPw  = $_SESSION['temp_pw'] ?? null;
unset($_SESSION['temp_pw']);

$totals   = ClassService::totalsForTeacher($teacherId);
$search   = trim((string) ($_GET['cs'] ?? ''));
$showAll  = isset($_GET['all']);
$allMatch = ClassService::listForTeacher($teacherId, $search);
$classes  = $showAll ? $allMatch : array_slice($allMatch, 0, 4);

$selectedId = (int) ($_GET['id'] ?? 0);
$class = null;
if (isset($_GET['id'])) {
    $class = ClassService::findOwned($selectedId, $user);
    if ($class === null) {
        abort_404();   // not yours (or does not exist): same answer, so nobody can probe class numbers
    }
} elseif ($allMatch) {
    $class = ClassService::findOwned((int) $allMatch[0]['id'], $user);
}

teacher_start('Classes', 'classes', 'teacher', ['/assets/css/classes.css']);
echo '<p class="page-sub">' . $totals['classes'] . ($totals['classes'] === 1 ? ' class' : ' classes') . ' · '
   . $totals['students'] . ($totals['students'] === 1 ? ' student' : ' students') . ' · Only classes you own appear here.</p>';

if ($flash !== null) {
    echo '<div class="alert alert--' . ($flash['type'] === 'error' ? 'error' : 'notice') . '" role="status">' . e($flash['message']) . '</div>';
}
?>
<div class="cls-grid">
  <!-- ============ left: your classes + create ============ -->
  <div class="cls-col">
    <section class="card" aria-labelledby="yours">
      <p class="card__eyebrow" id="yours">Your classes</p>
      <form method="get" action="<?= e($self) ?>">
        <?php if ($class): ?><input type="hidden" name="id" value="<?= (int) $class['id'] ?>"><?php endif; ?>
        <?= field('cs', 'Find a class', 'text', ['value' => $search, 'placeholder' => 'Search classes', 'maxlength' => 60]) ?>
      </form>
      <?php if (!$classes): ?>
        <?= $search !== '' ? state_empty('No matching classes', 'Try a different name, course code or section.') : state_empty('No classes yet', 'Create your first class with the form below.') ?>
      <?php else: ?>
        <ul class="cls-list">
          <?php foreach ($classes as $c): ?>
            <li>
              <a class="cls-item" href="<?= e(cls_url(['id' => $c['id'], 'cs' => $search, 'all' => $showAll ? 1 : null])) ?>"<?= $class && (int) $class['id'] === (int) $c['id'] ? ' aria-current="page"' : '' ?>>
                <strong><?= e(trim(($c['course_code'] ?? '') . ($c['section'] ? ' · Section ' . $c['section'] : ''), ' ·') ?: $c['name']) ?></strong>
                <span><?= e($c['name']) ?> · <?= (int) $c['student_count'] ?> student<?= (int) $c['student_count'] === 1 ? '' : 's' ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="foot-note">
          Showing <?= count($classes) ?> of <?= count($allMatch) ?> ·
          <?php if ($showAll): ?><a href="<?= e(cls_url(['id' => $class['id'] ?? null, 'cs' => $search])) ?>">Show fewer</a>
          <?php elseif (count($allMatch) > 4): ?><a href="<?= e(cls_url(['id' => $class['id'] ?? null, 'cs' => $search, 'all' => 1])) ?>">View all classes ↓</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="create-title">
      <h2 id="create-title">Create a class</h2>
      <?= error_summary($create['errors']) ?>
      <form method="post" action="<?= e($self) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <?= field('name', 'Class name', 'text', ['value' => $create['name'], 'error' => $create['errors']['name'] ?? '', 'required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Computer Networks']) ?>
        <div class="row2">
          <?= field('course_code', 'Course code', 'text', ['value' => $create['code'], 'error' => $create['errors']['course_code'] ?? '', 'maxlength' => 30, 'placeholder' => 'e.g. IT301']) ?>
          <?= field('section', 'Section', 'text', ['value' => $create['section'], 'error' => $create['errors']['section'] ?? '', 'maxlength' => 60, 'placeholder' => 'e.g. B']) ?>
        </div>
        <div class="field">
          <label class="field__label" for="term">School term</label>
          <select class="input" id="term" name="term"<?= isset($create['errors']['term']) ? ' aria-invalid="true"' : '' ?>>
            <?php foreach ($terms as $t): ?>
              <option value="<?= e($t) ?>"<?= $t === $create['term'] ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($create['errors']['term'])): ?><p class="field__error"><?= e($create['errors']['term']) ?></p><?php endif; ?>
        </div>
        <button class="btn btn--primary btn--pill" type="submit">Create class</button>
      </form>
    </section>
  </div>

  <!-- ============ right: the chosen class ============ -->
  <div class="cls-col">
  <?php if ($class === null): ?>
    <section class="card"><?= state_empty('Pick or create a class', 'Your class details, join code and roster appear here once you have a class.') ?></section>
  <?php else:
    $cid       = (int) $class['id'];
    $open      = $class['archived_at'] === null;
    $codeOn    = (int) $class['code_enabled'] === 1;
    $rq        = trim((string) ($_GET['q'] ?? ''));
    $rp        = max(1, (int) ($_GET['rp'] ?? 1));
    $perPage   = 10;
    $rosterTot = ClassService::rosterCount($cid, $rq);
    $pages     = max(1, (int) ceil($rosterTot / $perPage));
    $rp        = min($rp, $pages);
    $roster    = ClassService::roster($cid, $rq, $rp, $perPage);
    $from      = $rosterTot === 0 ? 0 : ($rp - 1) * $perPage + 1;
    $shown     = count($roster);
    $keep      = '<input type="hidden" name="class_id" value="' . $cid . '"><input type="hidden" name="q" value="' . e($rq) . '"><input type="hidden" name="rp" value="' . $rp . '">';
    $title     = trim(($class['course_code'] ?? '') . ($class['section'] ? ' · Section ' . $class['section'] : ''), ' ·');
  ?>
    <section class="card" aria-labelledby="class-title">
      <div class="card__row">
        <div>
          <h2 id="class-title"><?= e($class['name']) ?></h2>
          <p class="muted-sm"><?= e(trim($title . ($title !== '' ? ' · ' : '') . $class['school_year'])) ?></p>
        </div>
        <span class="mark" data-state="<?= $open ? 'active' : 'complete' ?>"><span class="mark__ring" aria-hidden="true"></span><span class="mark__label"><?= $open ? 'Active class' : 'Archived' ?></span></span>
      </div>
      <p class="muted-sm">Owner: <?= e($class['owner_name']) ?> · <?= (int) $class['student_count'] ?> enrolled · Created <?= e(to_manila($class['created_at'], 'd M Y')) ?></p>
      <hr class="rule">
      <div class="card__row">
        <div>
          <p class="card__eyebrow">Join code · <?= !$open ? 'closed' : ($codeOn ? 'enabled' : 'disabled') ?></p>
          <div class="join-code<?= $codeOn && $open ? '' : ' join-code--off' ?>"><?= e(ClassService::formatCode($class['join_code'])) ?></div>
        </div>
        <?php if ($open): ?>
        <div class="btn-row">
          <button class="btn btn--sm btn--pill btn--quiet" type="button" data-copy="<?= e(ClassService::formatCode($class['join_code'])) ?>"<?= $codeOn ? '' : ' disabled' ?>>Copy code</button>
          <form method="post" action="<?= e($self) ?>" data-confirm="Make a new code? The old code stops working. Enrolled students keep access.">
            <?= csrf_field() ?><input type="hidden" name="action" value="regen"><?= $keep ?>
            <button class="btn btn--sm btn--pill btn--quiet" type="submit">Regenerate</button>
          </form>
          <form method="post" action="<?= e($self) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_code"><input type="hidden" name="enable" value="<?= $codeOn ? '0' : '1' ?>"><?= $keep ?>
            <button class="btn btn--sm btn--pill btn--quiet" type="submit"><?= $codeOn ? 'Disable code' : 'Enable code' ?></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <p class="foot-note">Regenerating invalidates the old code. Enrolled students keep access.</p>
    </section>

    <section class="card" aria-labelledby="roster-title">
      <h2 id="roster-title">Class roster</h2>
      <?php if ($tempPw !== null): ?>
        <div class="alert alert--notice" role="status">Temporary password: <span class="temp-pw"><?= e($tempPw['password']) ?></span>
          <br>Give it to the student in person. They must choose a new password when they sign in. It will not be shown again.</div>
      <?php endif; ?>
      <div class="roster-tools">
        <form method="get" action="<?= e($self) ?>">
          <input type="hidden" name="id" value="<?= $cid ?>">
          <?= field('q', 'Search roster', 'text', ['value' => $rq, 'placeholder' => 'Name or username', 'maxlength' => 60]) ?>
        </form>
        <?php if ($open): ?>
        <form method="post" action="<?= e($self) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="add"><?= $keep ?>
          <?= field('username', 'Add by username', 'text', ['placeholder' => 'e.g. juan.delacruz', 'maxlength' => 30, 'nocaps' => true]) ?>
          <button class="btn btn--primary btn--pill" type="submit">Add</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($rosterTot === 0): ?>
        <?= $rq !== ''
            ? state_empty('No students match', 'Check the spelling, or clear the search.', 'Clear search', cls_url(['id' => $cid]))
            : state_empty('No students yet', 'Share the join code, or add a student by username.') ?>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th scope="col">Student</th><th scope="col">Username</th><th scope="col">Enrolled</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
          <tbody>
          <?php foreach ($roster as $s): ?>
            <tr>
              <td><?= e($s['full_name']) ?></td>
              <td><?= e($s['username']) ?></td>
              <td><?= e(to_manila($s['joined_at'], 'd M Y')) ?></td>
              <td class="actions">
                <?php if ($open): ?>
                <form class="inline-form" method="post" action="<?= e($self) ?>" data-confirm="Create a temporary password for <?= e($s['full_name']) ?>? Their current password stops working.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="reset_pw"><input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>"><?= $keep ?>
                  <button class="linklike" type="submit">Reset password</button>
                </form> ·
                <form class="inline-form" method="post" action="<?= e($self) ?>" data-confirm="Remove <?= e($s['full_name']) ?> from this class? Their earlier work is kept.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="student_id" value="<?= (int) $s['id'] ?>"><?= $keep ?>
                  <button class="linklike" type="submit">Remove</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="tbl-foot">
          <span><?= $shown ?> of <?= $rosterTot ?> student<?= $rosterTot === 1 ? '' : 's' ?></span>
          <?php if ($rp > 1): ?><a href="<?= e(cls_url(['id' => $cid, 'q' => $rq, 'rp' => $rp - 1])) ?>">‹ Previous</a><?php endif; ?>
          <?php if ($rp < $pages): ?><a href="<?= e(cls_url(['id' => $cid, 'q' => $rq, 'rp' => $rp + 1])) ?>">Next ›</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="quizzes-title">
      <h2 id="quizzes-title">Class quizzes</h2>
      <?= state_empty('No quizzes yet', 'Quizzes you assign to this class will show up here.') ?>
    </section>

    <?php if ($open): ?>
    <form class="archive-bar" method="post" action="<?= e($self) ?>" data-confirm="Archive this class? Joining closes. Records, attempts and grades are kept.">
      <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="class_id" value="<?= $cid ?>">
      <p class="foot-note">Archive when the term ends. Enrollment closes; class records, attempts and grades are retained.</p>
      <button class="btn btn--pill" type="submit">Archive class</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<script src="/assets/js/classes.js" defer></script>
<?php teacher_end();
