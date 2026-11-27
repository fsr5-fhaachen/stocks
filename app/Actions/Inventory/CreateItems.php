<?php

namespace App\Actions\Inventory;

use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create several equal single pieces at once (guided capture, "Anzahl gleicher Stücke"): each
 * gets its own number, the numbers follow each other. The photos are uploaded once and copied to
 * the other pieces. All or nothing: if one piece fails, none is created.
 */
class CreateItems
{
    /**
     * Most pieces in one go.
     */
    public const MAX_COUNT = 50;

    public function __construct(private readonly CreateItem $createItem) {}

    /**
     * @param  array<string, mixed>  $data  fields of the item, see CreateItem
     * @param  list<UploadedFile>  $photos
     * @return list<Item> the new items in the order of their numbers
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ItemCondition $condition, Location|Item|null $place, Location|Item|null $home, array $photos, int $count): array
    {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw ValidationException::withMessages(['count' => 'Es gehen 1 bis '.self::MAX_COUNT.' Stück auf einmal.']);
        }
        if ($count > 1 && ($data['kind'] ?? null) !== ItemKind::Single->value) {
            throw ValidationException::withMessages(['count' => 'Mehrere gleiche Stücke gibt es nur bei Einzelstücken. Für eine Menge wähle „Menge“ und gib die Anzahl dort an.']);
        }

        return DB::transaction(function () use ($actor, $data, $condition, $place, $home, $photos, $count) {
            $first = $this->createItem->handle($actor, $data, $condition, $place, $home, $photos);
            $items = [$first];

            for ($piece = 2; $piece <= $count; $piece++) {
                $item = $this->createItem->handle($actor, $data, $condition, $place, $home);
                foreach ($first->photos()->orderBy('sort')->get() as $photo) {
                    if ($photo->copyTo($item) === null) {
                        throw ValidationException::withMessages(['photos' => 'Die Fotos konnten nicht für alle Stücke gespeichert werden, der Speicher ist gerade nicht erreichbar. Versuch es später noch einmal.']);
                    }
                }
                $items[] = $item;
            }

            return $items;
        });
    }
}
