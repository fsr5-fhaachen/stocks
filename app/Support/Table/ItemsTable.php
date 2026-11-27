<?php

namespace App\Support\Table;

use App\Enums\ItemKind;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\User;
use App\Support\Extensions\ItemExtensions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Items of the inventory. Filters: category (with its sub categories), condition, retired, without
 * printed label ("noch ohne Etikett").
 *
 * "grouped" (switch "Nach Boxen gruppieren"): without search and filters the list shows only the
 * items whose box is not in the list itself, boxes with the number of their contents; the page
 * opens a box with contents() (route inventory.items.contents). Grouping is on unless switched off;
 * the choice is kept per device and list in a cookie (see wantsGrouping()).
 */
class ItemsTable extends Table
{
    /**
     * Most rows of the contents of a box at once, the rest is reached on the page of the box.
     *
     * @var int
     */
    public const CONTENTS_LIMIT = 200;

    /**
     * Notices of modules for the items of the current page, by item id.
     *
     * @var array<int, list<array<string, mixed>>>
     */
    private array $notices = [];

    /**
     * Pieces of bulk items of the current page that a module has away (lent, missing), by item id.
     *
     * @var array<int, int>
     */
    private array $away = [];

    /**
     * @param  bool  $onlyMisplaced  only items without place or away from their home (page "Ohne Platz")
     */
    public function __construct(Request $request, private readonly bool $onlyMisplaced = false)
    {
        parent::__construct($request);
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareRows(Collection $models): void
    {
        $this->notices = app(ItemExtensions::class)->notices($models, $this->request->user());
        $this->away = app(ItemExtensions::class)->awayQuantities($models->modelKeys());
    }

    /**
     * {@inheritDoc}
     */
    protected function query(): Builder
    {
        return $this->scope()->with(['category.parent', 'condition', 'location', 'container', 'photos'])
            ->withCount(['contents' => fn (Builder $query) => $query->active()]);
    }

    /**
     * Items of the page without filters: all, or those of "Ohne Platz".
     *
     * @return Builder<Item>
     */
    private function scope(): Builder
    {
        return $this->onlyMisplaced ? app(ItemExtensions::class)->misplaced() : Item::query();
    }

    /**
     * Name of the cookie that keeps the choice of grouping, one per list.
     */
    public function groupingCookie(): string
    {
        return $this->onlyMisplaced ? 'stocks_misplaced_grouped' : 'stocks_items_grouped';
    }

    /**
     * Check if grouping by boxes is wanted: the parameter "grouped" of the request, else the
     * choice kept in the cookie, else on.
     */
    public function wantsGrouping(): bool
    {
        if ($this->request->has('grouped')) {
            return $this->request->boolean('grouped');
        }

        return $this->request->cookie($this->groupingCookie(), '1') !== '0';
    }

    /**
     * Check if the rows are grouped by boxes: wanted and neither searched nor filtered.
     */
    public function isGrouped(): bool
    {
        return $this->wantsGrouping()
            && trim((string) $this->request->query('search', '')) === ''
            && ! array_filter($this->request->only(['category', 'condition', 'retired', 'unlabeled']));
    }

    /**
     * Rows of the active items directly in a box, by name, at most CONTENTS_LIMIT.
     *
     * @return array{rows: list<array<string, mixed>>, more: int}
     */
    public static function contents(Item $box, ?User $user): array
    {
        $query = $box->contents()->active()
            ->with(['category.parent', 'condition', 'location', 'container', 'photos'])
            ->withCount(['contents' => fn (Builder $query) => $query->active()]);
        $total = (clone $query)->count();
        $items = $query->reorder('name')->orderBy('id')->limit(self::CONTENTS_LIMIT)->get();
        $notices = app(ItemExtensions::class)->notices($items, $user);
        $away = app(ItemExtensions::class)->awayQuantities($items->modelKeys());

        return [
            'rows' => $items->map(fn (Item $item) => self::rowFor($item, $notices[$item->id] ?? [], $away[$item->id] ?? 0))->values()->all(),
            'more' => max(0, $total - self::CONTENTS_LIMIT),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query): void
    {
        if ($this->request->boolean('retired')) {
            $query->whereNotNull('retired_at');
        } else {
            $query->whereNull('retired_at');
        }

        if ($categoryId = $this->request->integer('category')) {
            $ids = Category::where('parent_id', $categoryId)->pluck('id')->push($categoryId);
            $query->whereIn('category_id', $ids);
        }

        if ($conditionId = $this->request->integer('condition')) {
            $query->where('condition_id', $conditionId);
        }

        if ($this->request->boolean('unlabeled')) {
            $query->whereNull('label_printed_at');
        }

        // grouped: the contents of a box in the list are shown below the box
        if ($this->isGrouped()) {
            $query->where(fn (Builder $query) => $query->whereNull('items.container_id')
                ->orWhereNotIn('items.container_id', $this->scope()->whereNull('retired_at')->select('items.id')));
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  Item  $model
     */
    protected function row(Model $model): array
    {
        return self::rowFor($model, $this->notices[$model->id] ?? [], $this->away[$model->id] ?? 0);
    }

    /**
     * Row of an item, also for the contents of a box.
     *
     * @param  list<array<string, mixed>>  $notices  notices of the modules for this item
     * @param  int  $away  pieces of a bulk item a module has away (lent, missing)
     * @return array<string, mixed>
     */
    public static function rowFor(Item $model, array $notices, int $away = 0): array
    {
        return [
            'id' => $model->id,
            'number' => $model->number,
            'name' => $model->name,
            'category' => $model->category?->fullName(),
            'quantity' => $model->quantity,
            // pieces of a bulk item that are there ("vorhanden"), without those away
            'quantity_here' => $model->kind === ItemKind::Bulk ? max(0, $model->quantity - $away) : null,
            'unit' => $model->unit,
            'kind' => $model->kind->value,
            'is_container' => $model->is_container,
            'condition' => $model->condition->name,
            'condition_is_defect' => $model->condition->is_defect,
            'place' => Item::placeSummary($model->place()),
            'away_from_home' => $model->isAwayFromHome(),
            'retired' => $model->isRetired(),
            'photo_url' => $model->photos->first()?->thumbUrl(),
            'label_printed_at' => $model->label_printed_at?->toIso8601String(),
            // active items directly in the box, for the grouped list
            'contents_count' => $model->is_container ? (int) $model->contents_count : 0,
            // short notices of modules, e.g. "ausgegeben bis 12.11."
            'badges' => array_values(array_filter(array_map(
                fn (array $notice) => $notice['badge'] ? ['text' => $notice['badge'], 'variant' => $notice['variant']] : null,
                $notices,
            ))),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function searchColumns(): array
    {
        return ['number', 'ean', 'name', 'description', 'category.name', 'location.full_code', 'container.number'];
    }

    /**
     * {@inheritDoc}
     */
    protected function sorts(): array
    {
        return [
            'number' => 'items.number',
            'name' => 'items.name',
            'condition' => fn (Builder $query, string $direction) => $query->orderBy(
                ItemCondition::select('name')->whereColumn('item_conditions.id', 'items.condition_id'),
                $direction,
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function applyDefaultSort(Builder $query): void
    {
        $query->orderBy('items.name');
    }
}
