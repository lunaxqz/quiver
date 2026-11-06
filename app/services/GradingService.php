<?php
declare(strict_types=1);

/**
 * Teacher grading service (Day 15).
 *
 * Rules enforced here:
 *  - Every query and action verifies that the quiz belongs to a class owned by `$teacherId` (`classes.teacher_id = ?`).
 *    Another teacher receives `not_found` (404), never 403.
 *  - Question-by-question stack: loads non-blank written (`short`, `essay`) responses across submitted/graded attempts,
 *    ordered by question position then a deterministic blind order (`crc32(attempt_id . ':' . quiz_question_id)`).
 *  - Whole-paper mode: loads all responses (objective and written) for a single student attempt, allowing teachers
 *    to grade written items AND override auto-graded objective items (`points_awarded` differs from `auto_points`).
 *  - Every grade save or undo:
 *      1. Locks the response + attempt + quiz + class row (`SELECT ... FOR UPDATE`) and checks teacher ownership.
 *      2. Clamps/validates `points` between `0` and `quiz_questions.points`.
 *      3. Updates `responses` (`points_awarded`, `feedback`, `graded_by`, `graded_at`).
 *      4. Inserts an append-only audit row into `grade_events` (undo also inserts a new `grade_events` row, never deletes).
 *      5. Recomputes the attempt's `score` and transitions `status` between `'submitted'` and `'graded'` depending on
 *         whether any non-blank written response on that attempt still has `points_awarded IS NULL`.
 */
final class GradingService
{
    private const CHOICE_TYPES  = ['single', 'true_false', 'multi'];
    private const WRITTEN_TYPES = ['short', 'essay'];

    /**
     * Counts all pending (ungraded, non-blank) written responses across all quizzes owned by `$teacherId`.
     * Used for the left-rail Grading badge.
     */
    public static function pendingCountForTeacher(int $teacherId): int
    {
        AttemptService::finalizeOverdue(null, $teacherId);

        $stmt = db()->prepare(
            "SELECT COUNT(*)
               FROM responses r
               JOIN quiz_attempts a ON a.id = r.attempt_id
               JOIN quiz_questions qq ON qq.id = r.quiz_question_id
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND q.status <> 'archived'
                AND a.status IN ('submitted', 'graded')
                AND qq.type IN ('short', 'essay')
                AND r.points_awarded IS NULL
                AND TRIM(COALESCE(r.answer_text, '')) <> ''"
        );
        $stmt->execute([$teacherId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Lists published quizzes owned by `$teacherId` that have submitted/graded attempts or written questions,
     * ordered with quizzes that have pending written responses first.
     *
     * @return list<array>
     */
    public static function listQuizzesForGrading(int $teacherId): array
    {
        AttemptService::finalizeOverdue(null, $teacherId);

        $stmt = db()->prepare(
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
                        AND TRIM(COALESCE(r.answer_text, '')) <> '') AS pending_written_count,
                    (SELECT COUNT(*)
                       FROM responses r
                       JOIN quiz_attempts a ON a.id = r.attempt_id
                       JOIN quiz_questions qq ON qq.id = r.quiz_question_id
                      WHERE a.quiz_id = q.id
                        AND a.status IN ('submitted', 'graded')
                        AND qq.type IN ('short', 'essay')
                        AND TRIM(COALESCE(r.answer_text, '')) <> '') AS total_written_count
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND q.status = 'published'
              ORDER BY pending_written_count DESC, q.updated_at DESC, q.id DESC"
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Builds the complete grading workspace payload for a specific quiz owned by `$teacherId`,
     * or returns `null` if the quiz does not exist or belongs to another teacher.
     */
    public static function workspace(int $quizId, int $teacherId): ?array
    {
        AttemptService::finalizeOverdue($quizId, $teacherId);

        $pdo = db();
        $qStmt = $pdo->prepare(
            "SELECT q.id, q.title, q.class_id, q.status, q.opens_at, q.closes_at,
                    c.name AS class_name, c.course_code, c.section
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE q.id = ? AND c.teacher_id = ?"
        );
        $qStmt->execute([$quizId, $teacherId]);
        $quiz = $qStmt->fetch();
        if ($quiz === false) {
            return null;
        }

        $classId = (int) $quiz['class_id'];

        $rStmt = $pdo->prepare('SELECT COUNT(*) FROM class_memberships WHERE class_id = ? AND removed_at IS NULL');
        $rStmt->execute([$classId]);
        $rosterCount = (int) $rStmt->fetchColumn();

        // Load all questions and options for this quiz
        $qqStmt = $pdo->prepare(
            'SELECT id, position, type, prompt, points, rubric
               FROM quiz_questions
              WHERE quiz_id = ?
              ORDER BY position ASC, id ASC'
        );
        $qqStmt->execute([$quizId]);
        $questions = $qqStmt->fetchAll();

        $optStmt = $pdo->prepare(
            'SELECT o.id, o.quiz_question_id, o.label, o.is_correct, o.position
               FROM quiz_question_options o
               JOIN quiz_questions qq ON qq.id = o.quiz_question_id
              WHERE qq.quiz_id = ?
              ORDER BY o.quiz_question_id ASC, o.position ASC'
        );
        $optStmt->execute([$quizId]);
        $optionsByQ = [];
        foreach ($optStmt->fetchAll() as $o) {
            $optionsByQ[(int) $o['quiz_question_id']][] = [
                'id'         => (int) $o['id'],
                'label'      => (string) $o['label'],
                'is_correct' => (int) $o['is_correct'] === 1,
                'position'   => (int) $o['position'],
            ];
        }

        // Load all submitted or graded attempts for this quiz
        $attStmt = $pdo->prepare(
            "SELECT a.id, a.student_id, a.attempt_no, a.status, a.submit_reason, a.submitted_at, a.score,
                    u.full_name AS student_name, u.username AS student_username
               FROM quiz_attempts a
               JOIN users u ON u.id = a.student_id
              WHERE a.quiz_id = ? AND a.status IN ('submitted', 'graded')
              ORDER BY a.submitted_at ASC, a.id ASC"
        );
        $attStmt->execute([$quizId]);
        $attempts = $attStmt->fetchAll();

        // Assign stable anonymous paper codes (e.g. PAPER K1, PAPER K2...) based on deterministic hash of attempt ID
        $sortedForCode = $attempts;
        usort($sortedForCode, static function (array $a, array $b): int {
            $ha = sprintf('%u', crc32('paper:' . $a['id']));
            $hb = sprintf('%u', crc32('paper:' . $b['id']));
            return $ha <=> $hb ?: ((int) $a['id'] <=> (int) $b['id']);
        });
        $paperCodeByAttempt = [];
        foreach ($sortedForCode as $idx => $aRow) {
            $paperCodeByAttempt[(int) $aRow['id']] = 'PAPER K' . ($idx + 1);
        }

        // Ensure every submitted/graded attempt has a `responses` row for every question in the quiz
        // so teachers can grade or override any question in whole-paper mode even if a student left it blank.
        $respByAttemptQ = [];
        $respIds = [];
        if ($attempts && $questions) {
            $attIds = array_map(static fn(array $a): int => (int) $a['id'], $attempts);
            $inAtt  = implode(',', array_fill(0, count($attIds), '?'));
            $rsStmt = $pdo->prepare(
                "SELECT id, attempt_id, quiz_question_id, answer_text, revision,
                        auto_points, points_awarded, feedback, graded_by, graded_at
                   FROM responses
                  WHERE attempt_id IN ($inAtt)"
            );
            $rsStmt->execute($attIds);
            foreach ($rsStmt->fetchAll() as $r) {
                $aid = (int) $r['attempt_id'];
                $qid = (int) $r['quiz_question_id'];
                $respByAttemptQ[$aid][$qid] = $r;
                $respIds[] = (int) $r['id'];
            }

            // Backfill any missing response row (defensive)
            $insMissing = $pdo->prepare(
                'INSERT IGNORE INTO responses (attempt_id, quiz_question_id, answer_text, revision, auto_points, points_awarded, graded_at)
                 VALUES (?, ?, NULL, 0, 0.00, 0.00, ?)'
            );
            $nowUtc = utc_now();
            $didInsert = false;
            foreach ($attempts as $aRow) {
                $aid = (int) $aRow['id'];
                foreach ($questions as $qRow) {
                    $qid = (int) $qRow['id'];
                    if (!isset($respByAttemptQ[$aid][$qid])) {
                        $insMissing->execute([$aid, $qid, $nowUtc]);
                        $didInsert = true;
                    }
                }
            }
            if ($didInsert) {
                $respByAttemptQ = [];
                $respIds = [];
                $rsStmt->execute($attIds);
                foreach ($rsStmt->fetchAll() as $r) {
                    $aid = (int) $r['attempt_id'];
                    $qid = (int) $r['quiz_question_id'];
                    $respByAttemptQ[$aid][$qid] = $r;
                    $respIds[] = (int) $r['id'];
                }
            }
        }

        // Load selected option IDs for choice responses
        $pickedByResp = [];
        if ($respIds) {
            $inResp = implode(',', array_fill(0, count($respIds), '?'));
            $roStmt = $pdo->prepare(
                "SELECT response_id, quiz_question_option_id
                   FROM response_options
                  WHERE response_id IN ($inResp)
                  ORDER BY quiz_question_option_id ASC"
            );
            $roStmt->execute($respIds);
            foreach ($roStmt->fetchAll() as $ro) {
                $pickedByResp[(int) $ro['response_id']][] = (int) $ro['quiz_question_option_id'];
            }
        }

        // Build question metadata map & question-by-question grading stack items
        $questionsOut = [];
        $stackItems   = [];
        $pendingWrittenCount = 0;

        foreach ($questions as $qRow) {
            $qid   = (int) $qRow['id'];
            $qType = (string) $qRow['type'];
            $qPts  = round((float) $qRow['points'], 2);
            $opts  = $optionsByQ[$qid] ?? [];

            $correctLabels = [];
            foreach ($opts as $o) {
                if ($o['is_correct']) {
                    $correctLabels[] = $o['label'];
                }
            }

            $questionsOut[$qid] = [
                'id'             => $qid,
                'position'       => (int) $qRow['position'],
                'type'           => $qType,
                'type_label'     => self::typeLabel($qType),
                'is_written'     => in_array($qType, self::WRITTEN_TYPES, true),
                'prompt'         => (string) $qRow['prompt'],
                'points'         => $qPts,
                'rubric'         => (string) ($qRow['rubric'] ?? ''),
                'correct_labels' => $correctLabels,
                'options'        => $opts,
            ];
        }

        // Build per-attempt papers (for Whole-paper mode) and collect written items (for Question-by-question stack)
        $papersOut = [];
        foreach ($attempts as $aRow) {
            $aid       = (int) $aRow['id'];
            $paperCode = $paperCodeByAttempt[$aid] ?? ('PAPER K' . $aid);
            $subAt     = (string) ($aRow['submitted_at'] ?? '');

            $paperItems = [];
            $paperPendingCount = 0;

            foreach ($questions as $qRow) {
                $qid   = (int) $qRow['id'];
                $qMeta = $questionsOut[$qid];
                $qType = $qMeta['type'];
                $r     = $respByAttemptQ[$aid][$qid] ?? null;
                if ($r === null) {
                    continue;
                }

                $rid       = (int) $r['id'];
                $pickedIds = $pickedByResp[$rid] ?? [];
                $pickedLabels = [];
                foreach ($qMeta['options'] as $o) {
                    if (in_array($o['id'], $pickedIds, true)) {
                        $pickedLabels[] = $o['label'];
                    }
                }

                $answerText = trim((string) ($r['answer_text'] ?? ''));
                $isBlankWritten = $qMeta['is_written'] && $answerText === '';
                $isPending = $qMeta['is_written'] && !$isBlankWritten && $r['points_awarded'] === null;
                if ($isPending) {
                    $pendingWrittenCount++;
                    $paperPendingCount++;
                }

                $autoPts    = $r['auto_points'] !== null ? round((float) $r['auto_points'], 2) : null;
                $awardedPts = $r['points_awarded'] !== null ? round((float) $r['points_awarded'], 2) : null;
                $isOverridden = !$qMeta['is_written']
                    && $r['graded_by'] !== null
                    && $autoPts !== null
                    && $awardedPts !== null
                    && abs($awardedPts - $autoPts) > 0.001;

                $itemPayload = [
                    'response_id'      => $rid,
                    'attempt_id'       => $aid,
                    'question_id'      => $qid,
                    'question_pos'     => $qMeta['position'],
                    'question_type'    => $qType,
                    'type_label'       => $qMeta['type_label'],
                    'is_written'       => $qMeta['is_written'],
                    'prompt'           => $qMeta['prompt'],
                    'max_points'       => $qMeta['points'],
                    'rubric'           => $qMeta['rubric'],
                    'correct_labels'   => $qMeta['correct_labels'],
                    'student_name'     => (string) $aRow['student_name'],
                    'student_username' => (string) $aRow['student_username'],
                    'paper_code'       => $paperCode,
                    'submitted_at'     => $subAt,
                    'submitted_manila' => $subAt !== '' ? to_manila($subAt, 'j M Y, H:i') : '',
                    'answer_text'      => (string) ($r['answer_text'] ?? ''),
                    'picked_labels'    => $pickedLabels,
                    'is_blank_written' => $isBlankWritten,
                    'is_pending'       => $isPending,
                    'auto_points'      => $autoPts,
                    'points_awarded'   => $awardedPts,
                    'is_overridden'    => $isOverridden,
                    'feedback'         => (string) ($r['feedback'] ?? ''),
                    'graded_by'        => $r['graded_by'] !== null ? (int) $r['graded_by'] : null,
                    'graded_at_manila' => $r['graded_at'] !== null ? to_manila((string) $r['graded_at'], 'j M Y, H:i') : '',
                    'blind_order'      => (int) sprintf('%u', crc32($aid . ':' . $qid)),
                ];

                $paperItems[] = $itemPayload;

                // Include non-blank written responses in the Question-by-question triage stack
                if ($qMeta['is_written'] && !$isBlankWritten) {
                    $stackItems[] = $itemPayload;
                }
            }

            $papersOut[] = [
                'attempt_id'       => $aid,
                'attempt_no'       => (int) $aRow['attempt_no'],
                'status'           => (string) $aRow['status'],
                'score'            => $aRow['score'] !== null ? round((float) $aRow['score'], 2) : 0.0,
                'student_name'     => (string) $aRow['student_name'],
                'student_username' => (string) $aRow['student_username'],
                'paper_code'       => $paperCode,
                'submitted_at'     => $subAt,
                'submitted_manila' => $subAt !== '' ? to_manila($subAt, 'j M Y, H:i') : '',
                'pending_count'    => $paperPendingCount,
                'items'            => $paperItems,
            ];
        }

        // Sort Question-by-question stack:
        // 1. Question position ascending
        // 2. Pending (ungraded) responses first, then already-graded responses
        // 3. Oldest submission / deterministic pseudo-random order
        usort($stackItems, static function (array $a, array $b): int {
            if ($a['question_pos'] !== $b['question_pos']) {
                return $a['question_pos'] <=> $b['question_pos'];
            }
            if ($a['is_pending'] !== $b['is_pending']) {
                return $a['is_pending'] ? -1 : 1;
            }
            if ($a['submitted_at'] !== $b['submitted_at']) {
                return strcmp($a['submitted_at'], $b['submitted_at']);
            }
            return $a['blind_order'] <=> $b['blind_order'];
        });

        $totalPoints = 0.0;
        foreach ($questionsOut as $qMeta) {
            $totalPoints += $qMeta['points'];
        }

        $classLabel = trim(($quiz['course_code'] ?? '') . (($quiz['section'] ?? '') !== '' ? ', Section ' . $quiz['section'] : ''), ', ');
        if ($classLabel === '') {
            $classLabel = (string) $quiz['class_name'];
        }

        return [
            'quiz' => [
                'id'                    => (int) $quiz['id'],
                'title'                 => (string) $quiz['title'],
                'class_label'           => $classLabel,
                'roster_count'          => $rosterCount,
                'submitted_count'       => count($attempts),
                'pending_written_count' => $pendingWrittenCount,
                'total_stack_count'     => count($stackItems),
                'total_points'          => round($totalPoints, 2),
            ],
            'questions'   => array_values($questionsOut),
            'stack_items' => $stackItems,
            'papers'      => $papersOut,
        ];
    }

    /**
     * Grades (or overrides) a single response and appends a row to `grade_events`.
     *
     * Rules enforced here:
     *  1. Verifies that `$responseId` belongs to a quiz in a class owned by `$teacherId` (locks row `FOR UPDATE`).
     *     If not found or owned by another teacher, returns `['result' => 'not_found']`.
     *  2. Verifies `$points` is finite and between `0` and `quiz_questions.points` (inclusive).
     *  3. Saves previous state (`prev_points`, `prev_feedback`) so the client or server can undo cleanly.
     *  4. Updates `responses` (`points_awarded`, `feedback`, `graded_by`, `graded_at`).
     *  5. Inserts a new audit row into `grade_events` (`response_id`, `points`, `feedback`, `graded_by`, `created_at`).
     *  6. Recomputes the parent attempt's `score` and updates `quiz_attempts.status` (`'graded'` if all non-blank
     *     written responses now have `points_awarded IS NOT NULL`, else `'submitted'`).
     *
     * @return array{
     *   result: 'ok'|'not_found'|'invalid_points',
     *   error?: string,
     *   response_id?: int,
     *   attempt_id?: int,
     *   quiz_id?: int,
     *   points_awarded?: float,
     *   auto_points?: ?float,
     *   is_overridden?: bool,
     *   feedback?: string,
     *   graded_at_manila?: string,
     *   attempt_score?: float,
     *   attempt_status?: string,
     *   pending_written_count?: int,
     *   grade_event_id?: int,
     *   previous_state?: array{points_awarded: ?float, feedback: ?string, graded_by: ?int, graded_at: ?string}
     * }
     */
    public static function gradeResponse(
        int $responseId,
        int $teacherId,
        float $points,
        ?string $feedback = null,
        ?string $nowUtc = null
    ): array {
        if (!is_finite($points)) {
            return ['result' => 'invalid_points', 'error' => 'Points must be a valid number.'];
        }

        $now = $nowUtc ?? utc_now();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                "SELECT r.id AS response_id, r.attempt_id, r.quiz_question_id,
                        r.auto_points, r.points_awarded AS prev_points, r.feedback AS prev_feedback,
                        r.graded_by AS prev_graded_by, r.graded_at AS prev_graded_at,
                        qq.quiz_id, qq.type AS question_type, qq.points AS max_points,
                        a.status AS attempt_status, a.score_released_at, a.review_released_at,
                        q.score_release, q.review_release, q.release_at
                   FROM responses r
                   JOIN quiz_attempts a ON a.id = r.attempt_id
                   JOIN quiz_questions qq ON qq.id = r.quiz_question_id
                   JOIN quizzes q ON q.id = qq.quiz_id
                   JOIN classes c ON c.id = q.class_id
                  WHERE r.id = ?
                    AND c.teacher_id = ?
                    AND a.status IN ('submitted', 'graded')
                  FOR UPDATE"
            );
            $lock->execute([$responseId, $teacherId]);
            $row = $lock->fetch();
            if ($row === false) {
                $pdo->rollBack();
                return ['result' => 'not_found'];
            }

            $maxPoints = round((float) $row['max_points'], 2);
            $roundedPoints = round($points, 2);
            if ($roundedPoints < 0.0 || $roundedPoints > $maxPoints + 0.0001) {
                $pdo->rollBack();
                return [
                    'result' => 'invalid_points',
                    'error'  => 'Points must be between 0 and ' . $maxPoints . '.',
                ];
            }

            $cleanFeedback = $feedback !== null ? trim($feedback) : null;
            if ($cleanFeedback === '') {
                $cleanFeedback = null;
            } elseif ($cleanFeedback !== null && mb_strlen($cleanFeedback) > 5000) {
                $cleanFeedback = mb_substr($cleanFeedback, 0, 5000);
            }

            $previousState = [
                'points_awarded' => $row['prev_points'] !== null ? round((float) $row['prev_points'], 2) : null,
                'feedback'       => $row['prev_feedback'] !== null ? (string) $row['prev_feedback'] : null,
                'graded_by'      => $row['prev_graded_by'] !== null ? (int) $row['prev_graded_by'] : null,
                'graded_at'      => $row['prev_graded_at'] !== null ? (string) $row['prev_graded_at'] : null,
            ];

            // 1. Update responses row
            $pdo->prepare(
                'UPDATE responses
                    SET points_awarded = ?,
                        feedback = ?,
                        graded_by = ?,
                        graded_at = ?
                  WHERE id = ?'
            )->execute([$roundedPoints, $cleanFeedback, $teacherId, $now, $responseId]);

            // 2. Append audit row to grade_events
            $pdo->prepare(
                'INSERT INTO grade_events (response_id, points, feedback, graded_by, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$responseId, $roundedPoints, $cleanFeedback, $teacherId, $now]);
            $eventId = (int) $pdo->lastInsertId();

            // 3. Recompute attempt score and status
            $attemptId = (int) $row['attempt_id'];
            $quizId    = (int) $row['quiz_id'];
            $recomputed = self::recomputeAttemptInTransaction($pdo, $attemptId, $row, $now);

            // 4. Count remaining pending written responses on this quiz
            $pendingQuizCount = self::countPendingWrittenForQuizInTransaction($pdo, $quizId);

            $pdo->commit();

            $isWritten = in_array((string) $row['question_type'], self::WRITTEN_TYPES, true);
            $autoPts   = $row['auto_points'] !== null ? round((float) $row['auto_points'], 2) : null;
            $isOverridden = !$isWritten && $autoPts !== null && abs($roundedPoints - $autoPts) > 0.001;

            return [
                'result'                => 'ok',
                'response_id'           => $responseId,
                'attempt_id'            => $attemptId,
                'quiz_id'               => $quizId,
                'points_awarded'        => $roundedPoints,
                'auto_points'           => $autoPts,
                'is_overridden'         => $isOverridden,
                'feedback'              => $cleanFeedback ?? '',
                'graded_at_manila'      => to_manila($now, 'j M Y, H:i'),
                'attempt_score'         => $recomputed['score'],
                'attempt_status'        => $recomputed['status'],
                'pending_written_count' => $pendingQuizCount,
                'grade_event_id'        => $eventId,
                'previous_state'        => $previousState,
            ];
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Undoes the last mark on a response while preserving an append-only `grade_events` history
     * (never deletes from `grade_events`).
     *
     * Rules enforced here:
     *  - Verifies teacher ownership of the response's quiz class (`FOR UPDATE`).
     *  - If `$restorePoints` is provided (numeric), restores that value and records a new `grade_events` row
     *    with those points.
     *  - If `$restorePoints` is `null` (i.e. the response was previously ungraded before the mark being undone):
     *      - For written questions (`short`, `essay`), sets `responses.points_awarded = NULL`, `graded_by = NULL`,
     *        `graded_at = NULL`, and `feedback = $restoreFeedback`, and still appends a new `grade_events` row
     *        (recording `0.00` points with `[Undo to ungraded]` note if feedback is empty) so `grade_events`
     *        always gains a new event on undo.
     *      - For objective questions, restores `points_awarded = auto_points` and appends a new `grade_events` row.
     */
    public static function undoResponse(
        int $responseId,
        int $teacherId,
        ?float $restorePoints = null,
        ?string $restoreFeedback = null,
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? utc_now();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                "SELECT r.id AS response_id, r.attempt_id, r.quiz_question_id,
                        r.auto_points, r.points_awarded, r.feedback,
                        qq.quiz_id, qq.type AS question_type, qq.points AS max_points,
                        a.status AS attempt_status, a.score_released_at, a.review_released_at,
                        q.score_release, q.review_release, q.release_at
                   FROM responses r
                   JOIN quiz_attempts a ON a.id = r.attempt_id
                   JOIN quiz_questions qq ON qq.id = r.quiz_question_id
                   JOIN quizzes q ON q.id = qq.quiz_id
                   JOIN classes c ON c.id = q.class_id
                  WHERE r.id = ?
                    AND c.teacher_id = ?
                    AND a.status IN ('submitted', 'graded')
                  FOR UPDATE"
            );
            $lock->execute([$responseId, $teacherId]);
            $row = $lock->fetch();
            if ($row === false) {
                $pdo->rollBack();
                return ['result' => 'not_found'];
            }

            $maxPoints = round((float) $row['max_points'], 2);
            $isWritten = in_array((string) $row['question_type'], self::WRITTEN_TYPES, true);
            $autoPts   = $row['auto_points'] !== null ? round((float) $row['auto_points'], 2) : null;

            // If caller did not pass explicit restore state, look up the second-most-recent grade_event if one exists
            if ($restorePoints === null && func_num_args() < 3) {
                $evStmt = $pdo->prepare(
                    'SELECT points, feedback FROM grade_events WHERE response_id = ? ORDER BY id DESC LIMIT 2'
                );
                $evStmt->execute([$responseId]);
                $evs = $evStmt->fetchAll();
                if (count($evs) >= 2) {
                    $restorePoints   = (float) $evs[1]['points'];
                    $restoreFeedback = $evs[1]['feedback'] !== null ? (string) $evs[1]['feedback'] : null;
                }
            }

            $cleanFeedback = $restoreFeedback !== null ? trim($restoreFeedback) : null;
            if ($cleanFeedback === '') {
                $cleanFeedback = null;
            }

            if ($restorePoints !== null) {
                $clamped = max(0.0, min($maxPoints, round($restorePoints, 2)));
                $newPointsAwarded = $clamped;
                $newGradedBy      = $teacherId;
                $newGradedAt      = $now;
                $eventPoints      = $clamped;
            } elseif (!$isWritten) {
                // Objective question reverts to auto_points
                $newPointsAwarded = $autoPts ?? 0.0;
                $newGradedBy      = null;
                $newGradedAt      = $now;
                $eventPoints      = $newPointsAwarded;
            } else {
                // Written question reverts to pending (ungraded)
                $newPointsAwarded = null;
                $newGradedBy      = null;
                $newGradedAt      = null;
                $eventPoints      = 0.0;
            }

            $pdo->prepare(
                'UPDATE responses
                    SET points_awarded = ?,
                        feedback = ?,
                        graded_by = ?,
                        graded_at = ?
                  WHERE id = ?'
            )->execute([$newPointsAwarded, $cleanFeedback, $newGradedBy, $newGradedAt, $responseId]);

            // Undo is always recorded as a new event in grade_events (never deletes!)
            $pdo->prepare(
                'INSERT INTO grade_events (response_id, points, feedback, graded_by, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$responseId, $eventPoints, $cleanFeedback, $teacherId, $now]);
            $eventId = (int) $pdo->lastInsertId();

            $attemptId = (int) $row['attempt_id'];
            $quizId    = (int) $row['quiz_id'];
            $recomputed = self::recomputeAttemptInTransaction($pdo, $attemptId, $row, $now);
            $pendingQuizCount = self::countPendingWrittenForQuizInTransaction($pdo, $quizId);

            $pdo->commit();

            $isOverridden = !$isWritten
                && $newGradedBy !== null
                && $autoPts !== null
                && $newPointsAwarded !== null
                && abs($newPointsAwarded - $autoPts) > 0.001;

            return [
                'result'                => 'ok',
                'response_id'           => $responseId,
                'attempt_id'            => $attemptId,
                'quiz_id'               => $quizId,
                'points_awarded'        => $newPointsAwarded,
                'auto_points'           => $autoPts,
                'is_overridden'         => $isOverridden,
                'is_pending'            => $isWritten && $newPointsAwarded === null,
                'feedback'              => $cleanFeedback ?? '',
                'graded_at_manila'      => $newGradedAt !== null ? to_manila($newGradedAt, 'j M Y, H:i') : '',
                'attempt_score'         => $recomputed['score'],
                'attempt_status'        => $recomputed['status'],
                'pending_written_count' => $pendingQuizCount,
                'grade_event_id'        => $eventId,
            ];
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Recomputes an attempt's total `score` and updates `status` (`'submitted'` vs `'graded'`) inside an active transaction.
     *
     * @return array{score: float, status: string}
     */
    private static function recomputeAttemptInTransaction(PDO $pdo, int $attemptId, array $attMeta, string $nowUtc): array
    {
        $stmt = $pdo->prepare(
            "SELECT r.points_awarded, r.answer_text, qq.type
               FROM responses r
               JOIN quiz_questions qq ON qq.id = r.quiz_question_id
              WHERE r.attempt_id = ?"
        );
        $stmt->execute([$attemptId]);

        $totalScore = 0.0;
        $hasUngradedWritten = false;
        foreach ($stmt->fetchAll() as $r) {
            if ($r['points_awarded'] !== null) {
                $totalScore += (float) $r['points_awarded'];
            } elseif (in_array((string) $r['type'], self::WRITTEN_TYPES, true) && trim((string) ($r['answer_text'] ?? '')) !== '') {
                $hasUngradedWritten = true;
            }
        }

        $totalScore = round($totalScore, 2);
        $newStatus  = $hasUngradedWritten ? 'submitted' : 'graded';

        $scoreReleasedAt  = $attMeta['score_released_at'] ?? null;
        $reviewReleasedAt = $attMeta['review_released_at'] ?? null;
        $scoreReleaseMode  = (string) ($attMeta['score_release'] ?? 'manual');
        $reviewReleaseMode = (string) ($attMeta['review_release'] ?? 'manual');
        $releaseAtUtc      = $attMeta['release_at'] ?? null;

        if ($newStatus === 'graded' && $scoreReleasedAt === null) {
            if ($scoreReleaseMode === 'on_submission') {
                $scoreReleasedAt = $nowUtc;
            } elseif ($scoreReleaseMode === 'scheduled' && $releaseAtUtc !== null && $releaseAtUtc <= $nowUtc) {
                $scoreReleasedAt = $releaseAtUtc;
            }
        }
        if ($newStatus === 'graded' && $reviewReleasedAt === null) {
            if ($reviewReleaseMode === 'on_submission') {
                $reviewReleasedAt = $nowUtc;
            } elseif ($reviewReleaseMode === 'scheduled' && $releaseAtUtc !== null && $releaseAtUtc <= $nowUtc) {
                $reviewReleasedAt = $releaseAtUtc;
            }
        }

        $pdo->prepare(
            'UPDATE quiz_attempts
                SET status = ?,
                    score = ?,
                    score_released_at = ?,
                    review_released_at = ?
              WHERE id = ?'
        )->execute([$newStatus, $totalScore, $scoreReleasedAt, $reviewReleasedAt, $attemptId]);

        return [
            'score'  => $totalScore,
            'status' => $newStatus,
        ];
    }

    private static function countPendingWrittenForQuizInTransaction(PDO $pdo, int $quizId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM responses r
               JOIN quiz_attempts a ON a.id = r.attempt_id
               JOIN quiz_questions qq ON qq.id = r.quiz_question_id
              WHERE a.quiz_id = ?
                AND a.status IN ('submitted', 'graded')
                AND qq.type IN ('short', 'essay')
                AND r.points_awarded IS NULL
                AND TRIM(COALESCE(r.answer_text, '')) <> ''"
        );
        $stmt->execute([$quizId]);
        return (int) $stmt->fetchColumn();
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'single'     => 'Single choice',
            'true_false' => 'True / False',
            'multi'      => 'Multi-select',
            'short'      => 'Short answer',
            'essay'      => 'Essay',
            default      => ucfirst($type),
        };
    }
}
