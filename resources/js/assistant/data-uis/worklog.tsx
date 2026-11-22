import { useState, type FC } from "react";
import { makeAssistantDataUI, type DataMessagePartProps } from "@assistant-ui/react";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Spinner } from "@/components/ui/spinner";
import { CheckCircle2Icon, AlertCircleIcon, ChevronDownIcon } from "lucide-react";
import { cn } from "@/lib/utils";

export type WorkLogStep = {
  agent: string;
  label: string;
  status: "running" | "done" | "failed";
  summary?: string;
};

export type WorkLogData = {
  plan: string | null;
  steps: WorkLogStep[];
};

export const WorkLogContent: FC<DataMessagePartProps<WorkLogData>> = ({ data, status }) => {
  if (!data || (!data.plan && (!data.steps || data.steps.length === 0))) {
    return null;
  }

  const steps: WorkLogStep[] = data.steps ?? [];
  const hasRunning =
    steps.some((s: WorkLogStep) => s.status === "running") || status?.type === "running";
  const hasFailed = steps.some((s: WorkLogStep) => s.status === "failed");
  const [open, setOpen] = useState(true);

  return (
    <div className="my-2 rounded-xl border border-border/50 bg-muted/30 p-2.5 text-xs">
      <Collapsible open={open} onOpenChange={setOpen}>
        <CollapsibleTrigger asChild>
          <button
            type="button"
            className="flex w-full items-center justify-between gap-2 rounded-lg px-1.5 py-1 text-start font-medium text-foreground transition-colors hover:bg-muted/60"
          >
            <div className="flex items-center gap-2">
              {hasRunning ? (
                <Spinner className="size-3.5 text-primary" />
              ) : hasFailed ? (
                <AlertCircleIcon className="size-3.5 text-destructive" />
              ) : (
                <CheckCircle2Icon className="size-3.5 text-emerald-600 dark:text-emerald-500" />
              )}
              <span className="font-semibold">خطوات التحليل</span>
              {steps.length > 0 && (
                <span className="text-[11px] text-muted-foreground">
                  ({steps.filter((s: WorkLogStep) => s.status === "done").length}/{steps.length})
                </span>
              )}
            </div>
            <ChevronDownIcon
              className={cn(
                "size-3.5 text-muted-foreground transition-transform duration-200",
                open && "rotate-180",
              )}
            />
          </button>
        </CollapsibleTrigger>

        <CollapsibleContent className="mt-2 space-y-2 pt-1 border-t border-border/40">
          {data.plan && (
            <div className="rounded-lg bg-background/60 p-2 text-xs font-medium text-muted-foreground">
              {data.plan}
            </div>
          )}

          {steps.length > 0 && (
            <div className="space-y-1.5 pe-1">
              {steps.map((step: WorkLogStep, idx: number) => (
                <div
                  key={`${step.agent}-${idx}`}
                  className="flex items-start gap-2 rounded-md p-1 transition-colors hover:bg-background/40"
                >
                  <div className="mt-0.5 shrink-0">
                    {step.status === "running" && (
                      <Spinner className="size-3.5 text-primary" />
                    )}
                    {step.status === "done" && (
                      <CheckCircle2Icon className="size-3.5 text-emerald-600 dark:text-emerald-500" />
                    )}
                    {step.status === "failed" && (
                      <AlertCircleIcon className="size-3.5 text-destructive" />
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                      <span className="font-medium text-foreground">{step.label}</span>
                    </div>
                    {step.summary && (
                      <p className="mt-0.5 text-[11px] text-muted-foreground whitespace-pre-wrap">
                        {step.summary}
                      </p>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
};

export const WorkLogUI = makeAssistantDataUI<WorkLogData>({
  name: "worklog",
  render: WorkLogContent,
});
