<?php
namespace App\Support;

/** Bangladeshi mobile numbers in one canonical form: 01XXXXXXXXX (11 digits). Other numbers keep their digits. */
final class Phone {
    public static function normalize(?string $raw): string {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($d, '8801') && strlen($d) === 13) $d = substr($d, 2);
        elseif (str_starts_with($d, '1') && strlen($d) === 10) $d = '0'.$d;
        return substr($d, 0, 20);
    }
}
