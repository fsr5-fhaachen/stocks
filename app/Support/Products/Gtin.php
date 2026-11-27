<?php

namespace App\Support\Products;

/**
 * Barcodes of packaging: EAN-8, EAN-13, UPC-A, UPC-E and GTIN-14. Stored in one form, so the same
 * product is found again however it was scanned: UPC-A gets the leading 0 of its EAN-13, UPC-E is
 * expanded to UPC-A first.
 */
class Gtin
{
    /**
     * The code in its stored form (8, 13 or 14 digits), null if it is no valid barcode. $format is
     * the format the scanner reported ("upc_e" decides between EAN-8 and UPC-E).
     */
    public static function normalize(string $code, ?string $format = null): ?string
    {
        $digits = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6,14}$/', $digits)) {
            return null;
        }

        if ($format === 'upc_e' || (strlen($digits) === 8 && ! self::hasValidCheckDigit($digits) && self::upcE($digits) !== null)) {
            $digits = self::upcE($digits) ?? '';
        }

        return match (strlen($digits)) {
            8, 13, 14 => self::hasValidCheckDigit($digits) ? $digits : null,
            12 => self::hasValidCheckDigit($digits) ? '0'.$digits : null,
            default => null,
        };
    }

    /**
     * Check the last digit (modulo 10, weights 3 and 1 from the right).
     */
    public static function hasValidCheckDigit(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;
        foreach (array_reverse(str_split($body)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /**
     * UPC-E (8 digits with number system and check digit, or 6 digits without) as UPC-A, null if it
     * is none.
     */
    public static function upcE(string $digits): ?string
    {
        if (strlen($digits) === 6) {
            $digits = '0'.$digits.'?';
        }
        if (strlen($digits) !== 8 || ! in_array($digits[0], ['0', '1'], true)) {
            return null;
        }

        [$system, $d1, $d2, $d3, $d4, $d5, $d6] = str_split(substr($digits, 0, 7));
        $body = match (true) {
            in_array($d6, ['0', '1', '2'], true) => "{$system}{$d1}{$d2}{$d6}0000{$d3}{$d4}{$d5}",
            $d6 === '3' => "{$system}{$d1}{$d2}{$d3}00000{$d4}{$d5}",
            $d6 === '4' => "{$system}{$d1}{$d2}{$d3}{$d4}00000{$d5}",
            default => "{$system}{$d1}{$d2}{$d3}{$d4}{$d5}0000{$d6}",
        };

        // check digit of the UPC-A
        $sum = 0;
        foreach (array_reverse(str_split($body)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }
        $check = (string) ((10 - $sum % 10) % 10);

        if ($digits[7] !== '?' && $digits[7] !== $check) {
            return null;
        }

        return $body.$check;
    }

    /**
     * The code for sources that know products by UPC-A (12 digits) where it is one.
     */
    public static function upcA(string $gtin): ?string
    {
        return strlen($gtin) === 13 && $gtin[0] === '0' ? substr($gtin, 1) : null;
    }
}
