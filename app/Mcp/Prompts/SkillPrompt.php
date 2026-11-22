<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/**
 * Serve an assistant skill (resources/ai/skills/<area>/<name>/SKILL.md) as an MCP prompt,
 * so external clients get the same tool order and output shape as the in-app specialists.
 */
final class SkillPrompt extends Prompt
{
    private function __construct(
        private readonly string $promptName,
        private readonly string $promptDescription,
        private readonly string $body,
    ) {}

    public static function fromFile(string $area, string $path): ?self
    {
        $raw = (string) file_get_contents($path);
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $parts) !== 1) {
            return null;
        }

        preg_match('/^name:\s*(.+)$/m', $parts[1], $name);
        preg_match('/^description:\s*(.+)$/m', $parts[1], $description);
        if (empty($name[1])) {
            return null;
        }

        return new self(
            "{$area}_".str_replace('-', '_', trim($name[1])),
            '['.ucfirst($area).'] '.trim($description[1] ?? ''),
            trim($parts[2]),
        );
    }

    public function name(): string
    {
        return $this->promptName;
    }

    public function title(): string
    {
        return str_replace('_', ' ', ucfirst($this->promptName));
    }

    public function description(): string
    {
        return $this->promptDescription;
    }

    public function handle(): Response
    {
        return Response::text($this->body);
    }
}
