<?php
declare(strict_types=1);

/**
 * Scoring rules live here as small plain functions that never touch the database or the web request.
 * That is what makes them easy to test and safe to trust.
 */

/**
 * Multi-select scoring: full points ONLY for an exact match, otherwise zero.
 * Picking every correct option plus one wrong one earns nothing, and neither does missing one.
 * Order and repeated ids do not matter.
 *
 * @param int[] $correctIds  ids of the options that are correct
 * @param int[] $selectedIds ids of the options the student ticked
 */
function score_multi(array $correctIds, array $selectedIds, float $points): float
{
    $correct  = array_values(array_unique(array_map('intval', $correctIds)));
    $selected = array_values(array_unique(array_map('intval', $selectedIds)));
    sort($correct);
    sort($selected);

    // A question with no correct option can never be answered correctly.
    if ($correct === []) {
        return 0.0;
    }
    return $correct === $selected ? $points : 0.0;
}
