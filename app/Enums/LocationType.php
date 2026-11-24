<?php

namespace App\Enums;

/**
 * Level of a location in the tree: address → (building) → room → shelf → compartment. The building
 * is optional, a room lies in an address or in a building.
 */
enum LocationType: string
{
    use HasOptions;

    case Address = 'address';
    case Building = 'building';
    case Room = 'room';
    case Shelf = 'shelf';
    case Compartment = 'compartment';

    public function label(): string
    {
        return match ($this) {
            self::Address => 'Adresse',
            self::Building => 'Gebäude',
            self::Room => 'Raum',
            self::Shelf => 'Lagerplatz',
            self::Compartment => 'Fach',
        };
    }

    /**
     * Levels a child of this level can have, the usual one first; empty for the last level.
     *
     * @return list<self>
     */
    public function childTypes(): array
    {
        return match ($this) {
            self::Address => [self::Room, self::Building],
            self::Building => [self::Room],
            self::Room => [self::Shelf],
            self::Shelf => [self::Compartment],
            self::Compartment => [],
        };
    }

    /**
     * Check if items may lie directly at a location of this level.
     */
    public function holdsItems(): bool
    {
        return $this !== self::Address && $this !== self::Building;
    }

    /**
     * Character that joins the code to the code of the parent: "EU-014-R03.4".
     */
    public function separator(): string
    {
        return $this === self::Compartment ? '.' : '-';
    }
}
