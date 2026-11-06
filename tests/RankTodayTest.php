<?php
declare(strict_types=1);

namespace Quiver\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/rules/rank_today.php';

final class RankTodayTest extends TestCase
{
    public function testStrictPriorityOrderAcrossAllSixTypes(): void
    {
        $unranked = [
            ['type' => 'incomplete_draft', 'id' => 60, 'urgency' => 1, 'title' => 'Draft'],
            ['type' => 'opens_soon',       'id' => 50, 'urgency' => 1, 'title' => 'Opens soon'],
            ['type' => 'ready_to_release', 'id' => 40, 'urgency' => 1, 'title' => 'Ready'],
            ['type' => 'needs_grading',    'id' => 30, 'urgency' => 1, 'title' => 'Needs grading'],
            ['type' => 'disconnected',     'id' => 20, 'urgency' => 1, 'title' => 'Disconnected'],
            ['type' => 'closing_soon',     'id' => 10, 'urgency' => 1, 'title' => 'Closing soon'],
        ];

        $ranked = \rank_today($unranked, 6);
        $types = array_column($ranked, 'type');

        $this->assertSame([
            'closing_soon',
            'disconnected',
            'needs_grading',
            'ready_to_release',
            'opens_soon',
            'incomplete_draft',
        ], $types);
    }

    public function testDefaultLimitCapsAtFiveItems(): void
    {
        $unranked = [
            ['type' => 'incomplete_draft', 'id' => 6, 'urgency' => 1],
            ['type' => 'opens_soon',       'id' => 5, 'urgency' => 1],
            ['type' => 'ready_to_release', 'id' => 4, 'urgency' => 1],
            ['type' => 'needs_grading',    'id' => 3, 'urgency' => 1],
            ['type' => 'disconnected',     'id' => 2, 'urgency' => 1],
            ['type' => 'closing_soon',     'id' => 1, 'urgency' => 1],
        ];

        $ranked = \rank_today($unranked);
        $this->assertCount(5, $ranked);
        $this->assertSame([
            'closing_soon',
            'disconnected',
            'needs_grading',
            'ready_to_release',
            'opens_soon',
        ], array_column($ranked, 'type'));
    }

    public function testWithinSameTypeOrdersByUrgencyAscendingThenId(): void
    {
        $items = [
            ['type' => 'needs_grading', 'id' => 102, 'urgency' => 300, 'title' => 'Newer ungraded'],
            ['type' => 'needs_grading', 'id' => 105, 'urgency' => 100, 'title' => 'Oldest ungraded B'],
            ['type' => 'needs_grading', 'id' => 101, 'urgency' => 100, 'title' => 'Oldest ungraded A'],
            ['type' => 'closing_soon',  'id' => 202, 'urgency' => 2400, 'title' => 'Closes in 40m'],
            ['type' => 'closing_soon',  'id' => 201, 'urgency' => 600,  'title' => 'Closes in 10m'],
        ];

        $ranked = \rank_today($items, 5);
        $this->assertSame([201, 202, 101, 105, 102], array_column($ranked, 'id'));
    }

    public function testIgnoresUnknownTypesAndHandlesEmptyArray(): void
    {
        $this->assertSame([], \rank_today([]));
        $this->assertSame([], \rank_today([['type' => 'unknown_type', 'id' => 1]]));
        $this->assertSame([], \rank_today([['type' => 'closing_soon', 'id' => 1]], 0));
    }
}
