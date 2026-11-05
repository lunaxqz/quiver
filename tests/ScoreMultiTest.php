<?php
declare(strict_types=1);

namespace Quiver\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/rules/score_multi.php';

final class ScoreMultiTest extends TestCase
{
    public function testExactMatchGetsFullCredit(): void
    {
        $this->assertSame(2.0, \score_multi([1, 3], [1, 3], 2.0));
    }

    public function testOrderDoesNotMatter(): void
    {
        $this->assertSame(2.0, \score_multi([1, 3], [3, 1], 2.0));
    }

    public function testMissingACorrectOptionGetsZero(): void
    {
        $this->assertSame(0.0, \score_multi([1, 3], [1], 2.0));
    }

    public function testAnExtraWrongOptionGetsZero(): void
    {
        $this->assertSame(0.0, \score_multi([1, 3], [1, 3, 4], 2.0));
    }

    public function testSelectingNothingGetsZero(): void
    {
        $this->assertSame(0.0, \score_multi([1, 3], [], 2.0));
    }

    public function testCompletelyWrongGetsZero(): void
    {
        $this->assertSame(0.0, \score_multi([1, 3], [2, 4], 2.0));
    }

    public function testRepeatedIdsAreIgnored(): void
    {
        $this->assertSame(2.0, \score_multi([1, 3], [1, 1, 3], 2.0));
    }

    public function testQuestionWithNoCorrectOptionNeverScores(): void
    {
        $this->assertSame(0.0, \score_multi([], [], 2.0));
    }

    public function testFractionalPointsAreKept(): void
    {
        $this->assertSame(0.5, \score_multi([7], [7], 0.5));
    }
}
