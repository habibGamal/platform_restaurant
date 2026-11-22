import { memo, useState } from "react";
import {
  AlertCircleIcon,
  CheckIcon,
  ChevronDownIcon,
  LoaderIcon,
  XCircleIcon,
} from "lucide-react";
import type {
  ToolCallMessagePartComponent,
} from "@assistant-ui/react";
import { cn } from "@/lib/utils";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";

const statusIconMap: Record<string, React.ElementType> = {
  running: LoaderIcon,
  complete: CheckIcon,
  incomplete: XCircleIcon,
  "requires-action": AlertCircleIcon,
};

export const ToolFallback: ToolCallMessagePartComponent = memo(({
  toolName,
  argsText,
  result,
  status,
}) => {
  const [open, setOpen] = useState(false);
  const statusType = status?.type ?? "complete";
  const Icon = statusIconMap[statusType] ?? CheckIcon;
  const isRunning = statusType === "running";

  return (
    <Collapsible
      open={open}
      onOpenChange={setOpen}
      className="aui-tool-fallback w-full rounded-lg border border-border/50 bg-muted/30 p-2 text-xs"
    >
      <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 text-muted-foreground hover:text-foreground">
        <div className="flex items-center gap-2">
          <Icon className={cn("size-3.5", isRunning && "animate-spin")} />
          <span className="font-mono font-medium">{toolName}</span>
        </div>
        <ChevronDownIcon
          className={cn("size-3.5 transition-transform duration-200", open && "rotate-180")}
        />
      </CollapsibleTrigger>
      <CollapsibleContent className="mt-2 space-y-2 border-t border-border/40 pt-2 font-mono">
        {argsText && (
          <div>
            <div className="mb-0.5 text-[10px] uppercase text-muted-foreground">Args</div>
            <pre className="overflow-x-auto rounded bg-background p-1.5 text-[11px] text-foreground">
              {argsText}
            </pre>
          </div>
        )}
        {result !== undefined && (
          <div>
            <div className="mb-0.5 text-[10px] uppercase text-muted-foreground">Result</div>
            <pre className="overflow-x-auto rounded bg-background p-1.5 text-[11px] text-foreground">
              {typeof result === "string" ? result : JSON.stringify(result, null, 2)}
            </pre>
          </div>
        )}
      </CollapsibleContent>
    </Collapsible>
  );
});

ToolFallback.displayName = "ToolFallback";
