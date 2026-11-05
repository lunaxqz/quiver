<?php
declare(strict_types=1);

/**
 * The teacher's question bank: the MASTER copies. A quiz takes its own copy later (Day 10),
 * so editing a bank question can never change an old quiz or an old grade.
 * Every query includes "and this question belongs to this teacher".
 */
final class QuestionService
{
    public const TYPES = [
        'single'     => 'Single-select',
        'true_false' => 'True / false',
        'multi'      => 'Multi-select',
        'short'      => 'Short answer',
        'essay'      => 'Essay',
    ];
    /** Types the editor can write so far (short answer and essay arrive on Day 8). */
    public const EDITABLE = ['single', 'true_false', 'multi'];
    public const MAX_OPTIONS = 6;
    public const PER_PAGE = 10;

    // ------------------------------------------------------------ list

    /** @return array{0:string,1:array} the WHERE text and its values */
    private static function where(int $teacherId, string $q, string $type, string $tag, bool $archived): array
    {
        $w = ['q.teacher_id = ?', $archived ? 'q.archived_at IS NOT NULL' : 'q.archived_at IS NULL'];
        $a = [$teacherId];
        if ($q !== '') {
            $w[] = 'q.prompt LIKE ?';
            $a[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        if (isset(self::TYPES[$type])) {
            $w[] = 'q.type = ?';
            $a[] = $type;
        }
        if ($tag !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM question_tags t WHERE t.question_id = q.id AND t.tag = ?)';
            $a[] = $tag;
        }
        return [implode(' AND ', $w), $a];
    }

    public static function count(int $teacherId, string $q = '', string $type = '', string $tag = '', bool $archived = false): int
    {
        [$where, $args] = self::where($teacherId, $q, $type, $tag, $archived);
        $stmt = db()->prepare('SELECT COUNT(*) FROM questions q WHERE ' . $where);
        $stmt->execute($args);
        return (int) $stmt->fetchColumn();
    }

    /** One page of the bank, each row with its tags and "used in N quizzes". */
    public static function list(int $teacherId, string $q = '', string $type = '', string $tag = '', int $page = 1, bool $archived = false): array
    {
        [$where, $args] = self::where($teacherId, $q, $type, $tag, $archived);
        $offset = max(0, ($page - 1) * self::PER_PAGE);
        $stmt = db()->prepare(
            'SELECT q.id, q.type, q.prompt, q.points, q.updated_at,
                    (SELECT COUNT(DISTINCT qq.quiz_id) FROM quiz_questions qq WHERE qq.source_question_id = q.id) AS used_in,
                    (SELECT GROUP_CONCAT(t.tag ORDER BY t.tag SEPARATOR \',\') FROM question_tags t WHERE t.question_id = q.id) AS tags
               FROM questions q WHERE ' . $where . '
              ORDER BY q.updated_at DESC, q.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /** Every tag this teacher has used, for the filter list. */
    public static function tagsFor(int $teacherId): array
    {
        $stmt = db()->prepare(
            'SELECT DISTINCT t.tag FROM question_tags t JOIN questions q ON q.id = t.question_id
              WHERE q.teacher_id = ? AND q.archived_at IS NULL ORDER BY t.tag'
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------ one question

    /** The question with its options and tags, or null if it is not this teacher's. */
    public static function find(int $id, int $teacherId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM questions WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$id, $teacherId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $o = db()->prepare('SELECT label, is_correct FROM question_options WHERE question_id = ? ORDER BY position');
        $o->execute([$id]);
        $row['options'] = $o->fetchAll();

        $t = db()->prepare('SELECT tag FROM question_tags WHERE question_id = ? ORDER BY tag');
        $t->execute([$id]);
        $row['tags'] = $t->fetchAll(PDO::FETCH_COLUMN);

        $u = db()->prepare('SELECT COUNT(DISTINCT quiz_id) FROM quiz_questions WHERE source_question_id = ?');
        $u->execute([$id]);
        $row['used_in'] = (int) $u->fetchColumn();
        return $row;
    }

    /** "Networking, week 3 ,networking" becomes ['Networking','week 3'] (case kept, repeats dropped). */
    public static function parseTags(string $text): array
    {
        $out = [];
        foreach (explode(',', $text) as $t) {
            $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
            if ($t !== '' && !isset($out[mb_strtolower($t)])) {
                $out[mb_strtolower($t)] = $t;
            }
        }
        return array_values($out);
    }

    /** Archive hides a bank question; restore brings it back. Quizzes that used it are not affected. */
    public static function setArchived(int $id, int $teacherId, bool $archived): void
    {
        db()->prepare('UPDATE questions SET archived_at = ? WHERE id = ? AND teacher_id = ?')
            ->execute([$archived ? utc_now() : null, $id, $teacherId]);
    }

    /**
     * Checks the form values. Returns [fieldId => message]; an empty array means valid.
     * $d keys: type, prompt, points, options (list of [label, correct]), tf (true|false), tags (list)
     */
    public static function validate(array $d): array
    {
        $err = [];
        if (!in_array($d['type'], self::EDITABLE, true)) {
            $err['type'] = 'Choose a question type.';
            return $err;
        }
        if ($d['prompt'] === '') {
            $err['prompt'] = 'Write the question.';
        } elseif (mb_strlen($d['prompt']) > 5000) {
            $err['prompt'] = 'Keep the question under 5,000 characters.';
        }
        if (!is_numeric($d['points']) || (float) $d['points'] < 0.25 || (float) $d['points'] > 100) {
            $err['points'] = 'Points must be between 0.25 and 100.';
        }
        if ($d['type'] === 'true_false') {
            if (!in_array($d['tf'], ['true', 'false'], true)) {
                $err['tf'] = 'Choose whether the statement is true or false.';
            }
        } else {
            $filled = array_filter($d['options'], static fn($o) => $o['label'] !== '');
            $correct = array_filter($filled, static fn($o) => $o['correct']);
            if (count($filled) < 2) {
                $err['option_0'] = 'Give at least two answer choices.';
            }
            foreach ($filled as $i => $o) {
                if (mb_strlen($o['label']) > 500) {
                    $err['option_' . $i] = 'Keep each choice under 500 characters.';
                }
            }
            if (!$correct) {
                $err['correct'] = $d['type'] === 'single' ? 'Mark the correct answer.' : 'Mark at least one correct answer.';
            } elseif ($d['type'] === 'single' && count($correct) !== 1) {
                $err['correct'] = 'A single-choice question has exactly one correct answer.';
            }
            if (count(array_unique(array_map(static fn($o) => mb_strtolower($o['label']), $filled))) < count($filled)) {
                $err['option_0'] = 'Two choices are identical. Make each one different.';
            }
        }
        if (count($d['tags']) > 10) {
            $err['tags'] = 'Use at most 10 tags.';
        }
        foreach ($d['tags'] as $t) {
            if (mb_strlen($t) > 40) {
                $err['tags'] = 'Each tag can be up to 40 characters.';
            }
        }
        return $err;
    }

    /** Saves a valid form. $id null creates; otherwise updates (only if it is this teacher's). Returns the id. */
    public static function save(int $teacherId, ?int $id, array $d): int
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $now = utc_now();
            if ($id === null) {
                $pdo->prepare('INSERT INTO questions (teacher_id, type, prompt, points, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$teacherId, $d['type'], $d['prompt'], $d['points'], $now, $now]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $u = $pdo->prepare('UPDATE questions SET prompt = ?, points = ?, updated_at = ? WHERE id = ? AND teacher_id = ?');
                $u->execute([$d['prompt'], $d['points'], $now, $id, $teacherId]);
                if ($u->rowCount() === 0 && self::find($id, $teacherId) === null) {
                    throw new RuntimeException('Not your question.');
                }
                $pdo->prepare('DELETE FROM question_options WHERE question_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM question_tags WHERE question_id = ?')->execute([$id]);
            }

            if ($d['type'] === 'true_false') {
                $opts = [['label' => 'True', 'correct' => $d['tf'] === 'true'], ['label' => 'False', 'correct' => $d['tf'] === 'false']];
            } else {
                $opts = array_values(array_filter($d['options'], static fn($o) => $o['label'] !== ''));
            }
            $ins = $pdo->prepare('INSERT INTO question_options (question_id, label, is_correct, position) VALUES (?, ?, ?, ?)');
            foreach ($opts as $pos => $o) {
                $ins->execute([$id, $o['label'], $o['correct'] ? 1 : 0, $pos + 1]);
            }
            $tag = $pdo->prepare('INSERT INTO question_tags (question_id, tag) VALUES (?, ?)');
            foreach ($d['tags'] as $t) {
                $tag->execute([$id, $t]);
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
