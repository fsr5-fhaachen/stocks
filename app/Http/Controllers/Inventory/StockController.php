<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\StockBook;
use App\Enums\ItemKind;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemStock;
use App\Support\Inventory\CodeLookup;
use App\Support\Products\Gtin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock of bulk items per place: "Wareneingang" (new pieces into "Eingang"), "+" at a place (out of
 * "Eingang"), the target quantity of a place ("Sollmenge"), and filling a box or location up to its
 * targets ("Auffüllen").
 * Moving pieces ("Einpacken", "Einräumen") is a move (MoveController). See documentation/INVENTORY.md.
 */
class StockController extends Controller
{
    /**
     * The page "Wareneingang": booking in, and what lies in "Eingang".
     */
    public function index(): Response
    {
        $incoming = ItemStock::query()
            ->where('place_key', ItemStock::INCOMING)
            ->where('quantity', '>', 0)
            ->whereHas('item', fn ($query) => $query->whereNull('retired_at'))
            ->with(['item.homeLocation', 'item.homeContainer'])
            ->latest('updated_at')
            ->get()
            ->map(fn (ItemStock $stock) => [
                'id' => $stock->item->id,
                'number' => $stock->item->number,
                'name' => $stock->item->name,
                'unit' => $stock->item->unit,
                'quantity' => $stock->quantity,
                'since' => $stock->updated_at?->toIso8601String(),
                'home' => Item::placeSummary($stock->item->home()),
                'stocks' => $stock->item->stocks()->get()->map(fn (ItemStock $entry) => $entry->summary())->values(),
            ]);

        return Inertia::render('Inventory/StockIn', ['incoming' => $incoming]);
    }

    /**
     * Book new pieces of a bulk item into "Eingang". The item is found by its number (scan of the
     * label) or the barcode of the packaging (EAN).
     */
    public function receive(Request $request, CodeLookup $codes, StockBook $stock): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $item = $this->bulkItemOf($codes, $validated['code']);
        $stock->receive($request->user(), $item, $validated['quantity'], null, $validated['note'] ?? null);

        return back()->with('success', "{$validated['quantity']} {$item->unit} von {$item->number} {$item->name} liegen im Eingang.");
    }

    /**
     * Target quantity ("Sollmenge") of a place of a bulk item, from its row (item page, box
     * contents, location). Empty clears it. Counting is no own action: more pieces come out of
     * "Eingang" (add()), fewer leave with "Verbraucht" or "Ausmustern" (Entscheidung von Maurice).
     */
    public function update(Request $request, Item $item, StockBook $stock): RedirectResponse
    {
        $validated = $request->validate([
            'place' => ['required', 'string', 'max:20'],
            'target_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $place = StockBook::sourceOf($item, $validated['place'], 'place');
        if ($place === null) {
            throw ValidationException::withMessages(['target_quantity' => 'Das Eingang hat keine Sollmenge, es wird geleert, nicht gefüllt.']);
        }
        $stock->setTarget($item, $place, $validated['target_quantity'] ?? null);
        $code = Item::placeSummary($place)['code'];

        return back()->with('success', isset($validated['target_quantity'])
            ? "In {$code} sollen {$validated['target_quantity']} {$item->unit} liegen."
            : "{$item->name} hat in {$code} keine Sollmenge mehr.");
    }

    /**
     * "+" at a place: pieces out of "Eingang" to this place (enough must lie there); at "Eingang"
     * itself new pieces ("Wareneingang", needs inventory.edit).
     */
    public function add(Request $request, Item $item, StockBook $stock): RedirectResponse
    {
        $validated = $request->validate([
            'place' => ['required', 'string', 'max:20'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $place = StockBook::sourceOf($item, $validated['place'], 'place');
        $quantity = $validated['quantity'];

        if ($place === null) {
            abort_unless($request->user()->can('inventory.edit'), 403);
            $stock->receive($request->user(), $item, $quantity, null, $validated['note'] ?? null);

            return back()->with('success', "{$quantity} {$item->unit} von {$item->number} liegen im Eingang.");
        }

        $incoming = ItemStock::for($item, null)->quantity;
        if ($incoming < $quantity) {
            throw ValidationException::withMessages(['quantity' => $incoming === 0
                ? "Im Eingang liegt nichts von {$item->number}. Erst einbuchen (Wareneingang)."
                : "Im Eingang liegen nur {$incoming} {$item->unit}."]);
        }
        $stock->transfer($request->user(), $item, null, $place, $quantity, $validated['note'] ?? 'Aus dem Eingang.');

        return back()->with('success', "{$quantity} {$item->unit} aus dem Eingang liegen jetzt in ".Item::placeSummary($place)['code'].'.');
    }

    /**
     * Target quantity ("Sollmenge") of a bulk item at a box or location, also where nothing of it
     * lies yet ("0 / soll 8"); "Auffüllen" then brings the pieces. An empty target clears it.
     */
    public function target(Request $request, CodeLookup $codes, StockBook $stock): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:200'],
            'place_code' => ['required', 'string', 'max:200'],
            'target_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $item = $this->bulkItemOf($codes, $validated['code']);
        $place = $codes->findPlace($validated['place_code'])
            ?? throw ValidationException::withMessages(['place_code' => $codes->notFoundMessage($validated['place_code'], place: true)]);

        $stock->setTarget($item, $place, $validated['target_quantity'] ?? null);
        $code = Item::placeSummary($place)['code'];

        return back()->with('success', isset($validated['target_quantity'])
            ? "In {$code} sollen {$validated['target_quantity']} {$item->unit} {$item->name} liegen."
            : "{$item->name} hat in {$code} keine Sollmenge mehr.");
    }

    /**
     * "Auffüllen": every bulk item below its target at a box or location gets the missing pieces
     * from "Eingang", as far as "Eingang" has them.
     */
    public function fill(Request $request, CodeLookup $codes, StockBook $stock): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:200']]);
        $place = $codes->findPlace($validated['code'])
            ?? throw ValidationException::withMessages(['code' => $codes->notFoundMessage($validated['code'], place: true)]);

        $filled = [];
        $missing = [];
        $short = ItemStock::query()->at($place)->whereNotNull('target_quantity')->whereColumn('quantity', '<', 'target_quantity')
            ->whereHas('item', fn ($query) => $query->whereNull('retired_at'))->with('item')->get();
        foreach ($short as $entry) {
            $item = $entry->item;
            $incoming = ItemStock::for($item, null)->quantity;
            $pieces = min($entry->target_quantity - $entry->quantity, $incoming);
            if ($pieces > 0) {
                $stock->transfer($request->user(), $item, null, $place, $pieces, 'Aufgefüllt.');
                $filled[] = "{$pieces} {$item->unit} {$item->name}";
            }
            if ($entry->target_quantity - $entry->quantity > $pieces) {
                $missing[] = ($entry->target_quantity - $entry->quantity - $pieces)." {$item->unit} {$item->name}";
            }
        }

        $code = Item::placeSummary($place)['code'];
        if ($short->isEmpty()) {
            return back()->with('success', "In {$code} fehlt nichts.");
        }
        $message = $filled === [] ? "Im Eingang liegt nichts für {$code}." : 'Aufgefüllt: '.implode(', ', $filled).'.';
        if ($missing !== []) {
            return back()->with('warning', $message.' Es fehlen noch '.implode(', ', $missing).'.');
        }

        return back()->with('success', $message);
    }

    /**
     * Active bulk item of a code: its number or the barcode of its packaging.
     *
     * @throws ValidationException
     */
    private function bulkItemOf(CodeLookup $codes, string $code): Item
    {
        $found = $codes->find($code);
        if ($found === null && ($ean = Gtin::normalize($code)) !== null) {
            $found = Item::active()->where('kind', ItemKind::Bulk)->where('ean', $ean)->orderBy('id')->first();
        }
        if (! $found instanceof Item) {
            throw ValidationException::withMessages(['code' => $codes->notFoundMessage($code)]);
        }
        if (! $found->isBulk()) {
            throw ValidationException::withMessages(['code' => "{$found->number} ist kein Mengenartikel. Einzelstücke legst du über „Erfassen“ an."]);
        }
        if ($found->isRetired()) {
            throw ValidationException::withMessages(['code' => "{$found->number} ist ausgemustert."]);
        }

        return $found;
    }
}
