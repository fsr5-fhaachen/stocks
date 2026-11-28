<?php

namespace Modules\Loans\Actions;

use App\Actions\Inventory\StockBook;
use App\Enums\ItemKind;
use App\Enums\RetireReason;
use App\Models\Item;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;
use Modules\Loans\Support\Availability;

/**
 * Add, change and remove positions while nothing is issued. A draft takes everything and shows the
 * collisions; a confirmed loan only takes what is free, so it never holds an item twice.
 */
class ChangePositions
{
    public function __construct(private readonly Availability $availability, private readonly StockBook $stock) {}

    /**
     * Add an item or a box. A bulk item that is already a position gets the quantity added.
     *
     * @throws ValidationException
     */
    public function add(Loan $loan, Item $item, int $quantity = 1, ?string $source = null): LoanItem
    {
        $this->ensureOpen($loan);
        // a bulk item: the place its pieces will leave from at the issue ("Einpacken" into a loan);
        // the loan only reserves, the pieces stay there until then
        if ($source !== null && $item->isBulk()) {
            StockBook::sourceOf($item, $source, 'from');
        }
        if ($problem = $this->availability->problemOf($item)) {
            throw ValidationException::withMessages(['item' => $problem]);
        }
        $quantity = $item->kind === ItemKind::Bulk ? $quantity : 1;

        $positions = $loan->positions()->with('item')->get();
        foreach ($positions as $position) {
            if ($position->item->is_container && $item->isInside($position->item)) {
                throw ValidationException::withMessages(['item' => "{$item->number} liegt in {$position->item->number}, die Box ist schon in der Buchung."]);
            }
            // the other way round: the box would bring a position along a second time
            if ($item->is_container && $position->item->isInside($item)) {
                throw ValidationException::withMessages(['item' => "In {$item->number} liegt {$position->item->number}, das schon in der Buchung ist. Nimm es erst heraus, dann kommt es mit der Box."]);
            }
        }

        return $this->reserving($loan, fn () => DB::transaction(function () use ($loan, $item, $quantity, $positions, $source) {
            $position = $positions->firstWhere('item_id', $item->id);
            if ($position && $item->kind !== ItemKind::Bulk) {
                throw ValidationException::withMessages(['item' => "{$item->number} ist schon in der Buchung."]);
            }

            $position ??= $loan->loanItems()->make(['item_id' => $item->id, 'quantity' => 0]);
            $position->quantity += $quantity;
            if ($source !== null && $item->isBulk()) {
                $position->source_key = $source;
            }
            $this->ensureQuantity($item, $position->quantity);
            $position->save();
            $this->ensureFree($loan, $position);

            return $position;
        }));
    }

    /**
     * Change the quantity of a bulk item.
     *
     * @throws ValidationException
     */
    public function setQuantity(Loan $loan, LoanItem $position, int $quantity): void
    {
        $this->ensureOpen($loan);
        $this->ensureQuantity($position->item, $quantity);

        $this->reserving($loan, fn () => DB::transaction(function () use ($loan, $position, $quantity) {
            $position->update(['quantity' => $quantity]);
            $this->ensureFree($loan, $position);
        }));
    }

    /**
     * Pieces of a bulk position used up or retired before the issue ("Verbraucht", "Ausmustern"
     * in the position list): they leave the inventory from the place the position takes them from,
     * and the position shrinks; at 0 it goes. Pieces already issued are counted at the return.
     *
     * @throws ValidationException
     */
    public function takeOff(User $actor, Loan $loan, LoanItem $position, int $quantity, RetireReason $reason, ?string $note = null): void
    {
        $this->ensureOpen($loan);
        if (! $position->isBulk() || $position->parent_id !== null || $position->issued_at !== null) {
            throw ValidationException::withMessages(['quantity' => 'Nur Stücke eines Mengenartikels, die noch nicht ausgegeben sind.']);
        }
        if ($quantity < 1 || $quantity > $position->quantity) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$position->quantity} liegen."]);
        }

        DB::transaction(function () use ($actor, $loan, $position, $quantity, $reason, $note) {
            $item = $position->item;
            $source = StockBook::sourceOf($item, $position->source_key, 'quantity');
            $this->stock->remove($actor, $item, $source, $quantity, $reason, trim("Aus {$loan->number}. ".(string) $note));
            $position->quantity -= $quantity;
            $position->quantity === 0 ? $position->delete() : $position->save();
        });
    }

    /**
     * A confirmed loan reserves what it takes: one change at a time (Availability::exclusively()).
     * A draft reserves nothing and needs no lock.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    private function reserving(Loan $loan, Closure $change): mixed
    {
        return $loan->status === LoanStatus::Confirmed ? Availability::exclusively($change) : $change();
    }

    /**
     * Take a position out of the loan.
     *
     * @throws ValidationException
     */
    public function remove(Loan $loan, LoanItem $position): void
    {
        $this->ensureOpen($loan);
        $position->delete();
    }

    /**
     * @throws ValidationException
     */
    private function ensureOpen(Loan $loan): void
    {
        if (! $loan->status->isOpen()) {
            throw ValidationException::withMessages(['item' => 'Nach der Ausgabe sind die Positionen fest. Was dazukommen soll, wird eine zweite Buchung in derselben Veranstaltung.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureQuantity(Item $item, int $quantity): void
    {
        if ($item->kind === ItemKind::Bulk && ($quantity < 1 || $quantity > $item->quantity)) {
            throw ValidationException::withMessages(['quantity' => "Die Menge muss zwischen 1 und {$item->quantity} liegen."]);
        }
    }

    /**
     * A confirmed loan only takes what is free in its time.
     *
     * @throws ValidationException
     */
    private function ensureFree(Loan $loan, LoanItem $position): void
    {
        if ($loan->status !== LoanStatus::Confirmed) {
            return;
        }

        $check = $this->availability->check($position->item, $position->quantity, $loan->pickup_at, $loan->return_at, $loan);
        if ($check['blocked']) {
            throw ValidationException::withMessages(['item' => $this->availability->message($position->item, $check)]);
        }
    }
}
