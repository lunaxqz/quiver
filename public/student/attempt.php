<?php
declare(strict_types=1);

/**
 * The attempt screen. Day 11: loads the safe payload and hands it to the browser.
 * Day 12 replaces the body with the Figma "Student attempt" design and the server-owned timer.
 *
 * The browser only ever receives AttemptService::payload(): no is_correct, no rubric, no answer keys.
 */

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/helpers/layout.php';

$user = require_role('student');
$data = AttemptService::payload((int) ($_GET['id'] ?? 0), (int) $user['id']);

if ($data === null) {
    http_response_code(404);
    student_start('Attempt not found');
    echo state_empty('Attempt not found', 'This attempt does not exist or is not yours.');
    echo '<p><a class="btn" href="/student/index.php">Back to your classes</a></p>';
    student_end();
    exit;
}

$a = $data['attempt'];
student_start($data['quiz']['title'], '', true, ['/assets/css/student.css']);
?>
<div class="q-head">
  <h1><?= e($data['quiz']['title']) ?></h1>
  <p class="q-meta">Attempt <?= (int) $a['attempt_no'] ?> of <?= (int) $a['max_attempts'] ?> · Ends <?= e(to_manila($a['deadline_at'], 'H:i')) ?> Asia/Manila</p>
</div>
<section class="q-card">
  <h2><?= count($data['questions']) ?> questions loaded</h2>
  <p class="q-blocked">The question screen is being built (Day 12). Your attempt has started and its deadline is fixed.</p>
</section>
<script type="application/json" id="attempt-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<?php student_end();
