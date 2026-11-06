<?php
declare(strict_types=1);

/**
 * Teacher Live View & Time Extension Service (Day 18).
 * Matches Figma "QUIVER Networking live view.png".
 *
 * Rules enforced here:
 *  - Every lookup and mutation verifies teacher ownership (`classes.teacher_id = ?`) or admin role.
 *    Unauthorized or foreign quizzes return `null` (404), never 403.
 *  - Overdue attempts are swept and finalized first (`AttemptService::finalizeOverdue`).
 *  - Real-time visibility:
 *      - Roster members categorized: working, disconnected (heartbeat >= 120s), submitted, auto-submitted, voided, not started.
 *      - Attention rows ordered first:
 *          1. Disconnected (rank 1)
 *          2. Working with <= 5m left (rank 2)
 *          3. Working (rank 3)
 *          4. Not started (rank 4)
 *          5. Submitted / Auto-submitted (rank 5)
 *          6. Voided (rank 6)
 *      - Live question progress (answered count / total questions) computed per student sitting.
 *  - Per-student Time Extension:
 *      - Locks attempt and quiz row (`SELECT ... FOR UPDATE`).
 *      - Only allowed for `in_progress` attempts.
 *      - Extends `deadline_at` by N minutes.
 *      - Capped at quiz `closes_at` if specified.
 *      - Records an append-only audit entry in `attempt_events` (`kind = 'time_extended'`).
 *  - Void Attempt:
 *      - Locks attempt row (`SELECT ... FOR UPDATE`).
 *      - Updates `status = 'voided'` and `submit_reason = 'teacher'`.
 *      - Records an append-only audit entry in `attempt_events` (`kind = 'voided'`).
 *      - Does not count against student's max_attempts allowance on retake.
 */
final class LiveService
{
    /** Heartbeat timeout (in seconds) before an active attempt is marked disconnected. */
    public const DISCONNECT_SECONDS = 120;

    /** Approaching deadline threshold (in seconds) for attention sorting (5 minutes). */
    public const URGENT_SECONDS = 300;

    /**
     * Fetches the complete live payload for a given quiz.
     * Returns null if quiz does not exist or is not owned by $teacherId.
     *
     * @return array{
     *   quiz: array<string, mixed>,
     *   hero: array<string, mixed>,
     *   attention_banner: array<string, mixed>,
     *   participants: list<array<string, mixed>>,
     *   events: list<array<string, mixed>>,
     *   server_now_utc: string,
     *   server_now_manila: string
     * }|null
     */
    public static function getLiveView(
        int $quizId,
        int $teacherId,
        bool $isAdmin = false,
        ?string $nowUtc = null
    ): ?array {
        $pdo = db();
        $now = $nowUtc !== null ? utc_to_format($nowUtc, 'Y-m-d H:i:s') : utc_now();
        $nowTs = strtotime($now . ' UTC') ?: time();

        // 1. Finalize overdue attempts on this quiz first
        AttemptService::finalizeOverdue($quizId, $isAdmin ? null : $teacherId, $now);

        // 2. Fetch quiz and class metadata with ownership verification
        $quizSql = "SELECT q.id, q.class_id, q.created_by, q.title, q.instructions, q.status,
                           q.opens_at, q.closes_at, q.duration_min, q.max_attempts,
                           q.score_release, q.review_release, q.score_policy,
                           c.name AS class_name, c.course_code, c.section, c.school_year, c.teacher_id,
                           u.full_name AS teacher_name,
                           (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS total_questions
                      FROM quizzes q
                      JOIN classes c ON c.id = q.class_id
                      JOIN users u ON u.id = c.teacher_id
                     WHERE q.id = ?";
        $stmt = $pdo->prepare($quizSql);
        $stmt->execute([$quizId]);
        $qRow = $stmt->fetch();

        if ($qRow === false) {
            return null;
        }

        if (!$isAdmin && (int) $qRow['teacher_id'] !== $teacherId) {
            return null;
        }

        $totalQuestions = (int) $qRow['total_questions'];
        $phase          = quiz_phase($qRow['opens_at'], $qRow['closes_at'], $now);
        $closesAtUtc    = (string) $qRow['closes_at'];
        $closesTs       = strtotime($closesAtUtc . ' UTC') ?: 0;
        $untilCloseSec  = max(0, $closesTs - $nowTs);

        // Class and date metadata string: e.g. "IT301, Section B · Thu 29 Oct 2026 · Asia/Manila"
        $classParts = [];
        if (!empty($qRow['course_code'])) {
            $classParts[] = (string) $qRow['course_code'];
        }
        if (!empty($qRow['section'])) {
            $classParts[] = 'Section ' . (string) $qRow['section'];
        }
        $classMeta = !empty($classParts) ? implode(', ', $classParts) : (string) $qRow['class_name'];
        $dateMeta  = to_manila((string) ($qRow['opens_at'] ?? $now), 'D j M Y') . ' · Asia/Manila';
        $subhead   = 'Live view · ' . $classMeta . ' · ' . $dateMeta;

        // 3. Fetch all enrolled students in the class
        $stuStmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.username
               FROM class_memberships cm
               JOIN users u ON u.id = cm.student_id
              WHERE cm.class_id = ? AND cm.removed_at IS NULL
              ORDER BY u.full_name ASC, u.username ASC"
        );
        $stuStmt->execute([(int) $qRow['class_id']]);
        $roster = $stuStmt->fetchAll();

        // 4. Fetch all attempts for this quiz
        $attStmt = $pdo->prepare(
            "SELECT a.id, a.student_id, a.attempt_no, a.status, a.submit_reason,
                    a.started_at, a.deadline_at, a.last_seen_at, a.submitted_at, a.score
               FROM quiz_attempts a
              WHERE a.quiz_id = ?
              ORDER BY a.student_id ASC, a.attempt_no DESC, a.id DESC"
        );
        $attStmt->execute([$quizId]);
        $rawAttempts = $attStmt->fetchAll();

        // Group attempts by student. An in-progress attempt always takes precedence; otherwise the latest attempt.
        $activeAttemptByStudent = [];
        $allAttemptsByStudent   = [];
        foreach ($rawAttempts as $att) {
            $sid = (int) $att['student_id'];
            $allAttemptsByStudent[$sid][] = $att;
            if (!isset($activeAttemptByStudent[$sid])) {
                $activeAttemptByStudent[$sid] = $att;
            } elseif ($att['status'] === 'in_progress' && $activeAttemptByStudent[$sid]['status'] !== 'in_progress') {
                $activeAttemptByStudent[$sid] = $att;
            }
        }

        // 5. Compute answered questions per attempt
        $ansStmt = $pdo->prepare(
            "SELECT a.id AS attempt_id, COUNT(DISTINCT r.quiz_question_id) AS answered_count
               FROM responses r
               JOIN quiz_attempts a ON a.id = r.attempt_id
              WHERE a.quiz_id = ?
                AND (
                  (r.answer_text IS NOT NULL AND TRIM(r.answer_text) <> '') OR
                  EXISTS (SELECT 1 FROM response_options ro WHERE ro.response_id = r.id)
                )
              GROUP BY a.id"
        );
        $ansStmt->execute([$quizId]);
        $answeredCounts = [];
        while ($ansRow = $ansStmt->fetch()) {
            $answeredCounts[(int) $ansRow['attempt_id']] = (int) $ansRow['answered_count'];
        }

        // 6. Build participants list with attention metrics
        $participants = [];
        $submittedCount    = 0;
        $workingCount      = 0;
        $disconnectedCount = 0;
        $voidedCount       = 0;
        $notStartedCount   = 0;
        $disconnectedList  = [];

        foreach ($roster as $stu) {
            $sid     = (int) $stu['id'];
            $att     = $activeAttemptByStudent[$sid] ?? null;
            $attId   = $att !== null ? (int) $att['id'] : null;
            $ansCnt  = $attId !== null ? ($answeredCounts[$attId] ?? 0) : 0;
            $attNo   = $att !== null ? (int) $att['attempt_no'] : null;
            $rawStat = $att !== null ? (string) $att['status'] : 'not_started';

            $stateCode       = 'not_started';
            $statusLabel     = 'Not started';
            $statusMarkState = 'neutral';
            $attentionRank   = 4;
            $timeLeftSec     = 0;
            $timeLeftDisplay = '—';
            $lastSeenManila  = '—';
            $lastSeenAgo     = '—';
            $deadlineManila  = '—';

            if ($att === null) {
                $notStartedCount++;
            } elseif ($rawStat === 'voided') {
                $stateCode       = 'voided';
                $statusLabel     = 'Voided';
                $statusMarkState = 'neutral';
                $attentionRank   = 6;
                $voidedCount++;
            } elseif ($rawStat === 'submitted' || $rawStat === 'graded') {
                $isTimeout       = ($att['submit_reason'] ?? '') === 'timeout';
                $stateCode       = $isTimeout ? 'auto_submitted' : 'submitted';
                $statusLabel     = $isTimeout ? 'Auto-submitted' : 'Submitted';
                $statusMarkState = 'complete';
                $attentionRank   = 5;
                $submittedCount++;

                if (!empty($att['submitted_at'])) {
                    $lastSeenManila = to_manila((string) $att['submitted_at'], 'H:i:s');
                }
            } elseif ($rawStat === 'in_progress') {
                $seenStr = (string) ($att['last_seen_at'] ?? $att['started_at']);
                $seenTs  = strtotime($seenStr . ' UTC') ?: $nowTs;
                $idleSec = max(0, $nowTs - $seenTs);

                $deadlineUtc = (string) $att['deadline_at'];
                $deadTs      = strtotime($deadlineUtc . ' UTC') ?: $nowTs;
                $effDeadline = $closesTs > 0 ? min($deadTs, $closesTs) : $deadTs;
                $timeLeftSec = max(0, $effDeadline - $nowTs);

                $deadlineManila = to_manila($deadlineUtc, 'H:i:s');
                $lastSeenManila = to_manila($seenStr, 'H:i:s');
                $lastSeenAgo    = self::formatRelativeTime($idleSec);

                $m = intdiv($timeLeftSec, 60);
                $s = $timeLeftSec % 60;
                $timeLeftDisplay = sprintf('%02d:%02d', $m, $s);

                if ($idleSec >= self::DISCONNECT_SECONDS) {
                    $stateCode       = 'disconnected';
                    $statusLabel     = 'Disconnected';
                    $statusMarkState = 'attention';
                    $attentionRank   = 1;
                    $disconnectedCount++;
                    $disconnectedList[] = [
                        'student_name' => (string) $stu['full_name'],
                        'student_id'   => $sid,
                        'attempt_id'   => $attId,
                        'idle_ago'     => $lastSeenAgo,
                        'answered'     => $ansCnt,
                        'total'        => $totalQuestions,
                    ];
                } else {
                    $workingCount++;
                    $stateCode       = 'working';
                    $statusLabel     = 'Working';
                    $statusMarkState = 'active';
                    $attentionRank   = ($timeLeftSec <= self::URGENT_SECONDS) ? 2 : 3;
                }
            }

            // Clean short name: e.g. "Dela Cruz, M." if possible, with full name fallback
            $shortName = self::formatShortName((string) $stu['full_name']);

            $participants[] = [
                'student_id'        => $sid,
                'full_name'         => (string) $stu['full_name'],
                'username'          => (string) $stu['username'],
                'short_name'        => $shortName,
                'attempt_id'        => $attId,
                'attempt_no'        => $attNo,
                'state_code'        => $stateCode,
                'status_label'      => $statusLabel,
                'status_mark_state' => $statusMarkState,
                'attention_rank'    => $attentionRank,
                'answered_count'    => $ansCnt,
                'total_questions'   => $totalQuestions,
                'answered_display'  => $att !== null ? ($ansCnt . ' / ' . $totalQuestions) : '—',
                'last_seen_manila'  => $lastSeenManila,
                'last_seen_ago'     => $lastSeenAgo,
                'time_left_seconds' => $timeLeftSec,
                'time_left_display' => $timeLeftDisplay,
                'deadline_manila'   => $deadlineManila,
                'can_extend'        => ($rawStat === 'in_progress'),
                'can_void'          => ($att !== null && $rawStat !== 'voided'),
                'can_view'          => ($rawStat === 'submitted' || $rawStat === 'graded'),
                'view_href'         => $attId !== null
                    ? '/teacher/grading.php?quiz_id=' . $quizId . '&attempt_id=' . $attId
                    : '',
            ];
        }

        // Sort: Attention rows first (rank 1 -> 6), then alphabetical by full_name
        usort($participants, static function (array $a, array $b): int {
            if ($a['attention_rank'] !== $b['attention_rank']) {
                return $a['attention_rank'] <=> $b['attention_rank'];
            }
            return strcasecmp($a['full_name'], $b['full_name']);
        });

        // 7. Hero Card Numbers & Copy
        $rosterCount  = count($roster);
        $workingTotal = $workingCount + $disconnectedCount;

        if ($rosterCount === 0) {
            $headline = 'No students enrolled in this class yet.';
        } elseif ($workingTotal === 0) {
            $headline = $submittedCount === $rosterCount
                ? 'All ' . $rosterCount . ' submitted. None still working.'
                : $submittedCount . ' of ' . $rosterCount . ' submitted. None currently working.';
        } else {
            $workWord = self::numberWord($workingTotal, true);
            $headline = $submittedCount . ' of ' . $rosterCount . ' submitted. ' . $workWord . ' still working.';
        }

        $submeta = $rosterCount . ' participants · '
                 . $submittedCount . ' submitted · '
                 . $workingCount . ' working · '
                 . $disconnectedCount . ' disconnected';

        $closesAtFormatted = !empty($qRow['closes_at']) ? to_manila((string) $qRow['closes_at'], 'H:i') : '—';
        $phaseBadge        = strtoupper($phase) . ' · CLOSES AT ' . $closesAtFormatted;

        $untilCloseFormatted = sprintf('%02d:%02d', intdiv($untilCloseSec, 60), $untilCloseSec % 60);

        // 8. Recent Attempt Events (Audit Log)
        $evtStmt = $pdo->prepare(
            "SELECT ae.id, ae.attempt_id, ae.kind, ae.old_deadline_at, ae.new_deadline_at,
                    ae.note, ae.created_at,
                    u_stu.full_name AS student_name,
                    u_act.full_name AS actor_name
               FROM attempt_events ae
               JOIN quiz_attempts a ON a.id = ae.attempt_id
               JOIN users u_stu ON u_stu.id = a.student_id
               JOIN users u_act ON u_act.id = ae.created_by
              WHERE a.quiz_id = ?
              ORDER BY ae.id DESC
              LIMIT 15"
        );
        $evtStmt->execute([$quizId]);
        $rawEvents = $evtStmt->fetchAll();

        $events = [];
        foreach ($rawEvents as $ev) {
            $events[] = [
                'id'                 => (int) $ev['id'],
                'attempt_id'         => (int) $ev['attempt_id'],
                'kind'               => (string) $ev['kind'],
                'student_name'       => (string) $ev['student_name'],
                'actor_name'         => (string) $ev['actor_name'],
                'note'               => (string) ($ev['note'] ?? ''),
                'created_at_manila'  => to_manila((string) $ev['created_at'], 'j M, H:i:s'),
                'old_deadline_manila'=> !empty($ev['old_deadline_at']) ? to_manila((string) $ev['old_deadline_at'], 'H:i:s') : null,
                'new_deadline_manila'=> !empty($ev['new_deadline_at']) ? to_manila((string) $ev['new_deadline_at'], 'H:i:s') : null,
            ];
        }

        return [
            'quiz' => [
                'id'                     => (int) $qRow['id'],
                'class_id'               => (int) $qRow['class_id'],
                'title'                  => (string) $qRow['title'],
                'status'                 => (string) $qRow['status'],
                'phase'                  => $phase,
                'opens_at'               => (string) $qRow['opens_at'],
                'closes_at'              => (string) $qRow['closes_at'],
                'closes_at_manila'       => $closesAtFormatted,
                'duration_min'           => $qRow['duration_min'] !== null ? (int) $qRow['duration_min'] : null,
                'max_attempts'           => (int) $qRow['max_attempts'],
                'class_name'             => (string) $qRow['class_name'],
                'course_code'            => (string) ($qRow['course_code'] ?? ''),
                'section'                => (string) ($qRow['section'] ?? ''),
                'class_meta'             => $classMeta,
                'subhead'                => $subhead,
                'total_questions'        => $totalQuestions,
            ],
            'hero' => [
                'badge'                  => $phaseBadge,
                'headline'               => $headline,
                'submeta'                => $submeta,
                'until_close_seconds'    => $untilCloseSec,
                'until_close_formatted'  => $untilCloseFormatted,
                'roster_count'           => $rosterCount,
                'submitted_count'        => $submittedCount,
                'working_count'          => $workingCount,
                'disconnected_count'     => $disconnectedCount,
                'voided_count'           => $voidedCount,
                'not_started_count'      => $notStartedCount,
            ],
            'attention_banner' => [
                'has_attention'          => !empty($disconnectedList),
                'first_student'          => $disconnectedList[0] ?? null,
                'total_disconnected'     => count($disconnectedList),
                'note'                   => 'Saved answers are retained. The attempt timer continues; the student can reconnect before their deadline.',
            ],
            'participants'               => $participants,
            'events'                     => $events,
            'server_now_utc'             => $now,
            'server_now_manila'          => to_manila($now, 'j M Y, H:i:s'),
        ];
    }

    /**
     * Extends a student's attempt deadline by N minutes.
     * Capped at quiz close time if a closing boundary exists.
     * Appends an audit entry into `attempt_events`.
     *
     * @return array{
     *   result: 'ok'|'not_found'|'not_in_progress'|'capped_at_close'|'invalid',
     *   message?: string,
     *   attempt_id?: int,
     *   old_deadline_at?: string,
     *   new_deadline_at?: string,
     *   new_deadline_manila?: string,
     *   capped?: bool,
     *   minutes_added?: int
     * }
     */
    public static function extendTime(
        int $attemptId,
        int $actorUserId,
        bool $isAdmin,
        int $minutes,
        ?string $note = null,
        ?string $nowUtc = null
    ): array {
        if ($minutes <= 0 || $minutes > 240) {
            return [
                'result'  => 'invalid',
                'message' => 'Extension must be between 1 and 240 minutes.',
            ];
        }

        $cleanNote = $note !== null ? trim($note) : null;
        if ($cleanNote !== null && mb_strlen($cleanNote) > 255) {
            $cleanNote = mb_substr($cleanNote, 0, 255);
        }

        $pdo = db();
        $now = $nowUtc !== null ? utc_to_format($nowUtc, 'Y-m-d H:i:s') : utc_now();
        $nowTs = strtotime($now . ' UTC') ?: time();

        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare(
                "SELECT a.id, a.quiz_id, a.student_id, a.status, a.deadline_at, a.started_at,
                        q.closes_at, q.status AS quiz_status,
                        c.teacher_id,
                        u.full_name AS student_name
                   FROM quiz_attempts a
                   JOIN quizzes q ON q.id = a.quiz_id
                   JOIN classes c ON c.id = q.class_id
                   JOIN users u ON u.id = a.student_id
                  WHERE a.id = ?
                    FOR UPDATE"
            );
            $lockStmt->execute([$attemptId]);
            $row = $lockStmt->fetch();

            if ($row === false) {
                $pdo->rollBack();
                return ['result' => 'not_found', 'message' => 'Attempt not found.'];
            }

            if (!$isAdmin && (int) $row['teacher_id'] !== $actorUserId) {
                $pdo->rollBack();
                return ['result' => 'not_found', 'message' => 'Attempt not found.'];
            }

            if ($row['status'] !== 'in_progress') {
                $pdo->rollBack();
                return [
                    'result'  => 'not_in_progress',
                    'message' => 'Time can only be extended on in-progress attempts (current status: ' . $row['status'] . ').',
                ];
            }

            $currentDeadlineUtc = (string) $row['deadline_at'];
            $currentDeadlineTs  = strtotime($currentDeadlineUtc . ' UTC') ?: $nowTs;

            // Target deadline: add minutes from current deadline (or from now if current deadline was in the past)
            $baseTs = max($nowTs, $currentDeadlineTs);
            $targetDeadlineTs = $baseTs + ($minutes * 60);

            // Cap at quiz close time if defined
            $capped = false;
            if (!empty($row['closes_at'])) {
                $quizCloseTs = strtotime((string) $row['closes_at'] . ' UTC') ?: 0;
                if ($quizCloseTs > 0) {
                    if ($currentDeadlineTs >= $quizCloseTs && $nowTs >= $quizCloseTs) {
                        $pdo->rollBack();
                        return [
                            'result'  => 'capped_at_close',
                            'message' => 'Attempt deadline cannot be extended: quiz close time (' . to_manila((string) $row['closes_at'], 'H:i') . ') has already passed.',
                        ];
                    }
                    if ($targetDeadlineTs > $quizCloseTs) {
                        $targetDeadlineTs = $quizCloseTs;
                        $capped = true;
                    }
                }
            }

            $newDeadlineUtc = gmdate('Y-m-d H:i:s', $targetDeadlineTs);
            $actualAddedSec = max(0, $targetDeadlineTs - $currentDeadlineTs);
            $actualMinutes  = (int) ceil($actualAddedSec / 60);

            // Update quiz_attempts
            $updStmt = $pdo->prepare('UPDATE quiz_attempts SET deadline_at = ? WHERE id = ?');
            $updStmt->execute([$newDeadlineUtc, $attemptId]);

            // Append audit record into attempt_events
            $eventNote = $cleanNote;
            if ($capped) {
                $capMsg = '(Capped at quiz close)';
                $eventNote = $eventNote !== null && $eventNote !== '' ? ($eventNote . ' ' . $capMsg) : $capMsg;
            }

            $evtStmt = $pdo->prepare(
                'INSERT INTO attempt_events (attempt_id, kind, old_deadline_at, new_deadline_at, note, created_by, created_at)
                 VALUES (?, "time_extended", ?, ?, ?, ?, ?)'
            );
            $evtStmt->execute([
                $attemptId,
                $currentDeadlineUtc,
                $newDeadlineUtc,
                $eventNote,
                $actorUserId,
                $now,
            ]);

            $pdo->commit();

            $newDeadlineManila = to_manila($newDeadlineUtc, 'H:i:s');
            $msg = $capped
                ? 'Deadline extended to quiz close at ' . $newDeadlineManila . ' Manila time.'
                : 'Added ' . $actualMinutes . ' minute' . ($actualMinutes === 1 ? '' : 's') . ' (new deadline: ' . $newDeadlineManila . ').';

            return [
                'result'              => 'ok',
                'attempt_id'          => $attemptId,
                'old_deadline_at'     => $currentDeadlineUtc,
                'new_deadline_at'     => $newDeadlineUtc,
                'new_deadline_manila' => $newDeadlineManila,
                'capped'              => $capped,
                'minutes_added'       => $actualMinutes,
                'message'             => $msg,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Voids an attempt with confirmation and an audit reason.
     * The attempt is excluded from grading and cannot be resumed.
     * Appends an audit entry into `attempt_events`.
     *
     * @return array{
     *   result: 'ok'|'not_found'|'already_voided'|'invalid',
     *   message?: string,
     *   attempt_id?: int
     * }
     */
    public static function voidAttempt(
        int $attemptId,
        int $actorUserId,
        bool $isAdmin,
        string $reason,
        ?string $nowUtc = null
    ): array {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            return [
                'result'  => 'invalid',
                'message' => 'A reason is required to void an attempt.',
            ];
        }

        if (mb_strlen($cleanReason) > 255) {
            $cleanReason = mb_substr($cleanReason, 0, 255);
        }

        $pdo = db();
        $now = $nowUtc !== null ? utc_to_format($nowUtc, 'Y-m-d H:i:s') : utc_now();

        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare(
                "SELECT a.id, a.quiz_id, a.student_id, a.status, a.deadline_at,
                        c.teacher_id,
                        u.full_name AS student_name
                   FROM quiz_attempts a
                   JOIN quizzes q ON q.id = a.quiz_id
                   JOIN classes c ON c.id = q.class_id
                   JOIN users u ON u.id = a.student_id
                  WHERE a.id = ?
                    FOR UPDATE"
            );
            $lockStmt->execute([$attemptId]);
            $row = $lockStmt->fetch();

            if ($row === false) {
                $pdo->rollBack();
                return ['result' => 'not_found', 'message' => 'Attempt not found.'];
            }

            if (!$isAdmin && (int) $row['teacher_id'] !== $actorUserId) {
                $pdo->rollBack();
                return ['result' => 'not_found', 'message' => 'Attempt not found.'];
            }

            if ($row['status'] === 'voided') {
                $pdo->rollBack();
                return ['result' => 'already_voided', 'message' => 'Attempt is already voided.'];
            }

            // Update quiz_attempts to voided
            $upd = $pdo->prepare("UPDATE quiz_attempts SET status = 'voided', submit_reason = 'teacher' WHERE id = ?");
            $upd->execute([$attemptId]);

            // Insert audit record into attempt_events
            $evt = $pdo->prepare(
                'INSERT INTO attempt_events (attempt_id, kind, old_deadline_at, new_deadline_at, note, created_by, created_at)
                 VALUES (?, "voided", ?, NULL, ?, ?, ?)'
            );
            $evt->execute([
                $attemptId,
                $row['deadline_at'],
                $cleanReason,
                $actorUserId,
                $now,
            ]);

            $pdo->commit();

            return [
                'result'     => 'ok',
                'attempt_id' => $attemptId,
                'message'    => 'Attempt #' . $attemptId . ' has been voided.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Formats a full name into "Last, Initial" (e.g. "Dela Cruz, M.") if feasible. */
    public static function formatShortName(string $fullName): string
    {
        $clean = trim($fullName);
        if ($clean === '') {
            return '—';
        }

        $parts = preg_split('/\s+/', $clean);
        if ($parts === false || count($parts) < 2) {
            return $clean;
        }

        // If format is already "Last, First":
        if (str_contains($clean, ',')) {
            return $clean;
        }

        $last = array_pop($parts);
        $first = $parts[0];
        $initial = mb_substr($first, 0, 1) . '.';

        // Support compound prefixes like "Dela Cruz, M."
        if (count($parts) > 1 && in_array(strtolower($parts[count($parts) - 1]), ['de', 'del', 'dela', 'van', 'von', 'da'], true)) {
            $prefix = array_pop($parts);
            $last = $prefix . ' ' . $last;
            $initial = mb_substr($parts[0], 0, 1) . '.';
        }

        return $last . ', ' . $initial;
    }

    /** Plain-word relative time (e.g. "4 minutes ago" or "just now"). */
    public static function formatRelativeTime(int $seconds): string
    {
        if ($seconds < 45) {
            return 'just now';
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        $hours = (int) round($minutes / 60);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    /** Small number to word helper. */
    public static function numberWord(int $n, bool $capitalize = false): string
    {
        $words = [
            0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four',
            5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
            10 => 'ten', 11 => 'eleven', 12 => 'twelve',
        ];
        $w = $words[$n] ?? (string) $n;
        return $capitalize ? ucfirst($w) : $w;
    }
}
