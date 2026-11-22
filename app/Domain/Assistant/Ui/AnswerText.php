<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Ui;

use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\AssistantStreamEvents;
use Illuminate\Support\Str;

final class AnswerText
{
    /**
     * Clean a stored assistant message content:
     * - Strips plan lines («خطة: ...»)
     * - Replaces printed tool calls (from failed/malformed runs) with the friendly Arabic retry message.
     */
    public static function clean(string $content): string
    {
        return AssistantStreamEvents::containsPrintedToolCall($content)
            ? AssistantService::MALFORMED_REPLY_AR
            : AssistantStreamEvents::withoutPlanLines($content);
    }

    /**
     * Build a thread title from the first question text (max 80 chars, normalized whitespace).
     */
    public static function titleFromText(string $text): string
    {
        $cleaned = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return Str::limit($cleaned, 80, '…');
    }
}
