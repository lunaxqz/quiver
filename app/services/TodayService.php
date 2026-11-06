<?php
declare(strict_types=1);

/**
 * Teacher Overview & Today Rail Service (Day 17).
 *
 * Rules enforced here:
 *  - Every query is strictly scoped to classes owned by `$teacherId` (`classes.teacher_id = ?`).
 *  - Runs `AttemptService::finalizeOverdue(null, $teacherId, $nowUtc)` before computing items so overdue attempts
 *    are swept and finalized first.
 *  - Runs 6 distinct queries corresponding to the 6 Today rail categories (guide §9.3):
 *      1. Closing soon      (`closing_soon`)     — Open quizzes closing within 60 minutes.
 *      2. Disconnected      (`disconnected`)     — In-progress attempts on open quizzes where `COALESCE(last_seen_at, started_at)` is >= 2 minutes ago.
 *      3. Needs grading     (`needs_grading`)    — Quizzes with ungraded non-blank written responses on submitted/graded attempts.
 *      4. Ready to release  (`ready_to_release`) — Quizzes where all submitted attempts are graded (`> 0`), no written items are pending, and scores or reviews are not yet released to all graded attempts.
 *      5. Opens soon        (`opens_soon`)       — Published scheduled quizzes opening within the next 24 hours.
 *      6. Incomplete drafts (`incomplete_draft`) — Draft quizzes owned by the teacher.
 *  - Passes all candidates through the pure `rank_today($candidates, 5)` rule function.
 *  - Also returns teacher summary counts (`classes_count`, `students_count`, `active_quizzes_count`) and
 *    the "This week" quiz list so the Overview page matches the Figma design.
 */
final class TodayService
{
    /** Number of seconds without a heartbeat before an in-progress attempt is flagged as Disconnected. */
    public const DISCONNECT_SECONDS = 120;

    /** Window (in seconds) for "Closing soon" (60 minutes). */
    public const CLOSING_SOON_SECONDS = 3600;

    /** Window (in seconds) for "Opens soon" (24 hours). */
    public const OPENS_SOON_SECONDS = 86400;

    /**
     * Builds the complete Overview / Today payload for `$teacherId`.
     *
     * @return array{
     *   today_items: list<array<string, mixed>>,
     *   hero_item: ?array<string, mixed>,
     *   also_today: list<array<string, mixed>>,
     *   summary: array{classes_count: int, students_count: int, active_quizzes_count: int},
     *   this_week: list<array<string, mixed>>,
     *   server_now_utc: string
     * }
     */
    public static function overviewForTeacher(int $teacherId, ?string $nowUtc = null, int $limit = 5): array
    {
        AttemptService::finalizeOverdue(null, $teacherId, $nowUtc);

        $now = $nowUtc ?? utc_now();
        $nowTs = strtotime($now . ' UTC');
        $pdo = db();

        $candidates = array_merge(
            self::queryClosingSoon($pdo, $teacherId, $now, $nowTs),
            self::queryDisconnected($pdo, $teacherId, $now, $nowTs),
            self::queryNeedsGrading($pdo, $teacherId, $now, $nowTs),
            self::queryReadyToRelease($pdo, $teacherId, $now, $nowTs),
            self::queryOpensSoon($pdo, $teacherId, $now, $nowTs),
            self::queryIncompleteDrafts($pdo, $teacherId, $now, $nowTs)
        );

        $ranked = rank_today($candidates, $limit);

        $heroItem  = null;
        $alsoToday = $ranked;
        if ($ranked !== [] && $ranked[0]['type'] === 'closing_soon') {
            $heroItem  = $ranked[0];
            $alsoToday = array_slice($ranked, 1);
        }

        return [
            'today_items'    => $ranked,
            'hero_item'      => $heroItem,
            'also_today'     => $alsoToday,
            'summary'        => self::querySummaryCounts($pdo, $teacherId),
            'this_week'      => self::queryThisWeekQuizzes($pdo, $teacherId, $now, $nowTs),
            'server_now_utc' => $now,
        ];
    }

    /**
     * 1. Closing soon: Published quizzes currently open and closing within 60 minutes.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryClosingSoon(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $cutoffUtc = gmdate('Y-m-d H:i:s', $nowTs + self::CLOSING_SOON_SECONDS);

        $stmt = $pdo->prepare(
            "SELECT q.id, q.title, q.class_id, q.opens_at, q.closes_at,
                    c.name AS class_name, c.course_code, c.section,
                    (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS roster_count
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
                AND q.opens_at <= ?
                AND q.closes_at > ?
                AND q.closes_at <= ?
              ORDER BY q.closes_at ASC, q.id ASC"
        );
        $stmt->execute([$teacherId, $now, $now, $cutoffUtc]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId     = (int) $r['id'];
            $classId    = (int) $r['class_id'];
            $closesTs   = strtotime((string) $r['closes_at'] . ' UTC');
            $remSeconds = max(0, $closesTs - $nowTs);
            $remMinutes = max(1, (int) ceil($remSeconds / 60));

            // Inspect student states for the grid & working count
            $gridData = self::buildParticipantGrid($pdo, $quizId, $classId, $nowTs);
            $workingTotal = $gridData['working_count'] + $gridData['disconnected_count'];

            $workPhrase = $workingTotal === 0
                ? 'All active attempts are in.'
                : (self::numberWord($workingTotal, true) . ' student' . ($workingTotal === 1 ? ' is' : 's are') . ' still working.');

            $headline = sprintf(
                '%s closes in %d minute%s. %s',
                (string) $r['title'],
                $remMinutes,
                $remMinutes === 1 ? '' : 's',
                $workPhrase
            );

            $classMeta = self::formatClassCodeSection($r);
            $subMeta   = $classMeta . ' · ' . $gridData['submitted_count'] . ' of ' . $gridData['roster_count'] . ' submitted';

            $out[] = [
                'type'               => 'closing_soon',
                'id'                 => $quizId,
                'quiz_id'            => $quizId,
                'urgency'            => $remSeconds,
                'state'              => 'attention',
                'badge_label'        => 'Closing soon',
                'title'              => (string) $r['title'],
                'headline'           => $headline,
                'detail'             => $headline . ' · ' . $subMeta,
                'submeta'            => $subMeta,
                'remaining_seconds'  => $remSeconds,
                'closes_at_utc'      => (string) $r['closes_at'],
                'submitted_count'    => $gridData['submitted_count'],
                'working_count'      => $gridData['working_count'],
                'disconnected_count' => $gridData['disconnected_count'],
                'roster_count'       => $gridData['roster_count'],
                'cells'              => $gridData['cells'],
                'action_label'       => 'Open live view',
                'action_href'        => '/teacher/quiz.php?id=' . $quizId . '&view=live',
            ];
        }

        return $out;
    }

    /**
     * 2. Disconnected: Students with an in-progress attempt on an open quiz whose last heartbeat is >= 2 minutes old.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryDisconnected(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $cutoffUtc = gmdate('Y-m-d H:i:s', $nowTs - self::DISCONNECT_SECONDS);

        $stmt = $pdo->prepare(
            "SELECT a.id AS attempt_id, a.quiz_id, a.student_id, a.started_at, a.deadline_at, a.last_seen_at,
                    u.full_name, u.username,
                    q.title AS quiz_title
               FROM quiz_attempts a
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
               JOIN users u ON u.id = a.student_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
                AND q.opens_at <= ?
                AND q.closes_at > ?
                AND a.status = 'in_progress'
                AND a.deadline_at > ?
                AND COALESCE(a.last_seen_at, a.started_at) <= ?
              ORDER BY COALESCE(a.last_seen_at, a.started_at) ASC, a.id ASC"
        );
        $stmt->execute([$teacherId, $now, $now, $now, $cutoffUtc]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $attId      = (int) $r['attempt_id'];
            $quizId     = (int) $r['quiz_id'];
            $lastSeen   = (string) ($r['last_seen_at'] ?? $r['started_at']);
            $lastSeenTs = strtotime($lastSeen . ' UTC');
            $deadlineTs = strtotime((string) $r['deadline_at'] . ' UTC');

            $quietMins = max(2, (int) floor(($nowTs - $lastSeenTs) / 60));
            $leftMins  = max(1, (int) ceil(max(0, $deadlineTs - $nowTs) / 60));

            $studentShort = self::formatLastFirstInitial((string) $r['full_name']);
            $detail = sprintf(
                '%s went quiet %d minutes ago – %d min left on their clock',
                $studentShort,
                $quietMins,
                $leftMins
            );

            $out[] = [
                'type'         => 'disconnected',
                'id'           => $attId,
                'quiz_id'      => $quizId,
                'attempt_id'   => $attId,
                'urgency'      => $lastSeenTs, // older heartbeat = smaller timestamp = more urgent
                'state'        => 'attention',
                'badge_label'  => 'Disconnected',
                'title'        => (string) $r['quiz_title'],
                'detail'       => $detail,
                'action_label' => 'Open live view',
                'action_href'  => '/teacher/quiz.php?id=' . $quizId . '&view=live',
            ];
        }

        return $out;
    }

    /**
     * 3. Needs grading: Quizzes with ungraded non-blank written responses on submitted/graded attempts.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryNeedsGrading(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $stmt = $pdo->prepare(
            "SELECT q.id AS quiz_id, q.title,
                    COUNT(r.id) AS pending_count,
                    MIN(COALESCE(a.submitted_at, a.deadline_at)) AS oldest_submitted_at
               FROM responses r
               JOIN quiz_attempts a ON a.id = r.attempt_id
               JOIN quiz_questions qq ON qq.id = r.quiz_question_id
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
                AND a.status IN ('submitted', 'graded')
                AND qq.type IN ('short', 'essay')
                AND r.points_awarded IS NULL
                AND TRIM(COALESCE(r.answer_text, '')) <> ''
              GROUP BY q.id, q.title
              HAVING COUNT(r.id) > 0
              ORDER BY oldest_submitted_at ASC, q.id ASC"
        );
        $stmt->execute([$teacherId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId       = (int) $r['quiz_id'];
            $pendingCount = (int) $r['pending_count'];
            $oldestUtc    = (string) ($r['oldest_submitted_at'] ?? $now);
            $oldestTs     = strtotime($oldestUtc . ' UTC') ?: $nowTs;
            $oldestDay    = to_manila($oldestUtc, 'l'); // e.g. "Monday"

            $detail = sprintf(
                '%s – %d written answer%s waiting, oldest from %s',
                (string) $r['title'],
                $pendingCount,
                $pendingCount === 1 ? '' : 's',
                $oldestDay !== '' ? $oldestDay : 'today'
            );

            $out[] = [
                'type'          => 'needs_grading',
                'id'            => $quizId,
                'quiz_id'       => $quizId,
                'urgency'       => $oldestTs,
                'state'         => 'attention',
                'badge_label'   => 'Needs grading',
                'title'         => (string) $r['title'],
                'detail'        => $detail,
                'pending_count' => $pendingCount,
                'action_label'  => 'Start grading',
                'action_href'   => '/teacher/grading.php?quiz_id=' . $quizId,
            ];
        }

        return $out;
    }

    /**
     * 4. Ready to release: Quizzes where all submitted attempts are graded (> 0 attempts), no ungraded written answers remain,
     *    and results (scores or answer review) have not yet been released.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryReadyToRelease(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $stmt = $pdo->prepare(
            "SELECT q.id AS quiz_id, q.title, q.closes_at, q.updated_at,
                    SUM(CASE WHEN a.status IN ('submitted', 'graded') THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN a.status = 'graded' THEN 1 ELSE 0 END) AS graded_count,
                    SUM(CASE WHEN a.status = 'graded' AND (a.score_released_at IS NULL OR a.score_released_at > ?) THEN 1 ELSE 0 END) AS unreleased_score_count,
                    SUM(CASE WHEN a.status = 'graded' AND (a.review_released_at IS NULL OR a.review_released_at > ?) THEN 1 ELSE 0 END) AS unreleased_review_count
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
               JOIN quiz_attempts a ON a.quiz_id = q.id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
              GROUP BY q.id, q.title, q.closes_at, q.updated_at
              HAVING SUM(CASE WHEN a.status IN ('submitted', 'graded') THEN 1 ELSE 0 END) > 0
                 AND SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) = 0
                 AND SUM(CASE WHEN a.status IN ('submitted', 'graded') THEN 1 ELSE 0 END) = SUM(CASE WHEN a.status = 'graded' THEN 1 ELSE 0 END)
                 AND (SUM(CASE WHEN a.status = 'graded' AND (a.score_released_at IS NULL OR a.score_released_at > ?) THEN 1 ELSE 0 END) > 0
                      OR SUM(CASE WHEN a.status = 'graded' AND (a.review_released_at IS NULL OR a.review_released_at > ?) THEN 1 ELSE 0 END) > 0)
              ORDER BY COALESCE(q.closes_at, q.updated_at) ASC, q.id ASC"
        );
        $stmt->execute([$now, $now, $teacherId, $now, $now]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId      = (int) $r['quiz_id'];
            $gradedCount = (int) $r['graded_count'];
            $refUtc      = (string) ($r['closes_at'] ?? $r['updated_at'] ?? $now);
            $refTs       = strtotime($refUtc . ' UTC') ?: $nowTs;

            $detail = sprintf(
                '%s – all %d attempt%s graded, results not yet released',
                (string) $r['title'],
                $gradedCount,
                $gradedCount === 1 ? '' : 's'
            );

            $out[] = [
                'type'         => 'ready_to_release',
                'id'           => $quizId,
                'quiz_id'      => $quizId,
                'urgency'      => $refTs,
                'state'        => 'complete',
                'badge_label'  => 'Ready',
                'title'        => (string) $r['title'],
                'detail'       => $detail,
                'graded_count' => $gradedCount,
                'action_label' => 'Review and release',
                'action_href'  => '/teacher/reports.php?quiz_id=' . $quizId,
            ];
        }

        return $out;
    }

    /**
     * 5. Opens soon: Published scheduled quizzes opening within the next 24 hours.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryOpensSoon(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $cutoffUtc = gmdate('Y-m-d H:i:s', $nowTs + self::OPENS_SOON_SECONDS);

        $stmt = $pdo->prepare(
            "SELECT q.id AS quiz_id, q.title, q.opens_at
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
                AND q.opens_at > ?
                AND q.opens_at <= ?
              ORDER BY q.opens_at ASC, q.id ASC"
        );
        $stmt->execute([$teacherId, $now, $cutoffUtc]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId  = (int) $r['quiz_id'];
            $opensAt = (string) $r['opens_at'];
            $opensTs = strtotime($opensAt . ' UTC') ?: $nowTs;

            $whenPhrase = self::formatOpensPhraseManila($opensAt, $now);
            $detail     = sprintf('%s opens %s', (string) $r['title'], $whenPhrase);

            $out[] = [
                'type'         => 'opens_soon',
                'id'           => $quizId,
                'quiz_id'      => $quizId,
                'urgency'      => $opensTs - $nowTs,
                'state'        => 'scheduled',
                'badge_label'  => 'Opens soon',
                'title'        => (string) $r['title'],
                'detail'       => $detail,
                'action_label' => 'Open quiz',
                'action_href'  => '/teacher/quiz.php?id=' . $quizId,
            ];
        }

        return $out;
    }

    /**
     * 6. Incomplete drafts: Draft quizzes owned by the teacher.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryIncompleteDrafts(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $stmt = $pdo->prepare(
            "SELECT q.id AS quiz_id, q.title, q.class_id, q.opens_at, q.closes_at, q.duration_min, q.updated_at,
                    c.name AS class_name, c.course_code, c.section
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'draft'
              ORDER BY q.updated_at DESC, q.id ASC"
        );
        $stmt->execute([$teacherId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId    = (int) $r['quiz_id'];
            $questions = QuizService::getQuestions($quizId);
            $blockers  = quiz_blockers($r, $questions);
            $qCount    = count($questions);

            if ($qCount === 0) {
                $reason = 'no questions added yet';
            } elseif ($blockers !== []) {
                $reason = count($blockers) . ' item' . (count($blockers) === 1 ? '' : 's') . ' needed before publishing';
            } else {
                $reason = $qCount . ' question' . ($qCount === 1 ? '' : 's') . ' ready to publish';
            }

            $updatedTs = strtotime((string) ($r['updated_at'] ?? $now) . ' UTC') ?: $nowTs;
            // More recently updated drafts appear first among drafts
            $urgency = max(1, $nowTs - $updatedTs);

            $out[] = [
                'type'         => 'incomplete_draft',
                'id'           => $quizId,
                'quiz_id'      => $quizId,
                'urgency'      => $urgency,
                'state'        => 'neutral',
                'badge_label'  => 'Incomplete draft',
                'title'        => (string) $r['title'],
                'detail'       => sprintf('%s – draft, %s', (string) $r['title'], $reason),
                'action_label' => 'Edit draft',
                'action_href'  => '/teacher/quiz.php?id=' . $quizId,
            ];
        }

        return $out;
    }

    /**
     * Builds the participant status cells for the "Closing soon" hero card (Solid: submitted, Outlined: working, Dashed: disconnected).
     *
     * @return array{
     *   roster_count: int,
     *   submitted_count: int,
     *   working_count: int,
     *   disconnected_count: int,
     *   cells: list<string>
     * }
     */
    private static function buildParticipantGrid(PDO $pdo, int $quizId, int $classId, int $nowTs): array
    {
        $rStmt = $pdo->prepare('SELECT student_id FROM class_memberships WHERE class_id = ? AND removed_at IS NULL ORDER BY student_id ASC');
        $rStmt->execute([$classId]);
        $rosterIds = array_map('intval', $rStmt->fetchAll(PDO::FETCH_COLUMN));

        $aStmt = $pdo->prepare(
            "SELECT student_id, status, started_at, last_seen_at
               FROM quiz_attempts
              WHERE quiz_id = ? AND status <> 'voided'
              ORDER BY attempt_no ASC, id ASC"
        );
        $aStmt->execute([$quizId]);
        $latestByStudent = [];
        foreach ($aStmt->fetchAll() as $a) {
            $sid = (int) $a['student_id'];
            $latestByStudent[$sid] = $a;
            if (!in_array($sid, $rosterIds, true)) {
                $rosterIds[] = $sid;
            }
        }

        $submittedCount    = 0;
        $workingCount      = 0;
        $disconnectedCount = 0;

        foreach ($rosterIds as $sid) {
            $att = $latestByStudent[$sid] ?? null;
            if ($att === null) {
                continue;
            }
            $st = (string) $att['status'];
            if ($st === 'submitted' || $st === 'graded') {
                $submittedCount++;
            } elseif ($st === 'in_progress') {
                $seenStr = (string) ($att['last_seen_at'] ?? $att['started_at']);
                $seenTs  = strtotime($seenStr . ' UTC') ?: $nowTs;
                if (($nowTs - $seenTs) >= self::DISCONNECT_SECONDS) {
                    $disconnectedCount++;
                } else {
                    $workingCount++;
                }
            }
        }

        // Order cells cleanly like the Figma dot matrix: solid (submitted) first, then outlined (working), then dashed (disconnected)
        $cells = array_merge(
            array_fill(0, $submittedCount, 'submitted'),
            array_fill(0, $workingCount, 'working'),
            array_fill(0, $disconnectedCount, 'disconnected')
        );

        return [
            'roster_count'       => count($rosterIds),
            'submitted_count'    => $submittedCount,
            'working_count'      => $workingCount,
            'disconnected_count' => $disconnectedCount,
            'cells'              => $cells,
        ];
    }

    /**
     * Queries the summary line: "N classes · M students · K active quizzes".
     *
     * @return array{classes_count: int, students_count: int, active_quizzes_count: int}
     */
    private static function querySummaryCounts(PDO $pdo, int $teacherId): array
    {
        $cStmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id = ? AND archived_at IS NULL');
        $cStmt->execute([$teacherId]);
        $classesCount = (int) $cStmt->fetchColumn();

        $sStmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM class_memberships m
               JOIN classes c ON c.id = m.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND m.removed_at IS NULL'
        );
        $sStmt->execute([$teacherId]);
        $studentsCount = (int) $sStmt->fetchColumn();

        $qStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'"
        );
        $qStmt->execute([$teacherId]);
        $activeQuizzesCount = (int) $qStmt->fetchColumn();

        return [
            'classes_count'        => $classesCount,
            'students_count'       => $studentsCount,
            'active_quizzes_count' => $activeQuizzesCount,
        ];
    }

    /**
     * Queries recent and upcoming published quizzes for the "This week" table on the Teacher overview page.
     *
     * @return list<array<string, mixed>>
     */
    private static function queryThisWeekQuizzes(PDO $pdo, int $teacherId, string $now, int $nowTs): array
    {
        $stmt = $pdo->prepare(
            "SELECT q.id, q.title, q.class_id, q.status, q.opens_at, q.closes_at,
                    c.name AS class_name, c.course_code, c.section,
                    (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS roster_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id = q.id AND a.status IN ('submitted', 'graded')) AS submitted_count,
                    (SELECT COUNT(*)
                       FROM responses r
                       JOIN quiz_attempts a ON a.id = r.attempt_id
                       JOIN quiz_questions qq ON qq.id = r.quiz_question_id
                      WHERE a.quiz_id = q.id
                        AND a.status IN ('submitted', 'graded')
                        AND qq.type IN ('short', 'essay')
                        AND r.points_awarded IS NULL
                        AND TRIM(COALESCE(r.answer_text, '')) <> '') AS pending_written_count
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND c.archived_at IS NULL
                AND q.status = 'published'
              ORDER BY q.closes_at DESC, q.id DESC
              LIMIT 6"
        );
        $stmt->execute([$teacherId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $quizId       = (int) $r['id'];
            $phase        = quiz_phase($r['opens_at'], $r['closes_at'], $now);
            $pendingCount = (int) $r['pending_written_count'];
            $subCount     = (int) $r['submitted_count'];
            $rosterCount  = (int) $r['roster_count'];

            if ($phase === 'open') {
                $statusState = 'active';
                $statusLabel = 'Open';
                $windowLabel = 'Closes ' . to_manila((string) $r['closes_at'], 'H:i');
                $href        = '/teacher/quiz.php?id=' . $quizId . '&view=live';
            } elseif ($phase === 'scheduled') {
                $statusState = 'scheduled';
                $statusLabel = 'Scheduled';
                $windowLabel = 'Opens ' . to_manila((string) $r['opens_at'], 'D H:i');
                $href        = '/teacher/quiz.php?id=' . $quizId;
            } elseif ($pendingCount > 0) {
                $statusState = 'attention';
                $statusLabel = 'Needs grading';
                $windowLabel = 'Closed ' . to_manila((string) $r['closes_at'], 'D H:i');
                $href        = '/teacher/grading.php?quiz_id=' . $quizId;
            } else {
                $statusState = 'complete';
                $statusLabel = 'Graded';
                $windowLabel = 'Closed ' . to_manila((string) $r['closes_at'], 'D H:i');
                $href        = '/teacher/reports.php?quiz_id=' . $quizId;
            }

            $pct = $rosterCount > 0 ? min(100, (int) round(($subCount / $rosterCount) * 100)) : 0;

            $out[] = [
                'id'              => $quizId,
                'title'           => (string) $r['title'],
                'class_meta'      => self::formatClassCodeSection($r),
                'phase'           => $phase,
                'status_state'    => $statusState,
                'status_label'    => $statusLabel,
                'window_label'    => $windowLabel,
                'submitted_count' => $subCount,
                'roster_count'    => $rosterCount,
                'progress_pct'    => $pct,
                'href'            => $href,
            ];
        }

        // Order so Open comes first, then Needs grading, then Graded, then Scheduled
        $orderMap = ['Open' => 1, 'Needs grading' => 2, 'Graded' => 3, 'Scheduled' => 4];
        usort($out, static function (array $a, array $b) use ($orderMap): int {
            $oa = $orderMap[$a['status_label']] ?? 9;
            $ob = $orderMap[$b['status_label']] ?? 9;
            return $oa <=> $ob;
        });

        return $out;
    }

    private static function formatClassCodeSection(array $r): string
    {
        $code = trim((string) ($r['course_code'] ?? ''));
        $sec  = trim((string) ($r['section'] ?? ''));
        if ($code !== '' && $sec !== '') {
            $secLabel = stripos($sec, 'section') === 0 ? $sec : ('Section ' . $sec);
            return $code . ' · ' . $secLabel;
        }
        if ($code !== '') {
            return $code;
        }
        return (string) ($r['class_name'] ?? '');
    }

    /**
     * Formats "Maria Dela Cruz" -> "Dela Cruz, M." or "Dela Cruz, Maria" -> "Dela Cruz, M."
     */
    private static function formatLastFirstInitial(string $fullName): string
    {
        $clean = trim($fullName);
        if ($clean === '') {
            return 'Student';
        }
        if (str_contains($clean, ',')) {
            [$last, $first] = array_map('trim', explode(',', $clean, 2));
            $initial = $first !== '' ? (mb_substr($first, 0, 1) . '.') : '';
            return trim($last . ($initial !== '' ? ', ' . $initial : ''));
        }
        $parts = preg_split('/\s+/u', $clean) ?: [];
        if (count($parts) <= 1) {
            return $clean;
        }
        $first = array_shift($parts);
        $last  = implode(' ', $parts);
        return $last . ', ' . mb_substr($first, 0, 1) . '.';
    }

    private static function formatOpensPhraseManila(string $opensAtUtc, string $nowUtc): string
    {
        try {
            $utc    = new DateTimeZone('UTC');
            $manila = new DateTimeZone('Asia/Manila');
            $openDt = (new DateTimeImmutable($opensAtUtc, $utc))->setTimezone($manila);
            $nowDt  = (new DateTimeImmutable($nowUtc, $utc))->setTimezone($manila);

            $openDate = $openDt->format('Y-m-d');
            $nowDate  = $nowDt->format('Y-m-d');
            $tomDate  = $nowDt->modify('+1 day')->format('Y-m-d');
            $timeStr  = $openDt->format('G:i');

            if ($openDate === $nowDate) {
                return 'today at ' . $timeStr;
            }
            if ($openDate === $tomDate) {
                return 'tomorrow at ' . $timeStr;
            }
            return $openDt->format('D j M') . ' at ' . $timeStr;
        } catch (Throwable) {
            return to_manila($opensAtUtc, 'D j M, G:i');
        }
    }

    public static function numberWord(int $n, bool $capitalize = false): string
    {
        $words = [
            0 => 'zero',
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
        ];
        $w = $words[$n] ?? (string) $n;
        return $capitalize ? ucfirst($w) : $w;
    }
}
