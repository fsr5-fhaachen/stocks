<?php

namespace App\Support\Products;

use App\Support\Settings;

/**
 * User agent of requests to product databases. Open Food Facts asks every app to name itself, so
 * the operators can reach the instance if something goes wrong.
 */
class UserAgent
{
    public function __construct(private readonly Settings $settings) {}

    public function __toString(): string
    {
        $contact = array_filter([config('app.url'), $this->settings->get('legal.contact_email')]);

        return 'Stocks/'.config('stocks.version').($contact ? ' ('.implode('; ', $contact).')' : '');
    }
}
