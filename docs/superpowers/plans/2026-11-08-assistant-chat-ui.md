# Assistant Chat UI (assistant-ui + AI SDK) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Livewire/Filament assistant page with a full-screen, ChatGPT-style React chat with real-time streaming, a saved conversation list and generative-UI cards for the Menu Intelligence tools.

**Architecture:**
- The **backend is unchanged in spirit.** Questions still run in the queued `RunAssistantQuestion` job, and `RunLog` (`ai_run_events`) stays the single source of truth for a run, so the guarantees around stop, resume, the watchdog and never billing twice are kept.
- **New streaming path.** A new SSE endpoint tails the run log and converts its rows into Vercel AI SDK "UI message stream v1" parts. assistant-ui's `useChatRuntime` + `AssistantChatTransport` consume that stream, with resumable streams for page refreshes.
- **Conversation list.** A `RemoteThreadListAdapter` is backed by the existing conversation tables.
- **Generative UI.** Selected tool results are captured from the coordinator and the specialists, stored per message, and rendered as React cards.

**Tech Stack:**
- Backend: Laravel 13, `laravel/ai` ^1.3, PHPUnit 12, MariaDB 10.4 locally / MySQL 8 in production
- Frontend: Inertia 3 + React 19, Tailwind 4, the shadcn "new-york" components (`components.json`)
- Chat: `@assistant-ui/react` + `@assistant-ui/ai-sdk`, `ai` / `@ai-sdk/react`
- Rendering: Streamdown (markdown while streaming), Recharts, `@tanstack/react-table`
- Tooling: vite-plus (`vp`)

**Spec:** `docs/superpowers/specs/2026-11-07-assistant-v2-decision-engine.md` §4 ("Is the stack right for the chat?"), plus these decisions agreed in chat on 2026-11-08:
- assistant-ui with its AI SDK binding (chosen for generative UI)
- an Inertia React page
- run-log-backed SSE instead of Reverb
- Streamdown, Recharts and TanStack Table

## Global Constraints

- **Run semantics stay as they are.** Every question still goes through `AssistantService::start()` → `RunAssistantQuestion` (tries = 1, unique per run id) → `RunLog`. The new endpoints never call the agents directly and never queue a second run for the same question.
- **Wire format:** AI SDK UI message stream v1. Response header `x-vercel-ai-ui-message-stream: v1`, `Content-Type: text/event-stream`, each part as `data: {json}\n\n`, ending with `data: [DONE]\n\n`. This is the same part vocabulary as `vendor/laravel/ai/src/Streaming/Protocols/VercelDataProtocol.php`.
- **Copy:** owner-facing text is Egyptian Arabic, as in the existing page (`app/Filament/App/Pages/Assistant.php`). Western digits, currency ج.م. The chat page sets `dir="rtl"` and `lang="ar"` and uses Tailwind logical classes (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`) only, never `ml-`, `mr-`, `left-` or `right-`.
- **Auth:** web session guard + `Gate::allows('use-assistant')` on every endpoint. Mutations send Laravel's `X-XSRF-TOKEN` (read from the `XSRF-TOKEN` cookie). Conversation ownership always goes through `ConversationGuard::assertBelongsTo()`, and run ownership through `ai_run_events.user_id`.
- **No new infrastructure:** no Reverb or websocket server, no Next.js. The SSE tail runs inside a normal Laravel response.
- **Versions:** Task 1 pins exact versions for `@assistant-ui/react`, `@assistant-ui/ai-sdk`, `ai`, `@ai-sdk/react`, `streamdown`, `recharts` and `@tanstack/react-table`, and records them in `resources/js/assistant/VERSIONS.md` together with the API names it confirmed (tool-UI registration, the resumable stream-id header, the history message format). Later tasks use those names. The library is fast-moving: `makeAssistantToolUI` is deprecated in favour of render-only toolkit entries.
- **UI tool allowlist:** `config('assistant.ui_tools')` = `['rank_items', 'get_combo_candidates', 'get_item_card', 'get_affinities', 'get_kpi_series']`. Only these tools' outputs are captured and rendered.
- **Tests:** PHPUnit feature tests under `tests/Feature/Assistant/Chat/`, run with `php -d memory_limit=2G vendor/bin/phpunit`. Pure TypeScript logic is tested with vite-plus's test runner (Task 1 confirms the command). The frontend must pass `npm run types:check` and `npm run build`.
- **Git:** the owner keeps uncommitted work in the tree and chose "no commits" last time. Commit only if the owner asks. Never revert or reformat unrelated files.

## Review Focus

1. **Refresh or network drop mid-answer:** the page reconnects to the same run and shows one answer, with no second POST, no second job and no double bill. Test: Task 5 `test_resume_replays_same_run_without_starting_a_new_one`.
2. **Sending while a run is streaming, or a double-click on send:** only one run starts per conversation. The second request gets a 409 with an Arabic message. Test: Task 5 `test_second_question_while_running_is_rejected`.
3. **Another user's ids:** another user's conversation id, run id or combo signature in the URL or body gets a 403/404 and leaks no data. This covers threads, messages, chat, resume, cancel and decisions. Tests: Tasks 5, 6 and 10 (`*_of_another_user_is_forbidden`).
4. **Unknown, oversized or malformed tool output:** the message still renders its text, and the card shows a small Arabic "couldn't show the details" fallback, never a blank screen. Tests: Task 4 `test_malformed_tool_output_is_passed_through_as_error_part`; Task 9 TS test `renders fallback for unexpected payload`.
5. **Half-written markdown in long Arabic answers while streaming** (tables, lists, mixed numbers) renders without layout jumps, and also on a 375 px viewport. Verification: Task 8 TS test for the markdown component, plus the Task 12 browser checklist.

---

## File Structure

**Backend (new)**
- `config/assistant.php`: `ui_tools`, `stream` settings: `poll_ms` 250, `max_seconds` 960, `queue_notice_seconds` 60, `queue_give_up_seconds` = the assistant connection's `retry_after`.
- `app/Domain/Assistant/Ui/UiStreamEncoder.php`: run-log rows → UI stream parts (pure, stateful per run).
- `app/Domain/Assistant/Ui/MessagePartsRepository.php`: persists and reads per-message tool and worklog parts.
- `app/Domain/Assistant/Ui/ThreadMessages.php`: a conversation's stored messages → `UIMessage[]` JSON.
- `app/Domain/Assistant/Ui/RunTail.php`: the SSE tail loop. Clock and sleeper are injectable; it runs the queue-wait watchdog.
- `app/Domain/Assistant/AssistantHome.php`: greeting, starter prompts and readiness, extracted from `Assistant.php`.
- `app/Http/Controllers/Assistant/{AssistantPageController,ChatController,RunController,ThreadController,ComboDecisionController}.php`
- `app/Http/Requests/Assistant/ChatRequest.php`
- `routes/assistant.php`, required from `routes/web.php`
- Migration `database/migrations/2026_11_09_000001_create_ai_message_parts_table.php` + model `app/Models/AiMessagePart.php`
- `app/Domain/Menu/Intelligence/ComboDecisions.php`, extracted from `ComboCandidatesPage`

**Backend (modified)**
- `app/Domain/Assistant/AssistantEvent.php`: new `toolOutput()` event
- `app/Domain/Assistant/AssistantStreamEvents.php`: emits tool outputs
- `app/Domain/Assistant/RunLog.php`: copies parts when a run finishes
- `app/Domain/Assistant/AssistantService.php`: public `createConversation()`
- `app/Filament/App/Pages/Assistant.php`: redirect behind a flag; delegates to `AssistantHome`
- `app/Filament/App/Pages/Management/ComboCandidatesPage.php`: uses `ComboDecisions`

**Frontend (new, under `resources/js/`)**
- `pages/assistant/index.tsx`: the page
- `assistant/runtime.ts`: transport, resumable storage, cancel
- `assistant/thread-list-adapter.ts`
- `assistant/api.ts`: fetch helpers, XSRF handling, Wayfinder routes
- `assistant/types.ts`: TS shapes of the tool envelopes
- `assistant/tool-uis/{rank-items,combo-candidates,item-card,affinities,kpi-series,fallback}.tsx`, plus `assistant/tool-uis/index.ts` (the registry)
- `assistant/data-uis/{worklog,status}.tsx`
- `components/assistant-ui/*`: added through the assistant-ui shadcn CLI, then made RTL-safe
- `assistant/VERSIONS.md`

---

### Task 1: Frontend foundation, version pinning and the empty RTL page

**Files:**
- Create: `resources/js/pages/assistant/index.tsx`, `resources/js/assistant/VERSIONS.md`, `resources/js/components/assistant-ui/*`, `routes/assistant.php`, `app/Http/Controllers/Assistant/AssistantPageController.php`, `app/Domain/Assistant/AssistantHome.php`
- Modify: `package.json`, `routes/web.php` (one `require`), `app/Filament/App/Pages/Assistant.php` (delegate greeting, starter prompts and readiness to `AssistantHome`, with no behaviour change)
- Test: `tests/Feature/Assistant/Chat/AssistantPageRouteTest.php`

**Interfaces:**
- Produces:
  - route `GET /assistant` named `assistant`, middleware `auth`, `verified`, `can:use-assistant`
  - Inertia component `assistant/index` with props `{ greeting: string, starterPrompts: string[], readiness: {ready: bool, message: ?string, settingsUrl: ?string} | null, can: {manageCombos: bool}, panelUrl: string }`
  - `AssistantHome::forUser(User $user): array` (the same shape as the props, minus `panelUrl`)

- [ ] **Step 1:** Write `AssistantPageRouteTest`:
  - `test_allowed_user_gets_assistant_page_with_props`: asserts the Inertia component is `assistant/index`, `starterPrompts` is a non-empty array, and `can.manageCombos` matches `ai.manage`
  - `test_user_without_assistant_access_is_forbidden`: 403
  - `test_guest_is_redirected_to_login`
- [ ] **Step 2:** Run it, expect FAIL (route missing).
- [ ] **Step 3:** Add `AssistantHome`, moving `getGreeting`, `getStarterPrompts` and `getReadiness` out of `Assistant.php` (which now calls it). Add the route file and the controller.
- [ ] **Step 4:** Install the latest stable `@assistant-ui/react`, `@assistant-ui/ai-sdk`, `ai`, `@ai-sdk/react`, `streamdown`, `recharts` and `@tanstack/react-table`, then pin exact versions.
- [ ] **Step 5:** Add assistant-ui's shadcn components (`thread`, `thread-list`, `markdown-text`, `tool-fallback`) with its CLI into `resources/js/components/assistant-ui/`.
- [ ] **Step 6:** Record in `VERSIONS.md`, from the installed packages' docs and types:
  - the exact versions
  - the render-only tool-UI registration API for backend tools
  - the data-part renderer API
  - the resumable stream-id response header name
  - the `ThreadHistoryAdapter.withFormat` format id for AI SDK messages
  - the test-runner command
- [ ] **Step 7:** `pages/assistant/index.tsx` renders `<Thread />` with a local echo runtime (temporary), inside `<div dir="rtl" lang="ar">`. Convert the generated components' physical direction classes to logical ones.
- [ ] **Step 8:** Run `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Assistant/Chat/AssistantPageRouteTest.php` (expect PASS), then `npm run types:check && npm run build` (expect success).

### Task 2: Capture UI tool outputs into the run log

**Files:**
- Create: `config/assistant.php`
- Modify: `app/Domain/Assistant/AssistantEvent.php`, `app/Domain/Assistant/AssistantStreamEvents.php`, `app/Domain/Assistant/AssistantService.php::runStream` (the specialist observer passes tool results through), `app/Domain/Assistant/RunLog.php::payload`
- Test: `tests/Feature/Assistant/Chat/ToolOutputCaptureTest.php`

**Interfaces:**
- Produces:
  - `AssistantEvent::toolOutput(string $toolName, string $toolCallId, array $input, mixed $output, ?string $agent): self`, with type `tool_output`
  - run-log payload `{toolName, toolCallId, input, output, agent?}`
  - `output` is the decoded JSON tool result (the tool envelope array); a non-JSON result is stored as `{"raw": "<string>"}`

- [ ] **Step 1:** Write the tests:
  - `test_allowlisted_tool_result_from_specialist_is_logged_as_tool_output`: feed `AssistantStreamEvents` a laravel/ai `ToolCall` + `ToolResult` pair for `rank_items` with specialist `marketing_specialist`; assert one `tool_output` event with the decoded envelope and matching `toolCallId`
  - `test_non_allowlisted_tool_result_is_not_logged`: `get_kpis`
  - `test_coordinator_level_allowlisted_tool_is_logged`: `get_kpi_series`
  - `test_whatsapp_channel_never_emits_tool_output`
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Pair `ToolCall.toolCall` (its id, name and arguments) with `ToolResult.toolResult` by id. Skip `preliminary` results. A failed result logs `output = {"error": "<message>"}`. Read the allowlist from config. The channel filter lives in `forChannel()`.
- [ ] **Step 4:** Run the new tests plus `tests/Feature/Assistant/Agent/AssistantServiceStreamTest.php`, expect PASS.

### Task 3: Persist per-message parts

**Files:**
- Create: migration `2026_11_09_000001_create_ai_message_parts_table.php`, `app/Models/AiMessagePart.php`, `app/Domain/Assistant/Ui/MessagePartsRepository.php`
- Modify: `app/Domain/Assistant/RunLog.php` (on the `done` event that carries a messageId)
- Test: `tests/Feature/Assistant/Chat/MessagePartsTest.php`

**Interfaces:**
- **Table `ai_message_parts`:** id, message_id string(36) indexed, conversation_id string(36), user_id FK cascade, position smallint, kind string(16) (`tool` | `worklog`), tool_name string(64) nullable, tool_call_id string(64) nullable, payload json, created_at. Unique(message_id, position).
- **`MessagePartsRepository::persistRun(string $runId, string $messageId): int`:** copies the run's `tool_output` rows in seq order, plus one `worklog` snapshot (the final step list folded from the agent/step events, same folding as `Assistant::reduceWorkLog`).
- **`MessagePartsRepository::forMessages(array $messageIds): array<string, list<array{kind, toolName, toolCallId, payload}>>`**

- [ ] **Step 1:** Write the tests:
  - `test_done_event_persists_tool_outputs_and_worklog_in_order`
  - `test_parts_survive_run_event_pruning`: prune the `ai_run_events` rows, then the parts are still readable
  - `test_run_without_message_id_persists_nothing`
  - `test_rerunning_persist_is_idempotent`
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Move the worklog folding into the repository (a static `foldWorkLog(list $rows): array`) and make `Assistant.php::reduceWorkLog` call it, so there is one implementation. Call `persistRun` inside `RunLog::append` right after the `done` update.
- [ ] **Step 4:** Run, expect PASS. Also run `tests/Feature/Assistant/Page` (the Livewire page still uses the folding).

### Task 4: UI stream encoder

**Files:**
- Create: `app/Domain/Assistant/Ui/UiStreamEncoder.php`
- Test: `tests/Unit/Assistant/UiStreamEncoderTest.php`

**Interfaces:**
- Produces: `new UiStreamEncoder(string $runId)`, `->encode(array $row): list<array>` where row = `{seq, type, payload}`, `->finish(): list<array>`, `->status(string $kind): list<array>`
- **Mapping:**

| Run-log event | UI stream parts |
|---|---|
| first event | `{type:'start', messageId: "run-$runId"}` |
| `text_delta` | `text-start` (once, id `t-$runId`), then `text-delta {id, delta}` |
| `plan`, `agent_started`, `step_started`, `step_finished`, `agent_finished`, `tool_call`, `tool_result` | one reconciled `{type:'data-worklog', id:'worklog', data:{plan: ?string, steps: list}}` (same id, so it updates in place) |
| `tool_output` | `tool-input-available {toolCallId, toolName, input}`, then `tool-output-available {toolCallId, output}` |
| `tool_output` with `output.error` | `tool-output-error {toolCallId, errorText}` |
| `done` | `text-end` (if a text part is open), then `{type:'finish', messageMetadata:{conversationId, messageId}}` |
| `error` | `{type:'error', errorText: <Arabic text>}`, then `finish` |
| `status('queue_wait')` | `{type:'data-status', id:'status', data:{kind:'queue_wait', text:'لسه مستني دوره…'}, transient:true}` |

- [ ] **Step 1:** Write table-driven tests, one per mapping row, asserting exact arrays. Also:
  - `test_malformed_tool_output_is_passed_through_as_error_part`
  - `test_text_is_closed_before_finish`
  - `test_worklog_part_keeps_stable_id_across_updates`
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement (pure PHP, no DB).
- [ ] **Step 4:** Run, expect PASS.

### Task 5: Chat, resume and cancel endpoints (SSE)

**Files:**
- Create: `app/Http/Controllers/Assistant/ChatController.php`, `app/Http/Controllers/Assistant/RunController.php`, `app/Http/Requests/Assistant/ChatRequest.php`, `app/Domain/Assistant/Ui/RunTail.php`
- Modify: `routes/assistant.php`
- Test: `tests/Feature/Assistant/Chat/ChatStreamTest.php`

**Interfaces:**
- Consumes: `AssistantService::start(...)`, `RunLog::since(runId, userId, afterSeq)`, `RunLog::isFinished`, `AssistantService::cancel`, `UiStreamEncoder`
- Produces:
  - `POST /assistant/api/chat` (`assistant.chat`). The body is the AssistantChatTransport body: uses `id` (conversation id) and the text of the last `messages[]` item with role `user`; ignores the rest. Generates a uuid runId, calls `start()`, then responds with SSE from `RunTail`. The response header named in VERSIONS.md carries the runId as the stream id.
  - `GET /assistant/api/runs/{runId}/stream` (`assistant.runs.stream`): replays from seq 0, then tails.
  - `POST /assistant/api/runs/{runId}/cancel` (`assistant.runs.cancel`): 204.
  - `RunTail::stream(string $runId, int $userId): Generator<string>` yields SSE lines. It polls every `poll_ms` and stops at done/error or `max_seconds`. Queue-wait watchdog: no rows after `queue_notice_seconds` → status part; after `queue_give_up_seconds` → append `AssistantEvent::error('queue_unavailable', …)` to the run log and finish (same text as `Assistant::watchQueueWait`). Clock and sleeper are constructor-injected.
  - Headers: `Cache-Control: no-cache`, `X-Accel-Buffering: no`, plus the stream-v1 header.

- [ ] **Step 1:** Write the tests, using the existing `FakeAssistant` from `tests/` and seeded run-log rows with a fake sleeper:
  - `test_chat_starts_run_and_streams_encoded_parts_until_done`: assert the response contains `text-delta` parts, `finish` and `[DONE]`
  - `test_gate_error_streams_arabic_error_part`
  - `test_second_question_while_running_is_rejected`: a 409 with an Arabic JSON message when the same conversation has an unfinished run
  - `test_resume_replays_same_run_without_starting_a_new_one`: assert no new job via `Queue::fake()` and the parts are identical
  - `test_cancel_sets_cancel_flag_for_own_run`
  - `test_resume_and_cancel_of_another_users_run_are_forbidden`: 404
  - `test_chat_with_another_users_conversation_is_forbidden`: 403
  - `test_queue_wait_notice_then_give_up`
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Use `response()->stream()`. Call `ob_flush()`/`flush()` after each part. Check `connection_aborted()` each loop and stop tailing quietly: a disconnect is NOT a cancel.
- [ ] **Step 4:** Run, expect PASS.
- [ ] **Step 5:** Manual check: `PHP_CLI_SERVER_WORKERS=4 php artisan serve` plus a queue worker (`php artisan queue:work assistant --queue=assistant`), then `curl -N -X POST` with a session cookie shows parts arriving live. Note the `PHP_CLI_SERVER_WORKERS` requirement in `docs/features/ai-assistant.md`.

### Task 6: Threads API

**Files:**
- Create: `app/Http/Controllers/Assistant/ThreadController.php`, `app/Domain/Assistant/Ui/ThreadMessages.php`
- Modify: `app/Domain/Assistant/AssistantService.php` (make `startConversation` public as `createConversation(User $user, Channel $channel, ?string $title = null): string`, with the default title «محادثة جديدة»), `routes/assistant.php`
- Test: `tests/Feature/Assistant/Chat/ThreadApiTest.php`

**Interfaces:**
- Produces, all under `/assistant/api/threads`, names `assistant.threads.*`:
  - `GET /` → `[{id, title, status: 'regular'|'archived', pinned, lastActivityAt}]`, scoped to the user and channel `chat`, pinned first, then most recent first
  - `POST /` → `{id}` via `createConversation`
  - `GET /{id}` → one item
  - `PATCH /{id}` with `{title?, status?, pinned?}`
  - `DELETE /{id}` → permanent delete of the meta row plus the laravel/ai conversation and its messages and parts. The UI confirms first.
  - `POST /{id}/title` → `{title}`: when the title is still the default, set it from the first user message (the same 80-char excerpt rule as today). No model call.
  - `GET /{id}/messages` → `UIMessage[]` from `ThreadMessages::for(string $conversationId): array`. User messages: `{id, role:'user', parts:[{type:'text', text}]}`. Assistant messages: `{id, role:'assistant', parts:[data-worklog?, tool parts (`type: "tool-<name>"`, `state: 'output-available'`, `toolCallId`, `input`, `output`) in position order, {type:'text', text: storedAnswerText(content)}]}`. Use `Assistant::storedAnswerText`'s cleaning rule, moved to a shared static helper.

- [ ] **Step 1:** Write the tests:
  - list scoping and ordering (another user's and WhatsApp threads hidden)
  - create, rename, archive/unarchive, pin
  - delete removes the messages and parts
  - the title is set from the first message
  - messages include tool parts from `ai_message_parts` in order
  - `test_threads_of_another_user_are_forbidden`, for every route
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run, expect PASS.

### Task 7: Runtime wiring (transport, thread list, history, cancel)

**Files:**
- Create: `resources/js/assistant/runtime.ts`, `resources/js/assistant/thread-list-adapter.ts`, `resources/js/assistant/api.ts`
- Modify: `resources/js/pages/assistant/index.tsx` (replace the echo runtime)
- Test: `resources/js/assistant/api.test.ts`

**Interfaces:**
- Consumes: the routes from Tasks 5–6 (through Wayfinder-generated helpers); the API names from `VERSIONS.md`
- Produces:
  - `useAssistantRuntime(): AssistantRuntime`. Builds `useChatRuntime` with `new AssistantChatTransport({ api: chat route, credentials: 'same-origin', headers: () => ({ 'X-XSRF-TOKEN': xsrf() }), resumable: { storage: createResumableSessionStorage(), resumeApi: id => runs.stream(id) } })`, wrapped in the remote-thread-list runtime with `threadListAdapter`.
  - `threadListAdapter: RemoteThreadListAdapter`. Maps to the Task 6 routes. `generateTitle` calls `POST /{id}/title`. The history `withFormat.load` reads `GET /{id}/messages`; `append` is a no-op, because the server already stores messages.
  - `cancelRun(runId: string): Promise<void>`. The composer's stop button calls it before the runtime's own cancel.
  - `xsrf(): string`

- [ ] **Step 1:** Write TS tests for `api.ts`: `xsrf()` decodes the cookie; `apiFetch` sends the XSRF header and same-origin credentials; a non-2xx response throws with the server's Arabic `message`.
- [ ] **Step 2:** Run the test command from `VERSIONS.md`, expect FAIL.
- [ ] **Step 3:** Implement. Track the current runId from the stream-id header (or the `start` part) so cancel can target it.
- [ ] **Step 4:** Tests pass, and `npm run types:check && npm run build` succeed.
- [ ] **Step 5:** Manual check in the browser:
  - send a question → it streams
  - refresh mid-answer → it resumes with no duplicate answer
  - the new thread appears in the list with its title
  - reopening an old thread loads its history

### Task 8: Page shell and chat UX

**Files:**
- Modify: `resources/js/pages/assistant/index.tsx`, `resources/js/components/assistant-ui/{thread,thread-list,markdown-text}.tsx`
- Create: `resources/js/assistant/data-uis/{worklog,status}.tsx`, `resources/js/assistant/markdown.test.tsx`

**Interfaces:**
- Consumes: the Task 1 props, the data parts `data-worklog` and `data-status`
- Produces:
  - **Layout:** a full-height page. The thread list sits in a start-side sidebar on ≥ 1024 px, and in a Sheet with a header toggle below that. The header has «المساعد الذكي» and a link back to `panelUrl` («لوحة التحكم»).
  - **Welcome screen:** shows `greeting` and the `starterPrompts` as suggestion chips that send on click.
  - **Readiness:** when `readiness.ready === false`, a non-dismissable Alert above the composer with `message`, plus a settings link when `settingsUrl` is present, and the composer disabled.
  - **Markdown:** `markdown-text` uses Streamdown, with RTL tables (horizontal scroll inside the bubble) and digits kept LTR inside numbers.
  - **Worklog:** the data UI shows a collapsible «خطوات التحليل» with the plan line and the steps (spinner on running steps, a check when done, a warning icon when failed).
  - **Status:** the data UI shows the queue-wait line.
  - **Errors:** errors show the server's Arabic text with a «حاول تاني» retry.
  - **Message actions:** copy and regenerate only. No edit, no branching.
  - **Theme:** dark mode follows the existing appearance setting.

- [ ] **Step 1:** Write the TS test `markdown.test.tsx`: an incomplete table chunk (`"| الصنف | الكمية |\n|---|"`) renders without throwing, and the final complete table renders 2 header cells.
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement the shell, the data UIs and the copy.
- [ ] **Step 4:** Tests pass and the build succeeds.
- [ ] **Step 5:** Browser check at 1280 px and 375 px: RTL alignment, sidebar and sheet, chips, worklog collapse, readiness alert (simulate by toggling the AI setting off).

### Task 9: Generative UI cards (rankings, item card, affinities, KPI chart, fallback)

**Files:**
- Create: `resources/js/assistant/types.ts`, `resources/js/assistant/tool-uis/{rank-items,item-card,affinities,kpi-series,fallback}.tsx`, `resources/js/assistant/tool-uis/index.ts`, `resources/js/assistant/tool-uis/tool-uis.test.tsx`
- Modify: `resources/js/pages/assistant/index.tsx` (register the toolkit)

**Interfaces:**
- **Consumes:** the tool envelopes produced by `app/Ai/Tools/Concerns/FormatsToolResult.php::formatEnvelope` (`{status, metrics, completeness:{warnings}, warnings, …}`) and the exact `metrics` shapes of `RankItems`, `GetItemCard`, `GetAffinities` (`app/Ai/Tools/Menu/*`) and `GetKpiSeries` (`app/Ai/Tools/Metrics/GetKpiSeries.php`). Read those classes and their tests to write the TS types; don't guess field names.
- **Produces:** a render-only toolkit registered with the API from `VERSIONS.md`, one entry per allowlisted tool:
  - **`rank_items`:** one card per segment (title from the segment, e.g. «أطباق رئيسية · عائلي»), with a TanStack table of rank, name, qty, net_sales (ج.م), margin_pct and score. Clicking a row expands its score breakdown as labelled bars.
  - **`get_item_card`:** a panel with serves_n, tier, season, the contains labels, list price and cost, a channel-mix mini bar (Recharts), and its rank and segment.
  - **`get_affinities`:** a list of itemsets with names, together_orders and lift (lift formatted to 1 decimal; together_orders always shown next to it).
  - **`get_kpi_series`:** a Recharts line or bar chart over the buckets, one series per branch when broken down. The KPI label comes from the payload.
  - **Fallback:** used when `status !== 'ok'` (Arabic reason from the payload), on a parse failure, or for a tool-output-error. It shows «مقدرتش أعرض التفاصيل دي» and never throws (wrapped in an error boundary).
  - **Running state** (input available, no output yet): a skeleton with the tool's Arabic label (reuse the label map from `Assistant::mapToolStatus`, exported as JSON through `AssistantHome` props as `toolLabels`).

- [ ] **Step 1:** Write the TS tests:
  - `rank-items renders one card per segment with rows in rank order`
  - `item-card shows contains labels and serves`
  - `kpi-series renders a chart for a two-bucket series`
  - `renders fallback for unexpected payload`: `output = {"raw":"x"}` and `output = {status:'insufficient_data', reason:'…'}`
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Add `toolLabels` to the `AssistantHome` props, and update `AssistantPageRouteTest` to assert it.
- [ ] **Step 4:** Tests pass and the build succeeds.

### Task 10: Combo cards with approve / reject

**Files:**
- Create: `app/Domain/Menu/Intelligence/ComboDecisions.php`, `app/Http/Controllers/Assistant/ComboDecisionController.php`, `resources/js/assistant/tool-uis/combo-candidates.tsx`
- Modify: `app/Filament/App/Pages/Management/ComboCandidatesPage.php` (call `ComboDecisions`), `routes/assistant.php`, `resources/js/assistant/tool-uis/index.ts`
- Test: `tests/Feature/Assistant/Chat/ComboDecisionTest.php`, plus a case in `tool-uis.test.tsx`

**Interfaces:**
- **`ComboDecisions::apply(int $organizationId, string $signature, string $status, ?string $note): int`:** status is one of `approved`, `rejected`, `candidate`. Updates every row of the org with that signature across all periods (the Task 7 ruling of the Menu Intelligence plan) and returns the rows updated.
- **`POST /assistant/api/combos/{signature}/decision`** (`assistant.combos.decision`): body `{status, note?}`; requires `ai.manage`; returns `{status, updated}`.
- **`get_combo_candidates` card:** shows the items («صينية الملوك + 4× مياه + شوربة»), the list total vs the suggested price (ج.م), margin %, discount %, the evidence (observed: «اتطلبوا مع بعض N مرة»; rule: «مقترح من المنيو»), an overlap warning and a status badge. When the page prop `can.manageCombos` is set, it also shows «اعتمد» / «ارفض» / «رجوع لمقترح» buttons with optimistic state, reverting on error with a toast.

- [ ] **Step 1:** Write the PHP tests:
  - approve updates every period's row
  - a user without `ai.manage` gets 403
  - `test_combo_decision_of_another_org_signature_is_forbidden`: 0 rows updated and a 404
  - the Filament page still passes `tests/Feature/Menu/Intelligence/ReviewPagesTest.php`

  And the TS test: the buttons are hidden without `manageCombos`.
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Extract `decide()` from `ComboCandidatesPage` into `ComboDecisions` with unchanged behaviour.
- [ ] **Step 4:** All pass and the build succeeds.

### Task 11: Cutover and docs

**Files:**
- Modify: `config/assistant.php` (`react_ui` => env `ASSISTANT_REACT_UI`, default true), `app/Filament/App/Pages/Assistant.php` (`mount()` redirects to `route('assistant')` when `react_ui` is on; navigation unchanged), `.env.example`, `docs/features/ai-assistant.md`
- Test: `tests/Feature/Assistant/Chat/CutoverTest.php`

- [ ] **Step 1:** Write the tests:
  - `test_filament_assistant_page_redirects_to_react_page_when_enabled`
  - `test_livewire_page_still_works_when_disabled`: the flag off renders the old page
- [ ] **Step 2:** Run, expect FAIL.
- [ ] **Step 3:** Implement. Document in `docs/features/ai-assistant.md`:
  - the new endpoints
  - the SSE requirements: nginx `proxy_buffering off` / `fastcgi_buffering off`, with `X-Accel-Buffering` already sent; local `PHP_CLI_SERVER_WORKERS>=4`
  - the flag and the rollback steps
- [ ] **Step 4:** Run `tests/Feature/Assistant`, expect PASS.

### Task 12: End-to-end verification

- [ ] **Step 1:** Start the app with `PHP_CLI_SERVER_WORKERS=4`, the assistant queue worker and Vite. Open `/assistant` in the built-in browser.
- [ ] **Step 2:** Ask «اشتغل على داتا شهر 9 وشهر 10 وقولي أفضل 5 منتجات المفروض أشتغل عليهم في شهر 11». Confirm:
  - text streams smoothly
  - the worklog updates live
  - ranking cards are per segment
  - a family-feast combo card for a tray shows serving-matched drinks
  - approve works and is reflected on «الكومبوهات المقترحة»
- [ ] **Step 3:** Refresh mid-answer: it resumes with no second answer, and `ai_run_events` has one run. Press stop: the run ends and the cancel flag is set.
- [ ] **Step 4:** Check 375 px and dark mode, a long answer with a table, the thread list (rename, pin, delete) and reloading history (the cards re-render from `ai_message_parts`).
- [ ] **Step 5:** Run `php -d memory_limit=2G vendor/bin/phpunit` and the TS tests, then `npm run types:check && npm run build`. Report the results, naming any failure in an area this plan didn't touch.
