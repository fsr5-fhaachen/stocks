<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\MoveItem;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Inventory\CodeLookup;
use App\Support\Inventory\Placement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Moving items: first the item, then the target, both by code. A hand scanner types the code like
 * a keyboard, so a whole box of material can be put away one scan after the other.
 *
 * Pieces of a bulk item move between its places (StockBook::transfer), no part is split off.
 *
 * The switch "Ort / Stammplatz / beides" (mode place, home, both) makes the target the new home
 * instead of or as well as the new place. A new home needs inventory.edit, as in the item form.
 */
class MoveController extends Controller
{
    /**
     * Display the page for moving by scan.
     */
    public function create(): Response
    {
        return Inertia::render('Inventory/Move');
    }

    /**
     * Move an item to the place of a code, or make it its home (mode). Also used by the dialog on
     * the item page. Of a bulk item pieces move from one of its places ("from", a place key) to the
     * target; without "from" from its only place or "Eingang".
     */
    public function store(Request $request, CodeLookup $codes, MoveItem $moveItem): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'target_code' => ['required', 'string', 'max:50'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirmed' => ['boolean'],
            'mode' => ['nullable', Rule::in(['place', 'home', 'both'])],
        ]);
        $mode = $validated['mode'] ?? 'place';
        if ($mode !== 'place' && ! $request->user()->can('inventory.edit')) {
            throw ValidationException::withMessages(['mode' => 'Den Stammplatz ändern darfst du nicht.']);
        }

        $item = Item::findOrFail($validated['item_id']);

        if ($mode === 'home') {
            return $this->changeHome($codes, $item, $validated['target_code']);
        }

        $target = $codes->findPlace($validated['target_code'])
            ?? throw ValidationException::withMessages(['target_code' => "Zu {$validated['target_code']} gibt es keinen Standort und keine Box."]);

        // "beides" for an item that lies there already: only the home is new
        if ($mode === 'both' && ! $item->isBulk() && $item->place()?->is($target)) {
            return $this->changeHome($codes, $item, $validated['target_code']);
        }

        // "beides": the home follows the same rules as the place, MoveItem has checked them
        DB::transaction(function () use ($request, $moveItem, $item, $target, $validated, $mode) {
            $moveItem->handle($request->user(), $item, $target, $validated['quantity'] ?? null, $validated['note'] ?? null, $validated['from'] ?? null);
            if ($mode === 'both') {
                $item->setHome($target);
                $item->save();
            }
        });

        $targetCode = Item::placeSummary($target)['code'];
        $alsoHome = $mode === 'both' ? ', das ist auch sein Stammplatz' : '';

        if ($item->isBulk()) {
            $moved = $validated['quantity'] ?? null;

            return back()->with('success', ($moved ? "{$moved} {$item->unit}" : 'Die Stücke').' von '.$item->number." liegen jetzt in {$targetCode}{$alsoHome}.");
        }

        return back()->with('success', "{$item->number} liegt jetzt in {$targetCode}{$alsoHome}.");
    }

    /**
     * Make the place of a code the home of an item without moving it ("Stammplatz").
     *
     * @throws ValidationException
     */
    private function changeHome(CodeLookup $codes, Item $item, string $code): RedirectResponse
    {
        if ($item->isRetired()) {
            throw ValidationException::withMessages(['item' => "{$item->number} ist ausgemustert."]);
        }
        $home = $codes->findPlace($code)
            ?? throw ValidationException::withMessages(['target_code' => "Zu {$code} gibt es keinen Standort und keine Box."]);
        Placement::check($item, $home, 'target');
        if ($item->home()?->is($home)) {
            throw ValidationException::withMessages(['target_code' => "{$item->number} hat schon diesen Stammplatz."]);
        }

        // a model update, so the audit log shows the old and the new home
        $item->setHome($home);
        $item->save();

        $homeCode = Item::placeSummary($home)['code'];

        return back()->with('success', "Stammplatz von {$item->number} ist jetzt {$homeCode}.");
    }
}
