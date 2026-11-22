# 11. Assistant integration: work in progress

The assistant is being developed concurrently and its uncommitted files were changing during this documentation pass. This is an inventory of observed code, not a deployment or completeness guarantee. Reinspect the checkout, migrations, service bindings, provider configuration, permissions and tests before using it.

## Access and conversation boundaries

[`AiAccess`](../../app/Domain/Assistant/AiAccess.php) checks that the user belongs to an organization, has `ai.view`, that the organization has AI enabled, and that its plan is Pro. [`Assistant`](../../app/Filament/App/Pages/Assistant.php) checks the `use-assistant` gate, exposes an assistant page and manages conversation selection and messages. [`ConversationGuard`](../../app/Domain/Assistant/ConversationGuard.php) checks the conversation ID against both user and organization in `ai_conversations_meta`. Whether the gate and concrete assistant implementation are wired and available depends on the current working tree and deployment.

The [`Assistant` contract](../../app/Domain/Assistant/Contracts/Assistant.php) defines streaming, synchronous questions and cancellation; an interface and comments alone do not establish that a production implementation, external messaging channel, rate limit, or end-to-end guarantees are present.

## Tools, grounding and evaluation

The current `app/Ai/Tools` tree includes workspace context, branch discovery, period resolution, KPI totals and KPI-series tools. They use report filters and `MetricsQuery`; inspect the tool's schema, authorization, caching and result-envelope concerns for the exact contract. [`NumericGroundingChecker`](../../app/Domain/Assistant/NumericGroundingChecker.php) compares numeric values extracted from an answer with tool-result values; it is a check rather than proof of overall answer correctness.

The `tests/Evals` dataset and `app/Domain/Assistant/Evals` contain an evaluation harness. Their presence alone does not mean an evaluation has run or met its thresholds. AI report proposals under `.plans/reports` should not be treated as implemented report pages unless the current source shows otherwise.
