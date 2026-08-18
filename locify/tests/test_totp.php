<?php

declare(strict_types=1);

/** RFC 6238 test vectors (appendix B) + self-round-trip checks for Totp. */

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/security/Totp.php';

$fail = 0;

// RFC 6238 secret: the ASCII bytes of "12345678901234567890", expressed in
// the Base32 encoding the app generates and stores.
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

// RFC appendix-B vectors are 8-digit codes; LOCIFY uses the standard 6-digit
// form, i.e. the last six digits of each RFC value.
$vectors = [
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
];
foreach ($vectors as [$t, $expected]) {
    $got = Totp::codeAt($secret, (int)$t);
    $ok = $got === $expected;
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'PASS' : 'FAIL') . " T=$t => $got (expected $expected)\n";
}

$secret = Totp::generateSecret();
echo (preg_match('/^[A-Z2-7]{20}$/', $secret) ? 'PASS' : 'FAIL') . " secret format: $secret\n";

$code = Totp::codeAt($secret);
echo (Totp::verify($secret, $code) ? 'PASS' : 'FAIL') . " self-verify (same window)\n";
echo (Totp::verify($secret, $code, time() - 30) ? 'PASS' : 'FAIL') . " self-verify (previous window)\n";
echo (!Totp::verify($secret, '000000') ? 'PASS' : 'FAIL') . " wrong code rejected\n";
echo (!Totp::verify($secret, '12345') ? 'PASS' : 'FAIL') . " malformed code rejected\n";

$codes = Totp::recoveryCodes(10);
echo (count($codes) === 10 && count(array_unique($codes)) === 10 ? 'PASS' : 'FAIL') . " recovery codes unique\n";
echo (preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codes[0]) ? 'PASS' : 'FAIL') . " recovery code format: {$codes[0]}\n";

$uri = Totp::otpauthUri($secret, 'locify-admin', 'LOCIFY');
echo (str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret=') ? 'PASS' : 'FAIL') . " otpauth URI\n";

exit($fail === 0 ? 0 : 1);