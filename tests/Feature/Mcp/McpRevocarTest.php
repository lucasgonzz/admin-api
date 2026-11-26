<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * CA8 de la misión dots-openclaw: `php artisan mcp:revocar [--admin_id=N]` revoca los tokens vigentes (todos o los
 * de un admin) e imprime cuántos.
 */
class McpRevocarTest extends TestCase
{
    use DatabaseTransactions;
    use McpFixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_mcp();
    }

    /**
     * Sin opciones revoca todos: imprime 2 y los dos tokens dan 401.
     *
     * @return void
     */
    public function test_revoca_todos(): void
    {
        $token_a = $this->token_para($this->admin_de_mcp())['access_token'];
        $token_b = $this->token_para($this->admin_de_mcp())['access_token'];
        $this->mcp_post($token_a, $this->rpc('tools/list'))->assertStatus(200);
        $this->mcp_post($token_b, $this->rpc('tools/list'))->assertStatus(200);

        $this->assertSame(0, Artisan::call('mcp:revocar'));
        $this->assertStringContainsString('2', Artisan::output());

        $this->mcp_post($token_a, $this->rpc('tools/list'))->assertStatus(401);
        $this->mcp_post($token_b, $this->rpc('tools/list'))->assertStatus(401);
    }

    /**
     * Con `--admin_id` sólo revoca los de ese admin: imprime 1, el suyo da 401 y el otro sigue andando.
     *
     * @return void
     */
    public function test_revoca_por_admin(): void
    {
        $admin_a = $this->admin_de_mcp();
        $admin_b = $this->admin_de_mcp();
        $token_a = $this->token_para($admin_a)['access_token'];
        $token_b = $this->token_para($admin_b)['access_token'];

        $this->assertSame(0, Artisan::call('mcp:revocar', ['--admin_id' => $admin_a->id]));
        $this->assertStringContainsString('1', Artisan::output());

        $this->mcp_post($token_a, $this->rpc('tools/list'))->assertStatus(401);
        $this->mcp_post($token_b, $this->rpc('tools/list'))->assertStatus(200);
    }
}
