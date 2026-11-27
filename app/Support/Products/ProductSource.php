<?php

namespace App\Support\Products;

/**
 * A database of products that is asked by barcode (App\Support\Products\ProductLookup).
 */
interface ProductSource
{
    /**
     * Name shown in the credit, e.g. "UPCitemdb".
     */
    public function name(): string;

    /**
     * Whether the source can be asked right now (e.g. not over its daily limit).
     */
    public function available(): bool;

    /**
     * The product of a barcode (stored form, see Gtin), null if the source does not know it.
     *
     * @return array{name: string|null, brand: string|null, model: string|null, url: string|null, license: string|null, images: list<array{url: string, license: string|null, author: string|null}>}|null
     *
     * @throws SourceUnavailable when the source can not be reached or refuses
     */
    public function find(string $gtin): ?array;
}
