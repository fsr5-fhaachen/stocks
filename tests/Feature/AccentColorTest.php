<?php

namespace Tests\Feature;

use App\Support\Settings;
use Tests\TestCase;

/**
 * The accent colour of the settings is the only definition, whatever order the css is loaded in.
 */
class AccentColorTest extends TestCase
{
    public function test_the_page_carries_the_accent_colour_of_the_settings(): void
    {
        app(Settings::class)->set(['organization.accent_color' => '#ff0000']);

        $this->get('/login')->assertOk()->assertSee('--color-accent: 255 0 0;', false);
    }

    public function test_the_stylesheet_does_not_define_the_accent_itself(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('--color-accent:', $css);
        $this->assertStringContainsString('token("accent", "34 148 140")', file_get_contents(base_path('tailwind.config.cjs')));
    }
}
