<?php

namespace Tests\Feature\Api;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use App\Enums\GenerationStatus;
use App\Mcp\Servers\PixelforgeStudioServer;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The published contract (docs-site/api-reference/openapi.yaml) and the code
 * must not drift apart: documentation that lies is worse than none.
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function specification(): array
    {
        return Yaml::parseFile(base_path('docs-site/api-reference/openapi.yaml'));
    }

    public function test_every_route_is_documented_and_every_documented_operation_exists(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (RoutingRoute $route): array => array_map(
                fn (string $method): string => $method.' /'.substr($route->uri(), strlen('api/v1/')),
                array_diff($route->methods(), ['HEAD']),
            ))
            ->sort()->values()->all();

        $documented = collect($this->specification()['paths'])
            ->flatMap(fn (array $operations, string $path): array => array_map(
                fn (string $method): string => strtoupper($method).' '.$path,
                array_keys($operations),
            ))
            ->sort()->values()->all();

        $this->assertSame($routes, $documented);
    }

    public function test_a_generation_has_exactly_the_documented_fields(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded()->create();
        Sanctum::actingAs($user);

        $schema = $this->specification()['components']['schemas']['Generation'];
        $actual = array_keys($this->getJson("/api/v1/generations/{$generation->id}")->assertOk()->json());

        $this->assertEqualsCanonicalizing(array_keys($schema['properties']), $actual);
        $this->assertEqualsCanonicalizing($schema['required'], $actual);
    }

    public function test_the_other_objects_have_exactly_the_documented_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $schemas = $this->specification()['components']['schemas'];

        $this->assertEqualsCanonicalizing(
            array_keys($schemas['Balance']['properties']),
            array_keys($this->getJson('/api/v1/balance')->json()),
        );
        $this->assertEqualsCanonicalizing(
            array_keys($schemas['GenerationList']['properties']),
            array_keys($this->getJson('/api/v1/generations')->json()),
        );
    }

    public function test_money_in_responses_matches_the_documented_pattern(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->succeeded()->create();
        Sanctum::actingAs($user);

        $pattern = '/'.$this->specification()['components']['schemas']['Money']['pattern'].'/';
        $response = $this->getJson("/api/v1/generations/{$generation->id}");

        foreach (['estimated_price_usd', 'maximum_price_usd', 'price_usd'] as $field) {
            $this->assertMatchesRegularExpression($pattern, $response->json($field));
        }
    }

    public function test_the_documented_enums_are_the_ones_the_application_accepts(): void
    {
        $schemas = $this->specification()['components']['schemas'];

        $this->assertSame(array_column(GenerationStatus::cases(), 'value'), $schemas['Status']['enum']);
        $this->assertSame(array_column(GenerationSize::cases(), 'value'), $schemas['Size']['enum']);
        $this->assertSame(array_column(GenerationQuality::cases(), 'value'), $schemas['Quality']['enum']);
    }

    public function test_the_mcp_guide_lists_exactly_the_tools_the_server_exposes(): void
    {
        $token = User::factory()->create()->createToken('docs')->plainTextToken;

        $tools = array_column($this->withToken($token)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson(PixelforgeStudioServer::PATH, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->json('result.tools'), 'name');

        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|\s*(?:No|\*\*Yes\*\*)\s*\|/m', (string) file_get_contents(base_path('docs-site/mcp/tools.mdx')), $matches);

        $this->assertEqualsCanonicalizing($tools, $matches[1]);
    }

    public function test_every_page_in_the_navigation_exists(): void
    {
        $navigation = json_decode((string) file_get_contents(base_path('docs-site/docs.json')), true);
        $pages = collect($navigation['navigation']['tabs'])->flatMap(fn (array $tab): array => array_merge(...array_column($tab['groups'], 'pages')));

        foreach ($pages as $page) {
            // "GET /balance" style entries are generated from the OpenAPI file.
            if (preg_match('/^(GET|POST|PATCH|DELETE) (\/.*)$/', $page, $operation) === 1) {
                $this->assertArrayHasKey(strtolower($operation[1]), $this->specification()['paths'][$operation[2]] ?? [], "{$page} is not in the specification.");

                continue;
            }

            $this->assertFileExists(base_path("docs-site/{$page}.mdx"));
        }
    }
}
