<?php
declare(strict_types=1);

/**
 * Teacher review release, class statistics, and safe CSV export service (Day 16).
 *
 * Rules enforced here:
 *  - Every query and action verifies that the quiz belongs to a class owned by `$teacherId` (`classes.teacher_id = ?`).
 *    Another teacher receives `not_found` (`null` / 404), never 403.
 *  - Sweeps overdue attempts (`AttemptService::finalizeOverdue`) before computing statistics, release status, or exports.
 *  - Independent release controls for Scores (`score_released_at`) and Answer review (`review_released_at`):
 *      - Can release immediately (stamps current UTC timestamp on all non-voided submitted/graded attempts and sets mode to `'on_submission'`).
 *      - Can hold / make private again (`score_released_at = NULL` / `review_released_at = NULL`, mode `'manual'`).
 *      - Can configure release mode (`'manual'`, `'on_submission'`, `'scheduled'` with `release_at` in UTC).
 *  - Retake score policy (`highest` vs `latest`):
 *      - When a student has multiple non-voided submitted/graded attempts on a quiz:
 *          - `'highest'` selects the attempt with the highest `COALESCE(score, -1)` (tie-breaking by latest `attempt_no`).
 *          - `'latest'` selects the attempt with the highest `attempt_no`.
 *      - Class statistics (mean, median, min/max range, graded count), the Student results table, and the student's
 *        final score display all follow the quiz's configured `score_policy`.
 *  - Safe CSV export:
 *      - Prefixes UTF-8 BOM (`\xEF\xBB\xBF`) so Excel opens UTF-8 names and symbols cleanly.
 *      - Neutralizes spreadsheet formula injection (`=`, `+`, `-`, `@`, `\t`, `\r`) by prefixing a single quote (`'`)
 *        on any cell that starts with (or after leading whitespace starts with) a formula trigger character.
 */
final class ReportService
{
    public const PER_PAGE = 25;

    /**
     * Lists published (and archived-after-publish) quizzes owned by `$teacherId` for the Reports switcher.
     *
     * @return list<array>
     */
    public static function listQuizzesForReports(int $teacherId): array
    {
        AttemptService::finalizeOverdue(null, $teacherId);

        $stmt = db()->prepare(
            "SELECT q.id, q.title, q.class_id, q.status, q.opens_at, q.closes_at,
                    q.score_policy, q.score_release, q.review_release, q.release_at,
                    c.name AS class_name, c.course_code, c.section,
                    (SELECT COUNT(*) FROM class_memberships m WHERE m.class_id = c.id AND m.removed_at IS NULL) AS roster_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id = q.id AND a.status IN ('submitted', 'graded')) AS submitted_count,
                    (SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id = q.id AND a.status = 'graded') AS graded_count
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
              WHERE c.teacher_id = ?
                AND q.status = 'published'
              ORDER BY submitted_count DESC, q.updated_at DESC, q.id DESC"
        );
        $stmt->execute([$teacherId]);
        return $stmt->fetchAll();
    }

    /**
     * Releases or holds scores or answer review for a quiz owned by `$teacherId`,
     * or updates the quiz's release schedule/mode.
     *
     * @param 'release_scores'|'hold_scores'|'release_review'|'hold_review'|'save_release_settings' $action
     * @return array{result: 'ok'|'not_found'|'has_pending'|'invalid_schedule', error?: string}
     */
    public static function updateRelease(
        int $quizId,
        int $teacherId,
        string $action,
        array $settings = [],
        ?string $nowUtc = null
    ): array {
        AttemptService::finalizeOverdue($quizId, $teacherId, $nowUtc);

        $now = $nowUtc ?? utc_now();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                "SELECT q.*
                   FROM quizzes q
                   JOIN classes c ON c.id = q.class_id
                  WHERE q.id = ? AND c.teacher_id = ?
                  FOR UPDATE"
            );
            $lock->execute([$quizId, $teacherId]);
            $quiz = $lock->fetch();
            if ($quiz === false) {
                $pdo->rollBack();
                return ['result' => 'not_found'];
            }

            if ($action === 'release_scores') {
                // Stamp score_released_at = now on all graded attempts (and submitted attempts that have no ungraded written items)
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET score_released_at = COALESCE(score_released_at, ?)
                      WHERE quiz_id = ?
                        AND status IN ('submitted', 'graded')"
                )->execute([$now, $quizId]);

                $pdo->prepare(
                    "UPDATE quizzes
                        SET score_release = 'on_submission',
                            updated_at = ?
                      WHERE id = ?"
                )->execute([$now, $quizId]);
            } elseif ($action === 'hold_scores') {
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET score_released_at = NULL
                      WHERE quiz_id = ?"
                )->execute([$quizId]);

                $pdo->prepare(
                    "UPDATE quizzes
                        SET score_release = 'manual',
                            updated_at = ?
                      WHERE id = ?"
                )->execute([$now, $quizId]);
            } elseif ($action === 'release_review') {
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET review_released_at = COALESCE(review_released_at, ?)
                      WHERE quiz_id = ?
                        AND status IN ('submitted', 'graded')"
                )->execute([$now, $quizId]);

                $pdo->prepare(
                    "UPDATE quizzes
                        SET review_release = 'on_submission',
                            updated_at = ?
                      WHERE id = ?"
                )->execute([$now, $quizId]);
            } elseif ($action === 'hold_review') {
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET review_released_at = NULL
                      WHERE quiz_id = ?"
                )->execute([$quizId]);

                $pdo->prepare(
                    "UPDATE quizzes
                        SET review_release = 'manual',
                            updated_at = ?
                      WHERE id = ?"
                )->execute([$now, $quizId]);
            } elseif ($action === 'save_release_settings') {
                $scoreMode  = (string) ($settings['score_release'] ?? $quiz['score_release']);
                $reviewMode = (string) ($settings['review_release'] ?? $quiz['review_release']);
                if (!in_array($scoreMode, ['manual', 'on_submission', 'scheduled'], true)) {
                    $scoreMode = 'manual';
                }
                if (!in_array($reviewMode, ['manual', 'on_submission', 'scheduled'], true)) {
                    $reviewMode = 'manual';
                }

                $releaseAtUtc = $quiz['release_at'];
                if (array_key_exists('release_at_manila', $settings)) {
                    $manilaStr = trim((string) ($settings['release_at_manila'] ?? ''));
                    if ($manilaStr !== '') {
                        $converted = manila_to_utc($manilaStr);
                        if ($converted === null) {
                            $pdo->rollBack();
                            return ['result' => 'invalid_schedule', 'error' => 'Enter a valid release date and time.'];
                        }
                        $releaseAtUtc = $converted;
                    } else {
                        $releaseAtUtc = null;
                    }
                } elseif (array_key_exists('release_at_utc', $settings)) {
                    $releaseAtUtc = $settings['release_at_utc'];
                }

                if (($scoreMode === 'scheduled' || $reviewMode === 'scheduled') && ($releaseAtUtc === null || $releaseAtUtc === '')) {
                    $pdo->rollBack();
                    return ['result' => 'invalid_schedule', 'error' => 'Select a release date and time for scheduled release.'];
                }

                $pdo->prepare(
                    'UPDATE quizzes
                        SET score_release = ?,
                            review_release = ?,
                            release_at = ?,
                            updated_at = ?
                      WHERE id = ?'
                )->execute([$scoreMode, $reviewMode, $releaseAtUtc, $now, $quizId]);

                // Apply immediate effect to existing attempts
                if ($scoreMode === 'manual') {
                    $pdo->prepare('UPDATE quiz_attempts SET score_released_at = NULL WHERE quiz_id = ?')->execute([$quizId]);
                } elseif ($scoreMode === 'on_submission') {
                    $pdo->prepare(
                        "UPDATE quiz_attempts
                            SET score_released_at = COALESCE(score_released_at, ?)
                          WHERE quiz_id = ? AND status = 'graded'"
                    )->execute([$now, $quizId]);
                } elseif ($scoreMode === 'scheduled') {
                    if ($releaseAtUtc !== null && $releaseAtUtc <= $now) {
                        $pdo->prepare(
                            "UPDATE quiz_attempts
                                SET score_released_at = ?
                              WHERE quiz_id = ? AND status = 'graded'"
                        )->execute([$releaseAtUtc, $quizId]);
                    } else {
                        $pdo->prepare('UPDATE quiz_attempts SET score_released_at = NULL WHERE quiz_id = ?')->execute([$quizId]);
                    }
                }

                if ($reviewMode === 'manual') {
                    $pdo->prepare('UPDATE quiz_attempts SET review_released_at = NULL WHERE quiz_id = ?')->execute([$quizId]);
                } elseif ($reviewMode === 'on_submission') {
                    $pdo->prepare(
                        "UPDATE quiz_attempts
                            SET review_released_at = COALESCE(review_released_at, ?)
                          WHERE quiz_id = ? AND status = 'graded'"
                    )->execute([$now, $quizId]);
                } elseif ($reviewMode === 'scheduled') {
                    if ($releaseAtUtc !== null && $releaseAtUtc <= $now) {
                        $pdo->prepare(
                            "UPDATE quiz_attempts
                                SET review_released_at = ?
                              WHERE quiz_id = ? AND status = 'graded'"
                        )->execute([$releaseAtUtc, $quizId]);
                    } else {
                        $pdo->prepare('UPDATE quiz_attempts SET review_released_at = NULL WHERE quiz_id = ?')->execute([$quizId]);
                    }
                }
            }

            $pdo->commit();
            return ['result' => 'ok'];
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /**
     * Builds the complete report, release status, class statistics, and student results table for a quiz owned by `$teacherId`.
     * Returns `null` if the quiz does not exist or belongs to another teacher.
     */
    public static function reportForQuiz(
        int $quizId,
        int $teacherId,
        string $search = '',
        int $page = 1,
        ?string $nowUtc = null
    ): ?array {
        AttemptService::finalizeOverdue($quizId, $teacherId, $nowUtc);
        $now = $nowUtc ?? utc_now();
        $pdo = db();

        $qStmt = $pdo->prepare(
            'SELECT q.*,
                    c.name AS class_name, c.course_code, c.section,
                    t.full_name AS teacher_name
               FROM quizzes q
               JOIN classes c ON c.id = q.class_id
               JOIN users t ON t.id = c.teacher_id
              WHERE q.id = ? AND c.teacher_id = ?'
        );
        $qStmt->execute([$quizId, $teacherId]);
        $quiz = $qStmt->fetch();
        if ($quiz === false) {
            return null;
        }

        // If scheduled release is due, stamp any graded attempts that haven't been stamped yet
        if ($quiz['release_at'] !== null && (string) $quiz['release_at'] <= $now) {
            if ($quiz['score_release'] === 'scheduled') {
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET score_released_at = COALESCE(score_released_at, ?)
                      WHERE quiz_id = ? AND status = 'graded'"
                )->execute([(string) $quiz['release_at'], $quizId]);
            }
            if ($quiz['review_release'] === 'scheduled') {
                $pdo->prepare(
                    "UPDATE quiz_attempts
                        SET review_released_at = COALESCE(review_released_at, ?)
                      WHERE quiz_id = ? AND status = 'graded'"
                )->execute([(string) $quiz['release_at'], $quizId]);
            }
        }

        $classId = (int) $quiz['class_id'];

        // Total points for the quiz
        $ptsStmt = $pdo->prepare('SELECT COUNT(*) AS q_count, COALESCE(SUM(points), 0) AS total_pts FROM quiz_questions WHERE quiz_id = ?');
        $ptsStmt->execute([$quizId]);
        $ptsRow = $ptsStmt->fetch();
        $questionCount = (int) ($ptsRow['q_count'] ?? 0);
        $totalPoints   = round((float) ($ptsRow['total_pts'] ?? 0), 2);

        // Enrolled students in the class (active memberships)
        $mStmt = $pdo->prepare(
            'SELECT u.id AS student_id, u.full_name, u.username
               FROM class_memberships m
               JOIN users u ON u.id = m.student_id
              WHERE m.class_id = ? AND m.removed_at IS NULL
              ORDER BY u.full_name ASC, u.username ASC'
        );
        $mStmt->execute([$classId]);
        $roster = $mStmt->fetchAll();
        $rosterCount = count($roster);

        // All attempts for this quiz (including any student who submitted before being removed, though we key by roster + attempt students)
        $aStmt = $pdo->prepare(
            'SELECT a.*, u.full_name, u.username
               FROM quiz_attempts a
               JOIN users u ON u.id = a.student_id
              WHERE a.quiz_id = ?
              ORDER BY a.attempt_no ASC, a.id ASC'
        );
        $aStmt->execute([$quizId]);
        $allAttempts = $aStmt->fetchAll();

        // Pending written answers count across submitted/graded attempts
        $pStmt = $pdo->prepare(
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
        $pStmt->execute([$quizId]);
        $pendingWrittenCount = (int) $pStmt->fetchColumn();

        // Group attempts by student
        $attemptsByStudent = [];
        $voidedCount       = 0;
        $inProgressCount   = 0;
        $studentMeta       = [];

        foreach ($roster as $st) {
            $sid = (int) $st['student_id'];
            $studentMeta[$sid] = [
                'student_id' => $sid,
                'full_name'  => (string) $st['full_name'],
                'username'   => (string) $st['username'],
            ];
        }

        foreach ($allAttempts as $a) {
            $sid = (int) $a['student_id'];
            if (!isset($studentMeta[$sid])) {
                $studentMeta[$sid] = [
                    'student_id' => $sid,
                    'full_name'  => (string) $a['full_name'],
                    'username'   => (string) $a['username'],
                ];
            }
            if ($a['status'] === 'voided') {
                $voidedCount++;
                continue;
            }
            if ($a['status'] === 'in_progress') {
                $inProgressCount++;
            }
            $attemptsByStudent[$sid][] = $a;
        }

        // Sort student list alphabetically in "Last, First" display style (or full_name ASC)
        $studentsList = array_values($studentMeta);
        usort($studentsList, static function (array $a, array $b): int {
            $da = self::displaySortName($a['full_name']);
            $db = self::displaySortName($b['full_name']);
            return strcasecmp($da, $db) ?: strcasecmp($a['username'], $b['username']);
        });

        $scorePolicy = (string) ($quiz['score_policy'] ?? 'highest');

        $studentRows         = [];
        $gradedScores        = [];
        $submittedStudentCnt = 0;
        $gradedStudentCnt    = 0;
        $anyScoreReleased    = false;
        $allScoreReleased    = true;
        $anyReviewReleased   = false;
        $allReviewReleased   = true;
        $completedAttemptCnt = 0;

        foreach ($studentsList as $st) {
            $sid  = $st['student_id'];
            $atts = $attemptsByStudent[$sid] ?? [];

            // Separate completed (submitted / graded) attempts from in_progress
            $completed = array_values(array_filter(
                $atts,
                static fn(array $x): bool => in_array((string) $x['status'], ['submitted', 'graded'], true)
            ));

            $chosen = self::pickEffectiveAttempt($completed, $scorePolicy);
            if ($chosen !== null) {
                $submittedStudentCnt++;
                $completedAttemptCnt++;
                $isGraded = $chosen['status'] === 'graded';
                if ($isGraded) {
                    $gradedStudentCnt++;
                    if ($chosen['score'] !== null) {
                        $gradedScores[] = round((float) $chosen['score'], 2);
                    }
                }

                $sRel = $chosen['score_released_at'] !== null && (string) $chosen['score_released_at'] <= $now;
                $rRel = $chosen['review_released_at'] !== null && (string) $chosen['review_released_at'] <= $now;

                if ($sRel) {
                    $anyScoreReleased = true;
                } else {
                    $allScoreReleased = false;
                }
                if ($rRel) {
                    $anyReviewReleased = true;
                } else {
                    $allReviewReleased = false;
                }

                $subUtc = (string) ($chosen['submitted_at'] ?? $chosen['deadline_at']);
                $studentRows[] = [
                    'student_id'          => $sid,
                    'full_name'           => $st['full_name'],
                    'display_name'        => self::formatLastFirst($st['full_name']),
                    'username'            => $st['username'],
                    'attempt_id'          => (int) $chosen['id'],
                    'attempt_no'          => (int) $chosen['attempt_no'],
                    'attempts_used'       => count($completed),
                    'status'              => (string) $chosen['status'],
                    'submit_reason'       => (string) ($chosen['submit_reason'] ?? 'student'),
                    'submitted_at'        => $subUtc,
                    'submitted_manila'    => to_manila($subUtc, 'j M · H:i:s'),
                    'submitted_csv'       => to_manila($subUtc, 'Y-m-d H:i:s'),
                    'score'               => $chosen['score'] !== null ? round((float) $chosen['score'], 2) : null,
                    'percentage'          => $chosen['score'] !== null && $totalPoints > 0
                        ? round(((float) $chosen['score'] / $totalPoints) * 100, 1)
                        : null,
                    'score_released'      => $sRel,
                    'review_released'     => $rRel,
                    'score_released_csv'  => $sRel && $chosen['score_released_at'] !== null ? to_manila((string) $chosen['score_released_at'], 'Y-m-d H:i:s') : 'Private',
                    'review_released_csv' => $rRel && $chosen['review_released_at'] !== null ? to_manila((string) $chosen['review_released_at'], 'Y-m-d H:i:s') : 'Private',
                    'access_label'        => self::accessLabel($sRel, $rRel),
                ];
            } else {
                $running = !empty($atts) ? $atts[count($atts) - 1] : null;
                $studentRows[] = [
                    'student_id'          => $sid,
                    'full_name'           => $st['full_name'],
                    'display_name'        => self::formatLastFirst($st['full_name']),
                    'username'            => $st['username'],
                    'attempt_id'          => $running !== null ? (int) $running['id'] : null,
                    'attempt_no'          => $running !== null ? (int) $running['attempt_no'] : 0,
                    'attempts_used'       => 0,
                    'status'              => $running !== null ? 'in_progress' : 'not_submitted',
                    'submit_reason'       => '',
                    'submitted_at'        => '',
                    'submitted_manila'    => $running !== null ? 'In progress' : 'Not submitted',
                    'submitted_csv'       => $running !== null ? 'In progress' : 'Not submitted',
                    'score'               => null,
                    'percentage'          => null,
                    'score_released'      => false,
                    'review_released'     => false,
                    'score_released_csv'  => 'Private',
                    'review_released_csv' => 'Private',
                    'access_label'        => 'Score & review private',
                ];
            }
        }

        if ($completedAttemptCnt === 0) {
            $allScoreReleased  = $quiz['score_release'] === 'on_submission'
                || ($quiz['score_release'] === 'scheduled' && $quiz['release_at'] !== null && (string) $quiz['release_at'] <= $now);
            $allReviewReleased = $quiz['review_release'] === 'on_submission'
                || ($quiz['review_release'] === 'scheduled' && $quiz['release_at'] !== null && (string) $quiz['release_at'] <= $now);
            $anyScoreReleased  = $allScoreReleased;
            $anyReviewReleased = $allReviewReleased;
        }

        // Compute class statistics (mean, median, min, max) over graded student effective scores
        $stats = self::computeStats($gradedScores, $totalPoints, $gradedStudentCnt);

        // Filter student rows by search query for on-screen table (CSV always exports all records)
        $filteredRows = $studentRows;
        $qTrim = trim($search);
        if ($qTrim !== '') {
            $needle = mb_strtolower($qTrim);
            $filteredRows = array_values(array_filter(
                $studentRows,
                static fn(array $r): bool => str_contains(mb_strtolower($r['full_name']), $needle)
                    || str_contains(mb_strtolower($r['display_name']), $needle)
                    || str_contains(mb_strtolower($r['username']), $needle)
            ));
        }

        $totalFiltered = count($filteredRows);
        $pages         = max(1, (int) ceil($totalFiltered / self::PER_PAGE));
        $page          = max(1, min($page, $pages));
        $offset        = ($page - 1) * self::PER_PAGE;
        $pagedRows     = array_slice($filteredRows, $offset, self::PER_PAGE);

        $classLabel = trim(($quiz['course_code'] ?? '') . (($quiz['section'] ?? '') !== '' ? ', Section ' . $quiz['section'] : ''), ', ');
        if ($classLabel === '') {
            $classLabel = (string) $quiz['class_name'];
        }

        $phase = $quiz['status'] === 'draft'
            ? 'draft'
            : ($quiz['status'] === 'archived' ? 'archived' : quiz_phase($quiz['opens_at'], $quiz['closes_at'], $now));

        $closesManila = $quiz['closes_at'] !== null ? to_manila((string) $quiz['closes_at'], 'D j M \a\t H:i') : '';
        $releaseAtManila = $quiz['release_at'] !== null ? to_manila((string) $quiz['release_at'], 'D j M Y, H:i') : '';
        $releaseAtInput  = $quiz['release_at'] !== null ? to_manila_input((string) $quiz['release_at']) : '';

        return [
            'quiz' => [
                'id'                    => (int) $quiz['id'],
                'title'                 => (string) $quiz['title'],
                'status'                => (string) $quiz['status'],
                'phase'                 => $phase,
                'class_id'              => $classId,
                'class_name'            => (string) $quiz['class_name'],
                'course_code'           => (string) ($quiz['course_code'] ?? ''),
                'section'               => (string) ($quiz['section'] ?? ''),
                'class_label'           => $classLabel,
                'teacher_name'          => (string) $quiz['teacher_name'],
                'closes_manila'         => $closesManila,
                'max_attempts'          => (int) $quiz['max_attempts'],
                'score_policy'          => $scorePolicy,
                'score_release'         => (string) $quiz['score_release'],
                'review_release'        => (string) $quiz['review_release'],
                'release_at'            => $quiz['release_at'],
                'release_at_manila'     => $releaseAtManila,
                'release_at_input'      => $releaseAtInput,
                'question_count'        => $questionCount,
                'total_points'          => $totalPoints,
                'roster_count'          => $rosterCount,
                'submitted_count'       => $submittedStudentCnt,
                'graded_count'          => $gradedStudentCnt,
                'pending_written_count' => $pendingWrittenCount,
                'voided_count'          => $voidedCount,
                'in_progress_count'     => $inProgressCount,
                'scores_released'       => $allScoreReleased && $anyScoreReleased,
                'reviews_released'      => $allReviewReleased && $anyReviewReleased,
            ],
            'stats'            => $stats,
            'students'         => $pagedRows,
            'all_students'     => $studentRows,
            'total_students'   => count($studentRows),
            'filtered_count'   => $totalFiltered,
            'page'             => $page,
            'pages'            => $pages,
            'search'           => $qTrim,
        ];
    }

    /**
     * Picks the student's effective attempt among completed (`submitted` or `graded`) attempts
     * according to the quiz's `score_policy` (`'highest'` vs `'latest'`).
     *
     * @param list<array> $completedAttempts
     */
    public static function pickEffectiveAttempt(array $completedAttempts, string $scorePolicy): ?array
    {
        if (empty($completedAttempts)) {
            return null;
        }

        if ($scorePolicy === 'latest') {
            $best = $completedAttempts[0];
            foreach ($completedAttempts as $a) {
                if ((int) $a['attempt_no'] >= (int) $best['attempt_no']) {
                    $best = $a;
                }
            }
            return $best;
        }

        // Default: 'highest' — pick the attempt with the highest score (tie-break by latest attempt_no).
        // Graded/scored attempts beat unscored attempts.
        $best = $completedAttempts[0];
        foreach ($completedAttempts as $a) {
            $aScore = $a['score'] !== null ? (float) $a['score'] : -1.0;
            $bScore = $best['score'] !== null ? (float) $best['score'] : -1.0;
            if ($aScore > $bScore + 0.0001) {
                $best = $a;
            } elseif (abs($aScore - $bScore) <= 0.0001 && (int) $a['attempt_no'] > (int) $best['attempt_no']) {
                $best = $a;
            }
        }
        return $best;
    }

    /**
     * Computes mean, median, min, max, and count over graded scores.
     *
     * @param list<float> $scores
     * @return array{mean: ?float, median: ?float, min: ?float, max: ?float, total_points: float, graded_count: int}
     */
    public static function computeStats(array $scores, float $totalPoints, int $gradedCount): array
    {
        if (empty($scores)) {
            return [
                'mean'         => null,
                'median'       => null,
                'min'          => null,
                'max'          => null,
                'total_points' => $totalPoints,
                'graded_count' => $gradedCount,
            ];
        }

        sort($scores, SORT_NUMERIC);
        $n   = count($scores);
        $sum = array_sum($scores);
        $mean = round($sum / $n, 1);

        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            $median = round($scores[$mid], 1);
        } else {
            $median = round(($scores[$mid - 1] + $scores[$mid]) / 2, 1);
        }

        return [
            'mean'         => $mean,
            'median'       => $median,
            'min'          => round($scores[0], 2),
            'max'          => round($scores[$n - 1], 2),
            'total_points' => $totalPoints,
            'graded_count' => $gradedCount,
        ];
    }

    /**
     * Sanitizes a single CSV cell value against spreadsheet formula injection (guide §7).
     * Any string whose first character (or first non-whitespace character) is `=`, `+`, `-`, `@`, `\t`, or `\r`
     * is prefixed with a single quote `'`.
     */
    public static function sanitizeCsvCell(string|int|float|null $value): string
    {
        $str = (string) ($value ?? '');
        if ($str === '') {
            return '';
        }
        $trimmed = ltrim($str, " \t\r\n");
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        if (in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Generates a complete CSV string with UTF-8 BOM (`\xEF\xBB\xBF`) and formula-sanitized cells
     * for a quiz owned by `$teacherId`, or returns `null` if the quiz is not found / not owned.
     */
    public static function exportCsv(int $quizId, int $teacherId, ?string $nowUtc = null): ?array
    {
        $report = self::reportForQuiz($quizId, $teacherId, '', 1, $nowUtc);
        if ($report === null) {
            return null;
        }

        $quiz  = $report['quiz'];
        $rows  = $report['all_students'];
        $total = $quiz['total_points'];

        $fp = fopen('php://temp', 'r+');
        // Write UTF-8 BOM so Microsoft Excel opens UTF-8 characters cleanly
        fwrite($fp, "\xEF\xBB\xBF");

        $headers = [
            'Student Name',
            'Username',
            'Class',
            'Quiz Title',
            'Score Policy',
            'Attempts Used',
            'Effective Attempt No',
            'Submitted (Asia/Manila)',
            'Score',
            'Out Of',
            'Percentage',
            'Grading Status',
            'Score Released',
            'Answer Review Released',
        ];
        fputcsv($fp, $headers);

        $fmtNum = static function (?float $v): string {
            if ($v === null) {
                return '';
            }
            return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        };

        foreach ($rows as $r) {
            $gradingLabel = match ($r['status']) {
                'graded'        => 'Graded',
                'submitted'     => 'Needs grading',
                'in_progress'   => 'In progress',
                default         => 'Not submitted',
            };

            $record = [
                self::sanitizeCsvCell($r['display_name']),
                self::sanitizeCsvCell($r['username']),
                self::sanitizeCsvCell($quiz['class_label']),
                self::sanitizeCsvCell($quiz['title']),
                self::sanitizeCsvCell(ucfirst($quiz['score_policy'])),
                (string) $r['attempts_used'],
                $r['attempt_no'] > 0 ? (string) $r['attempt_no'] : '',
                self::sanitizeCsvCell($r['submitted_csv']),
                $fmtNum($r['score']),
                $fmtNum($total),
                $r['percentage'] !== null ? $fmtNum($r['percentage']) . '%' : '',
                $gradingLabel,
                self::sanitizeCsvCell($r['score_released_csv']),
                self::sanitizeCsvCell($r['review_released_csv']),
            ];

            fputcsv($fp, $record);
        }

        rewind($fp);
        $csvContent = (string) stream_get_contents($fp);
        fclose($fp);

        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $quiz['title']));
        $slug = trim($slug, '-') ?: ('quiz-' . $quizId);
        $filename = $slug . '-results.csv';

        return [
            'filename' => $filename,
            'content'  => $csvContent,
        ];
    }

    /**
     * Formats "First Last" into "Last, First" matching the Figma Student results table
     * (e.g. "Bea Aguilar" -> "Aguilar, Bea"), or leaves as-is if already comma-formatted or single word.
     */
    private static function formatLastFirst(string $fullName): string
    {
        $clean = trim($fullName);
        if ($clean === '' || str_contains($clean, ',')) {
            return $clean;
        }
        $parts = preg_split('/\s+/u', $clean) ?: [];
        if (count($parts) <= 1) {
            return $clean;
        }
        $last  = array_pop($parts);
        $first = implode(' ', $parts);
        return $last . ', ' . $first;
    }

    private static function displaySortName(string $fullName): string
    {
        return self::formatLastFirst($fullName);
    }

    private static function accessLabel(bool $scoreReleased, bool $reviewReleased): string
    {
        if ($scoreReleased && $reviewReleased) {
            return 'Score & review released';
        }
        if ($scoreReleased) {
            return 'Score released · review private';
        }
        if ($reviewReleased) {
            return 'Review released · score private';
        }
        return 'Score & review private';
    }
}
