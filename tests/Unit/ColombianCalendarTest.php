<?php

namespace Tests\Unit;

use App\Support\ColombianCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Festivos de Colombia (Ley 51 de 1983) y días hábiles (RN-23, RN-24). */
class ColombianCalendarTest extends TestCase
{
    public function test_holidays_2026(): void
    {
        $this->assertSame([
            '2026-01-01', '2026-01-12', '2026-03-23', '2026-04-02', '2026-04-03', '2026-05-01', '2026-05-18',
            '2026-06-08', '2026-06-15', '2026-06-29', '2026-07-20', '2026-08-07', '2026-08-17', '2026-10-12',
            '2026-11-02', '2026-11-16', '2026-12-08', '2026-12-25',
        ], array_keys(ColombianCalendar::holidays(2026)));
    }

    public function test_easter_based_holidays_2027(): void
    {
        $holidays = ColombianCalendar::holidays(2027);   // Pascua: 28 de marzo de 2027

        $this->assertSame('Jueves Santo', $holidays['2027-03-25']);
        $this->assertSame('Ascensión del Señor', $holidays['2027-05-10']);
        $this->assertSame('Corpus Christi', $holidays['2027-05-31']);
        $this->assertSame('Sagrado Corazón', $holidays['2027-06-07']);
    }

    public function test_business_days_skip_weekends_and_holidays(): void
    {
        // Viernes 30 oct 2026 + 2 hábiles: lunes 2 nov es festivo → martes 3 y miércoles 4.
        $this->assertSame('2026-11-04', ColombianCalendar::addBusinessDays(CarbonImmutable::parse('2026-10-30'), 2)->toDateString());
        $this->assertFalse(ColombianCalendar::isBusinessDay(CarbonImmutable::parse('2026-12-08')));
        $this->assertTrue(ColombianCalendar::isBusinessDay(CarbonImmutable::parse('2026-12-09')));
    }
}
