# Assistant v2 — from "report reader" to "decision engine"

Status: proposal · Date: 2026-11-07 · Trigger: the "top 5 items for November" answer (Sep–Oct data)

## 1. Verdict

The answer was weak for three reasons, and none of them is the model or a missing vector DB:

1. **The analytics underneath are too thin.** The agent can only re-say what `MenuEngineeringAnalysis` and `ComboBuilder` return, and those engines answer a narrower question than the one the owner asked.
2. **The prompt forbids thinking.** About 60 lines of rules (repeat the specialist's order, no ratios, no projections, suggestions only as «اقتراح:») turn the coordinator into a formatter. It *cannot* tell you what to do, because "what to do" needs numbers it isn't allowed to produce.
3. **The platform data the decision needs is never used:** recipes, existing combos, channels, party size and repeat customers.

So the fix is mostly **deterministic engines + an item knowledge layer + a structured action-plan output**. That is a heavier build than RAG. Plus a **new chat front end**, because the Livewire polling UI cannot render the rich, interactive output this needs.

## 2. Why the answer was useless — evidence

### 2.1 The ranking compares trays with sandwiches
`MenuEngineeringAnalysis::quadrants()` puts *every* `main` into one pool and ranks "hidden gems" by `unit_margin / avg_unit_margin`.
A 3,200 EGP family tray will always be "16× the average" against a 60 EGP shawarma. So the top 5 are just the 5 most expensive trays. That is a tautology, not an insight.

Missing segmentation: **price tier / serving size** (single meal vs family tray), **channel** (talabat vs dine-in) and **daypart**.

### 2.2 The combo engine can only do main + 1 side and bans drinks
`ComboBuilder` takes pairs from `BasketAnalysis` (pairwise lift only). It keeps only `side`/`dessert` partners, applies a fixed discount ladder (15→5%), and stops.
Live data for صينية الملوك (M128, 3,200 EGP, cost 1,711.80):

| Co-ordered with (all-time, 248 orders) | Orders | Share |
|---|---|---|
| مياه معدنية صغيره | 187 | 75% |
| طشه ملوخيه | 65 | 26% |
| بيبسي | 46 | 19% |
| شاي براد | 37 | 15% |
| شوربة لسان عصفور | 33 | 13% |

The strongest real bundle (tray + 4 waters + molokhia + soup) is invisible, for two reasons. Drinks are excluded by rule, and the engine never looks at 3–4 item baskets. That's why the agent said "no ready combo".

### 2.3 Recipes are ignored
`dim_recipes` has 3,636 component rows covering 383 products, and nothing in `app/Ai` or `app/Domain/Menu` reads it. The recipe for صينية الملوك says:

- **It serves 4** (4× knife/fork/spoon, 4 pigeons, 10 bread).
- **It already contains** rice, 3 salads, pickles and bread. Adding a "salad" or "rice" side is redundant. Soup, molokhia and drinks are the complements.
- **It shares inputs** (stuffed vegetables, grape leaves, kofta) with other items, which affects kitchen load and stock.

That's exactly the "can these items be ordered together" signal you asked for, and it's already in the database.

### 2.4 Other unused signals
- **61 items are already `combo` role** (`بوكس شاورما مودز - 6 كيزر`, `فرايز مع شاورما لحمة`…). New combos are never checked against existing ones, so they can cannibalize them.
- **`customer_hash`** on 9,875 / 31,938 orders. That's enough for repeat-rate and "first item that brings customers back".
- **`wh_orders.type`**: talabat (6.7k) and delivery (7k) need different bundles from dine-in (10.9k), since packaging and price parity matter.
- **Trend Sep→Oct and seasonality** (November = winter: soups, molokhia, hot drinks). The question was about *next month*, but the analysis is a static snapshot.
- **Missing days are not normalised.** Saraya is missing Oct 27–31, and quantities are raw sums, so items that sell late in the month are penalised. Use per-open-day velocity.

### 2.5 No action, no measurement
The output has no "do this, at this price, on this channel, these days, expect X, check on date Y". Nothing is saved, so in December nobody can ask "did the November plan work?".

## 3. Do we need RAG / a vector DB?

**Mostly no.** Your knowledge is structured (orders, recipes, costs) and is answered by SQL and algorithms, not by retrieving text. A vector DB would not have fixed any item in §2.

Where embeddings *do* help (small scale, no new infrastructure):

| Need | Approach |
|---|---|
| Fuzzy item names ("صينيه/صينية الملوك", "ملوك", "الصينية الكبيرة") | Normalised Arabic name + an alias table first; embeddings as a fallback. About 800 products, so brute-force cosine in PHP over a MySQL JSON column is fine. |
| Owner knowledge ("no discounts on trays", "kitchen max 8 trays/hour", "Fridays are family day") | An `ai_business_facts` table, all injected into context (it's small). Embeddings only if it grows past a few hundred facts. |
| Memory of past recommendations and outcomes | A structured `ai_playbooks` table (see §5.4), queried by item and month. No vectors. |

What you actually need instead of RAG is a **semantic layer**: an *Item Card* per product (§5.1) that the agent and engines share. That's the "understanding of the platform data" the agent lacks.

## 4. Is the stack right for the chat?

**Backend: yes.** Keep Laravel + `laravel/ai`. Tenancy, permissions, `MetricsQuery` and the aggregates are already there, and rebuilding them in a Python/LangGraph service would duplicate the security model for no gain. `laravel/ai` already ships what's needed:
- `Streaming/Protocols/VercelDataProtocol` and `Vercel\Chat`: native Vercel AI SDK streaming.
- `broadcastOnQueue()`: queued runs streamed over websockets.
- `Approvals`: human-in-the-loop "approve this offer" steps.

**Front end: no.** The current page (`app/Filament/App/Pages/Assistant.php`, 797 lines + a 620-line Blade view) streams by having a queued job write rows to `ai_run_events`, which Livewire re-reads with `wire:poll.1s`. Each poll is a full server round-trip and re-render, with markdown rendered server-side. Text arrives in jumps, and the view can only show markdown. It cannot do combo cards, sortable tables, charts, "apply this offer" buttons or editable plans.

**Recommendation:** a dedicated **React chat page** in the Inertia + React stack you already have (`resources/js/pages`, React 19, Radix, Tailwind 4):
- **Transport:** Laravel Reverb (websockets) + `broadcastOnQueue`. Runs stay queued and resumable (keep the current watchdog/RunLog semantics), and tokens arrive instantly. Direct SSE is fine for short runs, but analysis runs take 30–120 s, so queue + websocket is the robust choice.
- **Client:** Vercel AI SDK `useChat` (it speaks the protocol `laravel/ai` emits) or `assistant-ui` components on top of it.
- **Generative UI:** tools return typed payloads (`focus_plan`, `bundle_card`, `item_table`, `chart`). The client renders each as a React component, and the LLM writes only the short narrative around them. This also fixes most grounding problems, because numbers come from the payload and the model doesn't retype them.
- Keep Filament for the admin and report pages. Link from report widgets into the chat ("ask about this chart").

The alternatives were weaker. A pure Python agent service duplicates tenancy and the metrics code, and it's another deploy. A Livewire 3 `wire:stream` upgrade fixes token smoothness but not rich components. An OpenAI Assistants/hosted thread service gives less control over tenancy, cost and Arabic output.

## 5. Target architecture

> The LLM is the **planner and explainer**. Deterministic engines are the **analyst**. The output is a **plan you can act on and measure**.

### 5.1 Item knowledge layer (`ItemCard`)
A materialised profile per item key, rebuilt nightly and on recipe or product ingest:

- identity: canonical name, aliases, category, role (existing `item_roles`)
- **serves_n**: from recipe utensils/bread counts, with owner override
- **price_tier**: single / sharing / family, from serves_n and price
- **components**: from `dim_recipes`, mapped to component classes (protein, carb, salad, bread, soup, drink, packaging)
- **contains_classes**: what the item already includes, so we never pair rice with a tray that has rice
- **kitchen station / shared inputs**: from components, used for capacity and stock risk
- behaviour: velocity per open day, trend (MoM, 8-week slope), daypart and weekday profile, channel mix, attach rate, repeat-customer share, return/waste rate
- existing combos that contain it

The Item Card is what the agent "knows" about an item. One tool, `get_item_card`, replaces several narrow calls.

### 5.2 Analytics engines (deterministic, unit-tested)
1. **Segmented menu engineering**: quadrants per `role × price_tier` (and optionally channel), using contribution share as well as unit margin.
2. **Next-month opportunity score**, the core of "what should I push in November":
   `score = margin_per_unit × headroom × momentum × season_fit × capacity_ok`
   - headroom: demand gap vs segment peers (low popularity, good margin)
   - momentum: Sep→Oct velocity trend, missing-day normalised
   - season_fit: category seasonality from prior years, or rules (winter → soups/molokhia/hot drinks) until there's history
   - capacity_ok: kitchen station load at the target dayparts

   It returns the score breakdown, so the agent can explain *why*.
3. **Basket mining v2**: itemsets of size 2–4 (FP-growth or Apriori over order lines), segmented by price tier/party size, channel and daypart. Drinks are kept as **attach items** (allowed in bundles, never as the "hero").
4. **Bundle constructor**: templates instead of "main + side":
   - *Solo meal*: main + side + drink
   - *Family feast*: tray + serving-matched drinks (serves_n) + soup/molokhia
   - *Delivery box*: packaging-aware, priced for talabat commission
   - *Upgrade*: "+X EGP to make it a feast"

   Constraints come from recipes (no duplicate component classes, kitchen station within capacity), margin floor, overlap with existing combos (cannibalisation check), and price endings.
5. **Offer simulator**: given a bundle/discount/channel/timeslot, it returns the expected units, revenue and margin delta. It uses observed attach rates and a simple elasticity prior, with explicit low/base/high scenarios and listed assumptions. Because the tool computes the projection, the assistant can state it without breaking grounding.

### 5.3 Agent layer
- **Fewer, higher-level tools:** `plan_month_focus`, `design_bundles`, `simulate_offer`, `get_item_card`, `explain_metric`, `save_playbook`. Keep `query_metrics` for ad-hoc facts.
- **Collapse the specialist hop for menu/marketing questions.** Today: coordinator → specialist → tools → specialist summary → coordinator re-summary. Each hop loses information and adds rules. One planner over the domain tools with structured outputs is simpler and more reliable.
- **Rewrite the prompts around reasoning, not prohibition.** Keep "never invent a number" and drop "never compute or recommend". The engines compute; the model chooses, prioritises and explains trade-offs.
- **Structured final output:** a `Recommendation` schema rendered by the UI:
  `action, items, channel, days/hours, price, why (evidence refs), expected impact (low/base/high), KPI to watch, review date, risks`.
- **Business facts in context:** owner rules and constraints (`ai_business_facts`), editable from the chat ("remember: we don't discount trays").

### 5.4 Closing the loop: playbooks
`ai_playbooks` stores the accepted plan with a baseline (from the engines) and target KPIs. A scheduled job scores it weekly against actuals. Then:
- the assistant can answer "how is the November plan doing?" with real numbers
- December's plan learns from what worked (per-org elasticity and attach-rate priors)

### 5.5 Data-quality gate
- Normalise by open days, and flag items whose ranking depends on missing days.
- A severe completeness gap (Saraya, Oct 27–31) triggers "ranking stable / unstable": the engine re-ranks with and without the affected branch-days and reports whether the top 5 changes. That replaces the vague two-line warning.

### 5.6 Quality bar (evals)
Extend `tests/Evals/Assistant` with decision-quality cases, judged against a rubric (actionable? segmented? recipe-consistent? measurable?) as well as numeric grounding:
- "top 5 for next month": must not all be family trays; must segment
- "combo for صينية الملوك": must include serving-matched drinks and molokhia or soup; must not add rice or salad
- "talabat offer": must account for the channel

## 6. What the same question should produce (illustrative)

> **خطة نوفمبر: 5 حاجات تعملها**
>
> **1. عرض «سفرة الملوك» للعيلة (جمعة وسبت، صالة + توصيل)**
> صينية الملوك بتكفي 4، و75% من طلباتها بييجي معاها مياه و26% طشة ملوخية. البوكس: الصينية + 4 مياه + طشة ملوخية + شوربة لسان عصفور بسعر [من المحاكي]. الهامش [x%] وده فوق الحد بتاعك. المتوقع [low–high] بوكس في الشهر. هنتابع: عدد الصواني في الجمعة والسبت مقارنة بأكتوبر. مراجعة: 15 نوفمبر.
>
> **2. أقوى صنف فردي ...** (من نفس فئة السعر، مش مقارن بالصواني)
>
> **3. شتوي: ...** (soups/molokhia momentum)
>
> **4. طلبات: ...** (channel-specific box)
>
> **5. متلمسش: شاورما لحمه كيزر** — بتتباع لوحدها، أي خصم عليها خسارة مباشرة.
>
> [زرار: احفظ الخطة وتابعها] [زرار: عدّل السعر] [زرار: ورّيني البدائل]

Bracketed values come from the simulator; the only real figures above are the co-order shares from §2.2.

## 7. Roadmap

| Phase | Scope | Outcome |
|---|---|---|
| **0 — Quick wins (1 week)** | Segment menu engineering by price tier; allow drinks as attach items; read recipes for `contains_classes` and serves_n; per-open-day normalisation; trim coordinator rules | Next answer to the same question is already much better |
| **1 — Item Cards + bundle constructor (2–3 weeks)** | `ItemCard` table + builder; basket itemsets 2–4 with segmentation; bundle templates with recipe/capacity/cannibalisation checks | Strong, explainable combos |
| **2 — Opportunity score + simulator (2 weeks)** | Next-month score with breakdown; offer simulator with scenarios | "What to do" with expected impact, grounded |
| **3 — New chat UI (2–3 weeks, can run parallel to 1–2)** | React/Inertia page, Reverb + `broadcastOnQueue`, Vercel AI SDK, generative-UI components (focus plan, bundle card, item table, chart, approve buttons) | Fast, rich, interactive chat |
| **4 — Playbooks + memory (1–2 weeks)** | `ai_playbooks`, weekly scoring job, `ai_business_facts`, alias/name resolution | Plans are tracked and the assistant learns per restaurant |
| **5 — Evals as a gate** | Decision-quality rubric cases in CI | Prevents regressions back to "report reader" |

Phases 0 → 1 → 2 are sequential (each feeds the next). Phase 3 is independent and can be built in parallel by someone else.
