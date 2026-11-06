<?php
declare(strict_types=1);

/**
 * Student attempts (Day 11).
 *
 * Rules that live here:
 *  - A student only sees quizzes that are published, in a class they are still a member of, and not archived.
 *  - start() checks membership, that the quiz is open, and the attempt limit. If an attempt is already
 *    running it is resumed; otherwise the next attempt number is created. Two tabs pressing Begin at the
 *    same moment get ONE attempt: the membership row is locked (FOR UPDATE) and uq_attempt is the backstop.
 *  - The deadline is fixed when the attempt starts: the earlier of (now + duration) and the quiz's closes_at.
 *  - payload() is the ONLY thing sent to the browser while answering. It uses explicit column lists and
 *    never selects is_correct, rubric, or the accepted answers stored for short-answer questions.
 */
final class AttemptService
{
    /** Question types whose options are shown to the student. Short and essay options are answer keys. */
    private const CHOICE_TYPES = ['single', 'true_false', 'multi'];

    // ------------------------------------------------------------------ reading

    /**
     * Published quizzes in the student's active classes, grouped by class.
     * Closed quizzes are left out (results come later). Each quiz carries its phase and attempt usage.
     *
     * @return list<array{class: array, quizzes: list<array>}>
     */
    public static function homeForStudent(int $studentId, ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? utc_now();
        $classes = ClassService::listForStudent($studentId);
        if (!$classes) {
            return [];
        }

        // Closed quizzes are left out unless the student has already started or submitted an attempt.
        $stmt = db()->prepare(
            "SELECT q.id, q.class_id, q.title, q.opens_at, q.closes_at, q.duration_min, q.max_attempts
               FROM quizzes q
               JOIN class_memberships m ON m.class_id = q.class_id AND m.student_id = ? AND m.removed_at IS NULL
               JOIN classes c ON c.id = q.class_id AND c.archived_at IS NULL
              WHERE q.status = 'published'
                AND (q.closes_at > ? OR EXISTS (
                    SELECT 1 FROM quiz_attempts a
                     WHERE a.quiz_id = q.id AND a.student_id = ? AND a.status <> 'voided'
                ))
              ORDER BY q.closes_at, q.id"
        );
        $stmt->execute([$studentId, $now, $studentId]);
        $byClass = [];
        foreach ($stmt->fetchAll() as $q) {
            $byClass[(int) $q['class_id']][] = self::withUsage($q, $studentId, $now);
        }

        $out = [];
        foreach ($classes as $c) {
            $out[] = ['class' => $c, 'quizzes' => $byClass[(int) $c['id']] ?? []];
        }
        return $out;
    }

    /**
     * Everything the start screen needs, or null if this student may not see the quiz
     * (not found, not published, not their class). Callers show "not found" for null.
     */
    public static function startInfo(int $studentId, int $quizId, ?string $nowUtc = null): ?array
    {
        $now = $nowUtc ?? utc_now();
        $quiz = self::visibleQuiz($studentId, $quizId);
        if ($quiz === null) {
            return null;
        }
        $stmt = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(points), 0) AS pts FROM quiz_questions WHERE quiz_id = ?');
        $stmt->execute([$quizId]);
        $agg = $stmt->fetch();
        $quiz['question_count'] = (int) $agg['n'];
        $quiz['total_points']   = (float) $agg['pts'];
        return self::withUsage($quiz, $studentId, $now);
    }

    // ------------------------------------------------------------------ starting

    /**
     * Start or resume an attempt.
     *
     * @return array{result: 'started'|'resumed'|'not_found'|'not_open'|'limit'|'empty', attempt_id: ?int}
     */
    public static function start(int $studentId, int $quizId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock this student's membership row. A second tab waits here until the first one commits.
            $lock = $pdo->prepare(
                "SELECT m.id
                   FROM class_memberships m
                   JOIN quizzes q ON q.class_id = m.class_id
                   JOIN classes c ON c.id = m.class_id
                  WHERE q.id = ? AND m.student_id = ? AND m.removed_at IS NULL
                    AND c.archived_at IS NULL AND q.status = 'published'
                    FOR UPDATE"
            );
            $lock->execute([$quizId, $studentId]);
            if ($lock->fetch() === false) {
                $pdo->rollBack();
                return ['result' => 'not_found', 'attempt_id' => null];
            }

            $q = $pdo->prepare('SELECT id, opens_at, closes_at, duration_min, max_attempts FROM quizzes WHERE id = ?');
            $q->execute([$quizId]);
            $quiz = $q->fetch();
            $now = utc_now();

            // Finalize any expired in_progress attempt for this student on this quiz before checking running attempts.
            $expStmt = $pdo->prepare(
                "SELECT a.*, q.score_release, q.review_release, q.release_at
                   FROM quiz_attempts a
                   JOIN quizzes q ON q.id = a.quiz_id
                  WHERE a.quiz_id = ? AND a.student_id = ? AND a.status = 'in_progress' AND a.deadline_at <= ?
                  FOR UPDATE"
            );
            $expStmt->execute([$quizId, $studentId, $now]);
            foreach ($expStmt->fetchAll() as $expAtt) {
                self::finalizeInTransaction($pdo, $expAtt, 'timeout', (string) $expAtt['deadline_at'], $now);
            }

            // Resume a running attempt whose deadline has not passed.
            $run = $pdo->prepare(
                "SELECT id FROM quiz_attempts
                  WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress' AND deadline_at > ?
                  ORDER BY attempt_no DESC LIMIT 1"
            );
            $run->execute([$quizId, $studentId, $now]);
            $running = $run->fetchColumn();
            if ($running !== false) {
                $pdo->commit();
                return ['result' => 'resumed', 'attempt_id' => (int) $running];
            }

            if (quiz_phase($quiz['opens_at'], $quiz['closes_at'], $now) !== 'open') {
                $pdo->rollBack();
                return ['result' => 'not_open', 'attempt_id' => null];
            }

            $cnt = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?');
            $cnt->execute([$quizId]);
            if ((int) $cnt->fetchColumn() === 0) {
                $pdo->rollBack();
                return ['result' => 'empty', 'attempt_id' => null];
            }

            // Voided attempts do not use up the allowance; everything else does.
            $used = $pdo->prepare(
                "SELECT COUNT(*) AS used, COALESCE(MAX(attempt_no), 0) AS last_no
                   FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?"
            );
            $used->execute([$quizId, $studentId]);
            $u = $used->fetch();
            $voided = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND status = 'voided'");
            $voided->execute([$quizId, $studentId]);
            if ((int) $u['used'] - (int) $voided->fetchColumn() >= (int) $quiz['max_attempts']) {
                $pdo->rollBack();
                return ['result' => 'limit', 'attempt_id' => null];
            }

            $deadline = self::deadlineFor($now, $quiz['closes_at'], $quiz['duration_min'] !== null ? (int) $quiz['duration_min'] : null);
            $ins = $pdo->prepare(
                "INSERT INTO quiz_attempts (quiz_id, student_id, attempt_no, status, started_at, deadline_at, last_seen_at)
                 VALUES (?, ?, ?, 'in_progress', ?, ?, ?)"
            );
            $ins->execute([$quizId, $studentId, (int) $u['last_no'] + 1, $now, $deadline, $now]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return ['result' => 'started', 'attempt_id' => $id];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Duplicate attempt number (the lock should prevent this): hand back the running attempt.
            if ($e->getCode() === '23000') {
                $again = self::runningAttemptId($studentId, $quizId);
                if ($again !== null) {
                    return ['result' => 'resumed', 'attempt_id' => $again];
                }
            }
            throw $e;
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /** Pure rule: the earlier of now + duration and the quiz's closing time (all UTC strings). */
    public static function deadlineFor(string $nowUtc, string $closesAtUtc, ?int $durationMin): string
    {
        $utc = new DateTimeZone('UTC');
        $close = new DateTimeImmutable($closesAtUtc, $utc);
        if ($durationMin === null || $durationMin <= 0) {
            return $close->format('Y-m-d H:i:s');
        }
        $byDuration = (new DateTimeImmutable($nowUtc, $utc))->modify('+' . $durationMin . ' minutes');
        return ($byDuration < $close ? $byDuration : $close)->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ what the browser receives

    /**
     * The attempt as the student's browser may see it, or null if it is not this student's running attempt.
     * Explicit columns only. Never is_correct, rubric, scores, or short-answer keys.
     */
    public static function payload(int $attemptId, int $studentId): ?array
    {
        $a = db()->prepare(
            "SELECT a.id, a.quiz_id, a.attempt_no, a.status, a.started_at, a.deadline_at, a.last_seen_at,
                    q.title, q.instructions, q.max_attempts,
                    c.name AS class_name, c.course_code, c.section
               FROM quiz_attempts a
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
              WHERE a.id = ? AND a.student_id = ?"
        );
        $a->execute([$attemptId, $studentId]);
        $att = $a->fetch();
        if ($att === false) {
            return null;
        }

        $qs = db()->prepare(
            'SELECT id, position, type, prompt, points FROM quiz_questions WHERE quiz_id = ? ORDER BY position'
        );
        $qs->execute([(int) $att['quiz_id']]);
        $questions = $qs->fetchAll();

        $choiceIds = [];
        foreach ($questions as $q) {
            if (in_array($q['type'], self::CHOICE_TYPES, true)) {
                $choiceIds[] = (int) $q['id'];
            }
        }
        $optsByQ = [];
        if ($choiceIds) {
            $in = implode(',', array_fill(0, count($choiceIds), '?'));
            $os = db()->prepare(
                "SELECT id, quiz_question_id, label, position FROM quiz_question_options
                  WHERE quiz_question_id IN ($in) ORDER BY quiz_question_id, position"
            );
            $os->execute($choiceIds);
            foreach ($os->fetchAll() as $o) {
                $optsByQ[(int) $o['quiz_question_id']][] = [
                    'id'       => (int) $o['id'],
                    'label'    => (string) $o['label'],
                    'position' => (int) $o['position'],
                ];
            }
        }

        $outQ = [];
        foreach ($questions as $q) {
            $outQ[] = [
                'id'       => (int) $q['id'],
                'position' => (int) $q['position'],
                'type'     => (string) $q['type'],
                'prompt'   => (string) $q['prompt'],
                'points'   => (float) $q['points'],
                'options'  => $optsByQ[(int) $q['id']] ?? [],
            ];
        }

        $rs = db()->prepare(
            'SELECT id, quiz_question_id, answer_text, revision FROM responses WHERE attempt_id = ?'
        );
        $rs->execute([$attemptId]);
        $respRows = $rs->fetchAll();
        $respIds = array_map(static fn($r) => (int) $r['id'], $respRows);
        $pickedByResp = [];
        if ($respIds) {
            $inR = implode(',', array_fill(0, count($respIds), '?'));
            $ros = db()->prepare(
                "SELECT response_id, quiz_question_option_id FROM response_options
                  WHERE response_id IN ($inR) ORDER BY quiz_question_option_id"
            );
            $ros->execute($respIds);
            foreach ($ros->fetchAll() as $ro) {
                $pickedByResp[(int) $ro['response_id']][] = (int) $ro['quiz_question_option_id'];
            }
        }
        $responses = [];
        foreach ($respRows as $r) {
            $qid = (int) $r['quiz_question_id'];
            $responses[$qid] = [
                'answer_text' => (string) ($r['answer_text'] ?? ''),
                'option_ids'  => $pickedByResp[(int) $r['id']] ?? [],
                'revision'    => (int) $r['revision'],
            ];
        }

        $classLabel = trim(($att['course_code'] ?? '') . (($att['section'] ?? '') !== '' ? ', Section ' . $att['section'] : ''), ', ');
        if ($classLabel === '') {
            $classLabel = (string) $att['class_name'];
        }

        $remaining = max(0, (new DateTimeImmutable($att['deadline_at'], new DateTimeZone('UTC')))->getTimestamp() - time());
        return [
            'attempt' => [
                'id'                => (int) $att['id'],
                'attempt_no'        => (int) $att['attempt_no'],
                'max_attempts'      => (int) $att['max_attempts'],
                'status'            => (string) $att['status'],
                'deadline_at'       => (string) $att['deadline_at'],
                'deadline_manila'   => to_manila($att['deadline_at'], 'H:i'),
                'saved_manila'      => $att['last_seen_at'] !== null ? to_manila((string) $att['last_seen_at'], 'j M Y, H:i:s') : '',
                'remaining_seconds' => $att['status'] === 'in_progress' ? $remaining : 0,
            ],
            'quiz' => [
                'id'           => (int) $att['quiz_id'],
                'title'        => (string) $att['title'],
                'instructions' => (string) ($att['instructions'] ?? ''),
                'class_label'  => $classLabel,
            ],
            'questions' => $outQ,
            'responses' => (object) $responses,
        ];
    }

    // ------------------------------------------------------------------ autosave & heartbeat (Day 13)

    /**
     * Autosave answers and/or record a heartbeat on an attempt.
     *
     * Rules enforced here:
     *  1. Transaction with row lock (`SELECT ... FOR UPDATE`) on `quiz_attempts`.
     *  2. Ownership check: attempt must belong to `$studentId`, else return `not_found`.
     *  3. Status & 15-second grace window: attempt must be `in_progress` and `now <= deadline_at + 15 seconds`.
     *  4. Question & option ownership: only questions belonging to this attempt's `quiz_id` are accepted;
     *     for choice questions, only option IDs belonging to that `quiz_question_id` are accepted (single/true_false truncated to 1).
     *  5. Revision counter: stale updates (`revision <= stored revision`) are ignored so a delayed retry never overwrites a newer answer.
     *  6. Heartbeat: updates `last_seen_at = now` on every valid call (even when `answers` is empty).
     *
     * @param array<int|string, mixed> $incomingAnswers
     * @return array{
     *   result: 'ok'|'not_found'|'closed',
     *   attempt_id?: int,
     *   status?: string,
     *   remaining_seconds?: int,
     *   saved_manila?: string,
     *   saved_revisions?: array<int, int>
     * }
     */
    public static function autosave(int $attemptId, int $studentId, array $incomingAnswers = [], ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? utc_now();
        $utc = new DateTimeZone('UTC');
        $nowDt = new DateTimeImmutable($now, $utc);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // 1 & 2. Lock attempt row and verify ownership.
            $lock = $pdo->prepare(
                'SELECT id, quiz_id, status, deadline_at, last_seen_at
                   FROM quiz_attempts
                  WHERE id = ? AND student_id = ?
                  FOR UPDATE'
            );
            $lock->execute([$attemptId, $studentId]);
            $att = $lock->fetch();
            if ($att === false) {
                $pdo->rollBack();
                return ['result' => 'not_found'];
            }

            $deadlineDt = new DateTimeImmutable((string) $att['deadline_at'], $utc);
            $remaining = max(0, $deadlineDt->getTimestamp() - $nowDt->getTimestamp());

            // 3. Must be in_progress and within the 15-second grace window after deadline_at.
            $graceDeadlineTs = $deadlineDt->getTimestamp() + 15;
            if ($att['status'] !== 'in_progress' || $nowDt->getTimestamp() > $graceDeadlineTs) {
                $pdo->rollBack();
                return [
                    'result'            => 'closed',
                    'attempt_id'        => (int) $att['id'],
                    'status'            => (string) $att['status'],
                    'remaining_seconds' => 0,
                    'saved_manila'      => $att['last_seen_at'] !== null ? to_manila((string) $att['last_seen_at'], 'j M Y, H:i:s') : '',
                    'saved_revisions'   => [],
                ];
            }

            $quizId = (int) $att['quiz_id'];

            // Load valid questions and their types for this quiz.
            $qStmt = $pdo->prepare('SELECT id, type FROM quiz_questions WHERE quiz_id = ?');
            $qStmt->execute([$quizId]);
            $validQuestions = [];
            foreach ($qStmt->fetchAll() as $qRow) {
                $validQuestions[(int) $qRow['id']] = (string) $qRow['type'];
            }

            // Load currently stored responses for this attempt (already protected by the attempt row lock).
            $rStmt = $pdo->prepare('SELECT id, quiz_question_id, revision FROM responses WHERE attempt_id = ?');
            $rStmt->execute([$attemptId]);
            $storedResponses = [];
            foreach ($rStmt->fetchAll() as $rRow) {
                $storedResponses[(int) $rRow['quiz_question_id']] = [
                    'id'       => (int) $rRow['id'],
                    'revision' => (int) $rRow['revision'],
                ];
            }

            // Normalize incoming answers: accept either a map keyed by question_id or a list of answer items.
            $normalized = [];
            foreach ($incomingAnswers as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qid = isset($item['question_id']) ? (int) $item['question_id'] : (int) $key;
                if ($qid > 0) {
                    $normalized[$qid] = $item;
                }
            }

            $savedRevisions = [];
            foreach ($storedResponses as $qid => $sr) {
                $savedRevisions[$qid] = $sr['revision'];
            }

            foreach ($normalized as $qid => $item) {
                // 4a. Ignore any question ID that does not belong to this quiz.
                if (!isset($validQuestions[$qid])) {
                    continue;
                }

                $qType = $validQuestions[$qid];
                $incomingRev = isset($item['revision']) ? (int) $item['revision'] : 0;
                if ($incomingRev <= 0) {
                    continue;
                }

                $existing = $storedResponses[$qid] ?? null;
                // 5. Revision check: ignore stale or duplicate saves (revision <= stored).
                if ($existing !== null && $incomingRev <= $existing['revision']) {
                    $savedRevisions[$qid] = $existing['revision'];
                    continue;
                }

                $isChoice = in_array($qType, self::CHOICE_TYPES, true);
                $answerText = null;
                $validOptionIds = [];

                if ($isChoice) {
                    $rawOpts = isset($item['option_ids']) && is_array($item['option_ids']) ? $item['option_ids'] : [];
                    $candidateIds = [];
                    foreach ($rawOpts as $oid) {
                        $oidInt = (int) $oid;
                        if ($oidInt > 0) {
                            $candidateIds[$oidInt] = $oidInt;
                        }
                    }
                    if ($candidateIds) {
                        $inSql = implode(',', array_fill(0, count($candidateIds), '?'));
                        $optStmt = $pdo->prepare(
                            "SELECT id FROM quiz_question_options
                              WHERE quiz_question_id = ? AND id IN ($inSql)
                              ORDER BY position, id"
                        );
                        $optStmt->execute(array_merge([$qid], array_values($candidateIds)));
                        $validOptionIds = array_map('intval', $optStmt->fetchAll(PDO::FETCH_COLUMN));
                        if ($qType !== 'multi' && count($validOptionIds) > 1) {
                            $validOptionIds = [$validOptionIds[0]];
                        }
                    }
                } else {
                    $rawText = $item['answer_text'] ?? '';
                    $answerText = is_string($rawText) || is_numeric($rawText) ? (string) $rawText : '';
                }

                if ($existing === null) {
                    $insR = $pdo->prepare(
                        'INSERT INTO responses (attempt_id, quiz_question_id, answer_text, revision)
                         VALUES (?, ?, ?, ?)'
                    );
                    $insR->execute([$attemptId, $qid, $answerText, $incomingRev]);
                    $responseId = (int) $pdo->lastInsertId();
                    $storedResponses[$qid] = ['id' => $responseId, 'revision' => $incomingRev];
                } else {
                    $responseId = $existing['id'];
                    $updR = $pdo->prepare(
                        'UPDATE responses SET answer_text = ?, revision = ? WHERE id = ?'
                    );
                    $updR->execute([$answerText, $incomingRev, $responseId]);
                    $storedResponses[$qid]['revision'] = $incomingRev;
                }

                if ($isChoice) {
                    $pdo->prepare('DELETE FROM response_options WHERE response_id = ?')->execute([$responseId]);
                    if ($validOptionIds) {
                        $insOpt = $pdo->prepare(
                            'INSERT INTO response_options (response_id, quiz_question_option_id) VALUES (?, ?)'
                        );
                        foreach ($validOptionIds as $oid) {
                            $insOpt->execute([$responseId, $oid]);
                        }
                    }
                }

                $savedRevisions[$qid] = $incomingRev;
            }

            // 6. Heartbeat: update last_seen_at on every valid autosave/heartbeat call.
            $pdo->prepare('UPDATE quiz_attempts SET last_seen_at = ? WHERE id = ?')
                ->execute([$now, $attemptId]);

            $pdo->commit();

            return [
                'result'            => 'ok',
                'attempt_id'        => $attemptId,
                'status'            => 'in_progress',
                'remaining_seconds' => $remaining,
                'saved_manila'      => to_manila($now, 'j M Y, H:i:s'),
                'saved_revisions'   => $savedRevisions,
            ];
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    // ------------------------------------------------------------------ submit, finalize, overdue sweep & receipt (Day 14)

    /**
     * Submit an attempt on behalf of the student (or auto-finalize if the deadline has passed).
     *
     * Rules enforced here:
     *  1. Idempotent: if the attempt is already `submitted` or `graded`, returns `already_submitted` with the current status.
     *  2. Optional final answers payload (`$finalAnswers`) is saved within the same transaction if still within the 15s grace window.
     *  3. Objective questions (`single`, `true_false`, `multi`) are scored automatically (`score_multi` for multi-select).
     *  4. Written questions (`short`, `essay`):
     *      - Blank written responses (missing or whitespace-only) are auto-zeroed (`auto_points = 0`, `points_awarded = 0`, `graded_at = now`).
     *      - Short answers matching an accepted answer key (case-insensitive, trimmed) are auto-scored full points.
     *      - Non-blank written responses that are not auto-scored remain pending (`points_awarded IS NULL`) for teacher grading.
     *  5. Attempt status transitions to `submitted` if any non-blank written response is still ungraded, else `graded`.
     *  6. `score` is recomputed as `SUM(COALESCE(points_awarded, 0))` across the attempt's responses.
     *  7. If `score_release = 'on_submission'` and the attempt is `graded`, `score_released_at` is stamped immediately;
     *     likewise for `review_release = 'on_submission'`.
     *
     * @param array<int|string, mixed> $finalAnswers
     * @return array{
     *   result: 'submitted'|'already_submitted'|'voided'|'not_found',
     *   attempt_id?: int,
     *   status?: string,
     *   submit_reason?: string,
     *   submitted_at?: string,
     *   submitted_manila?: string,
     *   reference?: string
     * }
     */
    public static function submit(
        int $attemptId,
        int $studentId,
        array $finalAnswers = [],
        string $reason = 'student',
        ?string $nowUtc = null
    ): array {
        $now = $nowUtc ?? utc_now();
        $utc = new DateTimeZone('UTC');
        $nowDt = new DateTimeImmutable($now, $utc);

        if (!in_array($reason, ['student', 'timeout', 'teacher'], true)) {
            $reason = 'student';
        }

        // If the browser sent final answers along with the submit request, save them first if still within grace window.
        if ($finalAnswers !== []) {
            self::autosave($attemptId, $studentId, $finalAnswers, $now);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT a.*, q.score_release, q.review_release, q.release_at,
                        c.course_code, u.full_name AS student_name, u.username AS student_username
                   FROM quiz_attempts a
                   JOIN quizzes q ON q.id = a.quiz_id
                   JOIN classes c ON c.id = q.class_id
                   JOIN users u ON u.id = a.student_id
                  WHERE a.id = ? AND a.student_id = ?
                  FOR UPDATE'
            );
            $lock->execute([$attemptId, $studentId]);
            $att = $lock->fetch();
            if ($att === false) {
                $pdo->rollBack();
                return ['result' => 'not_found'];
            }

            if ($att['status'] === 'voided') {
                $pdo->rollBack();
                return [
                    'result'     => 'voided',
                    'attempt_id' => (int) $att['id'],
                    'status'     => 'voided',
                ];
            }

            // Idempotent: already submitted or graded — return success without re-running or duplicating.
            if ($att['status'] === 'submitted' || $att['status'] === 'graded') {
                $pdo->commit();
                $subAt = (string) ($att['submitted_at'] ?? $now);
                return [
                    'result'           => 'already_submitted',
                    'attempt_id'       => (int) $att['id'],
                    'status'           => (string) $att['status'],
                    'submit_reason'    => (string) ($att['submit_reason'] ?? 'student'),
                    'submitted_at'     => $subAt,
                    'submitted_manila' => to_manila($subAt, 'j M Y, H:i:s'),
                    'reference'        => self::referenceCode($att),
                ];
            }

            // Determine whether the submission happened after the deadline.
            $deadlineDt = new DateTimeImmutable((string) $att['deadline_at'], $utc);
            $effectiveReason = $reason;
            $submittedAt = $now;
            if ($nowDt > $deadlineDt && $reason === 'timeout') {
                $submittedAt = $deadlineDt->format('Y-m-d H:i:s');
            } elseif ($nowDt->getTimestamp() > $deadlineDt->getTimestamp() + 15) {
                $effectiveReason = 'timeout';
                $submittedAt = $deadlineDt->format('Y-m-d H:i:s');
            }

            $finalState = self::finalizeInTransaction($pdo, $att, $effectiveReason, $submittedAt, $now);
            $pdo->commit();

            $att['submitted_at'] = $submittedAt;
            return [
                'result'           => 'submitted',
                'attempt_id'       => (int) $att['id'],
                'status'           => $finalState['status'],
                'submit_reason'    => $effectiveReason,
                'submitted_at'     => $submittedAt,
                'submitted_manila' => to_manila($submittedAt, 'j M Y, H:i:s'),
                'reference'        => self::referenceCode($att),
            ];
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Sweeps and finalizes any overdue `in_progress` attempts whose 15-second grace window has elapsed:
     *   `status = 'in_progress' AND deadline_at < UTC_TIMESTAMP() - INTERVAL 15 SECOND`
     * Stamps `submitted_at = deadline_at` and `submit_reason = 'timeout'`.
     *
     * If `$quizId` is provided, sweeps that quiz; if `$teacherId` is provided, sweeps all quizzes in that teacher's classes;
     * if both are null, sweeps all overdue attempts across the system.
     *
     * @return int Number of overdue attempts finalized.
     */
    public static function finalizeOverdue(?int $quizId = null, ?int $teacherId = null, ?string $nowUtc = null): int
    {
        $now = $nowUtc ?? utc_now();
        $utc = new DateTimeZone('UTC');
        $cutoff = (new DateTimeImmutable($now, $utc))->modify('-15 seconds')->format('Y-m-d H:i:s');

        $pdo = db();
        $where = ["a.status = 'in_progress'", 'a.deadline_at < ?'];
        $args  = [$cutoff];

        if ($quizId !== null) {
            $where[] = 'a.quiz_id = ?';
            $args[]  = $quizId;
        }
        if ($teacherId !== null) {
            $where[] = 'c.teacher_id = ?';
            $args[]  = $teacherId;
        }

        $stmt = $pdo->prepare(
            'SELECT a.id, a.student_id
               FROM quiz_attempts a
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.deadline_at ASC, a.id ASC'
        );
        $stmt->execute($args);
        $overdueRows = $stmt->fetchAll();

        $count = 0;
        foreach ($overdueRows as $row) {
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare(
                    'SELECT a.*, q.score_release, q.review_release, q.release_at
                       FROM quiz_attempts a
                       JOIN quizzes q ON q.id = a.quiz_id
                      WHERE a.id = ? AND a.status = \'in_progress\'
                      FOR UPDATE'
                );
                $lock->execute([(int) $row['id']]);
                $att = $lock->fetch();
                if ($att === false) {
                    $pdo->rollBack();
                    continue;
                }
                // Stamp submitted_at = deadline_at per §0 item 3 & §6.5.
                self::finalizeInTransaction($pdo, $att, 'timeout', (string) $att['deadline_at'], $now);
                $pdo->commit();
                $count++;
            } catch (Throwable $t) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $t;
            }
        }

        return $count;
    }

    /**
     * Core finalization logic executed inside an active transaction where `quiz_attempts` row `$att` is locked `FOR UPDATE`.
     *
     * @return array{status: string, score: float}
     */
    private static function finalizeInTransaction(
        PDO $pdo,
        array $att,
        string $reason,
        string $submittedAtUtc,
        string $nowUtc
    ): array {
        $attemptId = (int) $att['id'];
        $quizId    = (int) $att['quiz_id'];

        // 1. Load all questions for this quiz.
        $qStmt = $pdo->prepare(
            'SELECT id, type, points FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC'
        );
        $qStmt->execute([$quizId]);
        $questions = $qStmt->fetchAll();

        // 2. Load all options (including is_correct for objective & short-answer accepted keys).
        $optStmt = $pdo->prepare(
            'SELECT o.id, o.quiz_question_id, o.label, o.is_correct
               FROM quiz_question_options o
               JOIN quiz_questions qq ON qq.id = o.quiz_question_id
              WHERE qq.quiz_id = ?
              ORDER BY o.quiz_question_id, o.position'
        );
        $optStmt->execute([$quizId]);
        $correctIdsByQ   = [];
        $acceptedTextByQ = [];
        foreach ($optStmt->fetchAll() as $o) {
            $qid = (int) $o['quiz_question_id'];
            if ((int) $o['is_correct'] === 1) {
                $correctIdsByQ[$qid][] = (int) $o['id'];
                $acceptedTextByQ[$qid][] = mb_strtolower(trim((string) $o['label']));
            }
        }

        // 3. Load existing responses and picked option IDs for this attempt.
        $rStmt = $pdo->prepare(
            'SELECT id, quiz_question_id, answer_text, revision, points_awarded, graded_by
               FROM responses WHERE attempt_id = ?'
        );
        $rStmt->execute([$attemptId]);
        $respByQ = [];
        $respIds = [];
        foreach ($rStmt->fetchAll() as $r) {
            $qid = (int) $r['quiz_question_id'];
            $respByQ[$qid] = $r;
            $respIds[] = (int) $r['id'];
        }

        $pickedByResp = [];
        if ($respIds) {
            $inR = implode(',', array_fill(0, count($respIds), '?'));
            $roStmt = $pdo->prepare(
                "SELECT response_id, quiz_question_option_id FROM response_options WHERE response_id IN ($inR)"
            );
            $roStmt->execute($respIds);
            foreach ($roStmt->fetchAll() as $ro) {
                $pickedByResp[(int) $ro['response_id']][] = (int) $ro['quiz_question_option_id'];
            }
        }

        $insResp = $pdo->prepare(
            'INSERT INTO responses (attempt_id, quiz_question_id, answer_text, revision, auto_points, points_awarded, graded_at)
             VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $updResp = $pdo->prepare(
            'UPDATE responses SET auto_points = ?, points_awarded = ?, graded_at = ? WHERE id = ?'
        );

        $hasUngradedWritten = false;
        $totalScore = 0.0;

        foreach ($questions as $q) {
            $qid    = (int) $q['id'];
            $qType  = (string) $q['type'];
            $qPts   = (float) $q['points'];
            $existing = $respByQ[$qid] ?? null;

            if (in_array($qType, self::CHOICE_TYPES, true)) {
                // Objective question: single, true_false, or multi-select.
                $correctIds  = $correctIdsByQ[$qid] ?? [];
                $selectedIds = $existing !== null ? ($pickedByResp[(int) $existing['id']] ?? []) : [];

                if ($qType === 'multi') {
                    $awarded = score_multi($correctIds, $selectedIds, $qPts);
                } else {
                    $awarded = (count($selectedIds) === 1 && count($correctIds) === 1 && (int) $selectedIds[0] === (int) $correctIds[0])
                        ? $qPts
                        : 0.0;
                }

                if ($existing === null) {
                    $insResp->execute([$attemptId, $qid, null, $awarded, $awarded, $nowUtc]);
                } else {
                    // Preserve manual override if a teacher already graded it; otherwise set points_awarded = auto_points.
                    $finalPts = $existing['graded_by'] !== null && $existing['points_awarded'] !== null
                        ? (float) $existing['points_awarded']
                        : $awarded;
                    $updResp->execute([$awarded, $finalPts, $nowUtc, (int) $existing['id']]);
                    $awarded = $finalPts;
                }
                $totalScore += $awarded;
            } else {
                // Written question: short or essay.
                $rawText = $existing !== null ? (string) ($existing['answer_text'] ?? '') : '';
                $trimmed = trim($rawText);

                if ($trimmed === '') {
                    // Blank written answers are auto-zeroed immediately (§3 & §6.4).
                    if ($existing === null) {
                        $insResp->execute([$attemptId, $qid, '', 0.0, 0.0, $nowUtc]);
                    } else {
                        $updResp->execute([0.0, 0.0, $nowUtc, (int) $existing['id']]);
                    }
                } elseif ($existing !== null && $existing['points_awarded'] !== null) {
                    // Already graded.
                    $totalScore += (float) $existing['points_awarded'];
                } else {
                    // Non-blank written response requires teacher grading (responses row already exists).
                    $hasUngradedWritten = true;
                }
            }
        }

        $status = $hasUngradedWritten ? 'submitted' : 'graded';
        $totalScore = round($totalScore, 2);

        // Determine automatic release timestamps when configured for 'on_submission' or 'scheduled' past due.
        $scoreReleasedAt  = $att['score_released_at'] ?? null;
        $reviewReleasedAt = $att['review_released_at'] ?? null;

        $scoreReleaseMode  = (string) ($att['score_release'] ?? 'manual');
        $reviewReleaseMode = (string) ($att['review_release'] ?? 'manual');
        $releaseAtUtc      = $att['release_at'] ?? null;

        if ($status === 'graded' && $scoreReleasedAt === null) {
            if ($scoreReleaseMode === 'on_submission') {
                $scoreReleasedAt = $nowUtc;
            } elseif ($scoreReleaseMode === 'scheduled' && $releaseAtUtc !== null && $releaseAtUtc <= $nowUtc) {
                $scoreReleasedAt = $releaseAtUtc;
            }
        }
        if ($status === 'graded' && $reviewReleasedAt === null) {
            if ($reviewReleaseMode === 'on_submission') {
                $reviewReleasedAt = $nowUtc;
            } elseif ($reviewReleaseMode === 'scheduled' && $releaseAtUtc !== null && $releaseAtUtc <= $nowUtc) {
                $reviewReleasedAt = $releaseAtUtc;
            }
        }

        $pdo->prepare(
            "UPDATE quiz_attempts
                SET status = ?,
                    submit_reason = ?,
                    submitted_at = ?,
                    score = ?,
                    score_released_at = ?,
                    review_released_at = ?
              WHERE id = ? AND status = 'in_progress'"
        )->execute([
            $status,
            $reason,
            $submittedAtUtc,
            $totalScore,
            $scoreReleasedAt,
            $reviewReleasedAt,
            $attemptId,
        ]);

        return [
            'status' => $status,
            'score'  => $totalScore,
        ];
    }

    /**
     * Builds a deterministic, human-readable submission receipt reference (e.g. `QV-IT301-1029-AS01`).
     */
    public static function referenceCode(array $att): string
    {
        $course = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($att['course_code'] ?? '')));
        if ($course === '') {
            $course = 'QZ' . (int) ($att['quiz_id'] ?? 0);
        } else {
            $course = substr($course, 0, 6);
        }

        $subUtc = (string) ($att['submitted_at'] ?? $att['started_at'] ?? utc_now());
        $mmdd = to_manila($subUtc, 'md');
        if ($mmdd === '') {
            $mmdd = '0000';
        }

        $initials = '';
        $fullName = trim((string) ($att['student_name'] ?? ''));
        if ($fullName !== '') {
            foreach (preg_split('/\s+/u', $fullName) ?: [] as $part) {
                $ch = preg_replace('/[^A-Z0-9]/', '', strtoupper(mb_substr($part, 0, 1)));
                if ($ch !== '') {
                    $initials .= $ch;
                }
            }
        }
        if ($initials === '') {
            $initials = 'ST';
        }
        $initials = substr($initials, 0, 2);

        $num = sprintf('%02d', ((int) ($att['id'] ?? 1)) % 100);
        return 'QV-' . $course . '-' . $mmdd . '-' . $initials . $num;
    }

    /**
     * Data for the student submission receipt & released result screen (Day 14 & Day 16).
     *
     * Enforces strict visibility rules:
     *  - Only the owning student may view the attempt (`student_id = ?`), else returns `null` (404).
     *  - If the attempt is still `in_progress` and past its deadline + 15s grace, auto-finalizes it first.
     *  - `score` and `percentage` are ONLY included when `score_released_at IS NOT NULL` (and `<= now`).
     *  - Answer review (`review_questions` with prompts, submitted answers, correct keys, points_awarded, feedback)
     *    is ONLY included when `review_released_at IS NOT NULL` (and `<= now`).
     */
    public static function receipt(int $attemptId, int $studentId, ?string $nowUtc = null): ?array
    {
        $now = $nowUtc ?? utc_now();
        $pdo = db();

        $stmt = $pdo->prepare(
            'SELECT a.*,
                    q.title AS quiz_title, q.max_attempts, q.score_policy,
                    q.score_release, q.review_release, q.release_at, q.closes_at,
                    c.name AS class_name, c.course_code, c.section,
                    t.full_name AS teacher_name,
                    s.full_name AS student_name, s.username AS student_username
               FROM quiz_attempts a
               JOIN quizzes q ON q.id = a.quiz_id
               JOIN classes c ON c.id = q.class_id
               JOIN users t ON t.id = c.teacher_id
               JOIN users s ON s.id = a.student_id
              WHERE a.id = ? AND a.student_id = ?'
        );
        $stmt->execute([$attemptId, $studentId]);
        $att = $stmt->fetch();
        if ($att === false) {
            return null;
        }

        // If still in_progress and overdue, finalize it right away.
        if ($att['status'] === 'in_progress') {
            $utc = new DateTimeZone('UTC');
            $deadlineDt = new DateTimeImmutable((string) $att['deadline_at'], $utc);
            $nowDt      = new DateTimeImmutable($now, $utc);
            if ($nowDt >= $deadlineDt) {
                self::submit($attemptId, $studentId, [], 'timeout', $now);
                $stmt->execute([$attemptId, $studentId]);
                $att = $stmt->fetch();
                if ($att === false) {
                    return null;
                }
            } else {
                // Still actively running and not yet submitted.
                return [
                    'status'     => 'in_progress',
                    'attempt_id' => (int) $att['id'],
                    'quiz_id'    => (int) $att['quiz_id'],
                ];
            }
        }

        $quizId = (int) $att['quiz_id'];

        // Count total questions, total points, and how many questions the student answered vs left unanswered.
        $qStmt = $pdo->prepare(
            'SELECT id, position, type, prompt, points FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC'
        );
        $qStmt->execute([$quizId]);
        $questions = $qStmt->fetchAll();
        $totalQuestions = count($questions);
        $totalPoints = 0.0;
        foreach ($questions as $q) {
            $totalPoints += (float) $q['points'];
        }

        $rStmt = $pdo->prepare(
            'SELECT id, quiz_question_id, answer_text, points_awarded, feedback
               FROM responses WHERE attempt_id = ?'
        );
        $rStmt->execute([$attemptId]);
        $respByQ = [];
        $respIds = [];
        foreach ($rStmt->fetchAll() as $r) {
            $respByQ[(int) $r['quiz_question_id']] = $r;
            $respIds[] = (int) $r['id'];
        }

        $pickedByResp = [];
        if ($respIds) {
            $inR = implode(',', array_fill(0, count($respIds), '?'));
            $roStmt = $pdo->prepare(
                "SELECT response_id, quiz_question_option_id FROM response_options WHERE response_id IN ($inR)"
            );
            $roStmt->execute($respIds);
            foreach ($roStmt->fetchAll() as $ro) {
                $pickedByResp[(int) $ro['response_id']][] = (int) $ro['quiz_question_option_id'];
            }
        }

        $answeredCount = 0;
        foreach ($questions as $q) {
            $qid   = (int) $q['id'];
            $qType = (string) $q['type'];
            $r     = $respByQ[$qid] ?? null;
            if ($r === null) {
                continue;
            }
            if (in_array($qType, self::CHOICE_TYPES, true)) {
                if (!empty($pickedByResp[(int) $r['id']])) {
                    $answeredCount++;
                }
            } else {
                if (trim((string) ($r['answer_text'] ?? '')) !== '') {
                    $answeredCount++;
                }
            }
        }
        $unansweredCount = max(0, $totalQuestions - $answeredCount);

        // Check scheduled release timestamps if not yet stamped.
        $scoreReleasedAt  = $att['score_released_at'];
        $reviewReleasedAt = $att['review_released_at'];
        if ($att['status'] === 'graded') {
            if ($scoreReleasedAt === null && $att['score_release'] === 'scheduled' && $att['release_at'] !== null && $att['release_at'] <= $now) {
                $scoreReleasedAt = (string) $att['release_at'];
            }
            if ($reviewReleasedAt === null && $att['review_release'] === 'scheduled' && $att['release_at'] !== null && $att['release_at'] <= $now) {
                $reviewReleasedAt = (string) $att['release_at'];
            }
        }

        $scoreReleased  = $scoreReleasedAt !== null && $scoreReleasedAt <= $now;
        $reviewReleased = $reviewReleasedAt !== null && $reviewReleasedAt <= $now;

        $classLabel = trim(($att['course_code'] ?? '') . (($att['section'] ?? '') !== '' ? ', Section ' . $att['section'] : ''), ', ');
        if ($classLabel === '') {
            $classLabel = (string) $att['class_name'];
        }

        $subAt = (string) ($att['submitted_at'] ?? $att['deadline_at']);

        $out = [
            'status'                => (string) $att['status'],
            'attempt_id'            => (int) $att['id'],
            'quiz_id'               => $quizId,
            'quiz_title'            => (string) $att['quiz_title'],
            'class_label'           => $classLabel,
            'teacher_name'          => (string) $att['teacher_name'],
            'attempt_no'            => (int) $att['attempt_no'],
            'max_attempts'          => (int) $att['max_attempts'],
            'score_policy'          => (string) $att['score_policy'],
            'submit_reason'         => (string) ($att['submit_reason'] ?? 'student'),
            'submitted_at'          => $subAt,
            'submitted_manila'      => to_manila($subAt, 'j M Y, H:i:s'),
            'reference'             => self::referenceCode($att),
            'answered_count'        => $answeredCount,
            'unanswered_count'      => $unansweredCount,
            'total_questions'       => $totalQuestions,
            'total_points'          => round($totalPoints, 2),
            'score_release_mode'    => (string) $att['score_release'],
            'review_release_mode'   => (string) $att['review_release'],
            'release_at_manila'     => $att['release_at'] !== null ? to_manila((string) $att['release_at'], 'l, j M Y \a\t H:i') : '',
            'score_released'        => $scoreReleased,
            'score_released_manila' => $scoreReleased ? to_manila((string) $scoreReleasedAt, 'j M Y, H:i') : '',
            'review_released'       => $reviewReleased,
            'review_released_manila'=> $reviewReleased ? to_manila((string) $reviewReleasedAt, 'j M Y, H:i') : '',
            'score'                 => $scoreReleased && $att['score'] !== null ? round((float) $att['score'], 2) : null,
            'percentage'            => $scoreReleased && $att['score'] !== null && $totalPoints > 0
                ? (int) round(((float) $att['score'] / $totalPoints) * 100)
                : null,
            'review_items'          => [],
        ];

        // Only load and expose answer keys, submitted answers, and feedback if review is released!
        if ($reviewReleased) {
            $optStmt = $pdo->prepare(
                'SELECT o.id, o.quiz_question_id, o.label, o.is_correct
                   FROM quiz_question_options o
                   JOIN quiz_questions qq ON qq.id = o.quiz_question_id
                  WHERE qq.quiz_id = ?
                  ORDER BY o.quiz_question_id, o.position'
            );
            $optStmt->execute([$quizId]);
            $optsByQ = [];
            foreach ($optStmt->fetchAll() as $o) {
                $optsByQ[(int) $o['quiz_question_id']][] = [
                    'id'         => (int) $o['id'],
                    'label'      => (string) $o['label'],
                    'is_correct' => (int) $o['is_correct'] === 1,
                ];
            }

            $reviewItems = [];
            foreach ($questions as $q) {
                $qid   = (int) $q['id'];
                $qType = (string) $q['type'];
                $r     = $respByQ[$qid] ?? null;
                $pickedIds = $r !== null ? ($pickedByResp[(int) $r['id']] ?? []) : [];
                $opts = $optsByQ[$qid] ?? [];

                $studentLabels = [];
                $correctLabels = [];
                foreach ($opts as $o) {
                    if (in_array($o['id'], $pickedIds, true)) {
                        $studentLabels[] = $o['label'];
                    }
                    if ($o['is_correct']) {
                        $correctLabels[] = $o['label'];
                    }
                }

                $studentAnswer = in_array($qType, self::CHOICE_TYPES, true)
                    ? ($studentLabels ? implode(', ', $studentLabels) : '')
                    : trim((string) ($r['answer_text'] ?? ''));

                $correctAnswer = $qType === 'essay'
                    ? ''
                    : implode(' / ', $correctLabels);

                $reviewItems[] = [
                    'position'       => (int) $q['position'],
                    'type'           => $qType,
                    'prompt'         => (string) $q['prompt'],
                    'max_points'     => round((float) $q['points'], 2),
                    'points_awarded' => $r !== null && $r['points_awarded'] !== null ? round((float) $r['points_awarded'], 2) : 0.0,
                    'student_answer' => $studentAnswer,
                    'correct_answer' => $correctAnswer,
                    'feedback'       => $r !== null && $r['feedback'] !== null ? (string) $r['feedback'] : '',
                ];
            }
            $out['review_items'] = $reviewItems;
        }

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    private static function visibleQuiz(int $studentId, int $quizId): ?array
    {
        $stmt = db()->prepare(
            "SELECT q.id, q.class_id, q.title, q.instructions, q.opens_at, q.closes_at, q.duration_min,
                    q.max_attempts, q.score_release, q.review_release, q.release_at,
                    c.name AS class_name, c.course_code, c.section, u.full_name AS teacher_name
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id AND c.archived_at IS NULL
               JOIN class_memberships m ON m.class_id = q.class_id AND m.student_id = ? AND m.removed_at IS NULL
               JOIN users u ON u.id = c.teacher_id
              WHERE q.id = ? AND q.status = 'published'"
        );
        $stmt->execute([$studentId, $quizId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private static function runningAttemptId(int $studentId, int $quizId): ?int
    {
        $stmt = db()->prepare(
            "SELECT id FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress' AND deadline_at > ?
              ORDER BY attempt_no DESC LIMIT 1"
        );
        $stmt->execute([$quizId, $studentId, utc_now()]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Adds phase, attempts used, running attempt (if any), and latest completed attempt ID to a quiz row. */
    private static function withUsage(array $q, int $studentId, string $now): array
    {
        $stmt = db()->prepare(
            "SELECT
               SUM(status <> 'voided') AS used,
               MAX(CASE WHEN status = 'in_progress' AND deadline_at > ? THEN id END) AS running_id,
               MAX(CASE WHEN status = 'in_progress' AND deadline_at > ? THEN deadline_at END) AS running_deadline,
               MAX(CASE WHEN status IN ('submitted', 'graded') THEN id END) AS last_completed_id
             FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?"
        );
        $stmt->execute([$now, $now, (int) $q['id'], $studentId]);
        $u = $stmt->fetch();
        $q['phase']             = quiz_phase($q['opens_at'], $q['closes_at'], $now);
        $q['attempts_used']     = (int) ($u['used'] ?? 0);
        $q['running_id']        = $u['running_id'] !== null ? (int) $u['running_id'] : null;
        $q['running_deadline']  = $u['running_deadline'];
        $q['last_completed_id'] = $u['last_completed_id'] !== null ? (int) $u['last_completed_id'] : null;
        $q['can_start']         = $q['running_id'] !== null
            || ($q['phase'] === 'open' && $q['attempts_used'] < (int) $q['max_attempts']);
        return $q;
    }
}
