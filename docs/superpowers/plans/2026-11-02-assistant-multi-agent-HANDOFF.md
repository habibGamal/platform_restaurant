# Handoff: assistant multi-agent build

Date: 2026-11-02, 12:45
Branch: `feat/assistant-multi-agent` (created from `main` @ `cb7cf5b`; nothing pushed)

## Documents
- Design (approved): `docs/superpowers/specs/2026-11-02-assistant-multi-agent-design.md`
- Plan (14 tasks): `docs/superpowers/plans/2026-11-02-assistant-multi-agent.md`
- Progress ledger, rulings, task briefs/reports, review diffs (git-ignored): `.superpowers/sdd/2026-11-02-assistant-multi-agent/`
  - `progress.md`: ledger (first line names the plan; `Task N: complete` lines are done)
  - `constraints.md`, `implementer-instructions.md`, `reviewer-instructions.md`: reusable dispatch inputs

## Status
| Task | State | Commits |
|---|---|---|
| 1 Item role storage | complete, reviewed | `c459227` |
| 2 Signals + MenuFixture | complete, reviewed (1 fix round) | `053b782`, `32e6333` |
| 3 AI role classification + job | complete, reviewed (1 fix round) | `99f5542`, `950be86` |
| 4 Item roles review page | complete, reviewed (1 fix round) | `290433a`, `b16a3f7` |
| 5 Menu engineering + tool | **started, uncommitted, unreviewed** | none |
| 6–14 | not started | none |

### Task 5 partial work (uncommitted, from a stopped agent)
- `app/Ai/Tools/Contracts/` (ReportsProgress contract)
- `app/Domain/Menu/MenuEngineeringAnalysis.php`
- `tests/Feature/Menu/MenuEngineeringTest.php`
- `app/Ai/Tools/Concerns/FormatsToolResult.php`: adds `?string $summary = null` to `formatEnvelope`

The `menu_engineering` tool class was not written yet. Either continue from these files or delete them and redo Task 5 from its brief (`.superpowers/sdd/.../task-5-brief.md`).

## How to resume in Claude Code CLI
```
cd D:\platform-restaurants
git switch feat/assistant-multi-agent
claude
```
Then ask: "Continue executing docs/superpowers/plans/2026-11-02-assistant-multi-agent.md with superpowers:subagent-driven-development. The ledger is at .superpowers/sdd/2026-11-02-assistant-multi-agent/progress.md. Tasks 1–4 are complete; Task 5 has uncommitted partial work."

## Rulings made so far (each with its cost if wrong)
- **R1:** work happens on the branch in place, and implementers stage only their own files. Pre-existing user edits in touched files ride along: `config/ai.php` in `c459227`, and `AiSettingsPage.php` in `290433a`. Cost if wrong: some unrelated edits sit inside feature commits.
- **R2:** MenuFixture seeds complete days for all 60 days. Cost if wrong: Task 8 fixture rework.
- **R3:** Task 12 extracts `AssistantService::runStream()` after the gates. `stream()` becomes gates + runStream, and `start()` becomes gates + a synchronous conversation + a job. Cost if wrong: small refactor.
- **R4:** Task 12 adds `start()` to the Assistant contract and to `FakeAssistant`. Cost if wrong: none.
- **R5:** Task 14's menu evals seed roles via `setOwnerRole`, so there are no classifier calls. Cost if wrong: minor.
- **R6:** Task 13's live manual check (real provider plus a queue worker) happens at the end, by the controller. Cost if wrong: a UI issue is found later.
- **R7:** when the org has AI access but its budget is exhausted, the data-only roles are still saved and no model calls are made. Orgs without AI access skip entirely. Cost if wrong: trivial.
- **R8:** an item already judged by the AI within the last 7 days is not re-sent (`ai.roles.reclassify_after_days`). Cost if wrong: an uncertain item stays excluded for up to a week.
- **R9:** the commit trailer follows the harness attribution (Sonnet subagents wrote "Claude Sonnet 5.5"). Cost if wrong: cosmetic.
- **R10:** Sonnet returned "model not found" twice for Task 5, so it was retried on Opus. Cost if wrong: higher token spend.

## Open items for you
1. **Security finding (not fixed):** `config/ai.php` defaults the `openai` provider to miraiapi (`OPENAI_URL` default) with the `openai-compatible` driver. Any server without `OPENAI_URL` in `.env` sends OpenAI keys, including BYO keys, to miraiapi. Suggested fix: restore the defaults `https://api.openai.com/v1` / `openai`, and set in `.env`:
   ```
   OPENAI_URL=https://api.miraiapi.com/v1
   OPENAI_DRIVER=openai-compatible
   ```
2. **Model availability:** subagent dispatches with `model: sonnet` started failing with `model_not_found` at 12:40. Your preference is Sonnet for routine tasks; check it works before resuming.
3. **Test memory:** running `tests/Feature/Menu`, `Assistant`, `Ai` and `Ingestion` in one `php artisan test` call exhausts the 128M worker memory. Run each folder separately. This was already the case before this branch.
4. **Deferred minor findings** are listed in the ledger (`minor (deferred)` lines) for the final whole-branch review to triage.
5. **Uncommitted user work:** about 50 pre-existing uncommitted changes (report widgets, `AiSettingsPageTest.php`, `.plans/`, …) are still in the working tree, untouched.

## Earlier in this session (already on disk, uncommitted on main's history)
- The assistant "couldn't reach provider" error was fixed: miraiapi's `/responses` stream never sends `response.completed`. The `openai` provider now uses the chat-completions driver, `ProviderOptions` maps `reasoning_effort` and the BYO provider names. Tests cover it. These edits were in the working tree; the `config/ai.php` part was committed inside `c459227` (R1). `app/Ai/ProviderOptions.php`, `.env.example` and `ProviderOptionsTest.php` remain uncommitted.
