<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calendario de días hábiles de Colombia.
 *  · Festivos fijos: 1 ene, 1 may, 20 jul, 7 ago, 8 dic, 25 dic.
 *  · Ley 51 de 1983 (Ley Emiliani): Reyes, San José, San Pedro y San Pablo, Asunción, Día de la Raza,
 *    Todos los Santos e Independencia de Cartagena se trasladan al lunes siguiente.
 *  · Según la Pascua: Jueves y Viernes Santo; Ascensión, Corpus Christi y Sagrado Corazón (lunes).
 * Lo usan RN-23 (no enviar cobros en festivos) y RN-24 (plazos de respuesta en días hábiles).
 */
class ColombianCalendar
{
    /** @var array<int, array<string, string>> festivos por año: 'Y-m-d' => nombre */
    private static array $cache = [];

    public static function holidays(int $year): array
    {
        return self::$cache[$year] ??= self::build($year);
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        return isset(self::holidays($date->year)[$date->toDateString()]);
    }

    /** Lunes a viernes que no son festivos. */
    public static function isBusinessDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && ! self::isHoliday($date);
    }

    /** Suma días hábiles a partir del día siguiente a $date (RN-24). */
    public static function addBusinessDays(CarbonInterface $date, int $days): CarbonImmutable
    {
        $current = CarbonImmutable::instance($date)->startOfDay();
        while ($days > 0) {
            $current = $current->addDay();
            if (self::isBusinessDay($current)) {
                $days--;
            }
        }

        return $current;
    }

    private static function build(int $year): array
    {
        $date = fn (int $m, int $d) => CarbonImmutable::create($year, $m, $d);
        $monday = fn (CarbonImmutable $d) => $d->isMonday() ? $d : $d->next(CarbonInterface::MONDAY);
        $easter = self::easter($year);

        $list = [
            [$date(1, 1), 'Año Nuevo'],
            [$monday($date(1, 6)), 'Día de los Reyes Magos'],
            [$monday($date(3, 19)), 'Día de San José'],
            [$easter->subDays(3), 'Jueves Santo'],
            [$easter->subDays(2), 'Viernes Santo'],
            [$date(5, 1), 'Día del Trabajo'],
            [$easter->addDays(43), 'Ascensión del Señor'],
            [$easter->addDays(64), 'Corpus Christi'],
            [$easter->addDays(71), 'Sagrado Corazón'],
            [$monday($date(6, 29)), 'San Pedro y San Pablo'],
            [$date(7, 20), 'Día de la Independencia'],
            [$date(8, 7), 'Batalla de Boyacá'],
            [$monday($date(8, 15)), 'La Asunción de la Virgen'],
            [$monday($date(10, 12)), 'Día de la Raza'],
            [$monday($date(11, 1)), 'Todos los Santos'],
            [$monday($date(11, 11)), 'Independencia de Cartagena'],
            [$date(12, 8), 'Inmaculada Concepción'],
            [$date(12, 25), 'Navidad'],
        ];

        $holidays = [];
        foreach ($list as [$day, $name]) {
            $holidays[$day->toDateString()] = $name;
        }
        ksort($holidays);

        return $holidays;
    }

    /** Domingo de Pascua (algoritmo anónimo gregoriano). */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
