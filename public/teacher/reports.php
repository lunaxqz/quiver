<?php
declare(strict_types=1);

/**
 * Teacher review release, class statistics & CSV export (Day 16)
 * Matches Figma "QUIVER Review release and reports.png".
 *
 * Rules enforced here:
 *  - Every lookup and mutation checks teacher ownership (`ReportService` returns null / not_found -> 404).
 *  - Independent release controls for Scores (`score_released_at`) and Answer review (`review_released_at`),
 *    plus configurable release mode (manual, on submission, scheduled in Asia/Manila).
 *  - Class statistics (Mean, Median, Score range, Graded attempts) follow the quiz's `score_policy` (`highest` vs `latest`).
 *  - Student results table is alphabetical, shows no public rankings, supports search and pagination.
 *  - Export CSV streams a UTF-8 BOM (`\xEF\xBB\xBF`) file with spreadsheet formula injection neutralized (`=`, `+`, `-`, `@`).
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];

$requestedQuizId = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? $_GET['id'] ?? 0);
$quizzes         = ReportService::listQuizzesForReports($teacherId);

if ($requestedQuizId > 0) {
    $activeQuizId = $requestedQuizId;
} elseif (!empty($quizzes)) {
    $activeQuizId = (int) $quizzes[0]['id'];
} else {
    $activeQuizId = 0;
}

// Handle CSV Export (?quiz_id=N&export=csv)
if ($activeQuizId > 0 && (($_GET['export'] ?? '') === 'csv')) {
    $csv = ReportService::exportCsv($activeQuizId, $teacherId);
    if ($csv === null) {
        abort_404();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $csv['filename'] . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $csv['content'];
    exit;
}

// Handle POST actions (release/hold scores, release/hold review, or save release schedule/mode)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = post_str('action');
    $postQuizId = (int) post_str('quiz_id');
    if ($postQuizId <= 0) {
        abort_404();
    }

    if (in_array($action, ['release_scores', 'hold_scores', 'release_review', 'hold_review'], true)) {
        $res = ReportService::updateRelease($postQuizId, $teacherId, $action);
        if ($res['result'] === 'not_found') {
            abort_404();
        }
        $msg = match ($action) {
            'release_scores' => 'Scores have been released to students.',
            'hold_scores'    => 'Scores are now private.',
            'release_review' => 'Answer review has been released to students.',
            'hold_review'    => 'Answer review is now private.',
        };
        flash_set($msg);
        redirect('/teacher/reports.php?quiz_id=' . $postQuizId);
    }

    if ($action === 'save_release_settings') {
        $res = ReportService::updateRelease($postQuizId, $teacherId, 'save_release_settings', [
            'score_release'     => post_str('score_release'),
            'review_release'    => post_str('review_release'),
            'release_at_manila' => post_str('release_at_manila'),
        ]);
        if ($res['result'] === 'not_found') {
            abort_404();
        }
        if ($res['result'] !== 'ok') {
            flash_set($res['error'] ?? 'Could not update release settings.', 'error');
        } else {
            flash_set('Release settings updated.');
        }
        redirect('/teacher/reports.php?quiz_id=' . $postQuizId);
    }
}

$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page   = max(1, (int) ($_GET['p'] ?? 1));

$report = $activeQuizId > 0 ? ReportService::reportForQuiz($activeQuizId, $teacherId, $search, $page) : null;
if ($requestedQuizId > 0 && $report === null) {
    // Return 404 (never 403) when a teacher tries to open another teacher's quiz report
    abort_404();
}

if ($report === null) {
    teacher_start(
        'Reports',
        'reports',
        'teacher',
        ['/assets/css/classes.css', '/assets/css/reports.css'],
        'Review, release & report across your published quizzes.'
    );
    echo state_empty(
        'No published quizzes yet',
        'Publish a quiz to view class statistics, control score and answer review release, and export CSV results.',
        'Go to quizzes',
        '/teacher/quizzes.php'
    );
    teacher_end();
    exit;
}

$q     = $report['quiz'];
$stats = $report['stats'];
$rows  = $report['students'];

$fmtNum = static function (?float $v): string {
    if ($v === null) {
        return '—';
    }
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
};

$phaseSub = match ($q['phase']) {
    'closed'    => $q['closes_manila'] !== '' ? 'Closed ' . $q['closes_manila'] : 'Closed',
    'open'      => $q['closes_manila'] !== '' ? 'Open · Closes ' . $q['closes_manila'] : 'Open',
    'scheduled' => 'Scheduled',
    default     => ucfirst($q['phase']),
};

$subtitle = 'Review, release &amp; report · ' . e($q['class_label']) . ' · ' . e($phaseSub);
$exportBtn = '<a class="btn btn--pill btn-export-csv" href="/teacher/reports.php?quiz_id=' . (int) $q['id'] . '&amp;export=csv">Export CSV &darr;</a>';

teacher_start(
    (string) $q['title'],
    'reports',
    'teacher',
    ['/assets/css/classes.css', '/assets/css/reports.css'],
    $subtitle,
    $exportBtn
);

// Build top banner status mark, headline, and detail line matching Figma
$subCount     = (int) $q['submitted_count'];
$rosterCount  = (int) $q['roster_count'];
$gradedCount  = (int) $q['graded_count'];
$pendingCount = (int) $q['pending_written_count'];
$voidedCount  = (int) $q['voided_count'];

if ($pendingCount > 0) {
    $bannerMark  = status_mark('attention', 'Needs grading');
    $bannerTitle = $pendingCount . ' written ' . ($pendingCount === 1 ? 'answer needs' : 'answers need') . ' grading before all results are final.';
} elseif ($subCount === 0) {
    $bannerMark  = status_mark('neutral', 'No submissions yet');
    $bannerTitle = 'No student attempts have been submitted yet.';
} elseif ($q['scores_released'] && $q['reviews_released']) {
    $bannerMark  = status_mark('complete', 'Released');
    $bannerTitle = 'All ' . $gradedCount . ' ' . ($gradedCount === 1 ? 'attempt is' : 'attempts are') . ' graded. Scores and answer review are released.';
} elseif ($q['scores_released']) {
    $bannerMark  = status_mark('complete', 'Scores released');
    $bannerTitle = 'All ' . $gradedCount . ' ' . ($gradedCount === 1 ? 'attempt is' : 'attempts are') . ' graded. Scores are released; answer review is private.';
} else {
    $bannerMark  = status_mark('complete', 'Ready to release');
    $bannerTitle = 'All ' . $gradedCount . ' ' . ($gradedCount === 1 ? 'attempt is' : 'attempts are') . ' graded. Results are still private.';
}

$bannerDetails = [
    $subCount . ' of ' . $rosterCount . ' submitted',
    $gradedCount . ' of ' . $subCount . ' graded',
    $pendingCount === 0 ? 'No pending written answers' : ($pendingCount . ' pending written ' . ($pendingCount === 1 ? 'answer' : 'answers')),
    $voidedCount === 0 ? 'No voided attempts' : ($voidedCount . ' voided ' . ($voidedCount === 1 ? 'attempt' : 'attempts')),
];

function reports_page_url(int $quizId, string $qSearch, int $p): string
{
    $params = ['quiz_id' => $quizId];
    if ($qSearch !== '') {
        $params['q'] = $qSearch;
    }
    if ($p > 1) {
        $params['p'] = $p;
    }
    return '/teacher/reports.php?' . http_build_query($params);
}
?>

<?php if (count($quizzes) > 1): ?>
  <form class="reports-quiz-switcher" method="get" action="/teacher/reports.php">
    <label class="reports-quiz-switcher__label" for="reports_quiz_select">Quiz:</label>
    <select class="input reports-quiz-switcher__select" id="reports_quiz_select" name="quiz_id" data-autosubmit-report>
      <?php foreach ($quizzes as $qz):
          $qId = (int) $qz['id'];
          $qClass = trim(($qz['course_code'] ?? '') . (($qz['section'] ?? '') !== '' ? ', Section ' . $qz['section'] : ''), ', ');
      ?>
        <option value="<?= $qId ?>"<?= $qId === (int) $q['id'] ? ' selected' : '' ?>>
          <?= e($qz['title']) ?> (<?= e($qClass !== '' ? $qClass : $qz['class_name']) ?> · <?= (int) $qz['submitted_count'] ?>/<?= (int) $qz['roster_count'] ?> submitted)
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn--pill btn--sm" type="submit">Switch quiz</button></noscript>
  </form>
<?php endif; ?>

<!-- Top Status Banner Card -->
<section class="card rep-banner" aria-label="Grading and release summary">
  <div class="rep-banner__mark">
    <?= $bannerMark ?>
  </div>
  <div class="rep-banner__body">
    <h2 class="rep-banner__title"><?= e($bannerTitle) ?></h2>
    <p class="rep-banner__sub">
      <?= e(implode(' · ', $bannerDetails)) ?>
      <?php if ($pendingCount > 0): ?>
        · <a class="rep-banner__link" href="/teacher/grading.php?quiz_id=<?= (int) $q['id'] ?>">Open grading stack &rarr;</a>
      <?php endif; ?>
    </p>
  </div>
</section>

<!-- Two Side-by-Side Release Cards: Scores & Answer review -->
<div class="rep-release-grid">
  <!-- Scores Card -->
  <section class="card rep-release-card" aria-labelledby="scores-card-title">
    <h2 class="rep-release-card__title" id="scores-card-title">Scores</h2>

    <div class="rep-release-card__status">
      <?php if ($q['scores_released']): ?>
        <?= status_mark('complete', 'Released') ?>
      <?php elseif ($q['score_release'] === 'scheduled' && $q['release_at_manila'] !== ''): ?>
        <?= status_mark('scheduled', 'Scheduled · ' . $q['release_at_manila']) ?>
      <?php else: ?>
        <?= status_mark('neutral', 'Not released') ?>
      <?php endif; ?>
    </div>

    <p class="rep-release-card__desc">
      Release each student's own total score. This does not reveal questions, answer keys or feedback.
    </p>

    <div class="rep-release-card__foot">
      <span class="rep-release-card__meta"><?= $rosterCount ?> enrolled <?= $rosterCount === 1 ? 'student' : 'students' ?></span>

      <form method="post" action="/teacher/reports.php">
        <?= csrf_field() ?>
        <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
        <?php if ($q['scores_released']): ?>
          <input type="hidden" name="action" value="hold_scores">
          <button type="submit" class="btn btn--pill rep-btn-outline">Hold scores</button>
        <?php else: ?>
          <input type="hidden" name="action" value="release_scores">
          <button type="submit" class="btn btn--primary btn--pill rep-btn-solid">Release scores</button>
        <?php endif; ?>
      </form>
    </div>
  </section>

  <!-- Answer Review Card -->
  <section class="card rep-release-card" aria-labelledby="review-card-title">
    <h2 class="rep-release-card__title" id="review-card-title">Answer review</h2>

    <div class="rep-release-card__status">
      <?php if ($q['reviews_released']): ?>
        <?= status_mark('complete', 'Released') ?>
      <?php elseif ($q['review_release'] === 'scheduled' && $q['release_at_manila'] !== ''): ?>
        <?= status_mark('scheduled', 'Scheduled · ' . $q['release_at_manila']) ?>
      <?php else: ?>
        <?= status_mark('neutral', 'Not released') ?>
      <?php endif; ?>
    </div>

    <p class="rep-release-card__desc">
      Release questions, submitted answers, correct answers and feedback separately. Keep private until all classes finish.
    </p>

    <div class="rep-release-card__foot">
      <span class="rep-release-card__meta">Independent of score release</span>

      <form method="post" action="/teacher/reports.php">
        <?= csrf_field() ?>
        <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
        <?php if ($q['reviews_released']): ?>
          <input type="hidden" name="action" value="hold_review">
          <button type="submit" class="btn btn--pill rep-btn-outline">Hold answer review</button>
        <?php else: ?>
          <input type="hidden" name="action" value="release_review">
          <button type="submit" class="btn btn--pill rep-btn-outline">Release answer review</button>
        <?php endif; ?>
      </form>
    </div>
  </section>
</div>

<!-- Optional collapsible Schedule / Release Policy bar -->
<details class="rep-schedule-details">
  <summary class="rep-schedule-summary">Release policy &amp; scheduled release options (Asia/Manila)</summary>
  <form class="card rep-schedule-form" method="post" action="/teacher/reports.php">
    <?= csrf_field() ?>
    <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
    <input type="hidden" name="action" value="save_release_settings">
    <div class="rep-schedule-grid">
      <div class="field">
        <label class="field__label" for="score_release_mode">Scores release mode</label>
        <select class="input" id="score_release_mode" name="score_release">
          <option value="manual"<?= $q['score_release'] === 'manual' ? ' selected' : '' ?>>Manual (teacher clicks Release)</option>
          <option value="on_submission"<?= $q['score_release'] === 'on_submission' ? ' selected' : '' ?>>Immediate once graded</option>
          <option value="scheduled"<?= $q['score_release'] === 'scheduled' ? ' selected' : '' ?>>Scheduled date &amp; time</option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="review_release_mode">Answer review release mode</label>
        <select class="input" id="review_release_mode" name="review_release">
          <option value="manual"<?= $q['review_release'] === 'manual' ? ' selected' : '' ?>>Manual (teacher clicks Release)</option>
          <option value="on_submission"<?= $q['review_release'] === 'on_submission' ? ' selected' : '' ?>>Immediate once graded</option>
          <option value="scheduled"<?= $q['review_release'] === 'scheduled' ? ' selected' : '' ?>>Scheduled date &amp; time</option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="release_at_manila">Scheduled release (Asia/Manila)</label>
        <input class="input" type="datetime-local" id="release_at_manila" name="release_at_manila" value="<?= e($q['release_at_input']) ?>">
      </div>
      <div class="rep-schedule-actions">
        <button type="submit" class="btn btn--pill">Save policy</button>
      </div>
    </div>
  </form>
</details>

<!-- 4-Column Class Statistics Strip -->
<section class="rep-stats-strip" aria-label="Class statistics">
  <div class="rep-stat">
    <div class="rep-stat__val">
      <?php if ($stats['mean'] !== null): ?>
        <?= e($fmtNum($stats['mean'])) ?> <span class="rep-stat__slash">/</span> <?= e($fmtNum($q['total_points'])) ?>
      <?php else: ?>
        —
      <?php endif; ?>
    </div>
    <div class="rep-stat__lbl">Mean score</div>
  </div>

  <div class="rep-stat">
    <div class="rep-stat__val">
      <?php if ($stats['median'] !== null): ?>
        <?= e($fmtNum($stats['median'])) ?> <span class="rep-stat__slash">/</span> <?= e($fmtNum($q['total_points'])) ?>
      <?php else: ?>
        —
      <?php endif; ?>
    </div>
    <div class="rep-stat__lbl">Median score</div>
  </div>

  <div class="rep-stat">
    <div class="rep-stat__val">
      <?php if ($stats['min'] !== null && $stats['max'] !== null): ?>
        <?= e($fmtNum($stats['min'])) ?>–<?= e($fmtNum($stats['max'])) ?>
      <?php else: ?>
        —
      <?php endif; ?>
    </div>
    <div class="rep-stat__lbl">Score range</div>
  </div>

  <div class="rep-stat">
    <div class="rep-stat__val"><?= (int) $stats['graded_count'] ?></div>
    <div class="rep-stat__lbl">Graded attempts</div>
  </div>
</section>

<!-- Student Results Card -->
<section class="card rep-results-card" aria-labelledby="results-heading">
  <div class="rep-results-head">
    <div>
      <h2 class="rep-results-title" id="results-heading">Student results</h2>
      <p class="rep-results-sub">
        Alphabetical · <?= $q['score_policy'] === 'latest' ? 'Latest-score policy' : 'Highest-score policy' ?> · <?= (int) $q['max_attempts'] === 1 ? 'One attempt allowed' : (int) $q['max_attempts'] . ' attempts allowed' ?> · No rankings
      </p>
    </div>

    <form class="rep-search-form" method="get" action="/teacher/reports.php">
      <input type="hidden" name="quiz_id" value="<?= (int) $q['id'] ?>">
      <label class="field__label rep-search-label" for="student-search-input">Find a student</label>
      <div class="rep-search-box">
        <span class="rep-search-icon" aria-hidden="true">⌕</span>
        <input
          type="search"
          class="input rep-search-input"
          id="student-search-input"
          name="q"
          value="<?= e($report['search']) ?>"
          placeholder="Name or username"
          maxlength="100"
        >
      </div>
    </form>
  </div>

  <?php if (empty($rows)): ?>
    <?php if ($report['search'] !== ''): ?>
      <?= state_empty('No matching students', 'No student in this class matches "' . $report['search'] . '".', 'Clear search', reports_page_url((int) $q['id'], '', 1)) ?>
    <?php else: ?>
      <?= state_empty('No students enrolled yet', 'Add students to ' . $q['class_name'] . ' to track their quiz attempts and results.', 'Manage class', '/teacher/classes.php?id=' . (int) $q['class_id']) ?>
    <?php endif; ?>
  <?php else: ?>
    <table class="tbl rep-table">
      <thead>
        <tr>
          <th scope="col">Student</th>
          <th scope="col">Submitted</th>
          <th scope="col">Score</th>
          <th scope="col">Grading</th>
          <th scope="col">Access</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="rep-col-student">
              <strong><?= e($r['display_name']) ?></strong>
              <?php if ((int) $q['max_attempts'] > 1 && $r['attempts_used'] > 0): ?>
                <span class="rep-student-meta">Attempt <?= (int) $r['attempt_no'] ?> of <?= (int) $r['attempts_used'] ?></span>
              <?php endif; ?>
            </td>
            <td class="rep-col-submitted"><?= e($r['submitted_manila']) ?></td>
            <td class="rep-col-score">
              <?php if ($r['score'] !== null): ?>
                <?= e($fmtNum($r['score'])) ?> / <?= e($fmtNum($q['total_points'])) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="rep-col-grading">
              <?php if ($r['status'] === 'graded'): ?>
                <?= status_mark('complete', 'Graded') ?>
              <?php elseif ($r['status'] === 'submitted'): ?>
                <a class="rep-grade-link" href="/teacher/grading.php?quiz_id=<?= (int) $q['id'] ?>">
                  <?= status_mark('attention', 'Needs grading') ?>
                </a>
              <?php elseif ($r['status'] === 'in_progress'): ?>
                <?= status_mark('active', 'In progress') ?>
              <?php else: ?>
                <?= status_mark('neutral', 'Not submitted') ?>
              <?php endif; ?>
            </td>
            <td class="rep-col-access"><?= e($r['access_label']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="rep-results-foot">
      <span class="foot-note">
        Showing <?= count($rows) ?> of <?= (int) $report['total_students'] ?> <?= (int) $report['total_students'] === 1 ? 'student' : 'students' ?> · CSV includes all <?= (int) $report['total_students'] ?> records
      </span>

      <div class="rep-pager">
        <?php if ($report['page'] > 1): ?>
          <a class="btn btn--pill rep-btn-outline" href="<?= e(reports_page_url((int) $q['id'], $report['search'], $report['page'] - 1)) ?>">&lsaquo; Previous</a>
        <?php endif; ?>
        <?php if ($report['page'] < $report['pages']): ?>
          <a class="btn btn--pill rep-btn-outline" href="<?= e(reports_page_url((int) $q['id'], $report['search'], $report['page'] + 1)) ?>">Next students &rsaquo;</a>
        <?php elseif ($report['total_students'] > 0): ?>
          <span class="btn btn--pill rep-btn-outline is-disabled" aria-disabled="true">Next students &rsaquo;</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<p class="rep-privacy-foot">
  Controlled access: only <?= e($q['teacher_name']) ?> can manage this report; each student sees only their own released result. Administrators cannot view answers or grades. Store exported CSV files securely.
</p>

<script src="/assets/js/reports.js" defer></script>
<?php teacher_end();
