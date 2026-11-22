/**
 * Types for AI Assistant Menu Intelligence & Metric Tool envelopes.
 * Derived from App\Ai\Tools\Concerns\FormatsToolResult, ReadsPreparedMenu,
 * RankItems, GetItemCard, GetAffinities, and GetKpiSeries.
 */

export interface ToolResultEnvelope<TMetrics = unknown> {
  summary?: string;
  period?: {
    from: string;
    to: string;
    preset?: string;
  };
  comparison?: {
    from: string;
    to: string;
    mode?: string;
  } | null;
  branches?: Record<string | number, string>;
  currency?: string;
  metrics?: TMetrics;
  completeness?: {
    summary?: {
      complete_branches: number;
      total_branches: number;
    } | null;
    warnings?: string[];
  };
  definitions?: Record<string, string>;
  notes?: string[];
  truncated?: boolean;
  status?: string;
  reason?: string;
}

export interface ToolErrorPayload {
  error: string;
  message_ar?: string;
  truncated?: boolean;
  errors?: Record<string, string[]>;
}

export interface ToolRawPayload {
  raw: string;
}

export interface ToolPartStatus {
  type: "running" | "complete" | "incomplete" | "requires-action" | string;
  reason?: string;
}

export interface ToolPartProps<TArgs = Record<string, unknown>, TResult = unknown> {
  args?: TArgs;
  argsText?: string;
  result?: TResult;
  status?: ToolPartStatus;
  toolName?: string;
  toolCallId?: string;
  isError?: boolean;
}

// ----------------------------------------------------
// 1. rank_items
// ----------------------------------------------------

export interface RankedItem {
  rank: number;
  item_key: string;
  name: string;
  quadrant: string;
  score: number;
  qty: number;
  net_sales: number;
  unit_margin: number | null;
  margin_pct: number | null;
  factors?: Record<string, number>;
  score_by_period?: Record<string, number>;
}

export interface RankItemsSegment {
  label: string;
  items: RankedItem[];
}

export interface RankItemsMetrics {
  status: "ok" | "insufficient_data" | string;
  strategy?: string;
  channel?: string;
  segments?: Record<string, RankItemsSegment>;
  periods?: Record<string, { from: string; to: string }>;
  prepared_at?: string | null;
  reason?: string;
}

export type RankItemsResult = ToolResultEnvelope<RankItemsMetrics>;

// ----------------------------------------------------
// 2. get_item_card
// ----------------------------------------------------

export interface ItemCardProfile {
  item_key: string;
  name: string;
  role: string;
  serves_n: number;
  price_tier: string;
  season: string;
  contains: string[];
  main_protein: string | null;
  list_price: number | null;
  unit_cost: number | null;
  has_recipe: boolean;
  is_existing_combo: boolean;
  combo_members: string[] | null;
}

export interface ItemCardPeriodStat {
  qty: number;
  net_sales: number;
  gross_profit: number;
  unit_margin: number | null;
  margin_pct: number | null;
  orders: number;
  days_sold: number;
  open_days: number;
  daily_velocity: number;
  attach_rate: number;
  repeat_customer_share: number;
  velocity_change_vs_previous: number | null;
  channel_mix: Record<string, number>;
  daypart: Record<string, number>;
  weekday: Record<string, number>;
}

export interface ItemCardPushNextMonth {
  rank: number;
  of: number;
  segment: string;
  segment_label: string;
  quadrant: string;
  score: number;
}

export interface ItemCardBoughtWithItemset {
  items: Array<{ item_key: string; name: string }>;
  together_orders: number;
  support: number;
  lift: number;
  avg_order_value: number;
  orders_with_each: Array<{ item_key: string; orders: number }>;
}

export interface ItemCardCombo {
  status: string;
  template: string;
  items: Array<{ name: string; role: string; qty: number }>;
  list_total: number;
  suggested_price: number;
  margin_pct: number;
  discount_pct: number;
  checks: { floor: number | null };
}

export interface ItemCardMetrics {
  status: "ok" | "insufficient_data" | "not_found" | "ambiguous" | string;
  reason?: string;
  matches?: Array<{ item_key: string; name: string }>;
  item?: ItemCardProfile;
  stats?: Record<string, ItemCardPeriodStat | null>;
  push_next_month?: Record<string, ItemCardPushNextMonth | null>;
  bought_with?: {
    period: string;
    itemsets: ItemCardBoughtWithItemset[];
  };
  combos?: ItemCardCombo[];
  periods?: Record<string, { from: string; to: string }>;
  prepared_at?: string | null;
}

export type ItemCardResult = ToolResultEnvelope<ItemCardMetrics>;

// ----------------------------------------------------
// 3. get_affinities
// ----------------------------------------------------

export interface AffinityItemset {
  items: Array<{ item_key: string; name: string }>;
  together_orders: number;
  support: number;
  lift: number;
  avg_order_value: number;
  orders_with_each: Array<{ item_key: string; orders: number }>;
}

export interface AffinitiesMetrics {
  status: "ok" | "insufficient_data" | "not_found" | "ambiguous" | string;
  reason?: string;
  item?: { item_key: string; name: string };
  affinity_period?: string;
  itemsets?: AffinityItemset[];
  periods?: Record<string, { from: string; to: string }>;
  prepared_at?: string | null;
}

export type AffinitiesResult = ToolResultEnvelope<AffinitiesMetrics>;

// ----------------------------------------------------
// 4. get_kpi_series
// ----------------------------------------------------

export interface KpiSeriesMetrics {
  granularity?: "day" | "week" | "month" | string;
  per_branch?: boolean;
  series?:
    | Record<string, Record<string, number | null>>
    | Record<string, Record<string, Record<string, number | null>>>;
  status?: string;
  reason?: string;
}

export type KpiSeriesResult = ToolResultEnvelope<KpiSeriesMetrics>;

// ----------------------------------------------------
// 5. get_combo_candidates
// ----------------------------------------------------

export interface ComboItem {
  key?: string;
  item_key?: string;
  name: string;
  role: string;
  qty: number;
  list_price?: number;
}

export interface ComboEvidence {
  type?: "observed" | "rule" | string;
  itemset?: string[];
  together_orders?: number;
  support?: number;
  lift?: number;
  why?: string | null;
}

export interface ComboChecks {
  redundant_rejected?: boolean;
  unknown_recipe?: string[];
  drinks_match_serves?: boolean;
  floor?: number | null;
}

export interface ComboCandidateItem {
  signature?: string;
  status: "approved" | "rejected" | "candidate" | string;
  period?: string;
  template: string;
  template_label?: string;
  hero?: { item_key: string; name: string };
  items: ComboItem[];
  list_total: number;
  cost_total?: number;
  suggested_price: number;
  margin_pct: number;
  discount_pct: number;
  evidence?: ComboEvidence;
  checks?: ComboChecks;
  overlaps_existing_combo?: { item_key: string; name: string } | null;
  owner_note?: string | null;
}

export interface ComboCandidatesMetrics {
  status: "ok" | "insufficient_data" | string;
  reason?: string;
  combos?: ComboCandidateItem[];
  periods?: Record<string, { from: string; to: string }>;
  prepared_at?: string | null;
}

export type ComboCandidatesResult = ToolResultEnvelope<ComboCandidatesMetrics>;

