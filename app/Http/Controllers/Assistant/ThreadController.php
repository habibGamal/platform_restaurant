<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\ConversationGuard;
use App\Domain\Assistant\Ui\AnswerText;
use App\Domain\Assistant\Ui\ThreadMessages;
use App\Models\AiConversationMeta;
use App\Models\AiMessagePart;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

final class ThreadController
{
    /**
     * List threads for current user and channel chat (pinned first, then most recent activity).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $threads = AiConversationMeta::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('channel', Channel::Chat)
            ->orderByDesc('pinned')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();

        $data = $threads->map(fn (AiConversationMeta $meta): array => $this->formatThread($meta))->values()->all();

        return response()->json($data);
    }

    /**
     * Create a new conversation thread.
     */
    public function store(Request $request, AssistantService $assistant): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $title = isset($validated['title']) && is_string($validated['title']) ? $validated['title'] : null;
        $conversationId = $assistant->createConversation($user, Channel::Chat, $title);

        return response()->json(['id' => $conversationId], 201);
    }

    /**
     * Show a single thread item.
     */
    public function show(string $id, Request $request, ConversationGuard $guard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $guard->assertBelongsTo($id, $user);

        /** @var AiConversationMeta $meta */
        $meta = AiConversationMeta::query()->where('conversation_id', $id)->firstOrFail();

        return response()->json($this->formatThread($meta));
    }

    /**
     * Update thread metadata (title, status, pinned).
     */
    public function update(string $id, Request $request, ConversationGuard $guard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $guard->assertBelongsTo($id, $user);

        /** @var AiConversationMeta $meta */
        $meta = AiConversationMeta::query()->where('conversation_id', $id)->firstOrFail();

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:regular,archived'],
            'pinned' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('title', $validated)) {
            $meta->title = $validated['title'];
            Conversation::query()->where('id', $id)->update(['title' => $validated['title'] ?? 'محادثة جديدة']);
        }

        if (isset($validated['status'])) {
            $meta->archived_at = $validated['status'] === 'archived' ? Carbon::now() : null;
        }

        if (isset($validated['pinned'])) {
            $meta->pinned = (bool) $validated['pinned'];
        }

        $meta->save();

        return response()->json($this->formatThread($meta));
    }

    /**
     * Permanently delete a thread, its metadata, messages and parts.
     */
    public function destroy(string $id, Request $request, ConversationGuard $guard): Response
    {
        /** @var User $user */
        $user = $request->user();

        $guard->assertBelongsTo($id, $user);

        DB::transaction(function () use ($id): void {
            AiMessagePart::query()->where('conversation_id', $id)->delete();
            ConversationMessage::query()->where('conversation_id', $id)->delete();
            AiConversationMeta::query()->where('conversation_id', $id)->delete();
            Conversation::query()->where('id', $id)->delete();
        });

        return response()->noContent();
    }

    /**
     * Set the title from the first user message if still default.
     */
    public function title(string $id, Request $request, ConversationGuard $guard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $guard->assertBelongsTo($id, $user);

        /** @var AiConversationMeta $meta */
        $meta = AiConversationMeta::query()->where('conversation_id', $id)->firstOrFail();

        $isDefaultTitle = $meta->title === null || trim($meta->title) === '' || trim($meta->title) === 'محادثة جديدة';

        if ($isDefaultTitle) {
            /** @var ConversationMessage|null $firstUserMessage */
            $firstUserMessage = ConversationMessage::query()
                ->where('conversation_id', $id)
                ->where('role', 'user')
                ->oldest()
                ->first();

            if ($firstUserMessage !== null) {
                $newTitle = AnswerText::titleFromText((string) ($firstUserMessage->content ?? ''));
                if ($newTitle !== '') {
                    $meta->title = $newTitle;
                    $meta->save();

                    Conversation::query()->where('id', $id)->update(['title' => $newTitle]);
                }
            }
        }

        return response()->json([
            'title' => $meta->title ?? 'محادثة جديدة',
        ]);
    }

    /**
     * Return AI SDK UIMessage array for this conversation.
     */
    public function messages(string $id, Request $request, ConversationGuard $guard): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $guard->assertBelongsTo($id, $user);

        return response()->json(ThreadMessages::for($id));
    }

    /**
     * @return array{id: string, title: string, status: 'regular'|'archived', pinned: bool, lastActivityAt: ?string}
     */
    private function formatThread(AiConversationMeta $meta): array
    {
        return [
            'id' => $meta->conversation_id,
            'title' => $meta->title ?? 'محادثة جديدة',
            'status' => $meta->archived_at !== null ? 'archived' : 'regular',
            'pinned' => (bool) $meta->pinned,
            'lastActivityAt' => $meta->last_activity_at?->toISOString() ?? $meta->updated_at?->toISOString(),
        ];
    }
}
