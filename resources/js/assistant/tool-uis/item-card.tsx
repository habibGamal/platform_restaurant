import { useMemo } from "react";
import {
  ResponsiveContainer,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
} from "recharts";
import { UsersIcon, TagIcon, CalendarIcon, UtensilsIcon } from "lucide-react";
import type {
  ItemCardResult,
  ItemCardPushNextMonth,
  ToolPartProps,
} from "../types";
import {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
} from "./fallback";

const TIER_LABELS: Record<string, string> = {
  single: "فردي",
  sharing: "مشاركة",
  family: "عائلي",
};

const SEASON_LABELS: Record<string, string> = {
  all: "طوال العام",
  summer: "صيفي",
  winter: "شتوي",
  ramadan: "رمضان",
};

const CHANNEL_LABELS: Record<string, string> = {
  all: "الكل",
  dine_in: "صالة",
  takeaway: "تيك أواي",
  delivery: "دليفري",
  talabat: "طلبات",
  companies: "شركات",
  web_delivery: "دليفري ويب",
  web_takeaway: "تيك أواي ويب",
  pos: "كاشير",
};

export function ItemCard({ result, status }: ToolPartProps<Record<string, unknown>, unknown>) {
  if (status?.type === "running" || result === undefined) {
    return <RunningSkeleton toolName="get_item_card" />;
  }

  let payload: ItemCardResult;
  try {
    payload = typeof result === "string" ? JSON.parse(result) : (result as ItemCardResult);
  } catch {
    return <FallbackCard toolName="get_item_card" reason="تعذر قراءة بيانات كارت الصنف." />;
  }

  if (
    !payload ||
    typeof payload !== "object" ||
    (payload as Record<string, unknown>).raw !== undefined ||
    (payload as Record<string, unknown>).error !== undefined ||
    payload.status === "insufficient_data" ||
    payload.metrics?.status === "insufficient_data" ||
    payload.metrics?.status === "not_found" ||
    payload.metrics?.status === "ambiguous"
  ) {
    return <FallbackCard toolName="get_item_card" payload={payload} />;
  }

  const item = payload.metrics?.item;
  if (!item) {
    return (
      <FallbackCard
        toolName="get_item_card"
        payload={payload}
        reason="بيانات كارت الصنف غير متوفرة."
      />
    );
  }

  // Push next month ranking info (latest period or first available)
  const pushRanks = payload.metrics?.push_next_month;
  const latestRankInfo: ItemCardPushNextMonth | null = useMemo(() => {
    if (!pushRanks) return null;
    const periods = Object.keys(pushRanks).sort();
    if (periods.length === 0) return null;
    return pushRanks[periods[periods.length - 1]] ?? null;
  }, [pushRanks]);

  // Channel mix stats from latest period
  const stats = payload.metrics?.stats;
  const channelMixData = useMemo(() => {
    if (!stats) return [];
    const periods = Object.keys(stats).sort();
    if (periods.length === 0) return [];
    const latestStat = stats[periods[periods.length - 1]];
    if (!latestStat?.channel_mix) return [];

    return Object.entries(latestStat.channel_mix).map(([channel, share]) => ({
      channel: CHANNEL_LABELS[channel] ?? channel,
      share: Math.round(Number(share) * 100),
    }));
  }, [stats]);

  const tierLabel = TIER_LABELS[item.price_tier] ?? item.price_tier;
  const seasonLabel = SEASON_LABELS[item.season] ?? item.season;

  return (
    <ToolErrorBoundary toolName="get_item_card">
      <div
        dir="rtl"
        className="w-full rounded-xl border border-border/60 bg-card p-4 shadow-xs space-y-4 text-foreground"
      >
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-3">
          <div>
            <h3 className="text-base font-bold text-foreground">{item.name}</h3>
            <span className="font-mono text-xs text-muted-foreground">{item.item_key}</span>
          </div>
          {latestRankInfo && (
            <div className="rounded-lg bg-primary/10 border border-primary/20 px-2.5 py-1 text-xs font-semibold text-primary">
              {`المركز ${latestRankInfo.rank} من ${latestRankInfo.of} في ${latestRankInfo.segment_label || latestRankInfo.segment}`}
            </div>
          )}
        </div>

        {/* Specs Grid */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
          <div className="rounded-lg bg-muted/30 p-2.5 space-y-1">
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <UsersIcon className="size-3.5" />
              <span>تكفي</span>
            </div>
            <div className="font-semibold text-sm">
              {`${item.serves_n} ${item.serves_n === 1 ? "فرد" : "أفراد"}`}
            </div>
          </div>

          <div className="rounded-lg bg-muted/30 p-2.5 space-y-1">
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <TagIcon className="size-3.5" />
              <span>الفئة السعرية</span>
            </div>
            <div className="font-semibold text-sm">{tierLabel}</div>
          </div>

          <div className="rounded-lg bg-muted/30 p-2.5 space-y-1">
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <CalendarIcon className="size-3.5" />
              <span>الموسم</span>
            </div>
            <div className="font-semibold text-sm">{seasonLabel}</div>
          </div>

          <div className="rounded-lg bg-muted/30 p-2.5 space-y-1">
            <div className="text-muted-foreground">السعر والتكلفة</div>
            <div className="font-semibold text-sm">
              {item.list_price !== null ? `${item.list_price} ج.م` : "-"}
              <span className="text-[11px] font-normal text-muted-foreground ms-1">
                {`(تكلفة: ${item.unit_cost !== null ? `${item.unit_cost} ج.م` : "-"})`}
              </span>
            </div>
          </div>
        </div>

        {/* Contains labels */}
        {item.contains && item.contains.length > 0 && (
          <div className="space-y-1.5">
            <div className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
              <UtensilsIcon className="size-3.5" />
              <span>المكونات الأساسية:</span>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {item.contains.map((ingredient) => (
                <span
                  key={ingredient}
                  className="inline-flex items-center rounded-md border border-border/60 bg-muted/40 px-2 py-0.5 text-xs text-foreground font-medium"
                >
                  {ingredient}
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Channel Mix Mini Bar */}
        {channelMixData.length > 0 && (
          <div className="space-y-1.5 pt-2 border-t border-border/40">
            <div className="text-xs font-medium text-muted-foreground">
              توزيع قنوات البيع (Channel Mix):
            </div>
            <div className="h-24 w-full">
              <ResponsiveContainer width="100%" height={90} initialDimension={{ width: 360, height: 90 }}>
                <BarChart
                  data={channelMixData}
                  layout="vertical"
                  margin={{ top: 5, right: 10, left: 10, bottom: 5 }}
                >
                  <XAxis type="number" domain={[0, 100]} unit="%" tick={{ fontSize: 10 }} />
                  <YAxis type="category" dataKey="channel" tick={{ fontSize: 11 }} width={70} />
                  <Tooltip
                    formatter={(val) => [`${val}%`, "النسبة"]}
                    labelStyle={{ direction: "rtl", textAlign: "start" }}
                  />
                  <Bar dataKey="share" fill="#2563eb" radius={[0, 4, 4, 0]} barSize={14} />
                </BarChart>
              </ResponsiveContainer>
            </div>
            {/* Accessible fallback summary */}
            <div className="flex flex-wrap gap-3 text-[11px] text-muted-foreground pt-1">
              {channelMixData.map((d) => (
                <span key={d.channel}>
                  {d.channel}: <strong className="text-foreground">{d.share}%</strong>
                </span>
              ))}
            </div>
          </div>
        )}
      </div>
    </ToolErrorBoundary>
  );
}
