<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Support\Audit\AuditSubject;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A place in the tree address → (building) → room → shelf → compartment.
 *
 * The full code joins the codes of the path ("EU-014-R03.4"). It is stored for search and
 * sorting and recomputed by App\Actions\Inventory\SaveLocation for the whole subtree.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property LocationType $type
 * @property string $code
 * @property string|null $name
 * @property string|null $description
 * @property string $full_code
 * @property bool $show_in_code
 * @property Carbon|null $label_printed_at time of the last printed label
 * @property bool $no_label marked as "Kein Etikett nötig": left out of "Etikett fehlt", see App\Support\Labels\LabelStatus
 */
class Location extends Model implements Auditable, AuditSubject
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'type',
        'code',
        'name',
        'description',
        'full_code',
        'show_in_code',
        'no_label',
    ];

    /**
     * Attributes to exclude from the audit: a printed label is no change of the location.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'label_printed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'show_in_code' => 'boolean',
            'no_label' => 'boolean',
            'label_printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * Sort locations by full code as people count: R01.2 before R01.10, R9 before R10. The database
     * sorts codes as text, so lists sort again here (Locations/Index.vue does the same).
     *
     * @param  Collection<int, Location>  $locations
     * @return Collection<int, Location>
     */
    public static function naturally(Collection $locations, string $key = 'full_code'): Collection
    {
        return $locations->sortBy($key, SORT_NATURAL)->values();
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id')->orderBy('code');
    }

    /**
     * Items that lie directly at this location (not in a box).
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'location_id');
    }

    /**
     * Build the full code of a location from the full code of its parent. A building that is not
     * shown in the code passes the code of its address on to its children ("EU-014" instead of
     * "EU-A-014"); the building itself always has its own code ("EU-A").
     */
    public static function fullCodeOf(?Location $parent, LocationType $type, string $code): string
    {
        if ($parent?->type === LocationType::Building && ! $parent->show_in_code) {
            $parent = $parent->parent;
        }

        return $parent ? $parent->full_code.$type->separator().$code : $code;
    }

    /**
     * Ids of this location and of all locations below it.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        // the tree has at most five levels, so this runs at most five queries
        while ($level) {
            $level = Location::whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /**
     * Data of the location for the frontend.
     *
     * @return array{id: int, parent_id: int|null, type: string, type_label: string, code: string, name: string|null, full_code: string, show_in_code: bool}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'name' => $this->name,
            'full_code' => $this->full_code,
            'show_in_code' => $this->type === LocationType::Building && $this->show_in_code,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function auditTypeLabel(): string
    {
        return 'Standort';
    }

    /**
     * {@inheritDoc}
     */
    public static function auditName(array $attributes): string
    {
        return trim(($attributes['full_code'] ?? '').' '.($attributes['name'] ?? ''));
    }

    /**
     * {@inheritDoc}
     */
    public static function auditFieldLabels(): array
    {
        return [
            'parent_id' => 'Liegt in',
            'type' => 'Ebene',
            'code' => 'Kürzel',
            'name' => 'Name',
            'description' => 'Beschreibung',
            'full_code' => 'Kennziffer',
            'show_in_code' => 'Im Code zeigen',
            'no_label' => 'Kein Etikett nötig',
        ];
    }
}
