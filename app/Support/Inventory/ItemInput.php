<?php

namespace App\Support\Inventory;

use App\Enums\ItemKind;
use App\Http\Controllers\Inventory\ItemPhotoController;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
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
            'is_container' => ['boolean'],
            'public_contents' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'label_hint' => ['nullable', 'string', 'max:100'],
            'home_code' => ['nullable', 'string', 'max:50'],
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
            'is_container' => $validated['is_container'] ?? false,
            'public_contents' => $validated['public_contents'] ?? true,
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
