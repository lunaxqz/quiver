<?php
declare(strict_types=1);

/**
 * Pure rule function (no database access) to rank and cap the Teacher Today rail items (Day 17).
 *
 * The six Today item types in strict priority order (guide §9.3 & schedule Day 17):
 *   1. 'closing_soon'      — An open quiz closing within 60 minutes (or currently open with students working).
 *   2. 'disconnected'      — A student with an in-progress attempt whose heartbeat (last_seen_at) has gone quiet (>= 2 min).
 *   3. 'needs_grading'     — Submitted/graded attempts with ungraded non-blank written responses.
 *   4. 'ready_to_release'  — All submitted attempts are graded, but scores or answer reviews are not yet released.
 *   5. 'opens_soon'        — A scheduled published quiz opening within the next 24 hours.
 *   6. 'incomplete_draft'  — A draft quiz that is still missing required settings/questions or awaits publishing.
 *
 * Within the same type, items are ordered by their numeric `'urgency'` key ascending (e.g. soonest closing timestamp,
 * longest silence, oldest ungraded submission, etc.), with `'id'` as a deterministic tie-breaker.
 *
 * Returns at most `$limit` items (default 5).
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function rank_today(array $items, int $limit = 5): array
{
    if ($limit <= 0 || $items === []) {
        return [];
    }

    $priorityMap = [
        'closing_soon'     => 1,
        'disconnected'     => 2,
        'needs_grading'    => 3,
        'ready_to_release' => 4,
        'opens_soon'       => 5,
        'incomplete_draft' => 6,
    ];

    // Filter out unknown types or zero-count items if a caller passes raw candidates
    $valid = [];
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if (!isset($priorityMap[$type])) {
            continue;
        }
        $item['_priority'] = $priorityMap[$type];
        $item['_orig_idx'] = $idx;
        $valid[] = $item;
    }

    usort($valid, static function (array $a, array $b): int {
        if ($a['_priority'] !== $b['_priority']) {
            return $a['_priority'] <=> $b['_priority'];
        }

        $urgA = isset($a['urgency']) && is_numeric($a['urgency']) ? (float) $a['urgency'] : PHP_FLOAT_MAX;
        $urgB = isset($b['urgency']) && is_numeric($b['urgency']) ? (float) $b['urgency'] : PHP_FLOAT_MAX;
        if (abs($urgA - $urgB) > 0.0001) {
            return $urgA <=> $urgB;
        }

        $idA = (int) ($a['id'] ?? $a['quiz_id'] ?? 0);
        $idB = (int) ($b['id'] ?? $b['quiz_id'] ?? 0);
        if ($idA !== $idB) {
            return $idA <=> $idB;
        }

        return $a['_orig_idx'] <=> $b['_orig_idx'];
    });

    $sliced = array_slice($valid, 0, $limit);
    foreach ($sliced as &$row) {
        unset($row['_priority'], $row['_orig_idx']);
    }
    unset($row);

    return $sliced;
}
