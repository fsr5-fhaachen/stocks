<?php

namespace App\Support\Labels;

use App\Enums\ItemKind;
use App\Enums\LabelTarget;
use App\Models\Item;
use App\Models\Location;
use App\Models\ReservedNumber;
use App\Support\Inventory\ItemNumbers;
use App\Support\Settings;
use App\Support\Surface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Data of one label: the code for the short link and the values of the variables of the templates.
 * The server delivers the values, the frontend only puts them into the texts.
 */
class LabelData
{
    public function __construct(private readonly ShortLink $links, private readonly Settings $settings, private readonly ItemNumbers $numbers) {}

    /**
     * Label of an item or a box.
     *
     * @return array<string, mixed>
     */
    public function forItem(Item $item): array
    {
        $item->loadMissing(['category.parent', 'condition', 'location', 'container', 'homeLocation', 'homeContainer', 'photos']);

        return $this->itemLabel($item, "item-{$item->id}", [
            'photo_url' => ($photo = $item->photos->first()) ? Surface::route('inventory.photos.show', $photo) : null,
            'printed_at' => $item->label_printed_at?->toIso8601String(),
        ]);
    }

    /**
     * Label of an item that is not saved yet (preview of the guided capture). The number is a
     * placeholder with the prefix of new numbers; the browser shows the chosen photo itself.
     *
     * @return array<string, mixed>
     */
    public function forDraft(Item $item): array
    {
        $item->number = $this->numbers->placeholder();
        $item->setRelation('photos', new Collection);
        $item->loadMissing(['category.parent', 'condition', 'location', 'container', 'homeLocation', 'homeContainer']);

        return $this->itemLabel($item, 'draft');
    }

    /**
     * Label data of an item or a box, saved or not.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function itemLabel(Item $item, string $key, array $extra = []): array
    {
        $target = $item->is_container ? LabelTarget::Box : LabelTarget::Item;

        return $this->label($target, $key, $item->id, $item->number, $item->name, [
            'name' => $item->name,
            'number' => $item->number,
            'category' => $item->category?->fullName() ?? '',
            'quantity' => $item->kind === ItemKind::Bulk ? trim("{$item->quantity} {$item->unit}") : '1',
            'home' => Item::placeSummary($item->home())['code'] ?? '',
            'location' => Item::placeSummary($item->place())['code'] ?? '',
            'description' => (string) $item->description,
            'condition' => $item->condition->name,
            'hint' => (string) $item->label_hint,
            'count' => $item->is_container ? ($item->exists ? (string) $item->contents()->active()->count() : '0') : '',
        ], $extra);
    }

    /**
     * Label of a location.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Location $location): array
    {
        return $this->label(LabelTarget::Location, "location-{$location->id}", $location->id, $location->full_code, $location->full_code, [
            'name' => (string) $location->name,
            'number' => $location->full_code,
            'location' => (string) $location->parent?->full_code,
            'description' => (string) $location->description,
        ], ['printed_at' => $location->label_printed_at?->toIso8601String()]);
    }

    /**
     * Stock label of a reserved number.
     *
     * @return array<string, mixed>
     */
    public function forReserved(ReservedNumber $reserved): array
    {
        return $this->label(LabelTarget::Reserve, "reserved-{$reserved->id}", $reserved->id, $reserved->number, $reserved->number, [
            'number' => $reserved->number,
        ]);
    }

    /**
     * Data of a label with the variables every target has.
     *
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function label(LabelTarget $target, string $key, ?int $id, string $code, string $title, array $values, array $extra = []): array
    {
        $url = $this->links->url($code);

        return [
            'key' => $key,
            'target' => $target->value,
            'id' => $id,
            'title' => $title,
            'code' => $code,
            'url' => $url,
            'photo_url' => null,
            'printed_at' => null,
            ...$extra,
            'values' => [
                ...$values,
                'code' => $code,
                'owner' => (string) $this->settings->get('organization.name'),
                'contact' => (string) $this->settings->get('legal.contact_email'),
                'date' => now()->format('d.m.Y'),
                'url' => $url,
            ],
        ];
    }
}
