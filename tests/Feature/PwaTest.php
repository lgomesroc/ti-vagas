<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante a integridade dos arquivos do PWA e a sua inclusão no layout.
 * Ver docs/AplicativoPWA.md.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    private const ICONS = [
        'icons/icon-192.png' => 192,
        'icons/icon-512.png' => 512,
        'icons/icon-maskable-192.png' => 192,
        'icons/icon-maskable-512.png' => 512,
        'icons/apple-touch-icon.png' => 180,
    ];

    private function manifest(): array
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($manifest);

        return $manifest;
    }

    public function test_manifest_has_the_fields_required_for_installation(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('TI Vagas', $manifest['short_name']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('pt-BR', $manifest['lang']);
        $this->assertSame('#172554', $manifest['theme_color']);

        $sizes = collect($manifest['icons'])
            ->where('type', 'image/png')
            ->pluck('sizes')
            ->all();

        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertTrue(
            collect($manifest['icons'])->contains(fn (array $icon) => ($icon['purpose'] ?? '') === 'maskable'),
            'O manifesto precisa de ao menos um ícone maskable.'
        );
    }

    public function test_every_icon_referenced_by_the_manifest_exists(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), $icon['src']);
        }
    }

    public function test_png_icons_have_the_declared_dimensions(): void
    {
        foreach (self::ICONS as $file => $size) {
            $info = getimagesize(public_path($file));

            $this->assertNotFalse($info, $file);
            $this->assertSame([$size, $size], [$info[0], $info[1]], $file);
            $this->assertSame('image/png', $info['mime'], $file);
        }
    }

    public function test_service_worker_never_caches_pages_or_intercepts_unsafe_requests(): void
    {
        $path = public_path('sw.js');

        $this->assertFileExists($path);

        $script = (string) file_get_contents($path);

        // Só requisições GET da mesma origem são tratadas.
        $this->assertStringContainsString("request.method !== 'GET'", $script);
        $this->assertStringContainsString('url.origin !== self.location.origin', $script);

        // Navegações vão à rede com fallback offline, sem gravar HTML no cache.
        $this->assertStringContainsString("request.mode === 'navigate'", $script);
        $this->assertStringContainsString("const OFFLINE_URL = '/offline.html'", $script);
        $this->assertSame(
            1,
            preg_match('/async function networkFirstNavigation\(request\) \{(.*?)\n\}\n/s', $script, $navigation)
        );
        $this->assertStringNotContainsString(
            'cache.put',
            $navigation[1],
            'Páginas HTML não podem ser gravadas no cache do service worker.'
        );

        // Somente status 200 é gravado; 206 é recusado pela Cache API.
        $this->assertStringContainsString('response.status === 200', $script);
        $this->assertStringNotContainsString('response.ok', $script);

        // Falha de rede sem cópia em cache responde 503, nunca undefined.
        $this->assertStringContainsString('function unavailableResponse()', $script);
        $this->assertStringContainsString('status: 503', $script);

        // Troca de versão remove caches antigos e pré-cache incompleto.
        $this->assertStringContainsString('!CURRENT_CACHES.includes(key)', $script);
        $this->assertStringContainsString('caches.delete(STATIC_CACHE)', $script);

        // O comportamento real é coberto por tests/pwa/service-worker.e2e.test.mjs.

        // Arquivos pré-armazenados precisam existir.
        preg_match('/const PRECACHE_URLS = \[(.*?)\];/s', $script, $matches);
        preg_match_all("/'(\/[^']+)'/", $matches[1] ?? '', $urls);

        $this->assertNotEmpty($urls[1]);

        foreach ($urls[1] as $url) {
            $this->assertFileExists(public_path(ltrim($url, '/')), $url);
        }
    }

    public function test_offline_page_is_static_and_does_not_carry_session_data(): void
    {
        $path = public_path('offline.html');

        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);

        $this->assertStringContainsString('<html lang="pt-BR">', $html);
        $this->assertStringContainsString('Você está sem conexão', $html);
        $this->assertStringNotContainsString('csrf', strtolower($html));
        $this->assertStringNotContainsString('<form', strtolower($html));
    }

    public function test_layout_links_the_manifest_and_registers_the_service_worker(): void
    {
        $response = $this->get(route('welcome'));

        $response->assertOk();
        $response->assertSee('<link rel="manifest" href="' . asset('manifest.webmanifest') . '">', false);
        $response->assertSee('<link rel="apple-touch-icon" href="' . asset('icons/apple-touch-icon.png') . '">', false);
        $response->assertSee('<meta name="theme-color" content="#172554">', false);
        $response->assertSee("navigator.serviceWorker", false);
        $response->assertSee(json_encode(asset('sw.js')), false);
        $response->assertSee("updateViaCache: 'none'", false);
        $response->assertSee('id="pwa-install-button"', false);
    }

    public function test_install_button_starts_hidden_until_the_browser_offers_installation(): void
    {
        $content = $this->get(route('welcome'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="pwa-install-button"[^>]*\bhidden\b[^>]*>/s',
            $content
        );
    }

    public function test_apache_serves_manifest_type_and_revalidates_the_service_worker(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('AddType application/manifest+json .webmanifest', $htaccess);
        $this->assertStringContainsString('<Files "sw.js">', $htaccess);
        $this->assertStringContainsString('no-cache', $htaccess);

        // As regras originais de reescrita para o front controller continuam presentes.
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $htaccess);
    }
}
