<?php
declare(strict_types=1);

namespace Quiver\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/rules/quiz_phase.php';

final class QuizPhaseTest extends TestCase
{
    private string $openStr  = '2026-11-09 08:00:00';
    private string $closeStr = '2026-11-09 09:00:00';

    public function testScheduledBeforeOpening(): void
    {
        $now = '2026-11-09 07:59:59';
        $this->assertSame('scheduled', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testOpenRightAtOpeningBoundary(): void
    {
        $now = '2026-11-09 08:00:00';
        $this->assertSame('open', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testOpenMidwayThroughWindow(): void
    {
        $now = '2026-11-09 08:30:00';
        $this->assertSame('open', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testOpenOneSecondBeforeClosing(): void
    {
        $now = '2026-11-09 08:59:59';
        $this->assertSame('open', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testClosedRightAtClosingBoundary(): void
    {
        $now = '2026-11-09 09:00:00';
        $this->assertSame('closed', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testClosedAfterClosing(): void
    {
        $now = '2026-11-09 09:00:01';
        $this->assertSame('closed', \quiz_phase($this->openStr, $this->closeStr, $now));
    }

    public function testWorksWithDateTimeImmutableInstances(): void
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable('2026-11-09 08:00:00', $utc);
        $end   = new DateTimeImmutable('2026-11-09 09:00:00', $utc);

        $before = new DateTimeImmutable('2026-11-09 07:45:00', $utc);
        $during = new DateTimeImmutable('2026-11-09 08:15:00', $utc);
        $after  = new DateTimeImmutable('2026-11-09 09:15:00', $utc);

        $this->assertSame('scheduled', \quiz_phase($start, $end, $before));
        $this->assertSame('open', \quiz_phase($start, $end, $during));
        $this->assertSame('closed', \quiz_phase($start, $end, $after));
    }

    public function testNullDatesAreSafelyClosed(): void
    {
        $this->assertSame('closed', \quiz_phase(null, $this->closeStr));
        $this->assertSame('closed', \quiz_phase($this->openStr, null));
        $this->assertSame('closed', \quiz_phase(null, null));
    }

    public function testReversedWindowIsClosed(): void
    {
        // If closing is before opening
        $this->assertSame('closed', \quiz_phase('2026-11-09 10:00:00', '2026-11-09 09:00:00', '2026-11-09 09:30:00'));
    }
}
