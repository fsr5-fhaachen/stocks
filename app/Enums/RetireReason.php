<?php

namespace App\Enums;

/**
 * Why an item (or a part of a bulk item) left the inventory.
 */
enum RetireReason: string
{
    use HasOptions;

    case Consumed = 'consumed';
    case Defective = 'defective';
    case Lost = 'lost';
    case Sold = 'sold';
    case Disposed = 'disposed';
    case Other = 'other';
    // set for split off parts that went back into their origin, not offered for selection
    case Merged = 'merged';

    /**
     * Reasons to choose when retiring: "verbraucht" has its own action, "zurückgebucht" is only set
     * by the migration of split off parts.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function choices(): array
    {
        return array_values(array_filter(self::options(), fn (array $option) => ! in_array($option['value'], [self::Consumed->value, self::Merged->value], true)));
    }

    public function label(): string
    {
        return match ($this) {
            self::Consumed => 'verbraucht',
            self::Defective => 'defekt',
            self::Lost => 'verloren',
            self::Sold => 'verkauft',
            self::Disposed => 'entsorgt',
            self::Other => 'sonstiges',
            self::Merged => 'zurückgebucht',
        };
    }
}
