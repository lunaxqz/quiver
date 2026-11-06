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

        $stmt = db()->prepare(
            "SELECT q.id, q.class_id, q.title, q.opens_at, q.closes_at, q.duration_min, q.max_attempts
               FROM quizzes q
               JOIN class_memberships m ON m.class_id = q.class_id AND m.student_id = ? AND m.removed_at IS NULL
               JOIN classes c ON c.id = q.class_id AND c.archived_at IS NULL
              WHERE q.status = 'published' AND q.closes_at > ?
              ORDER BY q.closes_at, q.id"
        );
        $stmt->execute([$studentId, $now]);
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

    /** Adds phase, attempts used, and the running attempt (if any) to a quiz row. */
    private static function withUsage(array $q, int $studentId, string $now): array
    {
        $stmt = db()->prepare(
            "SELECT
               SUM(status <> 'voided') AS used,
               MAX(CASE WHEN status = 'in_progress' AND deadline_at > ? THEN id END) AS running_id,
               MAX(CASE WHEN status = 'in_progress' AND deadline_at > ? THEN deadline_at END) AS running_deadline
             FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?"
        );
        $stmt->execute([$now, $now, (int) $q['id'], $studentId]);
        $u = $stmt->fetch();
        $q['phase']            = quiz_phase($q['opens_at'], $q['closes_at'], $now);
        $q['attempts_used']    = (int) ($u['used'] ?? 0);
        $q['running_id']       = $u['running_id'] !== null ? (int) $u['running_id'] : null;
        $q['running_deadline'] = $u['running_deadline'];
        $q['can_start']        = $q['running_id'] !== null
            || ($q['phase'] === 'open' && $q['attempts_used'] < (int) $q['max_attempts']);
        return $q;
    }
}
