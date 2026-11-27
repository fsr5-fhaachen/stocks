<?php

namespace App\Support\Inventory;

use Illuminate\Validation\ValidationException;

/**
 * Codes of several locations in a row ("Mehrere anlegen"), counted on from the first one in its
 * own pattern: "1" → 1, 2, 3; "R01" → R01, R02; "R9" → R9, R10; "A" → A, B, C; "R03A" → R03A, R03B.
 * A code ending in a digit counts the number at its end and keeps its width; one ending in a
 * letter counts that letter up to Z. The frontend counts the same way for the preview
 * (components/inventory/LocationModal.vue).
 */
class CodeSequence
{
    /**
     * Most locations in one go.
     */
    public const MAX_COUNT = 50;

    /**
     * @return list<string> the codes in capitals, the first one first
     *
     * @throws ValidationException
     */
    public static function from(string $first, int $count): array
    {
        $first = mb_strtoupper($first);
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw ValidationException::withMessages(['count' => 'Es gehen 1 bis '.self::MAX_COUNT.' auf einmal.']);
        }

        if (preg_match('/^(.*?)(\d+)$/', $first, $match)) {
            [, $prefix, $digits] = $match;

            return array_map(
                fn (int $offset) => $prefix.str_pad((string) ((int) $digits + $offset), strlen($digits), '0', STR_PAD_LEFT),
                range(0, $count - 1),
            );
        }

        if (preg_match('/^(.*?)([A-Z])$/', $first, $match)) {
            [, $prefix, $letter] = $match;
            if (ord($letter) + $count - 1 > ord('Z')) {
                throw ValidationException::withMessages(['count' => "Von {$letter} aus gehen nur ".(ord('Z') - ord($letter) + 1).' Buchstaben bis Z.']);
            }

            return array_map(fn (int $offset) => $prefix.chr(ord($letter) + $offset), range(0, $count - 1));
        }

        throw ValidationException::withMessages(['code' => 'Das Kürzel muss auf eine Ziffer oder einen Buchstaben enden, damit Stocks weiterzählen kann.']);
    }
}
