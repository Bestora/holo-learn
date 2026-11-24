<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Installable web app: manifest, icons and service worker are in place and referenced. */
class PwaTest extends TestCase
{
    public function test_manifest_is_valid_and_its_icons_exist(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('holo-learn', $manifest['name']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            $sizes[] = $icon['sizes'].' '.$icon['purpose'];
        }
        $this->assertEqualsCanonicalizing(['192x192 any', '512x512 any', '512x512 maskable'], $sizes);
    }

    public function test_every_page_references_manifest_and_icons(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('<meta name="theme-color"', false)
            ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
    }

    public function test_service_worker_and_offline_page_exist(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("addEventListener('fetch'", $worker);
        $this->assertStringContainsString("'/offline.html'", $worker);
        $this->assertStringContainsString('needs a connection', (string) file_get_contents(public_path('offline.html')));
    }
}
