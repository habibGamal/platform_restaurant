import { useState } from "react";
import { usePage } from "@inertiajs/react";
import {
  GiftIcon,
  CheckIcon,
  XIcon,
  RotateCcwIcon,
  AlertTriangleIcon,
  SparklesIcon,
  TrendingUpIcon,
  ReceiptIcon,
} from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { apiFetch } from "../api";
import type {
  ComboCandidateItem,
  ComboCandidatesResult,
  ToolPartProps,
} from "../types";
import {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
} from "./fallback";

/**
 * Sends a combo approval/rejection decision to the assistant API.
 */
export async function sendComboDecision(
  signature: string,
  status: "approved" | "rejected" | "candidate" | string,
  note?: string | null
): Promise<{ status: string; updated: number }> {
  return apiFetch<{ status: string; updated: number }>(
    `/assistant/api/combos/${encodeURIComponent(signature)}/decision`,
    {
      method: "POST",
      body: JSON.stringify({ status, note }),
    }
  );
}

/**
 * Checks whether the current user has permission to manage combos.
 */
function useCanManageCombos(explicit?: boolean): boolean {
  if (typeof explicit === "boolean") {
    return explicit;
  }
  try {
    const page = usePage<{ can?: { manageCombos?: boolean } }>();
    return Boolean(page?.props?.can?.manageCombos);
  } catch {
    return false;
  }
}

interface SingleComboCardProps {
  combo: ComboCandidateItem;
  canManage: boolean;
}

function SingleComboCard({ combo, canManage }: SingleComboCardProps) {
  const [currentStatus, setCurrentStatus] = useState<string>(combo.status);
  const [isPending, setIsPending] = useState(false);

  // Items formatted as «صينية الملوك + 4× مياه + شوربة»
  const itemsLabel =
    combo.items && Array.isArray(combo.items)
      ? combo.items
          .map((item) => {
            const qty = Number(item.qty);
            const prefix = qty > 1 ? `${qty}× ` : "";
            return `${prefix}${item.name}`;
          })
          .join(" + ")
      : combo.hero?.name || "كومبو مقترح";

  const marginFormatted = `${(Number(combo.margin_pct) * 100).toFixed(1)}%`;
  const discountFormatted = `${(Number(combo.discount_pct) * 100).toFixed(1)}%`;
  const listPriceFormatted = `${Number(combo.list_total).toLocaleString("en-US")} ج.م`;
  const suggestedPriceFormatted = `${Number(combo.suggested_price).toLocaleString("en-US")} ج.م`;

  // Evidence copy: observed vs rule
  const isObserved = combo.evidence?.type === "observed";
  const evidenceLabel = isObserved
    ? `اتطلبوا مع بعض ${Number(combo.evidence?.together_orders ?? 0).toLocaleString("en-US")} مرة`
    : "مقترح من المنيو";

  const handleDecision = async (
    newStatus: "approved" | "rejected" | "candidate"
  ) => {
    if (!combo.signature || isPending) {
      return;
    }
    const previousStatus = currentStatus;
    setCurrentStatus(newStatus);
    setIsPending(true);

    try {
      await sendComboDecision(combo.signature, newStatus);
      toast.success("تم حفظ القرار");
    } catch (err: unknown) {
      setCurrentStatus(previousStatus);
      const msg = err instanceof Error ? err.message : "فشل حفظ القرار";
      toast.error(msg);
    } finally {
      setIsPending(false);
    }
  };

  return (
    <div className="rounded-xl border border-border/60 bg-card p-4 shadow-xs space-y-3.5 text-foreground">
      {/* Header with items and status */}
      <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-2 border-b border-border/40 pb-3">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <GiftIcon className="size-4 text-primary shrink-0" />
            <h4 className="text-sm font-bold text-foreground">{itemsLabel}</h4>
          </div>
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            {combo.template_label && (
              <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-medium">
                {combo.template_label}
              </span>
            )}
            {combo.period && (
              <span className="font-mono text-[11px] text-muted-foreground">
                فترة: {combo.period}
              </span>
            )}
          </div>
        </div>

        {/* Status Badge */}
        <div className="shrink-0">
          {currentStatus === "approved" && (
            <span className="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
              <CheckIcon className="size-3" />
              معتمد
            </span>
          )}
          {currentStatus === "rejected" && (
            <span className="inline-flex items-center gap-1 rounded-md border border-destructive/30 bg-destructive/10 px-2.5 py-1 text-xs font-semibold text-destructive">
              <XIcon className="size-3" />
              مرفوض
            </span>
          )}
          {currentStatus !== "approved" && currentStatus !== "rejected" && (
            <span className="inline-flex items-center rounded-md border border-border/60 bg-muted/60 px-2.5 py-1 text-xs font-semibold text-muted-foreground">
              مقترح
            </span>
          )}
        </div>
      </div>

      {/* Pricing and Margins Grid */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
        <div className="rounded-lg border border-border/40 bg-muted/30 p-2.5 space-y-0.5">
          <div className="text-[11px] text-muted-foreground flex items-center gap-1">
            <ReceiptIcon className="size-3 shrink-0" />
            السعر الأصلي
          </div>
          <div className="font-bold text-foreground">{listPriceFormatted}</div>
        </div>

        <div className="rounded-lg border border-primary/20 bg-primary/5 p-2.5 space-y-0.5">
          <div className="text-[11px] text-primary font-medium flex items-center gap-1">
            <SparklesIcon className="size-3 shrink-0" />
            السعر المقترح
          </div>
          <div className="font-bold text-foreground text-sm">
            {suggestedPriceFormatted}
          </div>
        </div>

        <div className="rounded-lg border border-border/40 bg-muted/30 p-2.5 space-y-0.5">
          <div className="text-[11px] text-muted-foreground flex items-center gap-1">
            <TrendingUpIcon className="size-3 shrink-0" />
            هامش الربح
          </div>
          <div className="font-bold text-emerald-600 dark:text-emerald-400">
            {marginFormatted}
          </div>
        </div>

        <div className="rounded-lg border border-border/40 bg-muted/30 p-2.5 space-y-0.5">
          <div className="text-[11px] text-muted-foreground">الخصم</div>
          <div className="font-bold text-foreground">{discountFormatted}</div>
        </div>
      </div>

      {/* Evidence and Overlap Notes */}
      <div className="space-y-2 text-xs">
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 rounded-md border border-border/60 bg-muted/40 px-2.5 py-1 font-medium text-foreground">
            <span>💡 الدليل:</span>
            <span>{evidenceLabel}</span>
          </span>
          {combo.evidence?.why && (
            <span className="text-[11px] text-muted-foreground">
              ({combo.evidence.why})
            </span>
          )}
        </div>

        {/* Overlap Warning */}
        {combo.overlaps_existing_combo && (
          <div className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-400">
            <AlertTriangleIcon className="size-4 shrink-0" />
            <span className="font-medium">
              {`شبه كومبو موجود: ${combo.overlaps_existing_combo.name}`}
            </span>
          </div>
        )}

        {/* Owner Note if present */}
        {combo.owner_note && (
          <div className="text-[11px] text-muted-foreground bg-muted/30 rounded p-2 border border-border/30">
            ملاحظة: {combo.owner_note}
          </div>
        )}
      </div>

      {/* Decision Actions (shown only when user has manageCombos permission) */}
      {canManage && (
        <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-border/40">
          <Button
            type="button"
            size="sm"
            variant={currentStatus === "approved" ? "secondary" : "default"}
            disabled={isPending || currentStatus === "approved" || !combo.signature}
            onClick={() => handleDecision("approved")}
            className="gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white dark:bg-emerald-600 dark:hover:bg-emerald-700"
          >
            <CheckIcon className="size-3.5" />
            <span>اعتمد</span>
          </Button>

          <Button
            type="button"
            size="sm"
            variant="destructive"
            disabled={isPending || currentStatus === "rejected" || !combo.signature}
            onClick={() => handleDecision("rejected")}
            className="gap-1.5"
          >
            <XIcon className="size-3.5" />
            <span>ارفض</span>
          </Button>

          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={isPending || currentStatus === "candidate" || !combo.signature}
            onClick={() => handleDecision("candidate")}
            className="gap-1.5"
          >
            <RotateCcwIcon className="size-3.5" />
            <span>رجوع لمقترح</span>
          </Button>
        </div>
      )}
    </div>
  );
}

export interface ComboCandidatesCardProps
  extends ToolPartProps<Record<string, unknown>, unknown> {
  canManage?: boolean;
}

export function ComboCandidatesCard({
  result,
  status,
  canManage,
}: ComboCandidatesCardProps) {
  const canManageCombos = useCanManageCombos(canManage);

  if (status?.type === "running" || result === undefined) {
    return <RunningSkeleton toolName="get_combo_candidates" />;
  }

  let payload: ComboCandidatesResult;
  try {
    payload =
      typeof result === "string"
        ? (JSON.parse(result) as ComboCandidatesResult)
        : (result as ComboCandidatesResult);
  } catch {
    return (
      <FallbackCard
        toolName="get_combo_candidates"
        reason="تعذر قراءة بيانات الكومبوهات المقترحة."
      />
    );
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
    return <FallbackCard toolName="get_combo_candidates" payload={payload} />;
  }

  const combos = payload.metrics?.combos;
  if (!combos || !Array.isArray(combos) || combos.length === 0) {
    return (
      <FallbackCard
        toolName="get_combo_candidates"
        payload={payload}
        reason="مفيش عروض جاهزة بهامش ربح آمن للاختيار ده."
      />
    );
  }

  return (
    <ToolErrorBoundary toolName="get_combo_candidates">
      <div dir="rtl" className="w-full space-y-3">
        {combos.map((combo, idx) => (
          <SingleComboCard
            key={combo.signature || idx}
            combo={combo}
            canManage={canManageCombos}
          />
        ))}
      </div>
    </ToolErrorBoundary>
  );
}
