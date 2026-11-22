import { describe, it, expect } from "vitest";
import { renderToString } from "react-dom/server";
import { RankItemsCard } from "./rank-items";
import { ItemCard } from "./item-card";
import { AffinitiesCard } from "./affinities";
import { KpiSeriesCard } from "./kpi-series";
import { ComboCandidatesCard } from "./combo-candidates";
import { FallbackCard, RunningSkeleton } from "./fallback";

describe("Tool UIs Generative Cards", () => {
  it("rank-items renders one card per segment with rows in rank order", () => {
    const payload = {
      summary: "رتبت 3 صنف في 2 مجموعة أصناف متشابهة.",
      period: { from: "2026-09-01", to: "2026-10-31" },
      currency: "EGP",
      metrics: {
        status: "ok",
        strategy: "push_next_month",
        channel: "all",
        segments: {
          "main:single": {
            label: "طبق رئيسي - فردي",
            items: [
              {
                rank: 1,
                item_key: "SHAWARMA",
                name: "وجبة شاورما",
                quadrant: "star",
                score: 80.0,
                qty: 200,
                net_sales: 16000,
                unit_margin: 50,
                margin_pct: 0.625,
                factors: { unit_margin: 0.9, popularity: 0.8 },
              },
              {
                rank: 2,
                item_key: "KOFTA",
                name: "سندوتش كفتة",
                quadrant: "plowhorse",
                score: 40.0,
                qty: 300,
                net_sales: 15000,
                unit_margin: 30,
                margin_pct: 0.4,
              },
            ],
          },
          "main:family": {
            label: "طبق رئيسي - عائلي",
            items: [
              {
                rank: 1,
                item_key: "TRAY",
                name: "صينية فراخ عائلي",
                quadrant: "star",
                score: 60.0,
                qty: 20,
                net_sales: 8000,
                unit_margin: 200,
                margin_pct: 0.5,
              },
            ],
          },
        },
      },
    };

    const html = renderToString(<RankItemsCard result={payload} />);

    // One card per segment: both segment titles are rendered
    expect(html).toContain("طبق رئيسي - فردي");
    expect(html).toContain("طبق رئيسي - عائلي");

    // Items rendered with details
    expect(html).toContain("وجبة شاورما");
    expect(html).toContain("سندوتش كفتة");
    expect(html).toContain("صينية فراخ عائلي");

    // Net sales formatted with currency
    expect(html).toContain("16,000 ج.م");
    expect(html).toContain("15,000 ج.م");

    // Margins formatted as percentages
    expect(html).toContain("62.5%");
    expect(html).toContain("40.0%");

    // Rows appear in rank order within main:single
    const shawarmaPos = html.indexOf("وجبة شاورما");
    const koftaPos = html.indexOf("سندوتش كفتة");
    expect(shawarmaPos).toBeGreaterThan(-1);
    expect(koftaPos).toBeGreaterThan(-1);
    expect(shawarmaPos).toBeLessThan(koftaPos);
  });

  it("item-card shows contains labels and serves", () => {
    const payload = {
      summary: "جهزت كارت صينية فراخ عائلي.",
      metrics: {
        status: "ok",
        item: {
          item_key: "TRAY",
          name: "صينية فراخ عائلي",
          role: "main",
          serves_n: 4,
          price_tier: "family",
          season: "all",
          contains: ["فراخ", "رز"],
          main_protein: null,
          list_price: 350.0,
          unit_cost: 150.0,
          has_recipe: true,
          is_existing_combo: false,
          combo_members: null,
        },
        stats: {
          "2026-10": {
            qty: 100,
            net_sales: 8000,
            gross_profit: 5000,
            unit_margin: 50,
            margin_pct: 0.625,
            orders: 90,
            days_sold: 30,
            open_days: 30,
            daily_velocity: 3.33,
            attach_rate: 0.2,
            repeat_customer_share: 0.4,
            velocity_change_vs_previous: null,
            channel_mix: { dine_in: 0.6, delivery: 0.4 },
            daypart: {},
            weekday: {},
          },
        },
        push_next_month: {
          "2026-10": {
            rank: 1,
            of: 2,
            segment: "main:family",
            segment_label: "طبق رئيسي - عائلي",
            quadrant: "star",
            score: 0.8,
          },
        },
      },
    };

    const html = renderToString(<ItemCard result={payload} />);

    // Name and key
    expect(html).toContain("صينية فراخ عائلي");
    expect(html).toContain("TRAY");

    // Serves count
    expect(html).toContain("4");
    expect(html).toContain("أفراد");

    // Price tier and prices
    expect(html).toContain("عائلي");
    expect(html).toContain("350 ج.م");
    expect(html).toContain("150 ج.م");

    // Contains labels
    expect(html).toContain("فراخ");
    expect(html).toContain("رز");

    // Rank and segment
    expect(html).toContain("المركز 1 من 2");
    expect(html).toContain("طبق رئيسي - عائلي");

    // Channel mix channels
    expect(html).toContain("صالة");
    expect(html).toContain("دليفري");
  });

  it("kpi-series renders a chart for a two-bucket series", () => {
    const payload = {
      currency: "EGP",
      metrics: {
        granularity: "day",
        per_branch: false,
        series: {
          "2026-10-03": { K01: 8, K06: 1240.0 },
          "2026-10-04": { K01: 10, K06: 1500.0 },
        },
      },
      definitions: {
        K01: "عدد الطلبات / Orders",
        K06: "المبيعات / Sales (headline)",
      },
    };

    const html = renderToString(<KpiSeriesCard result={payload} />);

    // KPI labels from payload definitions
    expect(html).toContain("عدد الطلبات");
    expect(html).toContain("المبيعات");

    // Buckets present
    expect(html).toContain("2026-10-03");
    expect(html).toContain("2026-10-04");

    // Data values present
    expect(html).toContain("1,240");
    expect(html).toContain("1,500");

    // Chart container is rendered
    expect(html).toContain("kpi-chart-container");
  });

  it("get_affinities renders itemsets with together_orders and formatted lift", () => {
    const payload = {
      metrics: {
        status: "ok",
        item: { item_key: "SHAWARMA", name: "وجبة شاورما" },
        affinity_period: "2026-10",
        itemsets: [
          {
            items: [
              { item_key: "RICE", name: "أرز بسمتي" },
              { item_key: "SHAWARMA", name: "وجبة شاورما" },
            ],
            together_orders: 40,
            support: 0.15,
            lift: 2.5,
            avg_order_value: 150.0,
            orders_with_each: [],
          },
        ],
      },
    };

    const html = renderToString(<AffinitiesCard result={payload} />);

    expect(html).toContain("أرز بسمتي + وجبة شاورما");
    expect(html).toContain("40 طلب مع بعض");
    expect(html).toContain("2.5x");
  });

  it("renders fallback for unexpected payload", () => {
    // 1. output = {"raw":"x"}
    const rawHtml = renderToString(<RankItemsCard result={{ raw: "x" }} />);
    expect(rawHtml).toContain("مقدرتش أعرض التفاصيل دي");

    // 2. output = {status:'insufficient_data', reason:'…'}
    const insufficientHtml = renderToString(
      <RankItemsCard
        result={{
          status: "insufficient_data",
          reason: "البيانات لسه بتتجهز للفترة دي، جرّب بعد شوية",
        }}
      />
    );
    expect(insufficientHtml).toContain("مقدرتش أعرض التفاصيل دي");
    expect(insufficientHtml).toContain("البيانات لسه بتتجهز للفترة دي، جرّب بعد شوية");

    // 3. Fallback on other tools
    const itemCardErrorHtml = renderToString(<ItemCard result={{ raw: "corrupted" }} />);
    expect(itemCardErrorHtml).toContain("مقدرتش أعرض التفاصيل دي");

    const kpiErrorHtml = renderToString(
      <KpiSeriesCard
        result={{
          metrics: {
            status: "insufficient_data",
            reason: "لا توجد بيانات للفترة المحددة",
          },
        }}
      />
    );
    expect(kpiErrorHtml).toContain("مقدرتش أعرض التفاصيل دي");
    expect(kpiErrorHtml).toContain("لا توجد بيانات للفترة المحددة");
  });

  it("renders running skeleton with Arabic tool label when status is running", () => {
    const rankRunningHtml = renderToString(
      <RankItemsCard status={{ type: "running" }} />
    );
    expect(rankRunningHtml).toContain("برتب الأصناف اللي تستاهل تركيز…");

    const itemCardRunningHtml = renderToString(
      <ItemCard status={{ type: "running" }} />
    );
    expect(itemCardRunningHtml).toContain("بجهز كارت الصنف…");

    const affinitiesRunningHtml = renderToString(
      <AffinitiesCard status={{ type: "running" }} />
    );
    expect(affinitiesRunningHtml).toContain("بشوف الأصناف اللي بتتطلب مع بعض…");

    const kpiRunningHtml = renderToString(
      <KpiSeriesCard status={{ type: "running" }} />
    );
    expect(kpiRunningHtml).toContain("بحلل الاتجاهات…");

    const comboRunningHtml = renderToString(
      <ComboCandidatesCard status={{ type: "running" }} />
    );
    expect(comboRunningHtml).toContain("بجيب العروض الجاهزة بهامش ربح آمن…");
  });

  it("get_combo_candidates renders items, list vs suggested price, margin, discount, evidence, overlap warning, and status badge", () => {
    const payload = {
      metrics: {
        status: "ok",
        combos: [
          {
            signature: "sig-abc-123",
            status: "approved",
            period: "2026-10",
            template: "family_feast",
            template_label: "وليمة عائلية",
            hero: { item_key: "TRAY", name: "صينية الملوك" },
            items: [
              { name: "صينية الملوك", role: "main", qty: 1, list_price: 400 },
              { name: "مياه", role: "drink", qty: 4, list_price: 10 },
              { name: "شوربة", role: "side", qty: 1, list_price: 50 },
            ],
            list_total: 490,
            cost_total: 200,
            suggested_price: 450,
            margin_pct: 0.556,
            discount_pct: 0.082,
            evidence: {
              type: "observed",
              together_orders: 37,
              lift: 2.5,
              why: "طلب متكرر",
            },
            checks: {},
            overlaps_existing_combo: {
              item_key: "OLD_COMBO",
              name: "كومبو العيلة القديم",
            },
            owner_note: "معتمد للشتاء",
          },
          {
            signature: "sig-rule-456",
            status: "candidate",
            template: "single_meal",
            items: [{ name: "شاورما", role: "main", qty: 1 }],
            list_total: 100,
            suggested_price: 90,
            margin_pct: 0.6,
            discount_pct: 0.1,
            evidence: { type: "rule" },
          },
        ],
      },
    };

    const html = renderToString(<ComboCandidatesCard result={payload} />);

    // Items formatted: single items and multiple with qty×
    expect(html).toContain("صينية الملوك + 4× مياه + شوربة");

    // Prices (original vs suggested in ج.م)
    expect(html).toContain("490 ج.م");
    expect(html).toContain("450 ج.م");

    // Margins and discounts
    expect(html).toContain("55.6%");
    expect(html).toContain("8.2%");

    // Evidence: observed vs rule
    expect(html).toContain("اتطلبوا مع بعض 37 مرة");
    expect(html).toContain("مقترح من المنيو");

    // Overlap warning
    expect(html).toContain("شبه كومبو موجود: كومبو العيلة القديم");

    // Status badges
    expect(html).toContain("معتمد");
    expect(html).toContain("مقترح");
  });

  it("get_combo_candidates hides approve / reject buttons without manageCombos and shows them with manageCombos", () => {
    const payload = {
      metrics: {
        status: "ok",
        combos: [
          {
            signature: "sig-test-btn",
            status: "candidate",
            template: "family_feast",
            items: [
              { name: "صينية فراخ", role: "main", qty: 1 },
              { name: "عصير", role: "drink", qty: 2 },
            ],
            list_total: 300,
            suggested_price: 260,
            margin_pct: 0.5,
            discount_pct: 0.133,
            evidence: { type: "rule" },
          },
        ],
      },
    };

    // 1. Without manageCombos: action buttons must be hidden
    const htmlWithoutManage = renderToString(
      <ComboCandidatesCard result={payload} canManage={false} />
    );
    expect(htmlWithoutManage).not.toContain("اعتمد");
    expect(htmlWithoutManage).not.toContain("ارفض");
    expect(htmlWithoutManage).not.toContain("رجوع لمقترح");

    // 2. With manageCombos: action buttons must be visible
    const htmlWithManage = renderToString(
      <ComboCandidatesCard result={payload} canManage={true} />
    );
    expect(htmlWithManage).toContain("اعتمد");
    expect(htmlWithManage).toContain("ارفض");
    expect(htmlWithManage).toContain("رجوع لمقترح");
  });
});
