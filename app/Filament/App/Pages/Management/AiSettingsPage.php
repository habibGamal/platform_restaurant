<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\AiBilling\BudgetGuard;
use App\Domain\AiBilling\BudgetState;
use App\Enums\OrganizationPlan;
use App\Filament\App\Pages\Assistant;
use App\Models\AiModelCertification;
use App\Models\AiSetting;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\Rule;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class AiSettingsPage extends Page
{
    protected static string $routePath = 'management/ai-settings';

    protected static ?string $title = 'إعدادات المساعد الذكي';

    protected static ?string $navigationLabel = 'إعدادات المساعد الذكي';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && $user->can('ai.manage');
    }

    public function isAiEligible(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        $org = $user->organization;
        if ($org === null) {
            return false;
        }

        $plan = $org->getAttribute('plan');
        $isPro = $plan === OrganizationPlan::PRO || $plan === OrganizationPlan::PRO->value;

        return (bool) $org->ai_enabled && $isPro;
    }

    public function mount(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('ai.manage')) {
            abort(403);
        }

        if (! $this->isAiEligible()) {
            return;
        }

        $orgId = $user->organization_id;
        $setting = $orgId !== null ? AiSetting::query()->where('organization_id', $orgId)->first() : null;

        $this->data = [
            'provider' => $setting !== null ? $setting->provider : 'anthropic',
            'model' => $setting !== null ? $setting->model : 'claude-sonnet-5-5',
            'reasoning_level' => $setting !== null ? $setting->reasoning_level : 'medium',
            'failover' => $setting !== null ? ($setting->failover ?? []) : [],
            'byo_key' => '', // Never pre-filled or revealed
            'monthly_budget_egp' => $setting !== null && $setting->monthly_budget_egp !== null ? (float) $setting->monthly_budget_egp : null,
            'per_user_monthly_egp' => $setting !== null && $setting->per_user_monthly_egp !== null ? (float) $setting->per_user_monthly_egp : null,
            'warn_at' => $setting !== null ? (int) round((float) $setting->warn_at * 100) : 80,
            'hard_stop' => $setting !== null ? (bool) $setting->hard_stop : true,
            'data_sharing_ack' => $setting !== null && $setting->data_sharing_ack_at !== null,
        ];
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->isAiEligible()) {
            return $schema->components([
                Section::make('خدمة المساعد الذكي غير مفعلة')
                    ->description($this->getAiDisabledNotice())
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->components([
                        Placeholder::make('notice')
                            ->label('')
                            ->content($this->getAiDisabledNotice()),
                    ]),
            ]);
        }

        /** @var User $user */
        $user = auth()->user();

        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    Section::make('مزود خدمة الذكاء الاصطناعي')
                        ->description('حدد المزود والنموذج الأساسي المعتمد لتحليلات المساعد الذكي.')
                        ->components([
                            Select::make('provider')
                                ->label('مزود الخدمة (Provider)')
                                ->options(static::getChatProviders())
                                ->required()
                                ->live(),

                            TextInput::make('model')
                                ->label('اسم النموذج (Model)')
                                ->required()
                                ->placeholder('claude-sonnet-5-5')
                                ->datalist(fn ($get) => static::getSuggestedModels((string) ($get('provider') ?? 'anthropic')))
                                ->live(onBlur: true)
                                ->hint(function ($get): ?string {
                                    $provider = (string) ($get('provider') ?? '');
                                    $model = (string) ($get('model') ?? '');
                                    if (blank($provider) || blank($model)) {
                                        return null;
                                    }

                                    $passed = AiModelCertification::query()
                                        ->where('provider', $provider)
                                        ->where('model', $model)
                                        ->where('passed', true)
                                        ->exists();

                                    return $passed ? 'معتمد وموثق' : 'غير معتمد بعد';
                                })
                                ->hintColor(function ($get): string {
                                    $provider = (string) ($get('provider') ?? '');
                                    $model = (string) ($get('model') ?? '');
                                    if (blank($provider) || blank($model)) {
                                        return 'gray';
                                    }

                                    $passed = AiModelCertification::query()
                                        ->where('provider', $provider)
                                        ->where('model', $model)
                                        ->where('passed', true)
                                        ->exists();

                                    return $passed ? 'success' : 'gray';
                                })
                                ->hintIcon(function ($get): ?Heroicon {
                                    $provider = (string) ($get('provider') ?? '');
                                    $model = (string) ($get('model') ?? '');
                                    if (blank($provider) || blank($model)) {
                                        return null;
                                    }

                                    $passed = AiModelCertification::query()
                                        ->where('provider', $provider)
                                        ->where('model', $model)
                                        ->where('passed', true)
                                        ->exists();

                                    return $passed ? Heroicon::OutlinedCheckBadge : Heroicon::OutlinedInformationCircle;
                                }),

                            Select::make('reasoning_level')
                                ->label('مستوى التفكير (Reasoning Level)')
                                ->options([
                                    'low' => 'سريع',
                                    'medium' => 'متوازن',
                                    'high' => 'عميق',
                                ])
                                ->default('medium')
                                ->required()
                                ->helperText('مستوى التفكير يؤثر على جودة التحليل والتكلفة؛ المستوى السريع أقل تكلفة وأسرع، بينما العميق يوفر تحليلاً أدق للمسائل المعقدة.'),

                            Repeater::make('failover')
                                ->label('مزودو الخدمة الاحتياطيون (Failover)')
                                ->schema([
                                    Select::make('provider')
                                        ->label('المزود')
                                        ->options(static::getChatProviders())
                                        ->required(),

                                    TextInput::make('model')
                                        ->label('اسم النموذج')
                                        ->required(),
                                ])
                                ->maxItems(3)
                                ->reorderable()
                                ->collapsible()
                                ->addActionLabel('إضافة مزود احتياطي')
                                ->default([]),
                        ]),

                    Section::make('مفتاح الربط الخاص بك (BYO API Key)')
                        ->description('يمكنك استخدام مفتاح الربط البرمجي الخاص بمؤسستك بدلاً من مفتاح المنصة المشترك.')
                        ->components([
                            TextInput::make('byo_key')
                                ->label('مفتاح الربط (API Key)')
                                ->password()
                                ->placeholder(fn () => $this->hasStoredByoKey() ? '•••••••••••••••• (مفتاح خاص محفوظ)' : 'أدخل المفتاح هنا في حال الرغبة باستخدامه')
                                ->helperText('يتم تخزين المفتاح مشفراً واستخدامه فقط لعمليات هذه المؤسسة.')
                                ->hintAction(
                                    Action::make('remove_byo_key')
                                        ->label('إزالة المفتاح الخاص')
                                        ->icon(Heroicon::OutlinedTrash)
                                        ->color('danger')
                                        ->requiresConfirmation()
                                        ->action(fn () => $this->removeByoKey())
                                        ->visible(fn () => $this->hasStoredByoKey())
                                ),
                        ]),

                    Section::make('الميزانية والتحكم بالتكاليف')
                        ->description('إدارة الميزانية الشهرية وضوابط الإنفاق الخاصة بالمساعد الذكي.')
                        ->components([
                            Placeholder::make('spend_summary')
                                ->label('ملخص الاستهلاك الشهري')
                                ->content(function () use ($user): string {
                                    $org = $user->organization;
                                    if ($org === null) {
                                        return '-';
                                    }

                                    $guard = app(BudgetGuard::class);
                                    $spend = number_format($guard->monthSpendEgp($org), 2);
                                    $budget = number_format($guard->budgetFor($org), 2);
                                    $state = $guard->state($org);
                                    $stateLabel = match ($state) {
                                        BudgetState::OK => 'طبيعي',
                                        BudgetState::WARNING => 'تحذير: اقتراب من الحد',
                                        BudgetState::EXHAUSTED => 'مستنفدة',
                                    };

                                    return "استهلاك هذا الشهر: {$spend} ج.م / الميزانية: {$budget} ج.م ({$stateLabel})";
                                }),

                            TextInput::make('monthly_budget_egp')
                                ->label('الميزانية الشهرية للمؤسسة')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('ج.م')
                                ->nullable()
                                ->helperText('الميزانية الشهرية الإجمالية للمؤسسة بالجنيه المصري.'),

                            TextInput::make('per_user_monthly_egp')
                                ->label('الحد الأقصى الشهري لكل مستخدم')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('ج.م')
                                ->nullable()
                                ->helperText('اختياري: الحد الأقصى المسموح باستهلاكه لكل مستخدم شهرياً.'),

                            TextInput::make('warn_at')
                                ->label('نسبة التحذير (%)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required()
                                ->helperText('إرسال إشعار تحذيري عند وصول استهلاك المؤسسة لهذه النسبة من الميزانية (مثلاً 80%).'),

                            Toggle::make('hard_stop')
                                ->label('إيقاف تلقائي صارم عند استنفاد الميزانية')
                                ->default(true)
                                ->helperText('منع استهلاك إضافي للمساعد الذكي فور وصول الإنفاق إلى 100% من الميزانية.'),
                        ]),

                    Section::make('إقرار مشاركة البيانات')
                        ->description('الشروط والأحكام الخاصة بإرسال البيانات التحليلية.')
                        ->components([
                            Checkbox::make('data_sharing_ack')
                                ->label('أقر وأوافق على إرسال الأرقام والإحصائيات المجمعة إلى مزود الخدمة المختار')
                                ->helperText('البيانات المرسلة تقتصر على مؤشرات الأداء والبيانات الإحصائية المجمعة ولا تتضمن أي بيانات شخصية للعملاء.')
                                ->required(),
                        ]),
                ])
                    ->livewireSubmitHandler('save'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->isAiEligible()) {
            return [];
        }

        return [
            $this->saveAction(),
            $this->testConnectionAction(),
            $this->openAssistantAction(),
        ];
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('حفظ التعديلات')
            ->color('primary')
            ->action('save');
    }

    public function testConnectionAction(): Action
    {
        return Action::make('test_connection')
            ->label('اختبار الاتصال')
            ->icon(Heroicon::OutlinedSignal)
            ->color('secondary')
            ->action('testConnection');
    }

    public function test_connectionAction(): Action
    {
        return $this->testConnectionAction();
    }

    public function openAssistantAction(): Action
    {
        return Action::make('open_assistant')
            ->label('فتح المساعد')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->url(fn (): string => Assistant::getUrl());
    }

    public function removeByoKeyAction(): Action
    {
        return Action::make('remove_byo_key')
            ->label('إزالة المفتاح الخاص')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->action('removeByoKey');
    }

    public function remove_byo_keyAction(): Action
    {
        return $this->removeByoKeyAction();
    }

    public function hasStoredByoKey(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user?->organization_id === null) {
            return false;
        }

        $setting = AiSetting::query()->where('organization_id', $user->organization_id)->first();

        return $setting !== null && ! empty($setting->byo_key);
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('ai.manage')) {
            abort(403);
        }

        $org = $user->organization;
        if ($org === null || ! $this->isAiEligible()) {
            abort(403);
        }

        $chatProviders = array_keys(static::getChatProviders());

        $this->validate([
            'data.provider' => ['required', 'string', Rule::in($chatProviders)],
            'data.model' => ['required', 'string', 'max:255'],
            'data.reasoning_level' => ['required', 'string', Rule::in(['low', 'medium', 'high'])],
            'data.failover' => ['nullable', 'array', 'max:3'],
            'data.failover.*.provider' => ['required', 'string', Rule::in($chatProviders)],
            'data.failover.*.model' => ['required', 'string', 'max:255'],
            'data.byo_key' => ['nullable', 'string'],
            'data.monthly_budget_egp' => ['nullable', 'numeric', 'min:0'],
            'data.per_user_monthly_egp' => ['nullable', 'numeric', 'min:0'],
            'data.warn_at' => ['required', 'numeric', 'min:1', 'max:100'],
            'data.hard_stop' => ['boolean'],
            'data.data_sharing_ack' => ['accepted'],
        ], [
            'data.provider.required' => 'حقل المزود مطلوب.',
            'data.provider.in' => 'المزود المختار غير صالح.',
            'data.model.required' => 'حقل النموذج مطلوب.',
            'data.reasoning_level.required' => 'حقل مستوى التفكير مطلوب.',
            'data.failover.max' => 'الحد الأقصى للمزودين الاحتياطيين هو 3.',
            'data.failover.*.provider.required' => 'يرجى اختيار مزود الخدمة الاحتياطي.',
            'data.failover.*.provider.in' => 'المزود الاحتياطي غير صالح.',
            'data.failover.*.model.required' => 'يرجى إدخال اسم النموذج الاحتياطي.',
            'data.monthly_budget_egp.min' => 'يجب أن تكون الميزانية أكبر من أو تساوي 0.',
            'data.per_user_monthly_egp.min' => 'يجب أن يكون الحد الأقصى للمستخدم أكبر من أو تساوي 0.',
            'data.warn_at.required' => 'حقل نسبة التحذير مطلوب.',
            'data.warn_at.min' => 'يجب أن تكون نسبة التحذير بين 1 و 100.',
            'data.warn_at.max' => 'يجب أن تكون نسبة التحذير بين 1 و 100.',
            'data.data_sharing_ack.accepted' => 'يجب الموافقة على إقرار مشاركة البيانات.',
        ]);

        $existing = AiSetting::query()->where('organization_id', $org->id)->first();
        $ackAt = $existing !== null && $existing->data_sharing_ack_at !== null
            ? $existing->data_sharing_ack_at
            : now();

        /** @var array<string, mixed> $data */
        $data = $this->data ?? [];

        $failover = ! empty($data['failover']) && is_array($data['failover'])
            ? array_values($data['failover'])
            : null;

        $attributes = [
            'provider' => (string) ($data['provider'] ?? 'anthropic'),
            'model' => (string) ($data['model'] ?? 'claude-sonnet-5-5'),
            'reasoning_level' => (string) ($data['reasoning_level'] ?? 'medium'),
            'failover' => $failover,
            'monthly_budget_egp' => filled($data['monthly_budget_egp'] ?? null) ? (string) ((float) $data['monthly_budget_egp']) : null,
            'per_user_monthly_egp' => filled($data['per_user_monthly_egp'] ?? null) ? (string) ((float) $data['per_user_monthly_egp']) : null,
            'warn_at' => (string) round(((float) ($data['warn_at'] ?? 80)) / 100, 2),
            'hard_stop' => (bool) ($data['hard_stop'] ?? true),
            'data_sharing_ack_at' => $ackAt,
        ];

        // Empty submission keeps the existing encrypted key; new key overwrites
        if (filled($data['byo_key'] ?? null)) {
            $attributes['byo_key'] = (string) $data['byo_key'];
        }

        AiSetting::updateOrCreate(
            ['organization_id' => $org->id],
            $attributes
        );

        // Clear submitted key from memory/state so it never appears in rendered HTML
        if (isset($this->data['byo_key'])) {
            $this->data['byo_key'] = '';
        }

        Notification::make()
            ->title('تم حفظ إعدادات المساعد الذكي بنجاح')
            ->success()
            ->send();
    }

    public function removeByoKey(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('ai.manage')) {
            abort(403);
        }

        $orgId = $user->organization_id;
        if ($orgId !== null) {
            $setting = AiSetting::query()->where('organization_id', $orgId)->first();
            if ($setting !== null) {
                $setting->update(['byo_key' => null]);
            }
        }

        if (is_array($this->data)) {
            $this->data['byo_key'] = '';
        }

        Notification::make()
            ->title('تمت إزالة المفتاح الخاص بنجاح')
            ->success()
            ->send();
    }

    public function testConnection(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('ai.manage')) {
            abort(403);
        }

        $org = $user->organization;
        if ($org === null || ! $this->isAiEligible()) {
            abort(403);
        }

        $setting = AiSetting::query()->where('organization_id', $org->id)->first();
        if ($setting === null) {
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('يرجى حفظ الإعدادات أولاً قبل اختبار الاتصال.')
                ->warning()
                ->send();

            return;
        }

        $providerName = $setting->provider;
        $model = $setting->model;

        if (! empty($setting->byo_key)) {
            /** @var array<string, mixed> $baseConfig */
            $baseConfig = config("ai.providers.{$providerName}", ['driver' => $providerName]);
            $onDemandName = "byo_test_{$providerName}_{$org->id}";

            try {
                $built = Ai::build(array_merge($baseConfig, [
                    'name' => $onDemandName,
                    'key' => $setting->byo_key,
                ]));
                $providerName = $built->name();
            } catch (Throwable) {
                // Testing the platform key here would report a success the organization's own key never earned.
                Notification::make()
                    ->title('فشل اختبار الاتصال')
                    ->body('تعذر تجهيز مفتاحك الخاص للاتصال. احفظ المفتاح مرة أخرى ثم أعد الاختبار.')
                    ->danger()
                    ->send();

                return;
            }
        }

        try {
            $agent = new AnonymousAgent('You are a connectivity test agent.', [], []);
            $agent->prompt('ping', provider: $providerName, model: $model, timeout: 5);

            Notification::make()
                ->title('تم الاتصال بنجاح')
                ->body('تم الاتصال بمزود الذكاء الاصطناعي بنجاح.')
                ->success()
                ->send();
        } catch (RateLimitedException) {
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('تجاوز معدل الطلبات المسموح به (Rate Limit). يرجى المحاولة لاحقاً.')
                ->danger()
                ->send();
        } catch (InsufficientCreditsException) {
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('رصيد الحساب غير كافٍ لدى مزود الخدمة.')
                ->danger()
                ->send();
        } catch (ProviderOverloadedException) {
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('خوادم مزود الخدمة تواجه ضغطاً كبيراً حالياً.')
                ->danger()
                ->send();
        } catch (ProviderConnectionException) {
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('فشل الاتصال بمزود الخدمة. يرجى التحقق من مفتاح الربط والاتصال.')
                ->danger()
                ->send();
        } catch (RequestException $e) {
            $status = $e->response->status();
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body(in_array($status, [401, 403], true)
                    ? 'المزود رفض مفتاح الاتصال. تأكد من المفتاح وصلاحياته ثم أعد الاختبار.'
                    : "رد المزود بخطأ (رمز {$status}). تأكد من اسم النموذج ثم أعد الاختبار.")
                ->danger()
                ->send();
        } catch (Throwable $e) {
            $cleanMessage = $this->sanitizeErrorMessage($e->getMessage(), $setting->byo_key);
            Notification::make()
                ->title('فشل اختبار الاتصال')
                ->body('تعذر إتمام اختبار الاتصال: '.$cleanMessage)
                ->danger()
                ->send();
        }
    }

    protected function sanitizeErrorMessage(string $message, ?string $byoKey = null): string
    {
        if ($byoKey !== null && $byoKey !== '') {
            $message = str_replace($byoKey, '[مفتاح محجوب]', $message);
        }

        $sanitized = preg_replace('/(sk-[a-zA-Z0-9_\-]{8,})/', '[مفتاح محجوب]', $message);

        return is_string($sanitized) ? $sanitized : 'حدث خطأ أثناء الاتصال.';
    }

    protected function getAiDisabledNotice(): string
    {
        /** @var User|null $user */
        $user = auth()->user();
        $org = $user?->organization;

        if ($org === null) {
            return 'المستخدم غير مرتبط بأي مؤسسة حالياً.';
        }

        if (! (bool) $org->ai_enabled) {
            return 'خدمة المساعد الذكي غير مفعلة لمؤسستك حالياً. يرجى التواصل مع إدارة المنصة لتفعيل الخدمة.';
        }

        $plan = $org->getAttribute('plan');
        $isPro = $plan === OrganizationPlan::PRO || $plan === OrganizationPlan::PRO->value;

        if (! $isPro) {
            return 'المساعد الذكي متاح فقط في باقة الاحترافي (Pro). يرجى ترقية باقة المؤسسة للاستفادة من هذه الميزة.';
        }

        return 'المساعد الذكي غير متاح حالياً.';
    }

    /**
     * @return array<string, string>
     */
    public static function getChatProviders(): array
    {
        $curated = [
            'anthropic' => 'Anthropic (Claude)',
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
            'openrouter' => 'OpenRouter',
            'deepseek' => 'DeepSeek',
            'mistral' => 'Mistral AI',
            'groq' => 'Groq',
            'xai' => 'xAI (Grok)',
            'azure' => 'Azure OpenAI',
            'ollama' => 'Ollama (محلي)',
        ];

        /** @var array<string, mixed> $configured */
        $configured = config('ai.providers', []);
        $options = [];

        foreach ($curated as $provider => $label) {
            if (! isset($configured[$provider])) {
                continue;
            }

            $hasPlatformKey = $provider === 'ollama' || (! empty($configured[$provider]['key']) && trim((string) $configured[$provider]['key']) !== '');
            $status = $hasPlatformKey ? '✓ مفتاح المنصة متاح' : 'يحتاج مفتاحك الخاص';
            $options[$provider] = "{$label} ({$status})";
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function getSuggestedModels(string $provider): array
    {
        return match ($provider) {
            'anthropic' => [
                'claude-sonnet-5-5',
                'claude-haiku-5-5',
                'claude-opus-5-5',
            ],
            'openai' => [
                'gpt-4o',
                'gpt-4o-mini',
                'o1',
                'o3-mini',
            ],
            'gemini' => [
                'gemini-1.5-pro',
                'gemini-1.5-flash',
                'gemini-2.0-flash',
            ],
            'openrouter' => [
                'anthropic/claude-3.5-sonnet',
                'openai/gpt-4o',
                'meta-llama/llama-3.3-70b-instruct',
            ],
            'deepseek' => [
                'deepseek-chat',
                'deepseek-reasoner',
            ],
            'mistral' => [
                'mistral-large-latest',
                'mistral-small-latest',
            ],
            'groq' => [
                'llama-3.3-70b-versatile',
                'mixtral-8x7b-32768',
            ],
            'xai' => [
                'grok-2-latest',
                'grok-beta',
            ],
            'azure' => [
                'gpt-4o',
                'gpt-4o-mini',
            ],
            'ollama' => [
                'llama3.2',
                'qwen2.5',
                'mistral',
            ],
            default => [],
        };
    }
}
