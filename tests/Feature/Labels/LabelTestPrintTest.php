<?php

namespace Tests\Feature\Labels;

use App\Models\Item;
use App\Models\LabelTemplate;
use Tests\TestCase;

/**
 * Test print in the editor: invented data with the code TEST, nothing noted as printed.
 */
class LabelTestPrintTest extends TestCase
{
    public function test_the_editor_gets_invented_test_labels(): void
    {
        $item = Item::factory()->create(['name' => 'Echter Gegenstand']);
        $template = LabelTemplate::firstOrFail();

        $this->actingAs($this->admin())->get("/labels/templates/{$template->id}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('testSamples.item.code', 'TEST')
                ->where('testSamples.item.values.number', 'TEST')
                ->where('testSamples.item.url', fn ($url) => str_ends_with($url, '/s/TEST'))
                ->where('testSamples.box.code', 'TEST')
                ->where('testSamples.location.values.name', 'Testetikett')
                ->where('testSamples.reserve.values.number', 'TEST')
                // the normal preview may show the real item
                ->where('samples.item.title', 'Echter Gegenstand'));

        $this->assertNull($item->refresh()->label_printed_at);
    }
}
