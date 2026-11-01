<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Assistant\AssistantReadiness;
use App\Domain\Assistant\AssistantReadinessResult;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Domain\Assistant\ConversationGuard;
use App\Filament\App\Pages\Management\AiSettingsPage;
use App\Models\AiConversationMeta;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Livewire\Attributes\Async;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;
use Throwable;

#[Isolate]
class Assistant extends Page
{
    protected static ?string $slug = 'assistant';

    protected static ?string $title = 'المساعد الذكي';

    protected static ?string $navigationLabel = 'المساعد الذكي';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 0;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.app.assistant.index';

    #[Locked]
    public ?string $selectedConversationId = null;

    #[Locked]
    public ?string $currentRunId = null;

    public string $prompt = '';

    public string $search = '';

    public bool $isStreaming = false;

    public ?string $toolStatus = null;

    public ?string $errorMessage = null;

    public ?string $errorCode = null;

    public ?string $streamedAnswer = null;

    /**
     * @var list<array{id: string, role: string, content: string, created_at: string, sources: list<string>}>
     */
    public array $messages = [];

    /**
     * Source labels already streamed into the rail for the current run.
     *
     * @var list<string>
     */
    protected array $streamedSources = [];

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && Gate::forUser($user)->allows('use-assistant');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(?string $conversation = null): void
    {
        abort_unless(static::canAccess(), 403);

        if ($conversation !== null) {
            /** @var User|null $user */
            $user = auth()->user();
            if ($user !== null) {
                /** @var ConversationGuard $guard */
                $guard = app(ConversationGuard::class);
                $guard->assertBelongsTo($conversation, $user);
                $this->selectedConversationId = $conversation;
                $this->loadMessages();
            }
        }
    }

    /**
     * @return Collection<int, AiConversationMeta>
     */
    public function getConversations(): Collection
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || $user->organization_id === null) {
            return collect();
        }

        return AiConversationMeta::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->active()
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $query->where('title', 'like', '%'.trim($this->search).'%');
            })
            ->orderByDesc('pinned')
            ->orderByDesc('last_activity_at')
            ->get();
    }

    public function getSelectedConversation(): ?AiConversationMeta
    {
        if ($this->selectedConversationId === null) {
            return null;
        }

        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || $user->organization_id === null) {
            return null;
        }

        return AiConversationMeta::query()
            ->where('conversation_id', $this->selectedConversationId)
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->first();
    }

    public function selectConversation(string $conversationId): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        /** @var ConversationGuard $guard */
        $guard = app(ConversationGuard::class);
        $guard->assertBelongsTo($conversationId, $user);

        $this->selectedConversationId = $conversationId;
        $this->messages = [];
        $this->prompt = '';
        $this->toolStatus = null;
        $this->errorMessage = null;
        $this->errorCode = null;
        $this->streamedAnswer = null;
        $this->currentRunId = null;
        $this->isStreaming = false;

        $this->loadMessages();
    }

    public function newConversation(): void
    {
        $this->selectedConversationId = null;
        $this->messages = [];
        $this->prompt = '';
        $this->toolStatus = null;
        $this->errorMessage = null;
        $this->errorCode = null;
        $this->streamedAnswer = null;
        $this->currentRunId = null;
        $this->isStreaming = false;
    }

    public function togglePin(string $conversationId): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        /** @var ConversationGuard $guard */
        $guard = app(ConversationGuard::class);
        $guard->assertBelongsTo($conversationId, $user);

        /** @var AiConversationMeta|null $meta */
        $meta = AiConversationMeta::query()
            ->where('conversation_id', $conversationId)
            ->first();

        if ($meta !== null) {
            $meta->update(['pinned' => ! $meta->pinned]);
        }
    }

    public function useStarterPrompt(string $prompt, ?string $runId = null): void
    {
        $this->prompt = $prompt;
        $this->send($runId ?? (string) Str::uuid());
    }

    public function send(?string $runId = null): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user instanceof User && Gate::forUser($user)->allows('use-assistant'), 403);

        if ($runId === null || ! Str::isUuid($runId)) {
            abort(400, 'A valid UUID runId is required.');
        }

        $text = trim($this->prompt);
        if ($text === '') {
            return;
        }

        if ($this->selectedConversationId !== null) {
            /** @var ConversationGuard $guard */
            $guard = app(ConversationGuard::class);
            $guard->assertBelongsTo($this->selectedConversationId, $user);
        }

        $this->prompt = '';
        $this->messages[] = [
            'id' => (string) Str::uuid(),
            'role' => 'user',
            'content' => $text,
            'created_at' => now()->format('H:i'),
            'sources' => [],
        ];

        $this->currentRunId = $runId;
        $this->streamedSources = [];
        $this->isStreaming = true;
        $this->toolStatus = null;
        $this->errorMessage = null;
        $this->errorCode = null;
        $this->streamedAnswer = '';

        /** @var AssistantContract $assistant */
        $assistant = app(AssistantContract::class);

        try {
            $events = $assistant->stream(
                user: $user,
                channel: Channel::Chat,
                conversationId: $this->selectedConversationId,
                text: $text,
                runId: $this->currentRunId,
            );

            foreach ($events as $event) {
                if ($event->type === 'text_delta' && $event->text !== null) {
                    $this->streamedAnswer .= $event->text;
                    $this->stream(to: 'answer', content: e($event->text), replace: false);
                } elseif ($event->type === 'tool_call' && $event->toolName !== null) {
                    $this->toolStatus = $this->mapToolStatus($event->toolName);
                    $this->stream(to: 'status', content: e($this->toolStatus), replace: true);

                    $source = $this->sourceLabel($event->toolName);
                    if (! in_array($source, $this->streamedSources, true)) {
                        $this->streamedSources[] = $source;
                        $this->stream(to: 'sources', content: '<span class="aa-ticket">'.e($source).'</span>', replace: false);
                    }
                } elseif ($event->type === 'tool_result') {
                    $this->toolStatus = null;
                    $this->stream(to: 'status', content: '', replace: true);
                } elseif ($event->type === 'done') {
                    if ($event->conversationId !== null) {
                        $this->selectedConversationId = $event->conversationId;
                    }
                    $this->toolStatus = null;
                    $this->isStreaming = false;
                    $this->loadMessages();
                } elseif ($event->type === 'error') {
                    $this->errorMessage = $event->text ?? 'حدث خطأ أثناء معالجة الطلب.';
                    $this->errorCode = $event->errorCode;
                    if ($event->conversationId !== null) {
                        $this->selectedConversationId = $event->conversationId;
                    }
                    $this->toolStatus = null;
                    $this->isStreaming = false;
                    $this->loadMessages();
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->errorMessage = 'تعذر إكمال الإجابة. أعد إرسال السؤال بعد قليل.';
            $this->errorCode = 'provider_error';
            $this->toolStatus = null;
            $this->isStreaming = false;
        } finally {
            $this->isStreaming = false;
            $this->toolStatus = null;
        }

        // If loadMessages fetched the persisted assistant message with matching content, clear streamedAnswer.
        if (! empty($this->messages) && end($this->messages)['role'] === 'assistant' && end($this->messages)['content'] === $this->streamedAnswer) {
            $this->streamedAnswer = null;
        }
    }

    #[Async]
    public function stop(?string $runId = null): void
    {
        $targetRunId = $runId ?? $this->currentRunId;

        if ($targetRunId === null || ! Str::isUuid($targetRunId)) {
            abort(400, 'A valid UUID runId is required.');
        }

        /** @var AssistantContract $assistant */
        $assistant = app(AssistantContract::class);
        $assistant->cancel($targetRunId);

        $this->isStreaming = false;
        $this->toolStatus = null;
    }

    protected function loadMessages(): void
    {
        if ($this->selectedConversationId === null) {
            $this->messages = [];

            return;
        }

        /** @var User|null $user */
        $user = auth()->user();
        if (! $user instanceof User || ! app(ConversationGuard::class)->belongsTo($this->selectedConversationId, $user)) {
            $this->messages = [];

            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ConversationMessage> $records */
        $records = ConversationMessage::query()
            ->where('conversation_id', $this->selectedConversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->oldest()
            ->get();

        if ($records->isNotEmpty()) {
            $this->messages = array_values($records->map(function (ConversationMessage $msg): array {
                $createdAt = $msg->getAttribute('created_at');
                $formattedTime = $createdAt instanceof DateTimeInterface ? $createdAt->format('H:i') : '';

                return [
                    'id' => (string) $msg->getKey(),
                    'role' => (string) $msg->getAttribute('role'),
                    'content' => (string) ($msg->getAttribute('content') ?? ''),
                    'created_at' => $formattedTime,
                    'sources' => $this->sourcesFromSteps($msg->getAttribute('steps')),
                ];
            })->all());
        }
    }

    protected function mapToolStatus(string $toolName): string
    {
        return match ($toolName) {
            'get_kpis' => '⏳ بحسب المؤشرات…',
            'get_kpi_series' => '⏳ بحلل الاتجاهات…',
            'query_metrics' => '⏳ بستعلم عن البيانات…',
            'overview_report' => '⏳ بجهز التقرير الشامل…',
            'sales_comparison' => '⏳ بقارن المبيعات…',
            'profitability' => '⏳ بحلل الربحية…',
            'food_cost_waste' => '⏳ بحسب تكلفة الخامات والهالك…',
            'product_performance' => '⏳ بحلل أداء الأصناف…',
            'shifts_weekdays' => '⏳ بحلل الورديات والأيام…',
            'branch_ranking' => '⏳ برتب الفروع…',
            'targets_status' => '⏳ بفحص المستهدفات…',
            'expenses_purchases' => '⏳ براجع المصروفات والمشتريات…',
            'data_health' => '⏳ بفحص صحة البيانات…',
            'analyze_growth' => '⏳ بحلل النمو…',
            'detect_anomalies' => '⏳ بفحص التغيرات غير المعتادة…',
            'analyze_profit_contribution' => '⏳ بحلل مساهمة الأرباح…',
            'analyze_cost_ratios' => '⏳ بحلل نسب التكاليف…',
            'forecast_sales' => '⏳ بحسب التوقعات…',
            'forecast_material_needs' => '⏳ بتوقع احتياجات المواد…',
            'menu_engineering' => '⏳ بهندس قائمة الطعام…',
            'get_workspace_context' => '⏳ بجهز سياق العمل…',
            'resolve_period' => '⏳ بحدد الفترة الزمنية…',
            'find_branches' => '⏳ ببحث عن الفروع…',
            'search_products' => '⏳ ببحث عن الأصناف…',
            default => '⏳ بجهز البيانات…',
        };
    }

    /**
     * Short noun for a data source, shown as a ticket under the answer that used it.
     */
    protected function sourceLabel(string $toolName): string
    {
        return match ($toolName) {
            'get_workspace_context' => 'نطاق حسابك',
            'resolve_period' => 'الفترة',
            'find_branches' => 'الفروع',
            'search_products' => 'الأصناف',
            'get_kpis' => 'المؤشرات',
            'get_kpi_series' => 'الاتجاه الزمني',
            'query_metrics' => 'تفاصيل المبيعات',
            'targets_status' => 'المستهدفات',
            'data_health' => 'حالة البيانات',
            default => 'بيانات التقارير',
        };
    }

    /**
     * Tool names recorded in a stored assistant message's steps, as source labels.
     *
     * @return list<string>
     */
    protected function sourcesFromSteps(mixed $steps): array
    {
        if (is_string($steps)) {
            $steps = json_decode($steps, true);
        }

        if (! is_array($steps)) {
            return [];
        }

        $labels = [];
        foreach ($steps as $step) {
            $calls = is_array($step) ? ($step['tool_calls'] ?? []) : [];
            foreach (is_array($calls) ? $calls : [] as $call) {
                $name = is_array($call) ? ($call['name'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $labels[] = $this->sourceLabel($name);
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Whether the organization has a usable provider; null when the readiness check is unavailable.
     */
    public function getReadiness(): ?AssistantReadinessResult
    {
        $organization = auth()->user()?->organization;

        if ($organization === null || ! class_exists(AssistantReadiness::class)) {
            return null;
        }

        return app(AssistantReadiness::class)->check($organization);
    }

    public function canManageAssistant(): bool
    {
        return (bool) auth()->user()?->can('ai.manage');
    }

    public function getSettingsUrl(): ?string
    {
        return class_exists(AiSettingsPage::class) && $this->canManageAssistant()
            ? AiSettingsPage::getUrl()
            : null;
    }

    public function getGreeting(): string
    {
        $name = Str::of((string) auth()->user()?->name)->trim()->before(' ')->toString();
        $greeting = now()->hour < 12 ? 'صباح الخير' : 'مساء الخير';

        return $name !== '' ? "{$greeting}، {$name}" : $greeting;
    }

    /**
     * @return list<string>
     */
    public function getStarterPrompts(): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user !== null && $user->hasRole('branch_manager')) {
            return [
                'ليه مبيعات فرعي نزلت الأسبوع ده؟',
                'إيه أكتر 5 أصناف مبيعاً في الفرع؟',
                'هل هنحقق مستهدف الفرع الشهر ده؟',
                'توقع مبيعات الفرع لآخر الشهر',
            ];
        }

        return [
            'ليه مبيعات فرع المعادي نزلت الأسبوع ده؟',
            'إيه أكتر 5 أصناف بتخسر؟',
            'هنحقق المستهدف الشهر ده؟',
            'توقع المبيعات لآخر الشهر',
        ];
    }

    public function renderMarkdown(?string $content): string
    {
        if (blank($content)) {
            return '';
        }

        $html = Str::markdown($content, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        // Markdown output is already escaped; these only add classes to tags it generated.
        $html = str_replace(['<table>', '</table>'], ['<div class="aa-table"><table>', '</table></div>'], $html);

        return (string) preg_replace('/<(p|li)>(\s*(?:<strong>)?\s*اقتراح)/u', '<$1 class="aa-suggest">$2', $html);
    }
}
