<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logo and favicon: deliver them to everybody, change them in the settings.
 */
class BrandingController extends Controller
{
    /**
     * Deliver an image. Without an uploaded favicon the default of public/ is used. The type comes
     * from the bytes of the image, not from S3: browsers show no favicon at all when S3 answers
     * with a general type like "application/octet-stream".
     */
    public function show(Branding $branding, string $kind): Response|BinaryFileResponse
    {
        if ($branding->path($kind) === null) {
            abort_unless($kind === 'favicon', 404);

            return response()->file(public_path('favicon.ico'));
        }

        $content = $branding->contents($kind);
        abort_if($content === null, 404);

        // the url changes with every new image (see Branding::url), so it can be kept for a week
        return response($content, headers: [
            'Content-Type' => Branding::mimeType($content),
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Upload or remove logos and favicon. SVG is not allowed, because it can contain scripts.
     */
    public function update(Request $request, Branding $branding): RedirectResponse
    {
        $request->validate([
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'logo_light' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'print_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'app_icon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove' => ['array'],
            'remove.*' => [Rule::in(array_keys(Branding::KINDS))],
        ]);

        foreach (array_keys(Branding::KINDS) as $kind) {
            if ($request->hasFile($kind)) {
                $branding->replace($kind, $request->file($kind));
            } elseif (in_array($kind, $request->input('remove', []), true)) {
                $branding->remove($kind);
            }
        }

        return back()->with('success', 'Logos und Symbol wurden gespeichert.');
    }
}
