<?php
declare(strict_types=1);

/**
 * Pure rule function to identify blockers that prevent a quiz from being published.
 *
 * Rules:
 * - Title is required.
 * - Class is required.
 * - Opens_at and closes_at are both required.
 * - Closes_at must be strictly after opens_at ("Closes before it opens").
 * - Duration must be > 0.
 * - Must have at least 1 question ("No questions added yet").
 * - Total points must be > 0.
 * - Each question:
 *   - Points must be > 0 ("Question {N} has 0 points").
 *   - Prompt cannot be empty ("Question {N} has an empty prompt").
 *   - Objective types (single, true_false, multi):
 *     - Must have at least 2 options ("Question {N} has fewer than 2 options").
 *     - Must have at least 1 correct answer marked ("Question {N} has no correct answer").
 *     - Single & true_false cannot have more than 1 correct answer ("Question {N} has multiple correct answers").
 */
function quiz_blockers(array $quiz, array $questions): array
{
    $blockers = [];

    // 1. Settings & Schedule checks
    $title = trim((string) ($quiz['title'] ?? ''));
    if ($title === '') {
        $blockers[] = 'Quiz title is required.';
    }

    $classId = (int) ($quiz['class_id'] ?? 0);
    if ($classId <= 0) {
        $blockers[] = 'A class must be selected.';
    }

    $opensAt = $quiz['opens_at'] ?? null;
    $closesAt = $quiz['closes_at'] ?? null;

    if (empty($opensAt) || empty($closesAt)) {
        $blockers[] = 'Opening and closing schedule dates are required.';
    } else {
        $opensTime = is_numeric($opensAt) ? (int) $opensAt : strtotime((string) $opensAt);
        $closesTime = is_numeric($closesAt) ? (int) $closesAt : strtotime((string) $closesAt);

        if ($opensTime === false || $closesTime === false) {
            $blockers[] = 'Invalid schedule dates.';
        } elseif ($closesTime <= $opensTime) {
            $blockers[] = 'Closes before it opens.';
        }
    }

    $duration = (int) ($quiz['duration_min'] ?? 0);
    if ($duration <= 0) {
        $blockers[] = 'Duration must be at least 1 minute.';
    }

    // 2. Question checks
    if (empty($questions)) {
        $blockers[] = 'No questions added yet.';
        return $blockers;
    }

    $totalPoints = 0.0;

    foreach ($questions as $index => $q) {
        $position = (int) ($q['position'] ?? ($index + 1));
        $prompt = trim((string) ($q['prompt'] ?? ''));
        $points = (float) ($q['points'] ?? 0);
        $type = (string) ($q['type'] ?? 'single');
        $options = $q['options'] ?? [];

        $totalPoints += $points;

        if ($prompt === '') {
            $blockers[] = "Question {$position} has an empty prompt.";
        }

        if ($points <= 0) {
            $blockers[] = "Question {$position} has 0 points.";
        }

        // Objective types check
        if (in_array($type, ['single', 'true_false', 'multi'], true)) {
            if (count($options) < 2) {
                $blockers[] = "Question {$position} has fewer than 2 options.";
            }

            $correctCount = 0;
            foreach ($options as $opt) {
                if (!empty($opt['is_correct'])) {
                    $correctCount++;
                }
            }

            if ($correctCount === 0) {
                $blockers[] = "Question {$position} has no correct answer.";
            } elseif ($correctCount > 1 && in_array($type, ['single', 'true_false'], true)) {
                $blockers[] = "Question {$position} has multiple correct answers.";
            }
        }
    }

    if ($totalPoints <= 0 && count($questions) > 0) {
        $blockers[] = 'Quiz total points must be greater than 0.';
    }

    return $blockers;
}
