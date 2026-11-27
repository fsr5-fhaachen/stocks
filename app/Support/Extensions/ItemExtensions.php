<?php

namespace App\Support\Extensions;

use App\Models\Item;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Extension points around items, used by modules (see documentation/MODULES.md):
 *
 * - tabs on the page of an item (Vue component of the module, gets the item as prop),
 * - notices and locks on items (ItemInfoProvider): shown on the item page, in the item list and
 *   on the scan card; a lock stops moving, splitting and retiring, a reservation asks first,
 * - sections on the public page of a box (PublicBoxSection),
 * - items that are away on purpose (ItemAwayProvider), left out of "Ohne Platz".
 *
 * Entries of a switched off module are left out.
 */
class ItemExtensions
{
    /**
     * @var list<array{title: string, component: string, permission: string|null, module: string|null, order: int}>
     */
    private array $tabs = [];

    /**
     * @var list<array{class: class-string<ItemInfoProvider>, module: string|null}>
     */
    private array $providers = [];

    /**
     * @var list<array{component: string, class: class-string<PublicBoxSection>, module: string|null}>
     */
    private array $publicBoxSections = [];

    /**
     * @var list<array{class: class-string<ItemAwayProvider>, module: string|null}>
     */
    private array $awayProviders = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Add a tab to the page of an item.
     *
     * @param  string  $component  Vue component "<Module>::<Component>", gets the prop "item"
     */
    public function addTab(string $title, string $component, ?string $permission = null, ?string $module = null, int $order = 100): void
    {
        $this->tabs[] = compact('title', 'component', 'permission', 'module', 'order');
    }

    /**
     * Add a provider of notices and locks.
     *
     * @param  class-string<ItemInfoProvider>  $class
     */
    public function addInfo(string $class, ?string $module = null): void
    {
        $this->providers[] = compact('class', 'module');
    }

    /**
     * Add a section to the public page of a box.
     *
     * @param  string  $component  Vue component "<Module>::<Component>"; besides its props it gets
     *                             "checked" (numbers ticked off on this device) and "total"
     * @param  class-string<PublicBoxSection>  $class
     */
    public function addPublicBoxSection(string $component, string $class, ?string $module = null): void
    {
        $this->publicBoxSections[] = compact('component', 'class', 'module');
    }

    /**
     * Add a provider of items that are away on purpose (e.g. lent).
     *
     * @param  class-string<ItemAwayProvider>  $class
     */
    public function addAway(string $class, ?string $module = null): void
    {
        $this->awayProviders[] = compact('class', 'module');
    }

    /**
     * Leave out the items that an active module has away on purpose.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $items  query on items
     * @return TBuilder
     */
    public function withoutAway(Builder $items): Builder
    {
        foreach ($this->awayProviders as $provider) {
            if ($this->isOn($provider['module'])) {
                $items->whereNotIn($items->qualifyColumn('id'), app($provider['class'])->awayItemIds());
            }
        }

        return $items;
    }

    /**
     * Pieces of a bulk item that an active module has away (lent, missing, back but not put away
     * yet): they are at no place; 0 for single items.
     */
    public function awayQuantity(Item $item): int
    {
        return $this->awayQuantities([$item->id])[$item->id] ?? 0;
    }

    /**
     * Pieces away by item id, see awayQuantity(). Items with nothing away are left out.
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    public function awayQuantities(array $itemIds): array
    {
        $away = [];
        foreach ($this->awayProviders as $provider) {
            if ($this->isOn($provider['module'])) {
                foreach (app($provider['class'])->awayQuantities($itemIds) as $id => $quantity) {
                    $away[$id] = ($away[$id] ?? 0) + $quantity;
                }
            }
        }

        return $away;
    }

    /**
     * Pieces of a bulk item that are here ("vorhanden"): the sum of its places (item_stocks). The
     * rest of its quantity is away (lent, missing, back but not put away yet).
     */
    public function quantityHere(Item $item): int
    {
        return (int) $item->stocks()->sum('quantity');
    }

    /**
     * Items of "Ohne Platz": active, without place or away from their home, and not away on purpose.
     *
     * @return Builder<Item>
     */
    public function misplaced(): Builder
    {
        return $this->withoutAway(Item::query()->active()->misplaced());
    }

    /**
     * Tabs the user may see.
     *
     * @return list<array{title: string, component: string}>
     */
    public function tabsFor(User $user): array
    {
        $tabs = array_filter($this->tabs, fn (array $tab) => $this->isOn($tab['module']) && ($tab['permission'] === null || $user->can($tab['permission'])));
        usort($tabs, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(fn (array $tab) => ['title' => $tab['title'], 'component' => $tab['component']], array_values($tabs));
    }

    /**
     * Notices of all providers by item id.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, list<array{text: string, badge: string|null, variant: string, href: string|null, lock: bool, actions: list<array{title: string, href: string, icon: string}>}>>
     */
    public function notices(Collection $items, ?User $user): array
    {
        $notices = [];
        if ($items->isEmpty()) {
            return $notices;
        }

        foreach ($this->providers as $provider) {
            if ($this->isOn($provider['module'])) {
                foreach (app($provider['class'])->notices($items, $user) as $id => $list) {
                    $notices[$id] = [...$notices[$id] ?? [], ...$list];
                }
            }
        }

        return $notices;
    }

    /**
     * Reason why the item can not be moved, split or retired right now, null if it can.
     */
    public function lockOf(Item $item): ?string
    {
        foreach ($this->notices(collect([$item]), null)[$item->id] ?? [] as $notice) {
            if ($notice['lock']) {
                return $notice['text'];
            }
        }

        return null;
    }

    /**
     * Bookings that count on the item later (e.g. a confirmed loan), as sentences. Retiring,
     * using up, splitting it or setting a defect condition is allowed, but asks first (see
     * ensureConfirmed()).
     *
     * @return list<string>
     */
    public function reservationsOf(Item $item): array
    {
        $texts = [];
        foreach ($this->providers as $provider) {
            if ($this->isOn($provider['module'])) {
                $texts = [...$texts, ...app($provider['class'])->reservations($item)];
            }
        }

        return $texts;
    }

    /**
     * Ask before an action that takes the item from a booking: without "confirmed" the action
     * stops with the bookings as error "confirm", the page asks and sends it again confirmed.
     *
     * @param  string  $action  what happens, for the question, e.g. "ausmustern"
     *
     * @throws ValidationException
     */
    public function ensureConfirmed(Item $item, bool $confirmed, string $action): void
    {
        if ($confirmed || ($reservations = $this->reservationsOf($item)) === []) {
            return;
        }

        throw ValidationException::withMessages(['confirm' => "{$item->number} ist gebucht: ".implode(' ', $reservations)." Trotzdem {$action}?"]);
    }

    /**
     * Sections of the public page of a box with their props.
     *
     * @return list<array{component: string, props: array<string, mixed>}>
     */
    public function publicBoxSections(Item $box): array
    {
        $sections = [];
        foreach ($this->publicBoxSections as $section) {
            if ($this->isOn($section['module']) && ($props = app($section['class'])->props($box)) !== null) {
                $sections[] = ['component' => $section['component'], 'props' => $props];
            }
        }

        return $sections;
    }

    /**
     * Check if an entry of the core or of an active module.
     */
    private function isOn(?string $module): bool
    {
        return $module === null || $this->modules->isActive($module);
    }
}
