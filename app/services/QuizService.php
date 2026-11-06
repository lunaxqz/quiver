<?php
declare(strict_types=1);

/**
 * QuizService handles quiz lifecycle, settings, and teacher operations.
 *
 * Core principles:
 * - Every query enforces class ownership: `classes.teacher_id = ?`
 * - Stored status: draft, published, archived.
 * - Derived phase: scheduled, open, closed (computed via quiz_phase()).
 * - Timezone safety: all stored timestamps are UTC.
 * - Frozen published copies: once published, settings cannot be edited; duplicate as new version instead.
 */
final class QuizService
{
    public const PER_PAGE = 10;

    /**
     * Finds a quiz if it belongs to the given teacher.
     */
    public static function find(int $id, int $teacherId): ?array
    {
        AttemptService::finalizeOverdue($id, $teacherId);

        $stmt = db()->prepare(
            'SELECT q.*, c.name AS class_name, c.subject, c.section, c.course_code
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE q.id = ? AND c.teacher_id = ?'
        );
        $stmt->execute([$id, $teacherId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return self::decorate($row);
    }

    /**
     * Decorates a quiz row with version number, derived phase, formatted window, and counts.
     */
    private static function decorate(array $row): array
    {
        $id = (int) $row['id'];
        $classId = (int) $row['class_id'];

        // Version: trace previous_version_id chain
        $version = 1;
        $prev = $row['previous_version_id'];
        while ($prev !== null) {
            $version++;
            $pStmt = db()->prepare('SELECT previous_version_id FROM quizzes WHERE id = ?');
            $pStmt->execute([(int) $prev]);
            $prev = $pStmt->fetchColumn();
            if ($prev === false) {
                break;
            }
        }
        $row['version'] = $version;

        // Derived phase
        if ($row['status'] === 'draft') {
            $row['phase'] = 'draft';
        } elseif ($row['status'] === 'archived') {
            $row['phase'] = 'archived';
        } else {
            $row['phase'] = quiz_phase($row['opens_at'], $row['closes_at']);
        }

        // Manila window formatted
        $row['window_formatted'] = format_window_manila($row['opens_at'], $row['closes_at']);

        // Submissions & roster counts
        $sStmt = db()->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND status IN ('submitted', 'graded')");
        $sStmt->execute([$id]);
        $row['submitted_count'] = (int) $sStmt->fetchColumn();

        $rStmt = db()->prepare('SELECT COUNT(*) FROM class_memberships WHERE class_id = ?');
        $rStmt->execute([$classId]);
        $row['roster_count'] = (int) $rStmt->fetchColumn();

        // Pending written responses count (for 'Grade · N pending →' link)
        $pStmt = db()->prepare(
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
        $pStmt->execute([$id]);
        $row['pending_written_count'] = (int) $pStmt->fetchColumn();

        // Question count and points
        $qStmt = db()->prepare('SELECT COUNT(*), COALESCE(SUM(points), 0) FROM quiz_questions WHERE quiz_id = ?');
        $qStmt->execute([$id]);
        [$qCount, $points] = $qStmt->fetch(PDO::FETCH_NUM);
        $row['questions_count'] = (int) $qCount;
        $row['total_points']    = (float) $points;

        return $row;
    }

    /**
     * Lists quizzes for teacher with filtering, search, and pagination.
     */
    public static function listForTeacher(
        int $teacherId,
        string $q = '',
        string $classId = '',
        string $state = '',
        string $tab = 'all',
        int $page = 1
    ): array {
        AttemptService::finalizeOverdue(null, $teacherId);

        $where = ['c.teacher_id = ?'];
        $args  = [$teacherId];

        if ($tab === 'drafts') {
            $where[] = "q.status = 'draft'";
        } elseif ($tab === 'published') {
            $where[] = "q.status = 'published'";
        } elseif ($tab === 'archived') {
            $where[] = "q.status = 'archived'";
        } else {
            // 'all' tab shows everything except archived
            $where[] = "q.status != 'archived'";
        }

        if ($q !== '') {
            $where[] = '(q.title LIKE ? OR c.name LIKE ? OR c.section LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        if ($classId !== '' && is_numeric($classId)) {
            $where[] = 'q.class_id = ?';
            $args[] = (int) $classId;
        }

        $sql = 'SELECT q.*, c.name AS class_name, c.subject, c.section, c.course_code
                  FROM quizzes q
                  JOIN classes c ON c.id = q.class_id
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY q.updated_at DESC, q.id DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $all = $stmt->fetchAll();

        // Decorate
        $decorated = [];
        foreach ($all as $item) {
            $dec = self::decorate($item);
            if ($state !== '') {
                // Filter by derived window state
                if ($state === 'draft' && $dec['phase'] !== 'draft') {
                    continue;
                }
                if ($state === 'open' && $dec['phase'] !== 'open') {
                    continue;
                }
                if ($state === 'scheduled' && $dec['phase'] !== 'scheduled') {
                    continue;
                }
                if ($state === 'closed' && $dec['phase'] !== 'closed') {
                    continue;
                }
                if ($state === 'archived' && $dec['phase'] !== 'archived') {
                    continue;
                }
            }
            $decorated[] = $dec;
        }

        $total = count($decorated);
        $offset = max(0, ($page - 1) * self::PER_PAGE);
        $paged = array_slice($decorated, $offset, self::PER_PAGE);

        return [
            'items' => $paged,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'page'  => $page,
        ];
    }

    /**
     * Counts quizzes by tab for teacher.
     */
    public static function countForTeacher(int $teacherId, string $tab = 'all'): int
    {
        $where = ['c.teacher_id = ?'];
        $args = [$teacherId];
        if ($tab === 'drafts') {
            $where[] = "q.status = 'draft'";
        } elseif ($tab === 'published') {
            $where[] = "q.status = 'published'";
        } elseif ($tab === 'archived') {
            $where[] = "q.status = 'archived'";
        } else {
            $where[] = "q.status != 'archived'";
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM quizzes q JOIN classes c ON c.id = q.class_id WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Active classes owned by teacher.
     */
    public static function activeClassesForTeacher(int $teacherId): array
    {
        $stmt = db()->prepare(
            'SELECT id, name, section, subject, course_code FROM classes
              WHERE teacher_id = ? AND archived_at IS NULL ORDER BY name, section'
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Creates a new draft quiz.
     */
    public static function createDraft(int $teacherId, int $classId, string $title): int
    {
        $classStmt = db()->prepare('SELECT id FROM classes WHERE id = ? AND teacher_id = ? AND archived_at IS NULL');
        $classStmt->execute([$classId, $teacherId]);
        if (!$classStmt->fetch()) {
            throw new InvalidArgumentException('Class not found or not owned.');
        }

        $title = trim($title);
        if ($title === '') {
            $title = 'Untitled Quiz';
        }
        if (mb_strlen($title) > 160) {
            $title = mb_substr($title, 0, 160);
        }

        $now = utc_now();
        $stmt = db()->prepare(
            'INSERT INTO quizzes (
                class_id, created_by, title, instructions, status, duration_min,
                max_attempts, score_policy, score_release, review_release, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $classId,
            $teacherId,
            $title,
            "Follow each question's instructions.",
            'draft',
            45,
            1,
            'highest',
            'manual',
            'manual',
            $now,
            $now,
        ]);

        return (int) db()->lastInsertId();
    }

    /**
     * Validates form settings.
     */
    public static function validateSettings(array $d, int $teacherId): array
    {
        $errors = [];

        // Title
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Enter a quiz title.';
        } elseif (mb_strlen($title) > 160) {
            $errors['title'] = 'Keep title under 160 characters.';
        }

        // Class
        $classId = (int) ($d['class_id'] ?? 0);
        $cStmt = db()->prepare('SELECT id FROM classes WHERE id = ? AND teacher_id = ? AND archived_at IS NULL');
        $cStmt->execute([$classId, $teacherId]);
        if (!$cStmt->fetch()) {
            $errors['class_id'] = 'Select an active class.';
        }

        // Instructions
        $instructions = trim((string) ($d['instructions'] ?? ''));
        if (mb_strlen($instructions) > 5000) {
            $errors['instructions'] = 'Keep instructions under 5,000 characters.';
        }

        // Duration
        $duration = (int) ($d['duration_min'] ?? 0);
        if ($duration < 1 || $duration > 600) {
            $errors['duration_min'] = 'Duration must be between 1 and 600 minutes.';
        }

        // Max attempts
        $attempts = (int) ($d['max_attempts'] ?? 1);
        if ($attempts < 1 || $attempts > 10) {
            $errors['max_attempts'] = 'Attempts must be between 1 and 10.';
        }

        // Score policy
        $policy = (string) ($d['score_policy'] ?? 'highest');
        if (!in_array($policy, ['highest', 'latest'], true)) {
            $errors['score_policy'] = 'Invalid score policy.';
        }

        // Dates (if provided)
        $opensManila = trim((string) ($d['opens_at_manila'] ?? ''));
        $closesManila = trim((string) ($d['closes_at_manila'] ?? ''));

        $opensUtc = null;
        $closesUtc = null;

        if ($opensManila !== '') {
            $opensUtc = manila_to_utc($opensManila);
            if ($opensUtc === null) {
                $errors['opens_at'] = 'Invalid opening date/time.';
            }
        }
        if ($closesManila !== '') {
            $closesUtc = manila_to_utc($closesManila);
            if ($closesUtc === null) {
                $errors['closes_at'] = 'Invalid closing date/time.';
            }
        }

        if ($opensUtc !== null && $closesUtc !== null && $errors === []) {
            if ($closesUtc <= $opensUtc) {
                $errors['closes_at'] = 'Closes must be after opening time.';
            }
        }

        return $errors;
    }

    /**
     * Saves settings for a draft quiz.
     */
    public static function saveSettings(int $id, int $teacherId, array $d): void
    {
        $quiz = self::find($id, $teacherId);
        if (!$quiz) {
            throw new RuntimeException('Quiz not found or not owned.');
        }

        if ($quiz['status'] !== 'draft') {
            throw new RuntimeException('Published quizzes are frozen. Duplicate as a new version to make changes.');
        }

        $opensUtc = manila_to_utc($d['opens_at_manila'] ?? null);
        $closesUtc = manila_to_utc($d['closes_at_manila'] ?? null);

        $scoreRelease = ($d['hold_score'] ?? false) ? 'manual' : 'on_submission';
        $reviewRelease = ($d['hold_review'] ?? false) ? 'manual' : 'on_submission';

        $stmt = db()->prepare(
            'UPDATE quizzes
                SET title = ?,
                    class_id = ?,
                    instructions = ?,
                    opens_at = ?,
                    closes_at = ?,
                    duration_min = ?,
                    max_attempts = ?,
                    score_policy = ?,
                    score_release = ?,
                    review_release = ?,
                    updated_at = ?
              WHERE id = ?'
        );

        $stmt->execute([
            trim($d['title']),
            (int) $d['class_id'],
            trim($d['instructions'] ?? ''),
            $opensUtc,
            $closesUtc,
            (int) ($d['duration_min'] ?? 45),
            (int) ($d['max_attempts'] ?? 1),
            $d['score_policy'] ?? 'highest',
            $scoreRelease,
            $reviewRelease,
            utc_now(),
            $id,
        ]);
    }

    /**
     * Archives or restores a quiz.
     */
    public static function setArchived(int $id, int $teacherId, bool $archived): void
    {
        $quiz = self::find($id, $teacherId);
        if (!$quiz) {
            throw new RuntimeException('Quiz not found or not owned.');
        }

        $now = utc_now();
        if ($archived) {
            $stmt = db()->prepare("UPDATE quizzes SET status = 'archived', archived_at = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$now, $now, $id]);
        } else {
            // Restore back to draft if it had no published_at, or published
            $status = $quiz['published_at'] !== null ? 'published' : 'draft';
            $stmt = db()->prepare('UPDATE quizzes SET status = ?, archived_at = NULL, updated_at = ? WHERE id = ?');
            $stmt->execute([$status, $now, $id]);
        }
    }

    /**
     * Duplicates a quiz as a new version in draft status.
     */
    public static function duplicateAsVersion(int $id, int $teacherId): int
    {
        $source = self::find($id, $teacherId);
        if (!$source) {
            throw new RuntimeException('Quiz not found or not owned.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $now = utc_now();
            $stmt = $pdo->prepare(
                'INSERT INTO quizzes (
                    class_id, created_by, previous_version_id, title, instructions, status,
                    opens_at, closes_at, duration_min, max_attempts, score_policy,
                    score_release, review_release, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $source['class_id'],
                $teacherId,
                $id,
                $source['title'],
                $source['instructions'],
                'draft',
                $source['opens_at'],
                $source['closes_at'],
                $source['duration_min'],
                $source['max_attempts'],
                $source['score_policy'],
                $source['score_release'],
                $source['review_release'],
                $now,
                $now,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Copy questions from source if any exist
            $qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position');
            $qStmt->execute([$id]);
            $questions = $qStmt->fetchAll();

            $insQ = $pdo->prepare(
                'INSERT INTO quiz_questions (quiz_id, source_question_id, position, type, prompt, points, rubric)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insOpt = $pdo->prepare(
                'INSERT INTO quiz_question_options (quiz_question_id, label, is_correct, position)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($questions as $q) {
                $insQ->execute([
                    $newId,
                    $q['source_question_id'],
                    $q['position'],
                    $q['type'],
                    $q['prompt'],
                    $q['points'],
                    $q['rubric'],
                ]);
                $newQId = (int) $pdo->lastInsertId();

                $optStmt = $pdo->prepare('SELECT * FROM quiz_question_options WHERE quiz_question_id = ? ORDER BY position');
                $optStmt->execute([(int) $q['id']]);
                foreach ($optStmt->fetchAll() as $opt) {
                    $insOpt->execute([
                        $newQId,
                        $opt['label'],
                        $opt['is_correct'],
                        $opt['position'],
                    ]);
                }
            }

            $pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Returns all copied questions for a quiz with their options.
     */
    public static function getQuestions(int $quizId): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC'
        );
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();

        $optStmt = db()->prepare(
            'SELECT * FROM quiz_question_options WHERE quiz_question_id = ? ORDER BY position ASC'
        );

        foreach ($questions as &$q) {
            $optStmt->execute([(int) $q['id']]);
            $q['options'] = $optStmt->fetchAll();
        }
        unset($q);

        return $questions;
    }

    /**
     * Returns blocking issues for publishing.
     */
    public static function getBlockers(int $quizId, int $teacherId): array
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz) {
            return ['Quiz not found or not owned.'];
        }
        $questions = self::getQuestions($quizId);
        return quiz_blockers($quiz, $questions);
    }

    /**
     * Adds questions from the question bank to the quiz draft.
     * Deep-copies prompt, points, type, rubric, and options.
     */
    public static function addBankQuestions(int $quizId, int $teacherId, array $bankQuestionIds): int
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz) {
            throw new RuntimeException('Quiz not found or not owned.');
        }
        if ($quiz['status'] !== 'draft') {
            throw new RuntimeException('Published quizzes are frozen and cannot be edited. Duplicate as a new version instead.');
        }

        if (empty($bankQuestionIds)) {
            return 0;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM quiz_questions WHERE quiz_id = ?');
            $posStmt->execute([$quizId]);
            $curPos = (int) $posStmt->fetchColumn();

            $qBankStmt = $pdo->prepare(
                'SELECT * FROM questions WHERE id = ? AND teacher_id = ? AND archived_at IS NULL'
            );
            $optBankStmt = $pdo->prepare(
                'SELECT * FROM question_options WHERE question_id = ? ORDER BY position ASC'
            );

            $insQ = $pdo->prepare(
                'INSERT INTO quiz_questions (quiz_id, source_question_id, position, type, prompt, points, rubric)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insOpt = $pdo->prepare(
                'INSERT INTO quiz_question_options (quiz_question_id, label, is_correct, position)
                 VALUES (?, ?, ?, ?)'
            );

            $count = 0;
            foreach ($bankQuestionIds as $bId) {
                $bId = (int) $bId;
                if ($bId <= 0) {
                    continue;
                }

                $qBankStmt->execute([$bId, $teacherId]);
                $bankQ = $qBankStmt->fetch();
                if (!$bankQ) {
                    continue;
                }

                $curPos++;
                $insQ->execute([
                    $quizId,
                    $bankQ['id'],
                    $curPos,
                    $bankQ['type'],
                    $bankQ['prompt'],
                    $bankQ['points'],
                    $bankQ['rubric'],
                ]);
                $newQId = (int) $pdo->lastInsertId();

                $optBankStmt->execute([$bId]);
                $bankOpts = $optBankStmt->fetchAll();
                foreach ($bankOpts as $opt) {
                    $insOpt->execute([
                        $newQId,
                        $opt['label'],
                        $opt['is_correct'],
                        $opt['position'],
                    ]);
                }
                $count++;
            }

            $pdo->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Updates inline points for a question in a draft quiz.
     */
    public static function updateQuestionPoints(int $quizId, int $teacherId, int $quizQuestionId, float $points): void
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz) {
            throw new RuntimeException('Quiz not found or not owned.');
        }
        if ($quiz['status'] !== 'draft') {
            throw new RuntimeException('Published quizzes are frozen and cannot be edited. Duplicate as a new version instead.');
        }

        $points = max(0.0, round($points, 2));

        $stmt = db()->prepare(
            'UPDATE quiz_questions SET points = ? WHERE id = ? AND quiz_id = ?'
        );
        $stmt->execute([$points, $quizQuestionId, $quizId]);

        db()->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
    }

    /**
     * Reorders questions within a draft quiz.
     */
    public static function reorderQuestions(int $quizId, int $teacherId, array $orderedIds): void
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz || $quiz['status'] !== 'draft') {
            throw new RuntimeException('Cannot reorder: quiz is frozen or not found.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Negative pass to avoid uq_position collisions
            $negStmt = $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ? AND quiz_id = ?');
            foreach ($orderedIds as $idx => $qId) {
                $negStmt->execute([-( ($idx + 1) * 100 ), (int) $qId, $quizId]);
            }

            // Positive final pass
            $posStmt = $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ? AND quiz_id = ?');
            foreach ($orderedIds as $idx => $qId) {
                $posStmt->execute([$idx + 1, (int) $qId, $quizId]);
            }

            $pdo->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Moves a question one position up or down.
     */
    public static function moveQuestion(int $quizId, int $teacherId, int $quizQuestionId, string $direction): void
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz || $quiz['status'] !== 'draft') {
            throw new RuntimeException('Cannot reorder: quiz is frozen or not found.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, position FROM quiz_questions WHERE id = ? AND quiz_id = ?');
            $stmt->execute([$quizQuestionId, $quizId]);
            $cur = $stmt->fetch();
            if (!$cur) {
                $pdo->rollBack();
                return;
            }

            $curPos = (int) $cur['position'];
            if ($direction === 'up') {
                $swapStmt = $pdo->prepare(
                    'SELECT id, position FROM quiz_questions WHERE quiz_id = ? AND position < ? ORDER BY position DESC LIMIT 1'
                );
            } else {
                $swapStmt = $pdo->prepare(
                    'SELECT id, position FROM quiz_questions WHERE quiz_id = ? AND position > ? ORDER BY position ASC LIMIT 1'
                );
            }
            $swapStmt->execute([$quizId, $curPos]);
            $target = $swapStmt->fetch();
            if (!$target) {
                $pdo->rollBack();
                return;
            }

            $targetId = (int) $target['id'];
            $targetPos = (int) $target['position'];

            // Swap using temporary position 0
            $pdo->prepare('UPDATE quiz_questions SET position = 0 WHERE id = ?')->execute([$cur['id']]);
            $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ?')->execute([$curPos, $targetId]);
            $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ?')->execute([$targetPos, $cur['id']]);

            $pdo->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Removes a question from a draft quiz and normalizes positions.
     */
    public static function removeQuestion(int $quizId, int $teacherId, int $quizQuestionId): void
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz || $quiz['status'] !== 'draft') {
            throw new RuntimeException('Cannot remove question: quiz is frozen or not found.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM quiz_question_options WHERE quiz_question_id = ?')->execute([$quizQuestionId]);
            $pdo->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?')->execute([$quizQuestionId, $quizId]);

            $qStmt = $pdo->prepare('SELECT id FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC');
            $qStmt->execute([$quizId]);
            $remaining = $qStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($remaining as $i => $qId) {
                $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ?')->execute([-( ($i + 1) * 100 ), $qId]);
            }
            foreach ($remaining as $i => $qId) {
                $pdo->prepare('UPDATE quiz_questions SET position = ? WHERE id = ?')->execute([$i + 1, $qId]);
            }

            $pdo->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Updates prompt, points, rubric and options for an individual question copy.
     */
    public static function updateQuestionCopy(int $quizId, int $teacherId, int $quizQuestionId, array $data): void
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz || $quiz['status'] !== 'draft') {
            throw new RuntimeException('Cannot edit question copy: quiz is frozen or not found.');
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $points = max(0.0, round((float) ($data['points'] ?? 1.0), 2));
        $rubric = !empty($data['rubric']) ? trim((string) $data['rubric']) : null;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE quiz_questions SET prompt = ?, points = ?, rubric = ? WHERE id = ? AND quiz_id = ?'
            );
            $stmt->execute([$prompt, $points, $rubric, $quizQuestionId, $quizId]);

            if (isset($data['options']) && is_array($data['options'])) {
                $pdo->prepare('DELETE FROM quiz_question_options WHERE quiz_question_id = ?')->execute([$quizQuestionId]);
                $insOpt = $pdo->prepare(
                    'INSERT INTO quiz_question_options (quiz_question_id, label, is_correct, position) VALUES (?, ?, ?, ?)'
                );
                $pos = 1;
                foreach ($data['options'] as $opt) {
                    $label = trim((string) ($opt['label'] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
                    $insOpt->execute([$quizQuestionId, $label, $isCorrect, $pos++]);
                }
            }

            $pdo->prepare('UPDATE quizzes SET updated_at = ? WHERE id = ?')->execute([utc_now(), $quizId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Publishes a quiz if there are zero blockers, freezing its contents.
     */
    public static function publish(int $quizId, int $teacherId): array
    {
        $quiz = self::find($quizId, $teacherId);
        if (!$quiz) {
            return ['success' => false, 'blockers' => ['Quiz not found or not owned.']];
        }
        if ($quiz['status'] !== 'draft') {
            return ['success' => false, 'blockers' => ['Quiz is already published or archived.']];
        }

        $blockers = self::getBlockers($quizId, $teacherId);
        if (!empty($blockers)) {
            return ['success' => false, 'blockers' => $blockers];
        }

        $now = utc_now();
        $stmt = db()->prepare(
            'UPDATE quizzes SET status = "published", published_at = ?, updated_at = ? WHERE id = ? AND status = "draft"'
        );
        $stmt->execute([$now, $now, $quizId]);

        return ['success' => true, 'blockers' => []];
    }

    /**
     * Lists bank questions for this teacher, noting which ones are already added.
     */
    public static function getAvailableBankQuestions(int $teacherId, int $quizId, string $q = '', string $type = ''): array
    {
        $sql = 'SELECT q.*,
                       (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = ? AND qq.source_question_id = q.id) AS in_this_quiz
                  FROM questions q
                 WHERE q.teacher_id = ? AND q.archived_at IS NULL';
        $params = [$quizId, $teacherId];

        if ($q !== '') {
            $sql .= ' AND q.prompt LIKE ?';
            $params[] = '%' . $q . '%';
        }
        if ($type !== '' && in_array($type, ['single', 'true_false', 'multi', 'short', 'essay'], true)) {
            $sql .= ' AND q.type = ?';
            $params[] = $type;
        }
        $sql .= ' ORDER BY q.id DESC LIMIT 100';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
