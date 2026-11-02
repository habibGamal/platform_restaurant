<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\FinanceSpecialist;
use App\Ai\Agents\MarketingSpecialist;
use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\AssistantStreamEvents;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\NumericGroundingChecker;
use App\Enums\OrganizationPlan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\AgentTool;
use ReflectionClass;
use RuntimeException;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

final class CoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $organization = Organization::factory()->create(['plan' => OrganizationPlan::PRO, 'ai_enabled' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $user->givePermissionTo('ai.view');

        return $user;
    }

    /** @return list<AssistantEvent> */
    private function stream(User $user, Channel $channel = Channel::Chat, ?string $runId = null): array
    {
        return iterator_to_array(app(AssistantService::class)->stream($user, $channel, null, 'راجع الأداء', $runId ?? (string) Str::uuid()));
    }

    /**
     * The stock fake gives a step either text or tool calls; real providers write the plan and
     * call the specialist in the same step, so the coordinator fake here allows both.
     *
     * @param  list<mixed>  $responses
     */
    private function fakeCoordinator(array $responses): void
    {
        $gateway = new class($responses) extends FakeTextGateway
        {
            protected function toStepResponse(mixed $response, TextProvider $provider, string $model): StepResponse
            {
                if ($response instanceof TextResponse && $response->toolCalls->isNotEmpty()) {
                    return new StepResponse($response->text, $response->toolCalls->all(), FinishReason::ToolCalls, $response->usage, $response->meta);
                }

                return parent::toStepResponse($response, $provider, $model);
            }
        };

        (fn () => $this->fakeAgentGateways[RestaurantAnalyst::class] = $gateway)->call(app(AiManager::class));
    }

    private function planAndDelegate(string $agent = 'marketing_specialist', string $plan = 'خطة: هراجع الأداء مع المتخصص.'): TextResponse
    {
        $response = new TextResponse($plan, new TextUsage, new Meta('fake', 'fake'));
        $response->toolCalls = collect([new ToolCall('delegation', $agent, ['task' => 'الفترة 2026-10-01 إلى 2026-10-16؛ الفروع المصرح بها؛ راجع الأداء'])]);

        return $response;
    }

    public function test_coordinator_exposes_basic_tools_and_specialists_with_same_run_context(): void
    {
        $user = new User;
        $agent = new RestaurantAnalyst($user, Channel::WhatsApp, 'run');
        $tools = [...$agent->tools()];
        $this->assertSame(['get_workspace_context', 'resolve_period', 'find_branches', 'get_kpis', 'get_kpi_series', 'query_metrics', 'marketing_specialist', 'finance_specialist', 'operations_specialist', 'data_checker'], array_map(fn ($tool) => $tool->name(), $tools));
        foreach (array_slice($tools, 6) as $tool) {
            $this->assertInstanceOf(AgentTool::class, $tool);
            $this->assertSame($user, $tool->agent()->user);
            $this->assertSame(Channel::WhatsApp, $tool->agent()->channel);
            $this->assertSame('run', $tool->agent()->runId);
        }
        $this->assertSame(10, (new ReflectionClass($agent))->getAttributes(MaxSteps::class)[0]->newInstance()->value);
    }

    public function test_simple_question_makes_no_delegation_and_keeps_source_chips(): void
    {
        $user = $this->user();
        RestaurantAnalyst::fake([new ToolCall('basic', 'get_kpis', []), 'تمت مراجعة المؤشرات.']);
        $events = $this->stream($user);
        $types = array_column($events, 'type');
        $this->assertNotContains('agent_started', $types);
        $this->assertContains('step_started', $types);
        $this->assertContains('tool_call', $types);
        $this->assertContains('tool_result', $types);
        $this->assertSame('done', end($events)->type);
    }

    public function test_delegated_run_emits_ordered_progress_and_hides_specialist_text(): void
    {
        $user = $this->user();
        $this->fakeCoordinator([$this->planAndDelegate(), 'الخلاصة النهائية فقط.']);
        MarketingSpecialist::fake([new ToolCall('menu', 'menu_engineering', ['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-16']), 'نتائج داخلية سرية.']);
        $events = $this->stream($user);
        $progress = array_values(array_filter($events, fn (AssistantEvent $event) => in_array($event->type, ['plan', 'agent_started', 'step_started', 'step_finished', 'agent_finished', 'done'], true)));
        $this->assertSame(['plan', 'agent_started', 'step_started', 'step_finished', 'agent_finished', 'done'], array_column($progress, 'type'));
        $this->assertSame('هراجع الأداء مع المتخصص.', $progress[0]->text);
        $this->assertSame('marketing', $progress[1]->agent);
        $this->assertSame('التسويق', $progress[1]->text);
        $this->assertSame('marketing', $progress[2]->agent);
        $this->assertSame('بحسب هوامش وأداء الأصناف…', $progress[2]->text);
        $this->assertNotEmpty($progress[3]->text);
        $this->assertFalse($progress[4]->failed);
        $answer = implode('', array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'text_delta'), 'text'));
        $this->assertSame('الخلاصة النهائية فقط.', $answer);
        $this->assertContains('menu_engineering', array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'tool_result'), 'toolName'));
    }

    public function test_failed_specialist_does_not_end_the_coordinator_run(): void
    {
        $user = $this->user();
        $this->fakeCoordinator([$this->planAndDelegate(), 'أقدر أجاوب بالبيانات المتاحة.']);
        MarketingSpecialist::fake([fn () => throw new RuntimeException('provider secret')]);
        $events = $this->stream($user);
        $finished = collect($events)->firstWhere('type', 'agent_finished');
        $this->assertInstanceOf(AssistantEvent::class, $finished);
        $this->assertTrue($finished->failed);
        $this->assertNotContains('error', array_column($events, 'type'));
        $this->assertSame('done', end($events)->type);
        $this->assertNotContains('provider secret', array_column($events, 'text'));
    }

    public function test_sequential_specialists_keep_their_steps_and_invocations_separate(): void
    {
        $user = $this->user();
        $this->fakeCoordinator([$this->planAndDelegate(), new ToolCall('finance', 'finance_specialist', ['task' => 'راجع المؤشرات لنفس الفترة والفروع المصرح بها']), 'الخلاصة.']);
        MarketingSpecialist::fake([new ToolCall('same-id', 'resolve_period', []), 'نتيجة التسويق.']);
        FinanceSpecialist::fake([new ToolCall('same-id', 'resolve_period', []), 'نتيجة المالية.']);
        $events = $this->stream($user);
        $this->assertSame(['marketing', 'finance'], array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'step_started'), 'agent'));
        $this->assertCount(2, array_filter($events, fn (AssistantEvent $event) => $event->type === 'agent_finished'));
        MarketingSpecialist::assertPrompted(fn (AgentPrompt $prompt) => $prompt->parentInvocationId !== null && $prompt->parentToolInvocationId !== null && $prompt->messages === null);
    }

    public function test_cancel_during_specialist_stops_all_agents_after_the_current_step(): void
    {
        $user = $this->user();
        $runId = (string) Str::uuid();
        $this->fakeCoordinator([$this->planAndDelegate(), 'لا يجب عرض هذه الإجابة.']);
        MarketingSpecialist::fake([function () use ($user, $runId): ToolCall {
            Cache::put("ai:cancel:{$user->id}:{$runId}", true, 600);

            return new ToolCall('first', 'resolve_period', []);
        }, 'لا يجب توليد هذه النتائج.']);
        $events = $this->stream($user, runId: $runId);
        $this->assertSame('error', end($events)->type);
        $this->assertSame('cancelled', end($events)->errorCode);
        $this->assertNotContains('done', array_column($events, 'type'));
        $this->assertNotContains('text_delta', array_column($events, 'type'));
    }

    public function test_whatsapp_omits_the_plan_and_work_log(): void
    {
        $user = $this->user();
        $this->fakeCoordinator([$this->planAndDelegate(), 'الخلاصة.']);
        MarketingSpecialist::fake([new ToolCall('period', 'resolve_period', []), 'نتائج داخلية.']);
        $events = $this->stream($user, Channel::WhatsApp);
        $this->assertEmpty(array_intersect(['plan', 'agent_started', 'step_started', 'step_finished', 'agent_finished'], array_column($events, 'type')));
        $this->assertSame('الخلاصة.', implode('', array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'text_delta'), 'text')));
    }

    public function test_delegated_grounding_uses_actual_nested_tool_results_and_rejects_narrative_numbers(): void
    {
        $fixture = TwoBranchesTwoWeeks::create();
        $fixture->managerA->givePermissionTo('ai.view');
        RestaurantAnalyst::fake([new ToolCall('delegate', 'marketing_specialist', ['task' => 'راجع المبيعات']), 'المبيعات 12,780 ج.م']);
        MarketingSpecialist::fake([new ToolCall('kpis', 'get_kpis', ['preset' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-16', 'branch_ids' => [$fixture->branchA->id], 'kpis' => ['K06']]), '{"invented":987654321}']);
        $case = ['id' => 'delegated', 'category' => 'kpis', 'persona' => 'manager_a', 'question' => 'راجع المبيعات', 'expected_tools' => ['marketing_specialist', 'get_kpis'], 'expected_numbers' => [12780], 'must_refuse' => false];
        $runner = new EvalRunner(new NumericGroundingChecker, $fixture);
        $passed = $runner->run(customCases: [$case])->cases[0];
        $this->assertTrue($passed->groundingPassed);
        $this->assertContains(12780, $passed->groundingResult->matched);

        RestaurantAnalyst::fake([new ToolCall('delegate', 'marketing_specialist', ['task' => 'راجع المبيعات']), 'المبيعات 987654321 ج.م']);
        MarketingSpecialist::fake(['{"invented":987654321}']);
        $failed = $runner->run(customCases: [$case])->cases[0];
        $this->assertFalse($failed->groundingPassed);
        $this->assertContains(987654321, $failed->groundingResult->ungrounded);
    }

    public function test_plan_lines_anywhere_in_the_coordinator_text_become_plan_events(): void
    {
        $user = $this->user();
        $first = $this->planAndDelegate(plan: "تمام، هبدأ.\nخطة: هراجع الأداء مع المتخصص.");
        $this->fakeCoordinator([$first, "الخلاصة الأولى.\nخطة: هأكد الأرقام.\nالخلاصة الثانية."]);
        MarketingSpecialist::fake(['نتائج داخلية.']);

        $events = $this->stream($user);

        $this->assertSame(['هراجع الأداء مع المتخصص.', 'هأكد الأرقام.'], array_column(array_values(array_filter($events, fn (AssistantEvent $event) => $event->type === 'plan')), 'text'));
        $answer = implode('', array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'text_delta'), 'text'));
        $this->assertStringNotContainsString('خطة:', $answer);
        $this->assertStringContainsString('تمام، هبدأ.', $answer);
        $this->assertStringContainsString("الخلاصة الأولى.\nالخلاصة الثانية.", $answer);
        $this->assertSame('done', end($events)->type);
    }

    public function test_non_streamed_reply_drops_plan_lines_and_rejects_printed_tool_calls(): void
    {
        $user = $this->user();

        RestaurantAnalyst::fake(["خطة: هراجع المؤشرات.\nالمبيعات مستقرة.\nخطة: تأكيد.\nلا تغيير."]);
        $reply = app(AssistantService::class)->ask($user, Channel::WhatsApp, null, 'راجع الأداء');
        $this->assertNull($reply->errorCode);
        $this->assertSame("المبيعات مستقرة.\nلا تغيير.", $reply->text);

        RestaurantAnalyst::fake(['<tool_call>{"name": "get_kpis"}</tool_call>']);
        $reply = app(AssistantService::class)->ask($user, Channel::WhatsApp, null, 'راجع الأداء');
        $this->assertSame('provider_error', $reply->errorCode);
    }

    public function test_plan_line_split_across_deltas_is_held_back_until_complete(): void
    {
        $mapping = new AssistantStreamEvents([], Channel::Chat);
        $events = $mapping->map((new StreamStart('s', 'fake', 'fake', 0))->withInvocationId('coordinator'));
        foreach (mb_str_split("مقدمة\n  خطة: هراجع المبيعات\n\nالإجابة") as $index => $char) {
            array_push($events, ...$mapping->map((new TextDelta("d{$index}", 'm', $char, 0))->withInvocationId('coordinator')));
        }
        array_push($events, ...$mapping->map((new StreamEnd('e', 'stop', new TextUsage, 0))->withInvocationId('coordinator')));

        $this->assertSame(['هراجع المبيعات'], array_column(array_values(array_filter($events, fn (AssistantEvent $event) => $event->type === 'plan')), 'text'));
        $this->assertSame("مقدمة\nالإجابة", implode('', array_column(array_filter($events, fn (AssistantEvent $event) => $event->type === 'text_delta'), 'text')));
    }

    public function test_tool_call_printed_as_text_ends_the_run_with_a_provider_error(): void
    {
        $user = $this->user();
        $printed = [
            "هراجع البيانات.\n<tool_call>{\"name\": \"get_kpis\"}</tool_call>",
            "هراجع البيانات.\n<tool_use name=\"get_kpis\">",
            "هراجع البيانات.\n{\"name\": \"marketing_specialist\", \"arguments\": {\"task\": \"راجع\"}}",
            // DeepSeek-style markup, seen live when the proxy routed a request to a DeepSeek model.
            "سأتحقق أولاً.\n<｜DSML｜ calls>\n<｜DSML｜ invoke name=\"data_checker\">\n</｜DSML｜ invoke>\n</｜DSML｜ calls>",
            "هراجع البيانات.\n<function_calls><invoke name=\"get_kpis\"></invoke></function_calls>",
        ];

        foreach ($printed as $text) {
            RestaurantAnalyst::fake([$text]);
            $events = $this->stream($user);

            $last = end($events);
            $this->assertSame('error', $last->type, $text);
            $this->assertSame('provider_error', $last->errorCode);
            $this->assertSame('النموذج رجّع رد غير سليم. أعد إرسال السؤال من فضلك.', $last->text);
            $this->assertNotContains('done', array_column($events, 'type'));
        }

        RestaurantAnalyst::fake(['الإجابة: {"name": "x"} ليست استدعاء أداة.']);
        $events = $this->stream($user);
        $this->assertSame('done', end($events)->type);
    }
}
