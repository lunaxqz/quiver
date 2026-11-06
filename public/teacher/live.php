<?php
declare(strict_types=1);

/**
 * Teacher Live View & Time Extension (Day 18).
 * Matches Figma "QUIVER Networking live view.png".
 *
 * Rules enforced here:
 *  - Every lookup and mutation verifies teacher ownership (`classes.teacher_id = ?`) or admin role.
 *    Unauthorized or foreign quizzes return 404 ("not found"), never 403.
 *  - Overdue attempts are finalized first (`AttemptService::finalizeOverdue`).
 *  - Attention rows ordered first: disconnected (rank 1), urgent working <= 5m (rank 2),
 *    working (rank 3), not started (rank 4), submitted (rank 5), voided (rank 6).
 *  - Real-time visibility via 15-second polling (`/api/live.php?quiz_id=...`).
 *  - Time extension (capped at quiz close, audit log).
 *  - Void attempt with confirmation (audit log, does not consume student's attempt allowance).
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user      = require_role('teacher');
$teacherId = (int) $user['id'];
$isAdmin   = ($user['role'] ?? '') === 'admin';

$quizId = (int) ($_GET['quiz_id'] ?? $_GET['id'] ?? 0);

if ($quizId <= 0) {
    // If no quiz ID provided, show list of open and recent quizzes to monitor
    $quizzes = db()->prepare(
        "SELECT q.id, q.title, q.status, q.opens_at, q.closes_at,
                c.name AS class_name, c.course_code, c.section
           FROM quizzes q
           JOIN classes c ON c.id = q.class_id
          WHERE (c.teacher_id = ? OR 1 = ?)
            AND q.status <> 'archived'
          ORDER BY q.closes_at DESC, q.id DESC
          LIMIT 20"
    );
    $quizzes->execute([$teacherId, $isAdmin ? 1 : 0]);
    $quizList = $quizzes->fetchAll();

    teacher_start(
        'Select a quiz for Live view',
        'quizzes',
        'teacher',
        ['/assets/css/live.css'],
        'Monitor active student attempts in real time.'
    );
    ?>
    <section class="live-card">
      <h2 style="font-family: var(--font-read); font-size: 22px; margin: 0 0 16px;">Active &amp; published quizzes</h2>
      <?php if (empty($quizList)): ?>
        <?= state_empty(
            'No published quizzes to monitor',
            'Publish a quiz from the Quiz builder to view real-time student activity here.',
            'View quizzes',
            '/teacher/quizzes.php'
        ) ?>
      <?php else: ?>
        <table class="live-tbl">
          <thead>
            <tr>
              <th scope="col">Quiz</th>
              <th scope="col">Class</th>
              <th scope="col">Status</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($quizList as $q):
                $phase = quiz_phase($q['opens_at'], $q['closes_at'], utc_now());
                $markState = $phase === 'open' ? 'active' : ($phase === 'scheduled' ? 'scheduled' : 'neutral');
                $classMeta = trim(($q['course_code'] ?? '') !== '' ? ($q['course_code'] . ' · ' . $q['section']) : $q['class_name']);
            ?>
              <tr>
                <td><strong><?= e((string) $q['title']) ?></strong></td>
                <td class="muted-sm"><?= e($classMeta) ?></td>
                <td><?= status_mark($markState, ucfirst($phase)) ?></td>
                <td>
                  <a class="live-btn-action" href="/teacher/live.php?quiz_id=<?= (int) $q['id'] ?>">Open live view →</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
    <?php
    teacher_end();
    exit;
}

// Fetch Live view data
$live = LiveService::getLiveView($quizId, $teacherId, $isAdmin);
if ($live === null) {
    abort_404();
}

$quiz         = $live['quiz'];
$hero         = $live['hero'];
$banner       = $live['attention_banner'];
$participants = $live['participants'];
$events       = $live['events'];

teacher_start(
    (string) $quiz['title'],
    'quizzes',
    'teacher',
    ['/assets/css/live.css'],
    (string) $quiz['subhead']
);
?>

<div id="live-root"
     data-quiz-id="<?= (int) $quiz['id'] ?>"
     data-csrf="<?= e(csrf_token()) ?>">

  <!-- 1. Header Row -->
  <div class="live-head">
    <div>
      <h1 class="live-head__title"><?= e((string) $quiz['title']) ?></h1>
      <p class="live-head__sub"><?= e((string) $quiz['subhead']) ?></p>
    </div>
    <div>
      <a class="live-head__btn" href="/teacher/quiz.php?id=<?= (int) $quiz['id'] ?>">Quiz details</a>
    </div>
  </div>

  <!-- 2. Dark Hero Card -->
  <section class="live-hero" aria-label="Live quiz overview">
    <div class="live-hero__left">
      <div class="live-hero__badge<?= $quiz['phase'] === 'open' ? ' live-hero__badge--open' : '' ?>" id="live-hero-badge">
        <?= e((string) $hero['badge']) ?>
      </div>
      <h2 class="live-hero__headline" id="live-hero-headline"><?= e((string) $hero['headline']) ?></h2>
      <p class="live-hero__meta" id="live-hero-meta"><?= e((string) $hero['submeta']) ?></p>
    </div>
    <div class="live-hero__right">
      <div class="live-hero__timer-label">Until close</div>
      <div class="live-hero__timer" id="live-hero-timer" data-seconds="<?= (int) $hero['until_close_seconds'] ?>">
        <?= e((string) $hero['until_close_formatted']) ?>
      </div>
    </div>
  </section>

  <!-- 3. Attention Banner (Disconnected Alert) -->
  <div id="live-alert-mount"<?= !$banner['has_attention'] ? ' hidden' : '' ?>>
    <?php if ($banner['has_attention'] && !empty($banner['first_student'])):
        $first = $banner['first_student'];
        $extra = (int) $banner['total_disconnected'] > 1 ? (' (+' . ((int) $banner['total_disconnected'] - 1) . ' others)') : '';
    ?>
      <div class="live-alert" role="alert">
        <div class="live-alert__badge">
          <?= status_mark('attention', 'Disconnected') ?>
        </div>
        <div class="live-alert__content">
          <p class="live-alert__title">
            <strong><?= e((string) $first['student_name']) ?><?= e($extra) ?></strong> · Last seen <?= e((string) $first['idle_ago']) ?> · <?= (int) $first['answered'] ?> of <?= (int) $first['total'] ?> answered
          </p>
          <p class="live-alert__note"><?= e((string) $banner['note']) ?></p>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- 4. Main Participants Table Card -->
  <section class="live-card" aria-label="Student participants">
    <!-- Filter bar -->
    <div class="live-filters">
      <div class="live-filters__group">
        <label class="live-filters__label" for="live-search-input">Find a student</label>
        <div class="live-search">
          <svg class="live-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input class="live-search__input"
                 id="live-search-input"
                 type="search"
                 placeholder="Name or username"
                 autocomplete="off">
        </div>
      </div>

      <div class="live-filters__group">
        <label class="live-filters__label" for="live-status-filter">Status</label>
        <select class="live-select" id="live-status-filter">
          <option value="all">All · attention first</option>
          <option value="working">Working</option>
          <option value="disconnected">Disconnected</option>
          <option value="submitted">Submitted</option>
          <option value="voided">Voided</option>
          <option value="not_started">Not started</option>
        </select>
      </div>
    </div>

    <!-- Table -->
    <table class="live-tbl">
      <thead>
        <tr>
          <th scope="col">Student</th>
          <th scope="col">Status</th>
          <th scope="col">Answered</th>
          <th scope="col">Last seen</th>
          <th scope="col">Time left</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody id="live-table-body">
        <?php if (empty($participants)): ?>
          <tr>
            <td colspan="6" style="padding: 32px 12px; text-align: center;">
              <?= state_empty(
                  'No students in this class yet',
                  'Enrolled students will appear here as soon as they join.',
                  'Class roster',
                  '/teacher/classes.php'
              ) ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($participants as $p):
              $isWorkingOrDis = ($p['state_code'] === 'working' || $p['state_code'] === 'disconnected');
              $isUrgent       = (int) ($p['time_left_seconds'] ?? 0) <= 300;
          ?>
            <tr class="live-row"
                data-state="<?= e((string) $p['state_code']) ?>"
                data-name="<?= e(strtolower((string) $p['full_name'] . ' ' . (string) $p['username'])) ?>">
              <td>
                <div class="live-tbl__student-col">
                  <span class="live-tbl__student-name"><?= e((string) ($p['short_name'] ?: $p['full_name'])) ?></span>
                  <span class="live-tbl__student-user">@<?= e((string) $p['username']) ?></span>
                </div>
              </td>
              <td>
                <?= status_mark((string) $p['status_mark_state'], (string) $p['status_label']) ?>
              </td>
              <td class="live-tbl__num">
                <?= e((string) $p['answered_display']) ?>
              </td>
              <td class="live-tbl__num">
                <?= e((string) $p['last_seen_manila']) ?>
              </td>
              <td>
                <?php if ($isWorkingOrDis && !empty($p['attempt_id'])): ?>
                  <span class="live-timer-val live-tbl__time-active<?= $isUrgent ? ' live-tbl__time-urgent' : '' ?>"
                        data-attempt-id="<?= (int) $p['attempt_id'] ?>">
                    <?= e((string) $p['time_left_display']) ?>
                  </span>
                <?php else: ?>
                  <span class="live-tbl__num">—</span>
                <?php endif; ?>
              </td>
              <td class="live-actions">
                <?php if (!empty($p['can_extend']) && !empty($p['attempt_id'])): ?>
                  <button class="live-btn-action"
                          type="button"
                          data-action="extend"
                          data-attempt-id="<?= (int) $p['attempt_id'] ?>"
                          data-student-name="<?= e((string) ($p['short_name'] ?: $p['full_name'])) ?>"
                          data-deadline="<?= e((string) ($p['deadline_manila'] ?? '')) ?>">
                    Extend time...
                  </button>
                <?php endif; ?>

                <?php if (!empty($p['can_void']) && !empty($p['attempt_id'])): ?>
                  <button class="live-btn-action live-btn-action--danger"
                          type="button"
                          data-action="void"
                          data-attempt-id="<?= (int) $p['attempt_id'] ?>"
                          data-student-name="<?= e((string) ($p['short_name'] ?: $p['full_name'])) ?>"
                          data-attempt-no="<?= (int) ($p['attempt_no'] ?: 1) ?>">
                    Void attempt...
                  </button>
                <?php endif; ?>

                <?php if (!empty($p['can_view']) && !empty($p['view_href'])): ?>
                  <a class="live-btn-link" href="<?= e((string) $p['view_href']) ?>">View attempt →</a>
                <?php elseif ($p['state_code'] === 'voided'): ?>
                  <span class="muted-sm">Voided</span>
                <?php elseif ($p['state_code'] === 'not_started'): ?>
                  <span class="muted-sm">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Table footer -->
    <div class="live-footer">
      <div id="live-footer-summary">
        Showing <?= count($participants) ?> of <?= count($participants) ?> participants
      </div>
      <div class="live-footer__meta">
        <span class="live-footer__pulse">
          <span class="live-footer__pulse-dot" aria-hidden="true"></span>
          <span>Live · updates every 15s</span>
        </span>
      </div>
    </div>
  </section>

  <!-- 5. Recent Activity Audit Trail (Collapsible) -->
  <section class="live-audit-card">
    <details>
      <summary class="live-audit-head">
        <h3>Audit log · Time extensions &amp; voided attempts</h3>
        <span class="muted-sm" style="font-size: 13px;">View history (<?= count($events) ?>)</span>
      </summary>
      <ul class="live-audit-list" id="live-audit-list">
        <?php if (empty($events)): ?>
          <li class="muted-sm">No recorded extensions or voided attempts yet.</li>
        <?php else: ?>
          <?php foreach ($events as $ev): ?>
            <li class="live-audit-item">
              <div>
                <strong><?= e((string) $ev['student_name']) ?></strong> —
                <?= $ev['kind'] === 'time_extended' ? 'Time extended' : 'Attempt voided' ?>
                <?php if (!empty($ev['note'])): ?>
                  <span class="muted-sm">(<?= e((string) $ev['note']) ?>)</span>
                <?php endif; ?>
                <span class="muted-sm">by <?= e((string) $ev['actor_name']) ?></span>
              </div>
              <div class="muted-sm font-tabular"><?= e((string) $ev['created_at_manila']) ?></div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </details>
  </section>

</div>

<!-- ---- Modal: Extend Student Time ---- -->
<div class="live-modal-backdrop" id="modal-extend" hidden>
  <div class="live-modal" role="dialog" aria-modal="true" aria-labelledby="modal-extend-title">
    <div class="live-modal__head">
      <h3 class="live-modal__title" id="modal-extend-title">Extend student time</h3>
      <button class="live-modal__close" type="button" data-close-modal aria-label="Close dialog">&times;</button>
    </div>
    <form id="modal-extend-form">
      <p class="live-modal__sub">
        Student: <strong id="modal-extend-name">—</strong><br>
        Current deadline: <span id="modal-extend-deadline">—</span>
      </p>

      <div class="live-modal__chips" aria-label="Quick minutes selection">
        <button class="live-chip-btn is-selected" type="button" data-min="5">+5 min</button>
        <button class="live-chip-btn" type="button" data-min="10">+10 min</button>
        <button class="live-chip-btn" type="button" data-min="15">+15 min</button>
        <button class="live-chip-btn" type="button" data-min="30">+30 min</button>
      </div>

      <div class="form-field" style="margin-bottom: 14px;">
        <label for="modal-extend-minutes">Additional minutes</label>
        <input class="input" id="modal-extend-minutes" type="number" min="1" max="180" value="5" required>
        <p class="muted-sm" style="margin-top: 4px;">Time is automatically capped at the quiz close time (<?= e((string) $quiz['closes_at_manila']) ?> Asia/Manila).</p>
      </div>

      <div class="form-field" style="margin-bottom: 14px;">
        <label for="modal-extend-note">Reason / Note <span class="muted-sm">(optional)</span></label>
        <input class="input" id="modal-extend-note" type="text" placeholder="e.g. Wi-Fi reconnection, power glitch">
      </div>

      <div class="form-error" id="modal-extend-error" hidden></div>

      <div class="live-modal__foot">
        <button class="live-modal__btn-cancel" type="button" data-close-modal>Cancel</button>
        <button class="live-modal__btn-confirm" type="submit">Save extension</button>
      </div>
    </form>
  </div>
</div>

<!-- ---- Modal: Void Attempt ---- -->
<div class="live-modal-backdrop" id="modal-void" hidden>
  <div class="live-modal" role="dialog" aria-modal="true" aria-labelledby="modal-void-title">
    <div class="live-modal__head">
      <h3 class="live-modal__title" id="modal-void-title">Void attempt</h3>
      <button class="live-modal__close" type="button" data-close-modal aria-label="Close dialog">&times;</button>
    </div>
    <form id="modal-void-form">
      <p class="live-modal__sub">
        Student: <strong id="modal-void-name">—</strong>
      </p>

      <div class="live-modal__warning">
        <strong>Important:</strong> Voiding excludes this sitting from grading, and the student cannot resume it. Its answers and event record are retained for the audit log. The student will be allowed to start a new attempt if under the attempt allowance.
      </div>

      <div class="form-field" style="margin-bottom: 14px;">
        <label for="modal-void-reason">Reason for voiding <span style="color: #B91C1C;">*</span></label>
        <input class="input" id="modal-void-reason" type="text" placeholder="e.g. Accidental early start, device reboot" required>
      </div>

      <div class="form-error" id="modal-void-error" hidden></div>

      <div class="live-modal__foot">
        <button class="live-modal__btn-cancel" type="button" data-close-modal>Cancel</button>
        <button class="live-modal__btn-confirm live-modal__btn-danger" type="submit">Void attempt</button>
      </div>
    </form>
  </div>
</div>

<script src="/assets/js/live.js"></script>

<?php
teacher_end();
