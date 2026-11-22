<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * Expose an assistant tool over MCP unchanged: same name, description, input schema and handler,
 * so the in-app agent and external MCP clients share one implementation.
 */
#[IsReadOnly]
final class BridgedTool extends Tool
{
    public function __construct(private readonly AiTool $tool) {}

    public function name(): string
    {
        return method_exists($this->tool, 'name') ? (string) $this->tool->name() : class_basename($this->tool);
    }

    public function title(): string
    {
        return str_replace('_', ' ', ucfirst($this->name()));
    }

    public function description(): string
    {
        return (string) $this->tool->description();
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Response
    {
        try {
            return Response::text((string) $this->tool->handle(new AiRequest($request->all())));
        } catch (Throwable $e) {
            report($e);

            return Response::error("Tool {$this->name()} failed: {$e->getMessage()}");
        }
    }
}
