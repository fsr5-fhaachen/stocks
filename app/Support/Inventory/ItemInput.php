<?php

namespace App\Support\Inventory;

use App\Enums\ItemKind;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Support\Products\Gtin;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Input of the item fields, shared by the item form (ItemController) and the guided capture
 * (CaptureController): validation rules, the fields of the item itself and the places of codes.
 */
class ItemInput
{
    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * Rules of the fields an item has when it is created or changed.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'kind' => ['required', Rule::enum(ItemKind::class)],
            'quantity' => ['required_if:kind,bulk', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['nullable', 'string', 'exists:item_units,name'],
            // bulk items: minimum stock ("Mindestbestand"), empty for none
            'min_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_container' => ['boolean'],
            'public_contents' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'label_hint' => ['nullable', 'string', 'max:100'],
            // "Kein Etikett nötig", see App\Enums\LabelStatus
            'no_label' => ['boolean'],
            'home_code' => ['nullable', 'string', 'max:50'],
            // barcode of the packaging; stored in one form, see Gtin::normalize()
            'ean' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                if (Gtin::normalize((string) $value) === null) {
                    $fail('Das ist keine gültige EAN (8, 12, 13 oder 14 Ziffern mit Prüfziffer).');
                }
            }],
        ];
    }

    /**
     * Rules of a new item: the fields plus condition, first place and photos.
     *
     * @return array<string, mixed>
     */
    public function createRules(): array
    {
        return [
            ...$this->rules(),
            'condition_id' => ['required', 'integer', 'exists:item_conditions,id'],
            'place_code' => ['nullable', 'string', 'max:50'],
            'photos' => ['array', 'max:'.ItemPhoto::MAX_PER_ITEM],
            'photos.*' => ItemPhotoController::fileRules(),
        ];
    }

    /**
     * Fields of the item itself from the validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function data(array $validated): array
    {
        return [
            ...array_intersect_key($validated, array_flip(['name', 'description', 'category_id', 'kind', 'unit', 'notes', 'label_hint'])),
            'quantity' => $validated['quantity'] ?? 1,
            'min_quantity' => ($validated['kind'] ?? null) === ItemKind::Bulk->value ? ($validated['min_quantity'] ?? null) : null,
            'is_container' => $validated['is_container'] ?? false,
            'public_contents' => $validated['public_contents'] ?? true,
            'no_label' => $validated['no_label'] ?? false,
            'ean' => isset($validated['ean']) ? Gtin::normalize($validated['ean']) : null,
        ];
    }

    /**
     * Find the place of a code, null for an empty code.
     *
     * @throws ValidationException
     */
    public function place(?string $code, string $field): Location|Item|null
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return $this->codes->findPlace($code)
            ?? throw ValidationException::withMessages([$field => $this->codes->notFoundMessage($code, place: true)]);
    }
}
