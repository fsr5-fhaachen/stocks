<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\MoveItem;
use App\Enums\ItemKind;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use App\Support\Inventory\CodeLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Enums\ReturnState;
use Modules\Loans\Models\LoanItem;

/**
 * Put an item back after the return ("Einräumen"). Stocks shows the home; the scan of the shelf
 * confirms it and moves the item there. A bulk item keeps its place during the loan: the scan
 * must name that place and only confirms it. Until then the item is "angenommen, noch nicht
 * eingeräumt".
 */
class StowItem
{
    public function __construct(
        private readonly CodeLookup $codes,
        private readonly MoveItem $moveItem,
    ) {}

    /**
     * Positions that came back and wait to be put back. Contents of boxes travel with their box; an
     * item that got a place some other way counts as put back. Something missing that turned up
     * again (ResolveMissing) waits here too, also from a box.
     *
     * @return Builder<LoanItem>
     */
    public static function pending(): Builder
    {
        return LoanItem::query()
            ->whereNotNull('returned_at')
            ->whereNull('stowed_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('parent_id')->where(fn (Builder $query) => $query->whereNull('return_state')->orWhere('return_state', '!=', ReturnState::Missing)->orWhere('returned_quantity', '>', 0)))
                ->orWhere('missing_resolution', 'found'))
            ->whereHas('loan', fn (Builder $query) => $query->where('status', '!=', LoanStatus::Cancelled))
            // a bulk item keeps its place and waits until someone confirms it; a single item waits
            // as long as it has no place (one that got a place some other way counts as put back)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('parent_id')->whereHas('item', fn (Builder $query) => $query->whereNull('retired_at')->where('kind', ItemKind::Bulk)))
                ->orWhereHas('item', fn (Builder $query) => $query->whereNull('retired_at')->whereNull('location_id')->whereNull('container_id')));
    }

    /**
     * Put the item of a position at the scanned place. A bulk item that has a place is not moved:
     * the scanned place must be its place, which confirms that the pieces are back there; another
     * place is refused with a hint. A bulk item without place (issued as a whole before bulk items
     * stopped being split) is put at the scanned place like a single item.
     *
     * @throws ValidationException
     */
    public function handle(User $actor, LoanItem $row, ?string $code): string
    {
        return DB::transaction(function () use ($actor, $row, $code) {
            // locked and checked again, so a second tap does not put it back twice
            LoanItem::whereKey($row->id)->lockForUpdate()->first();
            if (! self::pending()->whereKey($row->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'Die Position wartet nicht aufs Einräumen.']);
            }
            $row->refresh()->load(['item', 'loan']);

            $place = $this->codes->findPlace((string) $code)
                ?? throw ValidationException::withMessages(['code' => $this->codes->notFoundMessage((string) $code, place: true)]);
            $item = $row->item;

            if ($row->isBulk() && ($current = $item->place()) !== null) {
                if (! $current->is($place)) {
                    throw ValidationException::withMessages(['code' => "{$item->number} gehört nach {$this->codeOf($current)}, nicht nach {$this->codeOf($place)}. Dort einräumen oder {$item->number} vorher umlagern."]);
                }
                $row->forceFill(['stowed_at' => now()])->save();
                // what came back, plus missing pieces that turned up again
                $returned = ($row->returned_quantity ?? $row->quantity) + ($row->missing_resolution === 'found' ? (int) $row->missing_quantity : 0);

                return "{$returned} {$item->unit} von {$item->number} liegen wieder in {$this->codeOf($place)}.";
            }

            $this->moveItem->handle($actor, $item, $place, note: "Eingeräumt nach {$row->loan->number}.");
            $row->forceFill(['stowed_at' => now()])->save();

            return $item->home() !== null && ! $item->isAwayFromHome()
                ? "{$item->number} liegt wieder an seinem Stammplatz."
                : "{$item->number} liegt jetzt in {$this->codeOf($place)}, nicht am Stammplatz.";
        });
    }

    /**
     * Code of a place for messages.
     */
    private function codeOf(Location|Item $place): string
    {
        return $place instanceof Location ? $place->full_code : $place->number;
    }
}
