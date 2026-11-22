import { useMemo } from "react";
import {
  ResponsiveContainer,
  LineChart,
  Line,
  XAxis,
  YAxis,
  Tooltip,
  Legend,
  CartesianGrid,
} from "recharts";
import { TrendingUpIcon } from "lucide-react";
import type {
  KpiSeriesResult,
  ToolPartProps,
} from "../types";
import {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
} from "./fallback";

const PALETTE = [
  "#2563eb", // blue
  "#16a34a", // emerald
  "#ea580c", // orange
  "#9333ea", // purple
  "#0891b2", // cyan
  "#e11d48", // rose
  "#d97706", // amber
];

export function KpiSeriesCard({ result, status }: ToolPartProps<Record<string, unknown>, unknown>) {
  if (status?.type === "running" || result === undefined) {
    return <RunningSkeleton toolName="get_kpi_series" />;
  }

  let payload: KpiSeriesResult;
  try {
    payload = typeof result === "string" ? JSON.parse(result) : (result as KpiSeriesResult);
  } catch {
    return <FallbackCard toolName="get_kpi_series" reason="تعذر قراءة بيانات المؤشرات." />;
  }

  if (
    !payload ||
    typeof payload !== "object" ||
    (payload as Record<string, unknown>).raw !== undefined ||
    (payload as Record<string, unknown>).error !== undefined ||
    payload.status === "insufficient_data" ||
    payload.metrics?.status === "insufficient_data"
  ) {
    return <FallbackCard toolName="get_kpi_series" payload={payload} />;
  }

  const rawSeries = payload.metrics?.series;
  if (!rawSeries || typeof rawSeries !== "object" || Object.keys(rawSeries).length === 0) {
    return (
      <FallbackCard
        toolName="get_kpi_series"
        payload={payload}
        reason="مفيش بيانات سلاسل زمنية متاحة للعرض."
      />
    );
  }

  const perBranch = Boolean(payload.metrics?.per_branch);
  const definitions = payload.definitions ?? {};
  const branches = payload.branches ?? {};

  // Clean KPI label from payload definitions (extract Arabic part before slash if present)
  const getKpiLabel = (code: string): string => {
    const raw = definitions[code];
    if (!raw) return code;
    const parts = raw.split("/");
    return parts[0].trim();
  };

  // Build chart data and series lines
  const { chartData, seriesConfig, kpiCodes } = useMemo(() => {
    if (!perBranch) {
      // series: Record<bucket, Record<kpiCode, value>>
      const seriesObj = rawSeries as Record<string, Record<string, number | null>>;
      const buckets = Object.keys(seriesObj).sort();
      const kpisSet = new Set<string>();

      buckets.forEach((bucket) => {
        const metricsMap = seriesObj[bucket];
        if (metricsMap && typeof metricsMap === "object") {
          Object.keys(metricsMap).forEach((k) => kpisSet.add(k));
        }
      });

      const kpis = Array.from(kpisSet);
      const data = buckets.map((bucket) => {
        const row: Record<string, string | number | null> = { bucket };
        kpis.forEach((kpi) => {
          row[kpi] = seriesObj[bucket]?.[kpi] ?? null;
        });
        return row;
      });

      const configs = kpis.map((kpi, idx) => ({
        key: kpi,
        label: getKpiLabel(kpi),
        color: PALETTE[idx % PALETTE.length],
      }));

      return { chartData: data, seriesConfig: configs, kpiCodes: kpis };
    } else {
      // per_branch === true
      // series: Record<branchId, Record<bucket, Record<kpiCode, value>>>
      const branchSeriesObj = rawSeries as Record<
        string,
        Record<string, Record<string, number | null>>
      >;
      const branchIds = Object.keys(branchSeriesObj);
      const bucketsSet = new Set<string>();
      const kpisSet = new Set<string>();

      branchIds.forEach((bId) => {
        const bucketsMap = branchSeriesObj[bId];
        if (bucketsMap && typeof bucketsMap === "object") {
          Object.keys(bucketsMap).forEach((bucket) => {
            bucketsSet.add(bucket);
            const kpiMap = bucketsMap[bucket];
            if (kpiMap && typeof kpiMap === "object") {
              Object.keys(kpiMap).forEach((k) => kpisSet.add(k));
            }
          });
        }
      });

      const buckets = Array.from(bucketsSet).sort();
      const kpis = Array.from(kpisSet);
      const isSingleKpi = kpis.length <= 1;

      const data = buckets.map((bucket) => {
        const row: Record<string, string | number | null> = { bucket };
        branchIds.forEach((bId) => {
          kpis.forEach((kpi) => {
            const seriesKey = isSingleKpi ? bId : `${bId}_${kpi}`;
            row[seriesKey] = branchSeriesObj[bId]?.[bucket]?.[kpi] ?? null;
          });
        });
        return row;
      });

      const configs: Array<{ key: string; label: string; color: string }> = [];
      let colorIdx = 0;

      branchIds.forEach((bId) => {
        const branchName = branches[bId] ?? `فرع ${bId}`;
        if (isSingleKpi) {
          configs.push({
            key: bId,
            label: branchName,
            color: PALETTE[colorIdx % PALETTE.length],
          });
          colorIdx++;
        } else {
          kpis.forEach((kpi) => {
            configs.push({
              key: `${bId}_${kpi}`,
              label: `${branchName} - ${getKpiLabel(kpi)}`,
              color: PALETTE[colorIdx % PALETTE.length],
            });
            colorIdx++;
          });
        }
      });

      return { chartData: data, seriesConfig: configs, kpiCodes: kpis };
    }
  }, [rawSeries, perBranch, definitions, branches]);

  const headlineKpi = kpiCodes.length === 1 ? getKpiLabel(kpiCodes[0]) : "المؤشرات الرئيسية";

  return (
    <ToolErrorBoundary toolName="get_kpi_series">
      <div
        dir="rtl"
        className="w-full rounded-xl border border-border/60 bg-card p-4 shadow-xs space-y-3.5 text-foreground"
      >
        {/* Header */}
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/40 pb-2.5">
          <div className="flex items-center gap-2">
            <TrendingUpIcon className="size-4 text-primary shrink-0" />
            <h4 className="text-sm font-bold text-foreground">
              {headlineKpi} {perBranch ? "(مقسمة حسب الفروع)" : ""}
            </h4>
          </div>
          {payload.currency && (
            <span className="text-xs font-medium text-muted-foreground bg-muted/40 px-2 py-0.5 rounded">
              العملة: {payload.currency === "EGP" ? "ج.م" : payload.currency}
            </span>
          )}
        </div>

        {/* Chart */}
        <div className="h-64 w-full pt-2" data-testid="kpi-chart-container">
          <ResponsiveContainer
            width="100%"
            height={240}
            initialDimension={{ width: 480, height: 240 }}
          >
            <LineChart
              data={chartData}
              margin={{ top: 10, right: 10, left: 10, bottom: 5 }}
            >
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted/50" />
              <XAxis
                dataKey="bucket"
                tick={{ fontSize: 11 }}
                className="text-muted-foreground text-[10px]"
              />
              <YAxis
                tick={{ fontSize: 11 }}
                className="text-muted-foreground text-[10px]"
                width={45}
              />
              <Tooltip
                formatter={(val, name) => [
                  typeof val === "number" ? val.toLocaleString("en-US") : val,
                  name,
                ]}
                labelStyle={{ direction: "rtl", textAlign: "start" }}
              />
              <Legend
                wrapperStyle={{ direction: "rtl", fontSize: "11px", paddingTop: "8px" }}
              />
              {seriesConfig.map((s) => (
                <Line
                  key={s.key}
                  type="monotone"
                  dataKey={s.key}
                  name={s.label}
                  stroke={s.color}
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
              ))}
            </LineChart>
          </ResponsiveContainer>
        </div>

        {/* Summary data table for accessibility and instant readability */}
        <div className="overflow-x-auto rounded-lg border border-border/40 text-xs">
          <table className="w-full text-start">
            <thead className="bg-muted/30 border-b border-border/40">
              <tr>
                <th className="p-2 text-start font-medium text-muted-foreground">الفترة</th>
                {seriesConfig.map((s) => (
                  <th key={s.key} className="p-2 text-start font-medium text-muted-foreground">
                    {s.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-border/30">
              {chartData.map((row) => (
                <tr key={String(row.bucket)} className="hover:bg-muted/20">
                  <td className="p-2 font-mono text-muted-foreground">{String(row.bucket)}</td>
                  {seriesConfig.map((s) => {
                    const val = row[s.key];
                    return (
                      <td key={s.key} className="p-2 font-mono font-medium text-foreground">
                        {typeof val === "number"
                          ? val.toLocaleString("en-US")
                          : (val ?? "-")}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </ToolErrorBoundary>
  );
}
