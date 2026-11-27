<?php

namespace App\Actions\Inventory;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a location or change it (code, name, parent, for buildings "im Code zeigen").
 *
 * The level follows from the parent: an address has no parent, a room lies in an address or a
 * building and so on. Where the parent allows two levels (an address: room or building), the
 * level is chosen when the location is created; it stays fixed afterwards. When the code, the
 * parent or "im Code zeigen" changes, the full codes of the whole subtree are recomputed.
 */
class SaveLocation
{
    /**
     * @param  array{parent_id: int|null, code: string, name?: string|null, description?: string|null, type?: string|null, show_in_code?: bool|null, no_label?: bool|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(?Location $location, array $data): Location
    {
        return DB::transaction(function () use ($location, $data) {
            $parent = $data['parent_id'] ? Location::findOrFail($data['parent_id']) : null;
            $type = $location?->type ?? $this->typeOf($parent, $data['type'] ?? null);

            $fitsParent = $parent ? in_array($type, $parent->type->childTypes(), true) : $type === LocationType::Address;
            if (! $fitsParent) {
                throw ValidationException::withMessages(['parent_id' => $this->levelMessage($type, $parent)]);
            }
            // the fixed levels also prevent a location from being moved below itself

            $location ??= new Location(['type' => $type]);
            $location->fill([
                'parent_id' => $parent?->id,
                'code' => mb_strtoupper($data['code']),
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                // only a building can leave its code out of the codes below it
                'show_in_code' => $type === LocationType::Building && (bool) ($data['show_in_code'] ?? $location->show_in_code),
                'no_label' => (bool) ($data['no_label'] ?? $location->no_label),
            ]);
            $location->setRelation('parent', $parent);

            // all new codes first: a collision stops before anything is changed
            $codes = $this->codesOf($location);
            $this->ensureFree($codes, $location);

            $location->full_code = $codes[0][1];
            $location->save();
            $this->updateChildCodes($location, array_slice($codes, 1));

            return $location;
        });
    }

    /**
     * Level of a new location below the parent: the only possible one, or the chosen one.
     */
    private function typeOf(?Location $parent, ?string $chosen): ?LocationType
    {
        if ($parent === null) {
            return LocationType::Address;
        }

        $types = $parent->type->childTypes();
        if ($chosen !== null && count($types) > 1) {
            return LocationType::tryFrom($chosen);
        }

        return $types[0] ?? null;
    }

    /**
     * The location and every location below it with its new full code, parents before children.
     *
     * @return list<array{0: Location, 1: string}>
     */
    private function codesOf(Location $location): array
    {
        $own = Location::fullCodeOf($location->parent, $location->type, $location->code);
        $codes = [[$location, $own]];
        if (! $location->exists) {
            return $codes;
        }

        // the children compute from the location as it will be
        $planned = $location->replicate();
        $planned->full_code = $own;
        $planned->show_in_code = $location->show_in_code;
        $planned->setRelation('parent', $location->parent);
        foreach ($location->children()->orderBy('id')->get() as $child) {
            $child->setRelation('parent', $planned);
            $codes = [...$codes, ...$this->codesOf($child)];
        }

        return $codes;
    }

    /**
     * Stop if a new code is taken by a location outside the changed subtree, or twice inside it.
     *
     * @param  list<array{0: Location, 1: string}>  $codes
     *
     * @throws ValidationException
     */
    private function ensureFree(array $codes, Location $location): void
    {
        $new = array_column($codes, 1);
        $ids = array_filter(array_map(fn (array $entry) => $entry[0]->id, $codes));
        $taken = Location::whereIn('full_code', $new)->whereNotIn('id', $ids)->value('full_code')
            ?? collect($new)->duplicates()->first();

        if ($taken !== null) {
            $field = $taken === $new[0] ? 'code' : ($location->type === LocationType::Building ? 'show_in_code' : 'code');
            throw ValidationException::withMessages([$field => "Die Kennziffer {$taken} gibt es schon."]);
        }
    }

    /**
     * Save the new full codes below a location.
     *
     * @param  list<array{0: Location, 1: string}>  $codes
     */
    private function updateChildCodes(Location $location, array $codes): void
    {
        foreach ($codes as [$child, $code]) {
            if ($child->full_code !== $code) {
                // saved one by one, so the audit log shows every changed code
                $child->unsetRelation('parent');
                $child->update(['full_code' => $code]);
            }
        }
    }

    /**
     * Message for a parent of the wrong level.
     */
    private function levelMessage(?LocationType $type, ?Location $parent): string
    {
        return match ($type) {
            null => $parent?->type === LocationType::Compartment ? 'In einem Fach gibt es keine weiteren Standorte.' : 'Diese Ebene gibt es nicht.',
            LocationType::Address => 'Eine Adresse liegt in keinem anderen Standort.',
            LocationType::Building => 'Ein Gebäude liegt in einer Adresse.',
            LocationType::Room => 'Ein Raum liegt in einer Adresse oder einem Gebäude.',
            LocationType::Shelf => 'Ein Lagerplatz liegt in einem Raum.',
            LocationType::Compartment => 'Ein Fach liegt in einem Lagerplatz.',
        };
    }
}
