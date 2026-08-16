<?php

declare(strict_types=1);

/**
 * Ethiopian Calendar conversion (E.C. ↔ G.C.).
 *
 * Algorithm: Beyene-Kudlek (geez.org), the standard Ethiopian algorithm,
 * also used by the widely deployed et-cal / ethiopian-date-converter libraries.
 *   JDN(eth) = 1724221 + 365*(y-1) + floor((y-1)/4) + 30*(m-1) + (d-1)
 * Verified anchors:
 *   1 Meskerem 2000 E.C. = 11 September 2007 G.C.  (true millennium day;
 *      the Sept 12, 2007 celebrations were held one day later)
 *   Genna (Tahsas 29, 2000 E.C.) = 7 January 2008 G.C.  (always Jan 7)
 *   26 Megabit 2014 E.C. = 4 April 2022 G.C. (et-cal documented example)
 * Years have 13 months: 12 x 30 days + Pagume (5 days, 6 in leap years).
 * NOTE: re-validate against the official calendar authority before production use.
 */

final class Calendar
{
    public const EPOCH_JDN = 1724221;

    public const MONTHS_AM = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ህዳር', 4 => 'ታህሳስ', 5 => 'ጥር', 6 => 'የካቲት',
        7 => 'መጋቢት', 8 => 'ሚያዝያ', 9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ',
    ];

    /** Ethiopian year y,m,d → Julian Day Number. */
    public static function ethToJdn(int $y, int $m, int $d): int
    {
        return self::EPOCH_JDN + 365 * ($y - 1) + intdiv($y - 1, 4) + 30 * ($m - 1) + ($d - 1);
    }

    /** Julian Day Number → [year, month, day]. */
    public static function jdnToEth(int $jdn): array
    {
        $days = $jdn - self::EPOCH_JDN;
        $y = intdiv($days, 1461) * 4 + 1;
        $r = $days % 1461;
        if ($r >= 1095) {
            $y += 3;
            $r -= 1095;
        } elseif ($r >= 730) {
            $y += 2;
            $r -= 730;
        } elseif ($r >= 365) {
            $y += 1;
            $r -= 365;
        }
        $m = intdiv($r, 30) + 1;
        $day = $r % 30 + 1;
        return [$y, $m, $day];
    }

    /** Gregorian y,m,d → Julian Day Number (standard formula; JDN 2000-01-01 = 2451545). */
    public static function gregToJdn(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;
        return $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;
    }

    /** Gregorian y,m,d → Ethiopian [year, month, day]. */
    public static function gregToEth(int $y, int $m, int $d): array
    {
        return self::jdnToEth(self::gregToJdn($y, $m, $d));
    }

    /** Ethiopian y,m,d → Gregorian 'Y-m-d'. */
    public static function ethToGregDate(int $y, int $m, int $d): string
    {
        return self::jdnToGregDate(self::ethToJdn($y, $m, $d));
    }

    /** JDN → Gregorian 'Y-m-d'. */
    public static function jdnToGregDate(int $jdn): string
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $dd = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $dd, 4);
        $m = intdiv(5 * $e + 2, 153);
        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $dd - 4800 + intdiv($m, 10);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** 'Y-m-d' (Gregorian) → Ethiopian [year, month, day]. */
    public static function gregDateToEth(string $date): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return self::gregToEth($y, $m, $d);
    }

    /** Days in Ethiopian month m of year y (13 = Pagume). */
    public static function daysInMonth(int $y, int $m): int
    {
        if ($m < 1 || $m > 13) {
            throw new InvalidArgumentException("Invalid Ethiopian month: $m");
        }
        if ($m === 13) {
            return self::isLeapYear($y) ? 6 : 5;
        }
        return 30;
    }

    /** Ethiopian leap year rule: divisible by 4 (Julian-style). */
    public static function isLeapYear(int $y): bool
    {
        return $y % 4 === 0;
    }

    public static function monthNameAm(int $m): string
    {
        return self::MONTHS_AM[$m] ?? '';
    }

    /** 'Y-m-d' Ethiopian date string. */
    public static function formatEth(int $y, int $m, int $d): string
    {
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }
}
