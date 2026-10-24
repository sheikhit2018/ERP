<?php
namespace App\Support;

/** RFC 6238 time-based one-time passwords (Google Authenticator, Microsoft Authenticator, Authy…). */
final class Totp {
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string { return self::base32(random_bytes($bytes)); }

    public static function uri(string $secret, string $account, string $issuer): string {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function code(string $secret, ?int $time = null): string {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::unbase32($secret), true);
        $o = ord($hash[19]) & 0x0f;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and one step either side (clock drift). */
    public static function verify(string $secret, string $code, ?int $time = null): bool {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;
        $t = $time ?? time();
        foreach ([-30, 0, 30] as $d) if (hash_equals(self::code($secret, $t + $d), $code)) return true;
        return false;
    }

    public static function recoveryCodes(int $n = 8): array {
        return array_map(fn () => strtolower(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))), range(1, $n));
    }

    private static function base32(string $bin): string {
        $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }
    private static function unbase32(string $s): string {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = ''; foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = ''; foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }
}
