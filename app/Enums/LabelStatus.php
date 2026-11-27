<?php

namespace App\Enums;

use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * Label of an item or a location: printed, still missing, or not needed ("Kein Etikett nötig",
 * column no_label). Not needed wins over printed: the mark says nobody looks for a label there.
 * Printing a label takes the mark away again (App\Http\Controllers\Labels\PrintController).
 */
enum LabelStatus: string
{
    use HasOptions;

    case Missing = 'missing';
    case Printed = 'printed';
    case NotNeeded = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Etikett fehlt',
            self::Printed => 'Etikett gedruckt',
            self::NotNeeded => 'Bewusst ohne Etikett',
        };
    }

    /**
     * Status of an item or a location.
     */
    public static function of(Item|Location $model): self
    {
        return match (true) {
            $model->no_label => self::NotNeeded,
            $model->label_printed_at !== null => self::Printed,
            default => self::Missing,
        };
    }

    /**
     * Limit a query of items or locations to this status.
     *
     * @param  Builder<Item>|Builder<Location>  $query
     */
    public function apply(Builder $query): void
    {
        match ($this) {
            self::Missing => $query->where('no_label', false)->whereNull('label_printed_at'),
            self::Printed => $query->where('no_label', false)->whereNotNull('label_printed_at'),
            self::NotNeeded => $query->where('no_label', true),
        };
    }
}
