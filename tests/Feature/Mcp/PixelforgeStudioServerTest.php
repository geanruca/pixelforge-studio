<?php

namespace Tests\Feature\Mcp;

use App\Jobs\SubmitGeneration;
use App\Mcp\Servers\PixelforgeStudioServer;
use App\Mcp\Tools\EstimatePriceTool;
use App\Mcp\Tools\GenerateImageTool;
use App\Mcp\Tools\GetBalanceTool;
use App\Mcp\Tools\GetGenerationTool;
use App\Mcp\Tools\ListGenerationsTool;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithPixelforge;
use Tests\TestCase;

class PixelforgeStudioServerTest extends TestCase
{
    use InteractsWithPixelforge;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePixelforge();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*/pricing' => Http::response($this->pricingPayload())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'medium'];
    }

    public function test_the_endpoint_refuses_requests_without_a_token_with_a_bearer_challenge(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_a_token_can_list_the_tools_over_json_rpc(): void
    {
        $token = User::factory()->create()->createToken('Claude Code')->plainTextToken;

        $response = $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['get_balance', 'estimate_price', 'generate_image', 'get_generation', 'list_generations'],
            array_column($response->json('result.tools'), 'name'),
        );
    }

    public function test_get_balance_reports_the_exact_balance(): void
    {
        PixelforgeStudioServer::actingAs($this->userWithCredits(5))
            ->tool(GetBalanceTool::class)
            ->assertOk()
            ->assertSee('Available credits: $5.000000000.')
            ->assertStructuredContent(['available_usd' => '5.000000000', 'currency' => 'usd']);
    }

    public function test_estimate_price_spends_nothing(): void
    {
        $user = $this->userWithCredits(1);

        PixelforgeStudioServer::actingAs($user)
            ->tool(EstimatePriceTool::class, $this->input())
            ->assertOk()
            ->assertStructuredContent([
                'minimum_price_usd' => '0.058150000',
                'expected_price_usd' => '0.072218750',
                'maximum_price_usd' => '0.086287500',
                'currency' => 'usd',
            ]);

        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1_000_000_000, $user->refresh()->balance_nanos);
    }

    public function test_generate_image_holds_credits_and_queues_the_generation(): void
    {
        $user = $this->userWithCredits(1);

        PixelforgeStudioServer::actingAs($user)
            ->tool(GenerateImageTool::class, $this->input())
            ->assertOk()
            ->assertSee('is queued')
            ->assertStructuredContentPath('generation.status', 'queued')
            ->assertStructuredContentPath('generation.maximum_price_usd', '0.086287500');

        $generation = Generation::query()->sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(1_000_000_000 - $generation->reserved_nanos, $user->refresh()->balance_nanos);
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_an_agent_retrying_generate_image_with_the_same_key_does_not_pay_twice(): void
    {
        $user = $this->userWithCredits(1);
        $input = [...$this->input(), 'idempotency_key' => 'retry-1'];

        PixelforgeStudioServer::actingAs($user)->tool(GenerateImageTool::class, $input)->assertOk();
        PixelforgeStudioServer::actingAs($user)->tool(GenerateImageTool::class, $input)->assertOk();

        $this->assertSame(1, Generation::query()->count());
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_generate_image_explains_when_credits_are_missing_and_creates_nothing(): void
    {
        PixelforgeStudioServer::actingAs(User::factory()->create())
            ->tool(GenerateImageTool::class, $this->input())
            ->assertHasErrors(['Not enough credits', '$0.086287500', 'Nothing was created']);

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_generate_image_validates_like_the_web_form(): void
    {
        PixelforgeStudioServer::actingAs($this->userWithCredits(1))
            ->tool(GenerateImageTool::class, [...$this->input(), 'size' => '512x512', 'reference_images' => ['ftp://x/y.png']])
            ->assertHasErrors();

        $this->assertSame(0, Generation::query()->count());
    }

    public function test_get_generation_reports_the_charge_or_the_failure(): void
    {
        $user = User::factory()->create();
        $succeeded = Generation::factory()->for($user)->succeeded(56_500_000)->create();
        $failed = Generation::factory()->for($user)->failed('generation_failed')->create();

        PixelforgeStudioServer::actingAs($user)
            ->tool(GetGenerationTool::class, ['generation_id' => $succeeded->id])
            ->assertOk()
            ->assertSee('Charged $0.070625000')
            ->assertStructuredContentPath('generation.price_usd', '0.070625000');

        PixelforgeStudioServer::actingAs($user)
            ->tool(GetGenerationTool::class, ['generation_id' => $failed->id])
            ->assertOk()
            ->assertSee('failed and was not charged')
            ->assertStructuredContentPath('generation.price_usd', null);
    }

    public function test_tools_never_reveal_another_accounts_generation(): void
    {
        $foreign = Generation::factory()->succeeded()->create(['prompt' => 'Secret prompt']);
        $user = User::factory()->create();

        PixelforgeStudioServer::actingAs($user)
            ->tool(GetGenerationTool::class, ['generation_id' => $foreign->id])
            ->assertHasErrors(['does not exist or belongs to another account'])
            ->assertDontSee('Secret prompt');

        PixelforgeStudioServer::actingAs($user)
            ->tool(ListGenerationsTool::class)
            ->assertOk()
            ->assertSee('No generations found.');
    }

    public function test_list_generations_filters_by_status(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->succeeded()->count(2)->create();
        Generation::factory()->for($user)->failed()->create();

        PixelforgeStudioServer::actingAs($user)
            ->tool(ListGenerationsTool::class, ['status' => 'failed'])
            ->assertOk()
            ->assertStructuredContentCount(1, 'generations');
    }
}
