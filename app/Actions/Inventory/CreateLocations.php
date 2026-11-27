<?php

namespace App\Actions\Inventory;

use App\Enums\LocationType;
use App\Models\Location;
use App\Support\Inventory\CodeSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create several locations of one level below one parent at once ("Mehrere anlegen", e.g. the
 * compartments 1 to 8 of a shelf). The codes count on from the first one (CodeSequence); name,
 * description and "Kein Etikett nötig" are the same for all. All or nothing: a code that is taken
 * stops before anything is created. Only shelves and compartments.
 */
class CreateLocations
{
    public function __construct(private readonly SaveLocation $saveLocation) {}

    /**
     * @param  array{parent_id: int|null, code: string, name?: string|null, description?: string|null, type?: string|null, no_label?: bool|null}  $data
     * @return list<Location> the new locations, the first code first
     *
     * @throws ValidationException
     */
    public function handle(array $data, int $count): array
    {
        $codes = CodeSequence::from($data['code'], $count);

        $parent = $data['parent_id'] ? Location::findOrFail($data['parent_id']) : null;
        $type = $this->typeOf($parent, $data['type'] ?? null);
        if ($parent === null || ! in_array($type, [LocationType::Shelf, LocationType::Compartment], true)) {
            throw ValidationException::withMessages(['count' => 'Mehrere auf einmal gehen nur bei Lagerplätzen und Fächern.']);
        }

        // all planned full codes first, so the message names every taken one
        $planned = array_map(fn (string $code) => Location::fullCodeOf($parent, $type, $code), $codes);
        $taken = Location::whereIn('full_code', $planned)->orderBy('full_code')->pluck('full_code')->all();
        if ($taken) {
            $label = count($taken) === 1 ? 'Die Kennziffer' : 'Die Kennziffern';
            throw ValidationException::withMessages(['code' => "{$label} ".implode(', ', $taken).' gibt es schon.']);
        }

        return DB::transaction(fn () => array_map(
            fn (string $code) => $this->saveLocation->handle(null, [...$data, 'code' => $code, 'show_in_code' => null]),
            $codes,
        ));
    }

    /**
     * Level of the new locations below the parent: the only possible one, or the chosen one. The
     * real check is SaveLocation's.
     */
    private function typeOf(?Location $parent, ?string $chosen): ?LocationType
    {
        $types = $parent?->type->childTypes() ?? [];
        if ($chosen !== null && count($types) > 1) {
            return LocationType::tryFrom($chosen);
        }

        return $types[0] ?? null;
    }
}
