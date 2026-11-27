<?php

namespace Modules\Loans\Support;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Location;
use App\Models\User;
use App\Support\Extensions\ItemInfoProvider;
use App\Support\Surface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Loans\Actions\StowItem;
use Modules\Loans\Enums\LoanStatus;
use Modules\Loans\Models\Loan;
use Modules\Loans\Models\LoanItem;

/**
 * Notices of the loan module at items (App\Support\Extensions\ItemExtensions):
 *
 * - "ausgegeben an … bis …": locks moving, splitting and retiring until the item is back,
 * - "ungeprüft zurück" and "noch nicht eingeräumt" after a return, "vermisst" after a loan,
 * - the next confirmed loan and drafts that note the item ("Vormerkung"), also through a box it lies in,
 * - on the scan card the actions "Rückgabe dieser Buchung", "Einräumen" and "Zur Buchung hinzufügen",
 *   each only where the surface has the page (the kiosk has no loan details and no new loans).
 */
class LoanItemInfo implements ItemInfoProvider
{
    /**
     * {@inheritDoc}
     */
    public function notices(Collection $items, ?User $user): array
    {
        $ids = $items->pluck('id')->all();
        $notices = [];

        // issued: the item itself or a row of a box; pieces of a bulk item do not lock it: they left
        // a place, the rest stays where it is
        $issued = LoanAwayItems::issuedRows()->whereIn('item_id', $ids)
            ->with('loan.responsible', 'loan.responsibleUser', 'item')->get();
        foreach ($issued as $row) {
            $loan = $row->loan;
            $overdue = $loan->isOverdue();
            $pieces = $row->isBulk();
            $what = $pieces ? "{$row->quantity} {$row->item->unit} ausgegeben" : 'Ausgegeben';
            $notices[$row->item_id][] = $this->notice(
                $overdue
                    ? "{$what} an {$loan->responsibleName()}, seit {$loan->overdueDays()} Tagen überfällig ({$loan->number})."
                    : "{$what} an {$loan->responsibleName()} bis {$loan->return_at->format('d.m.Y, H:i')} Uhr ({$loan->number}).",
                $overdue ? 'überfällig' : ($pieces ? "{$row->quantity} ausgegeben" : 'ausgegeben bis '.$loan->return_at->format('d.m.')),
                $overdue ? 'danger' : 'warning',
                $loan,
                lock: ! $pieces,
                actions: $user?->can('loans.issue') ? $this->action('Rückgabe dieser Buchung', 'loans.return.show', [$loan, 'return'], 'rotate-left') : [],
            );
        }

        // the lock is all that is asked for when the item is moved
        if ($user === null) {
            return $notices;
        }

        foreach (LoanItem::whereIn('item_id', $ids)->whereNotNull('returned_at')->whereNull('inspected_at')->with('loan')->get() as $row) {
            $notices[$row->item_id][] = $this->notice("Ungeprüft zurück aus {$row->loan->number}; die Prüfung ist offen.", 'ungeprüft', 'warning', $row->loan);
        }

        $missing = LoanItem::stillMissing()->where(fn (Builder $query) => $query->whereIn('missing_item_id', $ids)->orWhere(fn (Builder $query) => $query->where('missing_quantity', '>', 0)->whereIn('item_id', $ids)))
            ->with('loan', 'item')->get();
        foreach ($missing as $row) {
            $pieces = $row->missing_quantity > 0;
            $notices[$pieces ? $row->item_id : $row->missing_item_id][] = $this->notice(
                ($pieces ? "{$row->missing_quantity} {$row->item->unit} vermisst" : 'Vermisst').
                    " seit der Rückgabe von {$row->loan->number} am {$row->returned_at->format('d.m.Y')}.",
                'vermisst',
                'danger',
                $row->loan,
                actions: $user->can('loans.issue') ? $this->action('Wieder da melden', 'loans.show', $row->loan, 'rotate-left', '#vermisst') : [],
            );
        }

        foreach (StowItem::pending()->whereIn('item_id', $ids)->with('loan', 'item')->get() as $row) {
            $pieces = $row->isBulk();
            $suggestion = $pieces ? self::stowPlaceOf($row) : $row->item->home();
            $where = $pieces
                ? 'Vorschlag '.($suggestion ? Item::placeSummary($suggestion)['code'] : 'Eingang')
                : 'Stammplatz '.($row->item->home() ? Item::placeSummary($row->item->home())['code'] : 'keiner');
            $notices[$row->item_id][] = $this->notice(
                ($pieces ? ($row->returned_quantity ?? $row->quantity)." {$row->item->unit} zurück" : 'Zurück').
                    " aus {$row->loan->number}, noch nicht eingeräumt ({$where}).",
                'nicht eingeräumt',
                'info',
                $row->loan,
                actions: $user->can('loans.issue') ? $this->action('Einräumen', 'loans.returns', ['row' => $row->id], 'warehouse') : [],
            );
        }

        if ($user->can('loans.view')) {
            $issuedIds = array_keys($notices);
            // a booked box books what lies in it
            $boxes = app(Availability::class)->boxesAroundAll($items);
            $upcoming = LoanItem::whereIn('item_id', array_unique([...$ids, ...array_merge(...array_values($boxes))]))
                ->whereHas('loan', fn (Builder $query) => $query->whereIn('status', [LoanStatus::Confirmed, LoanStatus::Draft])->where('return_at', '>', now()))
                ->with('loan', 'item')->get()->sortBy('loan.pickup_at');
            $shown = [];
            foreach ($items as $item) {
                foreach ($upcoming as $row) {
                    $loan = $row->loan;
                    $inBox = $row->item_id !== $item->id;
                    if (($inBox && ! in_array($row->item_id, $boxes[$item->id], true)) || isset($shown[$item->id][$loan->id])) {
                        continue;
                    }
                    $shown[$item->id][$loan->id] = true;
                    $via = $inBox ? " mit der Box {$row->item->number}" : '';
                    $text = $loan->status === LoanStatus::Draft
                        ? "Vorgemerkt{$via} im Entwurf {$loan->number}: {$loan->pickup_at->format('d.m.')} bis {$loan->return_at->format('d.m.Y')}."
                        : "Gebucht{$via} in {$loan->number}: {$loan->pickup_at->format('d.m.')} bis {$loan->return_at->format('d.m.Y')}.";
                    $notices[$item->id][] = $this->notice($text, null, 'info', $loan);
                }
            }

            // offer to add a lendable item that is not out
            if ($user->can('loans.manage') && Surface::has('loans.add-item')) {
                foreach ($items as $item) {
                    if (! in_array($item->id, $issuedIds, true) && ! $item->isRetired() && ! $item->condition->is_defect) {
                        $notices[$item->id][] = $this->notice('', null, 'info', null, actions: $this->action('Zur Buchung hinzufügen', 'loans.add-item', $item, 'calendar-plus'));
                    }
                }
            }
        }

        // the offer has no text: it only adds the action to the scan card
        return array_map(fn (array $list) => array_values($list), $notices);
    }

    /**
     * {@inheritDoc}
     *
     * Confirmed loans and rests of a partial issue that still wait for the pickup, holding the
     * item itself or a box it lies in.
     */
    public function reservations(Item $item): array
    {
        $ids = [$item->id, ...app(Availability::class)->boxesAround($item)];
        $rows = LoanItem::whereIn('item_id', $ids)->whereNull('issued_at')
            ->whereHas('loan', fn (Builder $query) => $query->where('return_at', '>', now())->whereIn('status', LoanStatus::reserving()))
            ->with('loan', 'item')->get()->sortBy('loan.pickup_at')->unique('loan_id');

        return $rows->map(function (LoanItem $row) use ($item) {
            $via = $row->item_id === $item->id ? '' : " (mit der Box {$row->item->number})";
            $when = $row->loan->pickup_at->isFuture() ? 'ab '.$row->loan->pickup_at->format('d.m., H:i').' Uhr' : 'Abholung offen';

            return "{$row->loan->number}{$via}, {$when}.";
        })->values()->all();
    }

    /**
     * An action of the scan card as list, empty if the current surface (kiosk) lacks the page.
     *
     * @return list<array{title: string, href: string, icon: string}>
     */
    private function action(string $title, string $route, mixed $parameters, string $icon, string $fragment = ''): array
    {
        $href = Surface::link($route, $parameters);

        return $href ? [['title' => $title, 'href' => $href.$fragment, 'icon' => $icon]] : [];
    }

    /**
     * @param  list<array{title: string, href: string, icon: string}>  $actions
     * @return array{text: string, badge: string|null, variant: string, href: string|null, lock: bool, actions: list<array{title: string, href: string, icon: string}>}
     */
    private function notice(string $text, ?string $badge, string $variant, ?Loan $loan, bool $lock = false, array $actions = []): array
    {
        return [
            'text' => $text,
            'badge' => $badge,
            'variant' => $variant,
            'href' => $loan ? Surface::link('loans.show', $loan) : null,
            'lock' => $lock,
            'actions' => $actions,
        ];
    }

    /**
     * Where the pieces of a bulk position go when they are put away: the place they left from, else
     * the home of the item; null for "Eingang".
     */
    public static function stowPlaceOf(LoanItem $row): Location|Item|null
    {
        $source = $row->source_key !== null ? ItemStock::placeOf($row->source_key) : null;

        return $source ?? $row->item->home();
    }
}
