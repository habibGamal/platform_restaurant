<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Assistant\AssistantReadiness;
use App\Domain\Assistant\AssistantReadinessResult;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Domain\Assistant\ConversationGuard;
use App\Domain\Assistant\RunLog;
use App\Filament\App\Pages\Management\AiSettingsPage;
use App\Models\AiConversationMeta;
use App\Models\AiRunEvent;
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
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;

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

    /** The last run-log row read for the current run. */
    #[Locked]
    public int $lastSeq = 0;

    /**
     * What the agents of the current run are doing, rebuilt from its run log.
     *
     * @var array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    #[Locked]
    public array $workLog = ['plan' => null, 'agents' => []];

    /**
     * Source labels of the tools the current run called.
     *
     * @var list<string>
     */
    #[Locked]
    public array $liveSources = [];

    /**
     * @var list<array{id: string, role: string, content: string, created_at: string, sources: list<string>, workLog: ?array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}}>
     */
    public array $messages = [];

    /** The coordinator never sends its own label; specialists normally do (agent_started text). */
    private const AGENT_LABELS = [
        'assistant' => 'المساعد',
        'marketing' => 'التسويق',
        'finance' => 'المالية',
        'operations' => 'التشغيل',
        'data_checker' => 'مدقق البيانات',
    ];

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
                $this->resumeUnfinishedRun($user);
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
        $this->resetRun();

        $this->loadMessages();
        $this->resumeUnfinishedRun($user);
    }

    public function newConversation(): void
    {
        $this->selectedConversationId = null;
        $this->messages = [];
        $this->prompt = '';
        $this->resetRun();
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
            'workLog' => null,
        ];

        $this->resetRun();
        $this->currentRunId = $runId;
        $this->isStreaming = true;
        $this->streamedAnswer = '';

        /** @var AssistantContract $assistant */
        $assistant = app(AssistantContract::class);

        $conversationId = $assistant->start(
            user: $user,
            channel: Channel::Chat,
            conversationId: $this->selectedConversationId,
            text: $text,
            runId: $runId,
        );

        if ($conversationId !== null) {
            $this->selectedConversationId = $conversationId;
        }

        // A gate failure is already in the log; show it now rather than on the first poll.
        $this->poll();
    }

    /**
     * Read the run-log rows written since the last poll. Wired to `wire:poll` only while a run streams.
     */
    public function poll(): void
    {
        if (! $this->isStreaming || $this->currentRunId === null) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        foreach (app(RunLog::class)->since($this->currentRunId, $user->id, $this->lastSeq) as $row) {
            $this->lastSeq = $row['seq'];
            $this->applyRunEvent($row['type'], $row['payload']);

            if (! $this->isStreaming) {
                break;
            }
        }
    }

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
        $this->workLog = $this->finishWorkLog($this->workLog, 'failed');
    }

    /**
     * One-line summary of a finished work log, e.g. «استعان بـ: التسويق، المالية · 2 خطوات».
     *
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}  $log
     */
    public function workLogSummary(array $log): string
    {
        $specialists = array_values(array_filter($log['agents'], fn (array $agent): bool => $agent['agent'] !== 'assistant'));
        $labels = array_column($specialists !== [] ? $specialists : $log['agents'], 'label');
        $steps = array_sum(array_column($log['agents'], 'steps'));

        return 'استعان بـ: '.implode('، ', $labels).' · '.($steps === 1 ? 'خطوة واحدة' : "{$steps} خطوات");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyRunEvent(string $type, array $payload): void
    {
        $text = is_string($payload['text'] ?? null) ? $payload['text'] : null;
        $this->workLog = $this->reduceWorkLog($this->workLog, $type, $payload);

        if ($type === 'text_delta' && $text !== null) {
            $this->streamedAnswer .= $text;
        } elseif ($type === 'tool_call' && is_string($payload['toolName'] ?? null)) {
            $this->toolStatus = $this->mapToolStatus($payload['toolName']);
            $source = $this->sourceLabel($payload['toolName']);
            if (! in_array($source, $this->liveSources, true)) {
                $this->liveSources[] = $source;
            }
        } elseif ($type === 'tool_result') {
            $this->toolStatus = null;
        } elseif ($type === 'done' || $type === 'error') {
            if (is_string($payload['conversationId'] ?? null)) {
                $this->selectedConversationId = $payload['conversationId'];
            }
            $this->isStreaming = false;
            $this->toolStatus = null;

            if ($type === 'error') {
                // Text streamed before an error may be a half answer or a printed tool call: drop it.
                $this->streamedAnswer = null;
                $this->errorMessage = $text ?? 'حدث خطأ أثناء معالجة الطلب.';
                $this->errorCode = is_string($payload['errorCode'] ?? null) ? $payload['errorCode'] : null;
            }

            $this->loadMessages();

            if ($type === 'done' && $this->answerIsStored($payload['messageId'] ?? null)) {
                $this->streamedAnswer = null;
                $this->workLog = ['plan' => null, 'agents' => []];
                $this->liveSources = [];
            }
        }
    }

    /**
     * The work log after one more run event.
     *
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}  $log
     * @param  array<string, mixed>  $payload
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    protected function reduceWorkLog(array $log, string $type, array $payload): array
    {
        $text = is_string($payload['text'] ?? null) ? trim($payload['text']) : '';

        if ($type === 'plan') {
            $log['plan'] = $text !== '' ? $text : $log['plan'];

            return $log;
        }
        if ($type === 'done' || $type === 'error') {
            return $this->finishWorkLog($log, $type === 'done' ? 'done' : 'failed');
        }

        $agent = is_string($payload['agent'] ?? null) ? $payload['agent'] : null;
        if ($agent === null || ! in_array($type, ['agent_started', 'step_started', 'step_finished', 'agent_finished'], true)) {
            return $log;
        }

        $index = array_search($agent, array_column($log['agents'], 'agent'), true);
        if ($index === false) {
            $log['agents'][] = ['agent' => $agent, 'label' => self::AGENT_LABELS[$agent] ?? $agent, 'status' => 'running', 'step' => null, 'findings' => [], 'steps' => 0];
            $index = array_key_last($log['agents']);
        }
        $row = $log['agents'][$index];

        if ($type === 'agent_started') {
            $row['status'] = 'running';
            if ($agent !== 'assistant' && $text !== '') {
                $row['label'] = $text;
            }
        } elseif ($type === 'step_started') {
            $row['status'] = 'running';
            $row['step'] = $text !== '' ? $text : null;
            $row['steps']++;
        } elseif ($type === 'step_finished') {
            $row['step'] = null;
            if ($text !== '') {
                $row['findings'][] = $text;
            }
        } else {
            $row['status'] = ($payload['failed'] ?? false) === true ? 'failed' : 'done';
            $row['step'] = null;
        }

        $log['agents'][$index] = $row;

        return $log;
    }

    /**
     * Agents still marked running when the run ended get its final status.
     *
     * @param  array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}  $log
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    protected function finishWorkLog(array $log, string $status): array
    {
        foreach ($log['agents'] as $i => $agent) {
            if ($agent['status'] === 'running') {
                $log['agents'][$i]['status'] = $status;
                $log['agents'][$i]['step'] = null;
            }
        }

        return $log;
    }

    /**
     * The work log of a stored assistant message. Only called for messages of a conversation the user owns.
     *
     * @return array{plan: ?string, agents: list<array{agent: string, label: string, status: string, step: ?string, findings: list<string>, steps: int}>}
     */
    protected function workLogForMessage(string $messageId): array
    {
        $log = ['plan' => null, 'agents' => []];
        foreach (app(RunLog::class)->forMessage($messageId) as $row) {
            $log = $this->reduceWorkLog($log, $row['type'], $row['payload']);
        }

        return $log;
    }

    private function answerIsStored(mixed $messageId): bool
    {
        $last = end($this->messages);

        if (is_string($messageId) && in_array($messageId, array_column($this->messages, 'id'), true)) {
            return true;
        }

        return $last !== false && $last['role'] === 'assistant' && $last['content'] === $this->streamedAnswer;
    }

    /**
     * After a reload, pick up the user's latest run in the selected conversation if it has not finished.
     */
    private function resumeUnfinishedRun(User $user): void
    {
        if ($this->selectedConversationId === null) {
            return;
        }

        $runId = AiRunEvent::query()
            ->where('conversation_id', $this->selectedConversationId)
            ->where('user_id', $user->id)
            ->latest('id')
            ->value('run_id');

        if (! is_string($runId) || app(RunLog::class)->isFinished($runId)) {
            return;
        }

        $this->resetRun();
        $this->currentRunId = $runId;
        $this->isStreaming = true;
        $this->streamedAnswer = '';
        $this->poll();
    }

    private function resetRun(): void
    {
        $this->currentRunId = null;
        $this->isStreaming = false;
        $this->lastSeq = 0;
        $this->workLog = ['plan' => null, 'agents' => []];
        $this->liveSources = [];
        $this->toolStatus = null;
        $this->errorMessage = null;
        $this->errorCode = null;
        $this->streamedAnswer = null;
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

                $role = (string) $msg->getAttribute('role');

                return [
                    'id' => (string) $msg->getKey(),
                    'role' => $role,
                    'content' => (string) ($msg->getAttribute('content') ?? ''),
                    'created_at' => $formattedTime,
                    'sources' => $this->sourcesFromSteps($msg->getAttribute('steps')),
                    'workLog' => $role === 'assistant' ? $this->workLogForMessage((string) $msg->getKey()) : null,
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
