<?php

namespace App\Http\Middleware;

use App\Support\AppVersion;
use App\Support\Branding;
use App\Support\Extensions\Navigation;
use App\Support\Settings;
use App\Support\Surface;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props every page gets. All props are closures, so partial reloads (e.g. infinite scroll of a
 * table) do not compute them.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Defines the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settings = app(Settings::class);

        return [
            ...parent::share($request),
            'auth' => fn () => $request->user() ? [
                'user' => $request->user()->only(['id', 'firstname', 'lastname', 'email', 'name']),
                'permissions' => $request->user()->getAllPermissions()->pluck('name'),
            ] : null,
            // a surface (e.g. the kiosk) has no menu
            'navigation' => fn () => $request->user() && ! Surface::current() ? app(Navigation::class)->forUser($request->user()) : [],
            // surface of the request (e.g. the kiosk) or null, see App\Support\Surface
            'surface' => fn () => Surface::shared(),
            // version below the menu and a newer release on GitHub; not on the kiosk
            'app' => fn () => $request->user() && ! Surface::current() ? app(AppVersion::class)->shared($request->user()) : null,
            'organization' => fn () => [
                'name' => $settings->get('organization.name'),
                'short_name' => $settings->get('organization.short_name'),
                'accent_color' => $settings->get('organization.accent_color'),
                // logo for dark backgrounds (dark mode) and for light ones (light mode)
                'logo_url' => app(Branding::class)->url('logo'),
                'logo_light_url' => app(Branding::class)->url('logo_light'),
                'imprint_url' => $settings->get('legal.imprint_url'),
                'privacy_url' => $settings->get('legal.privacy_url'),
            ],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                // item with a new number (e.g. split off part): offer to print its label
                'label' => $request->session()->get('label'),
            ],
            // start time of the live update of tables, see App\Support\Table\Table
            'serverTime' => fn () => now()->timestamp,
        ];
    }
}
