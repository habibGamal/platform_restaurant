# WhatsApp Two-Way Channel & Daily Brief

## What
Two-way Meta WhatsApp Business Cloud API integration (`POST /webhooks/whatsapp`), staff phone OTP linking, daily automated Arabic management brief (`SendDailyReports` + `daily_brief_ar` / `alert_ar` / `otp_ar` templates), and PDF report attachment rendering.

## Why
Owners and managers want daily yesterday-summary briefs and urgent alerts delivered on WhatsApp, with the ability to reply directly into a grounded `RestaurantAnalyst` conversation. `App\Domain\Assistant\Channel::WhatsApp` and WhatsApp brevity rules already exist in `RestaurantAnalyst`, but no transport, identity linking, or scheduled sender is built.

## Where
- **Migrations:** `whatsapp_identities`, `whatsapp_messages`, `ai_brief_subscriptions`, `notification_recipients` (or consolidated recipient subscriptions).
- **Domain:** `app/Domain/Messaging/WhatsApp/` (`CloudApiClient`, signature verifier, inbound router, outbound formatter splitting at 4096 chars / max 3 parts), `SendDailyReports` job.
- **Routes / Controllers:** `POST /webhooks/whatsapp` (and GET verify challenge) + profile phone verification UI in `/app`.
- **Templates:** Approved Meta templates (`daily_report_ar` / `daily_brief_ar`, `alert_ar`, `otp_ar`).

## Done when
- Verified staff can opt in/out ("إيقاف") and receive daily briefs scoped to `AccessibleBranches` (`branch_manager` never sees other branches).
- Inbound WhatsApp messages from verified numbers route through `AssistantService` and respect `AiAccess` + `BudgetGuard`; unknown numbers receive a fixed rejection without invoking an LLM.
- Numeric post-check (`NumericGroundingChecker`) guards scheduled narratives and falls back to a fixed template on failure.
- Feature tests cover signature verification, `wamid` deduplication, formatter rules, scoping, and template fallback.
