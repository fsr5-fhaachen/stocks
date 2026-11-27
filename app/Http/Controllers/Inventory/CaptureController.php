<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateItems;
use App\Enums\ItemKind;
use App\Enums\LabelTarget;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\ItemPhoto;
use App\Models\ItemUnit;
use App\Models\LabelTemplate;
use App\Models\Printer;
use App\Support\Branding;
use App\Support\Inventory\ItemInput;
use App\Support\Inventory\Placement;
use App\Support\Labels\LabelData;
use App\Support\Labels\ShortLink;
use App\Support\Products\Gtin;
use App\Support\Products\ImageFetcher;
use App\Support\Products\ProductLookup;
use App\Support\Surface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guided capture of items (documentation/INVENTORY.md, "Erfassen"): data, photos, place, label,
 * summary, then create and print in one go. The page stays in its flow, so creating answers with
 * json instead of a redirect; printing happens in the browser like on the print page.
 */
class CaptureController extends Controller
{
    /**
     * How long a repeated request with the same capture_key gets the items of the first one.
     */
    private const KEY_MINUTES = 30;

    public function __construct(private readonly ItemInput $input, private readonly LabelData $labels, private readonly ProductLookup $products) {}

    /**
     * Display the guided capture. Templates and printers only with the right to print labels.
     */
    public function show(Request $request, ShortLink $links, Branding $branding): Response
    {
        $printing = $request->user()->can('labels.print');

        return Inertia::render('Inventory/Capture', [
            'categories' => Category::options(),
            'kinds' => ItemKind::options(),
            'units' => ItemUnit::options(),
            'conditions' => ItemCondition::options(),
            'maxPhotos' => ItemPhoto::MAX_PER_ITEM,
            'maxCount' => CreateItems::MAX_COUNT,
            // barcode of the packaging: name and images from product databases (setting)
            'productLookup' => $this->products->enabled(),
            'printing' => $printing ? [
                'templates' => LabelTemplate::whereIn('target', [LabelTarget::Item, LabelTarget::Box])->orderBy('name')->get()
                    ->map(fn (LabelTemplate $template) => $template->summary()),
                'printers' => Printer::with('printerModel')->orderBy('name')->get()->map(fn (Printer $printer) => $printer->summary()),
                'variables' => [
                    LabelTarget::Item->value => LabelTarget::Item->variables(),
                    LabelTarget::Box->value => LabelTarget::Box->variables(),
                ],
                'baseUrl' => $links->baseUrl(),
                'baseUrlConfirmed' => $links->isConfirmed(),
                'logoUrl' => $branding->url($branding->printKind()),
            ] : null,
        ]);
    }

    /**
     * Label data of the item being captured, before it exists (json). Creates nothing.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...array_diff_key($this->input->createRules(), array_flip(['photos', 'photos.*'])),
        ]);

        $item = new Item($this->input->data($validated));
        $place = $this->input->place($validated['place_code'] ?? null, 'place_code');
        $home = $this->input->place($validated['home_code'] ?? null, 'home_code');
        Placement::check($item, $place, 'place');
        Placement::check($item, $home, 'home');
        if ($item->kind === ItemKind::Single) {
            $item->quantity = 1;
            $item->unit = null;
        }
        $item->condition()->associate(ItemCondition::findOrFail($validated['condition_id']));
        $item->setPlace($place);
        $item->setHome($home);

        return response()->json(['label' => $this->labels->forDraft($item)]);
    }

    /**
     * Create the item, or several equal single pieces (json with the items and their labels).
     * A repeated request with the same capture_key answers with the items of the first one.
     */
    public function store(Request $request, CreateItems $createItems): JsonResponse
    {
        $validated = $request->validate([
            ...$this->input->createRules(),
            'count' => ['nullable', 'integer', 'min:1', 'max:'.CreateItems::MAX_COUNT],
            'capture_key' => ['required', 'string', 'max:64'],
            // photos taken from a product database: "<barcode>:<index of the image>" per photo
            'photo_refs' => ['array'],
            'photo_refs.*' => ['nullable', 'string', 'regex:/^\d{8,14}:\d{1,2}$/'],
        ]);
        $user = $request->user();
        $key = "inventory-capture:{$user->id}:{$validated['capture_key']}";

        $items = Cache::lock("{$key}:lock", 60)->block(30, function () use ($request, $validated, $user, $key, $createItems) {
            $ids = Cache::get($key);
            if (is_array($ids)) {
                return Item::whereIn('id', $ids)->orderBy('number')->get()->all();
            }

            $items = $createItems->handle(
                $user,
                $this->input->data($validated),
                ItemCondition::findOrFail($validated['condition_id']),
                $this->input->place($validated['place_code'] ?? null, 'place_code'),
                $this->input->place($validated['home_code'] ?? null, 'home_code'),
                $request->file('photos', []),
                (int) ($validated['count'] ?? 1),
                $this->photoSources($validated['photo_refs'] ?? []),
            );
            Cache::put($key, array_map(fn (Item $item) => $item->id, $items), now()->addMinutes(self::KEY_MINUTES));

            return $items;
        });

        $printing = $user->can('labels.print');

        return response()->json([
            'items' => array_map(fn (Item $item) => [
                ...$item->only(['id', 'number', 'name']),
                'place' => Item::placeSummary($item->place()),
                'href' => Surface::link('inventory.items.show', $item),
            ], $items),
            'labels' => $printing ? array_map(fn (Item $item) => $this->labels->forItem($item), $items) : [],
        ], 201);
    }

    /**
     * Suggestions for the barcode of a packaging (json): name, description, images and the items
     * that have this barcode already.
     */
    public function product(Request $request): JsonResponse
    {
        abort_unless($this->products->enabled(), 404);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'format' => ['nullable', 'string', 'max:20'],
        ]);
        $gtin = Gtin::normalize($validated['code'], $validated['format'] ?? null)
            ?? throw ValidationException::withMessages(['code' => 'Das ist kein gültiger Barcode einer Verpackung (EAN oder UPC). Prüfziffer falsch?']);

        $existing = Item::active()->where('ean', $gtin)->with(['location', 'container'])->orderBy('number')->limit(5)->get();

        return response()->json([
            ...$this->products->lookup($gtin),
            'existing' => $existing->map(fn (Item $item) => [
                ...$item->only(['id', 'number', 'name']),
                'place' => Item::placeSummary($item->place()),
                'href' => Surface::link('inventory.items.show', $item),
            ])->all(),
        ]);
    }

    /**
     * An image of the suggestions, downloaded by the server and handed on as jpeg. Only images of
     * a cached answer, never a free address.
     */
    public function productImage(string $gtin, int $index, ImageFetcher $fetcher): HttpResponse
    {
        abort_unless($this->products->enabled(), 404);
        $image = $this->products->cachedImage($gtin, $index);
        abort_if($image === null, 404);

        $jpeg = $fetcher->fetch($image['url']);
        abort_if($jpeg === null, 404, 'Das Bild ließ sich nicht laden.');

        return response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * Origin of the photos taken from product databases, by the keys of the photos.
     *
     * @param  array<int|string, string|null>  $refs
     * @return array<int, array<string, string|null>|null>
     */
    private function photoSources(array $refs): array
    {
        $sources = [];
        foreach ($refs as $key => $ref) {
            if ($ref !== null && str_contains($ref, ':')) {
                [$gtin, $index] = explode(':', $ref);
                $sources[(int) $key] = $this->products->origin($gtin, (int) $index);
            }
        }

        return $sources;
    }
}
