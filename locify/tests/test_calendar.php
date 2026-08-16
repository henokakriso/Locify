<?php

define('LOCIFY_ROOT', dirname(__DIR__));

require LOCIFY_ROOT . '/config/config.php';
require LOCIFY_ROOT . '/app/helpers/Calendar.php';

$cases = [
    // [ethYear, ethMonth, ethDay, gregDate]
    [2000, 1, 1, '2007-09-11'],   // true millennium day
    [2000, 4, 29, '2008-01-07'],  // Genna: Tahsas 29 = Jan 7 (certain)
    [2014, 7, 26, '2022-04-04'],  // et-cal documented example
    [2013, 1, 1, '2020-09-11'],
    [2016, 1, 1, '2023-09-11'],
    [2017, 1, 1, '2024-09-11'],
    [2019, 1, 1, '2026-09-11'],
    [1992, 4, 23, '2000-01-01'],  // 1 Jan 2000 G.C. = 23 Tahsas 1992 E.C.
    [1999, 13, 5, '2007-09-10'],  // Pagume 5, common year
    [1, 1, 1, '0008-08-27'],      // epoch (proleptic Gregorian)
];

$allOk = true;
foreach ($cases as [$ey, $em, $ed, $expected]) {
    $got = Calendar::ethToGregDate($ey, $em, $ed);
    $ok = $got === $expected;
    $allOk = $allOk && $ok;
    printf("%s %d/%d/%d E.C. => %s (expected %s) %s\n", $ok ? 'PASS' : 'FAIL', $ey, $em, $ed, $got, $expected, $ok ? '' : '<-- MISMATCH');
}

// Reverse: 1 Jan 2000 G.C.
[$y, $m, $d] = Calendar::gregDateToEth('2000-01-01');
$ok = $y === 1992 && $m === 4 && $d === 23;
$allOk = $allOk && $ok;
printf("%s gregToEth(2000-01-01) => %d/%d/%d E.C.\n", $ok ? 'PASS' : 'FAIL', $y, $m, $d);

// Round trips
$rtOk = true;
for ($i = 0; $i < 5000; $i++) {
    $jdn = Calendar::EPOCH_JDN + $i * 137;
    [$y, $m, $d] = Calendar::jdnToEth($jdn);
    if (Calendar::ethToJdn($y, $m, $d) !== $jdn) { $rtOk = false; }
    $greg = Calendar::jdnToGregDate($jdn);
    [$ry, $rm, $rd] = Calendar::gregToEth((int)substr($greg,0,4), (int)substr($greg,5,2), (int)substr($greg,8,2));
    if ($ry !== $y || $rm !== $m || $rd !== $d) { $rtOk = false; }
}
printf("Round trips: %s\n", $rtOk ? 'PASS' : 'FAIL');
exit($allOk && $rtOk ? 0 : 1);
