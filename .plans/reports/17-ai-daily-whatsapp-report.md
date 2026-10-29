# 17 — AI: Daily Management Report on WhatsApp (تقرير يومي للإدارة على WhatsApp)

**Requirement:** §3 — send a daily report to management on WhatsApp with reports, suggestions or alerts.
**Tier:** AI · **Delivery:** WhatsApp Business Cloud API · **Also viewable:** `/analytics/daily-report/{date}`

## Recipients
`notification_recipients` (managed in the `app` panel (Management group)): name, WhatsApp number (E.164), user link,
scope (`group` or specific branches), language (ar), report on/off, alerts on/off, opt-in timestamp
(required by WhatsApp policy). Branch managers receive only their branches.

## Content (for yesterday's business date)
1. **Headline** — group K06, K14, K15, K01 vs same weekday last week and vs daily target.
2. **Branches** — best and worst branch by sales vs target; any branch with `missing` data.
3. **Alerts** — up to 3 highest-severity items from report 12/18 for that date.
4. **Suggestions** — 1–2 items: AI suggestion of the day (menu, cost or staffing) with impact.
5. **Link** — signed URL (48 h) to the full daily report page/PDF.

## Generation pipeline (`SendDailyReports` job, per org)
1. Trigger: when all active branches of the org have `is_day_closed` for D, or at 10:00 local time on D+1, whichever first (late branches marked "لم يُغلق اليوم").
2. Build payload JSON per recipient scope (deterministic, from `agg_branch_daily`, targets, anomalies).
3. LLM writes the Arabic summary (≤ 700 characters) from the payload; numeric post-check (architecture §8); fallback to a fixed template text.
4. Render the full report page to PDF (Browsershot) → S3 → signed URL.
5. Send via WhatsApp **template message** (business-initiated messages outside the 24 h window must use an approved template):
   - Template `daily_report_ar` — header: document (PDF); body params: `{{1}}` date, `{{2}}` sales, `{{3}}` vs last week, `{{4}}` net profit, `{{5}}` target %, `{{6}}` short summary (≤ 1024 chars total body); button: URL to report page.
6. Record `whatsapp_messages`: recipient, template, wamid, status (sent/delivered/read/failed via webhook), error.

## WhatsApp integration (`App\Domain\Messaging\WhatsApp`)
- Config per org (or platform-wide number): phone_number_id, WABA id, access token (encrypted).
- Webhook `POST /webhooks/whatsapp` (signature verified with app secret) → status updates, opt-out handling ("إيقاف" → disable recipient).
- Retries: 3 with backoff; failures surfaced in the manage panel.

## Acceptance criteria
- Report is not sent twice for the same (recipient, date) — unique key.
- Branch manager's payload contains only their branches.
- Template params never exceed WhatsApp length limits (truncate summary with "…" + link).
- With LLM unavailable, the fallback template text is sent on time.
