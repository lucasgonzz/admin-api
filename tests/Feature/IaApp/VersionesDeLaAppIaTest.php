<?php

namespace Tests\Feature\IaApp;

use App\Models\IaAppVersion;
use Illuminate\Support\Facades\Http;

/**
 * Las versiones de la app `ia-spa` por `claude/*` (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.5).
 *
 * Lo que se protege: 🔴 «publicada ⇒ el asset `ia-spa-v{V}-dist.zip` existe en el release `v{V}`» por los dos
 * caminos (alta y PATCH), con 422 si falta y 502 si GitHub falla, sin escribir nada; el orden semántico del
 * listado; y que `deployed_at` solo se estampe en una versión publicada.
 */
class VersionesDeLaAppIaTest extends BaseDeLaAppIa
{
    /** URL del release de un tag de ia-spa en la API de GitHub. */
    private function release(string $version): string
    {
        return 'https://api.github.com/repos/lucasgonzz/ia-spa/releases/tags/v' . $version;
    }

    /**
     * @param string $version
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    private function con_asset(string $version)
    {
        return Http::response(['assets' => [[
            'name' => 'ia-spa-v' . $version . '-dist.zip', 'url' => 'https://api.github.com/assets/1', 'size' => 1234,
            'browser_download_url' => 'https://github.com/x.zip',
        ]]], 200);
    }

    public function test_publicar_con_el_asset_da_201(): void
    {
        config(['services.github.releases_owner' => 'lucasgonzz']);
        $this->fakear([$this->release('1.0.0') => $this->con_asset('1.0.0')]);

        $this->postJson('/api/claude/ia/versions', ['version' => '1.0.0', 'title' => 'Primera'], $this->headers_claude())
            ->assertStatus(201)
            ->assertJsonPath('model.version', '1.0.0')
            ->assertJsonPath('model.status', 'published')
            ->assertJsonPath('artefactos_verificados', true)
            ->assertJsonPath('artefacto.asset', 'ia-spa-v1.0.0-dist.zip');

        $this->assertNotNull(IaAppVersion::where('version', '1.0.0')->value('published_at'));
    }

    public function test_sin_el_asset_es_422_y_con_github_caido_502_sin_escribir_nada(): void
    {
        config(['services.github.releases_owner' => 'lucasgonzz']);
        $this->fakear([
            $this->release('1.0.1') => Http::response(['assets' => [['name' => 'otro.zip']]], 200),
            $this->release('1.0.2') => Http::response('', 404),
            $this->release('1.0.3') => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        $this->postJson('/api/claude/ia/versions', ['version' => '1.0.1'], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'artefacto_faltante');
        $this->postJson('/api/claude/ia/versions', ['version' => '1.0.2'], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('faltantes.0.asset', 'ia-spa-v1.0.2-dist.zip');
        $this->postJson('/api/claude/ia/versions', ['version' => '1.0.3'], $this->headers_claude())
            ->assertStatus(502)->assertJsonPath('code', 'github_no_responde');

        $this->assertSame(0, IaAppVersion::whereIn('version', ['1.0.1', '1.0.2', '1.0.3'])->count());
    }

    public function test_un_borrador_no_mira_github_y_el_patch_que_lo_publica_si(): void
    {
        config(['services.github.releases_owner' => 'lucasgonzz']);
        $this->fakear([$this->release('2.0.0') => Http::response(['assets' => []], 200)]);

        $id = $this->postJson('/api/claude/ia/versions', ['version' => '2.0.0', 'status' => 'draft'], $this->headers_claude())
            ->assertStatus(201)->assertJsonPath('artefactos_verificados', false)->json('model.id');
        Http::assertNothingSent();

        $this->patchJson('/api/claude/ia/versions/' . $id, ['status' => 'published'], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'artefacto_faltante');
        $this->assertSame('draft', IaAppVersion::find($id)->status);

        // deployed_at en un borrador: no.
        $this->patchJson('/api/claude/ia/versions/' . $id, ['deployed_at' => now()->toIso8601String()], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'no_publicada');

        // El código no se edita, y sin campos tampoco.
        $this->patchJson('/api/claude/ia/versions/' . $id, ['version' => '9.9.9'], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'version_no_editable');
        $this->patchJson('/api/claude/ia/versions/' . $id, ['otra_cosa' => 1], $this->headers_claude())
            ->assertStatus(422)->assertJsonPath('code', 'sin_campos');
        $this->patchJson('/api/claude/ia/versions/999999', ['title' => 'x'], $this->headers_claude())->assertStatus(404);
    }

    public function test_deployed_at_en_una_publicada_y_el_listado_semantico(): void
    {
        $vieja = IaAppVersion::create(['version' => '1.9.0', 'status' => 'published', 'published_at' => now()]);
        $nueva = IaAppVersion::create(['version' => '1.10.0', 'status' => 'published', 'published_at' => now()]);
        IaAppVersion::create(['version' => '1.11.0', 'status' => 'draft']);

        $fecha = '2026-10-29T15:30:00-03:00';
        $this->patchJson('/api/claude/ia/versions/' . $vieja->id, ['deployed_at' => $fecha], $this->headers_claude())
            ->assertStatus(200)->assertJsonPath('campos_editados', ['deployed_at']);
        $this->assertNotNull($vieja->fresh()->deployed_at);

        $lista = $this->getJson('/api/claude/ia/versions?status=all', $this->headers_claude())->assertStatus(200);
        $codigos = array_column($lista->json('data'), 'version');
        $this->assertSame(array_values(array_intersect(['1.11.0', '1.10.0', '1.9.0'], $codigos)), array_values(array_intersect($codigos, ['1.11.0', '1.10.0', '1.9.0'])));
        $this->assertLessThan(array_search('1.9.0', $codigos, true), array_search('1.10.0', $codigos, true), '1.10.0 va antes que 1.9.0.');
        $this->assertNotNull($lista->json('data.0.id'));
        $this->assertSame('1.10.0', $lista->json('ultima_publicada.version'));
        $this->assertSame('1.9.0', $lista->json('ultima_desplegada.version'));
        $this->assertSame((int) $nueva->id, (int) collect($lista->json('data'))->firstWhere('version', '1.10.0')['id']);

        $publicadas = $this->getJson('/api/claude/ia/versions?status=published', $this->headers_claude())->json('data');
        $this->assertNotContains('1.11.0', array_column($publicadas, 'version'));

        $this->getJson('/api/claude/ia/versions?status=banana', $this->headers_claude())->assertStatus(422);
    }
}
