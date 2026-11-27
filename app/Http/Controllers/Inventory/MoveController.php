<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\MergeItem;
use App\Actions\Inventory\MoveItem;
use App\Enums\ItemKind;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Support\Extensions\ItemExtensions;
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
     * the item page. If the code is the item a part was split off from, the part is booked back
     * (MergeItem, only when moving).
     */
    public function store(Request $request, CodeLookup $codes, MoveItem $moveItem, MergeItem $mergeItem, ItemExtensions $extensions): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'target_code' => ['required', 'string', 'max:50'],
            'quantity' => ['nullable', 'integer', 'min:1'],
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

        // scanning the item a part was split off from books the part back
        $found = $codes->find($validated['target_code']);
        if ($found instanceof Item && $item->mergeTarget()?->id === $found->id) {
            $origin = $mergeItem->handle($request->user(), $item, $validated['quantity'] ?? null, $validated['note'] ?? null);

            return back()->with('success', "{$item->number} zurück zu {$origin->number} gebucht, dort sind jetzt {$origin->quantity} {$origin->unit}.");
        }

        $target = $codes->findPlace($validated['target_code'])
            ?? throw ValidationException::withMessages(['target_code' => "Zu {$validated['target_code']} gibt es keinen Standort und keine Box."]);

        // a part split off leaves the stock the bookings count on: ask first
        if ($item->kind === ItemKind::Bulk && ($validated['quantity'] ?? $item->quantity) < $item->quantity) {
            $extensions->ensureConfirmed($item, $request->boolean('confirmed'), 'abteilen');
        }

        // "beides" for an item that lies there already: only the home is new
        if ($mode === 'both' && $item->place()?->is($target)) {
            return $this->changeHome($codes, $item, $validated['target_code']);
        }

        // "beides": the home follows the same rules as the place, MoveItem has checked them
        $moved = DB::transaction(function () use ($request, $moveItem, $item, $target, $validated, $mode) {
            $moved = $moveItem->handle($request->user(), $item, $target, $validated['quantity'] ?? null, $validated['note'] ?? null);
            if ($mode === 'both') {
                $moved->setHome($target);
                $moved->save();
            }

            return $moved;
        });

        $targetCode = Item::placeSummary($target)['code'];
        $alsoHome = $mode === 'both' ? ', das ist auch sein Stammplatz' : '';

        if ($moved->is($item)) {
            return back()->with('success', "{$item->number} liegt jetzt in {$targetCode}{$alsoHome}.");
        }

        // the split off part has a new number and needs a label
        return back()
            ->with('success', "{$moved->quantity} {$moved->unit} von {$item->number} liegen jetzt als {$moved->number} in {$targetCode}.")
            ->with('label', ['id' => $moved->id, 'number' => $moved->number]);
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
