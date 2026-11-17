<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\EstimatePriceTool;
use App\Mcp\Tools\GenerateImageTool;
use App\Mcp\Tools\GetBalanceTool;
use App\Mcp\Tools\GetGenerationTool;
use App\Mcp\Tools\ListGenerationsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * The MCP server (routes/ai.php), for clients such as Claude Code, Claude
 * Desktop or Cursor. It is a third door to the same services as the web app
 * and the API v1, authenticated with the same personal access tokens.
 */
#[Name('Pixelforge Studio')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Pixelforge Studio generates images and bills each one at its exact cost. Credits are US dollars.

    Workflow: estimate_price → generate_image → get_generation (poll until the status is "succeeded" or "failed").

    - generate_image spends the user's credits: call it only when the user asked for an image, and tell
      them the estimated price first. The final price is only known on completion, so the maximum price
      is reserved up front and the actual price charged when the image is delivered.
    - Failed generations cost nothing.
    - A generation takes from a few seconds to about a minute and a half. Poll get_generation every few
      seconds rather than calling generate_image again.
    - Prices are exact decimal strings in USD. Never round them when reporting what was charged.
    MARKDOWN)]
class PixelforgeStudioServer extends Server
{
    public const string PATH = '/mcp';

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetBalanceTool::class,
        EstimatePriceTool::class,
        GenerateImageTool::class,
        GetGenerationTool::class,
        ListGenerationsTool::class,
    ];
}
