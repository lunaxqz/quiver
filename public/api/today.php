<?php
declare(strict_types=1);

/**
 * Teacher Today rail polling endpoint (Day 17).
 *
 * Polled every 45 seconds by `/assets/js/overview.js`.
 * Enforces teacher authentication and returns the ranked Today items (max 5),
 * hero card data, summary counts, and the "This week" quiz list scoped strictly to the signed-in teacher.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

$user = current_user();
if ($user === null || ($user['role'] ?? '') !== 'teacher') {
    json_out(['error' => 'Not found'], 404);
}

$teacherId = (int) $user['id'];
$overview  = TodayService::overviewForTeacher($teacherId);
$pendingBadge = GradingService::pendingCountForTeacher($teacherId);

json_out([
    'ok'                  => true,
    'today_items'         => $overview['today_items'],
    'hero_item'           => $overview['hero_item'],
    'also_today'          => $overview['also_today'],
    'summary'             => $overview['summary'],
    'this_week'           => $overview['this_week'],
    'grading_badge_count' => $pendingBadge,
    'server_manila'       => to_manila($overview['server_now_utc'], 'j M Y, H:i:s'),
]);
