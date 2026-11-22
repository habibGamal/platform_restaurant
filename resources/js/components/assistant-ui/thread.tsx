import { type FC } from "react";
import {
  ThreadPrimitive,
  ComposerPrimitive,
  MessagePrimitive,
  ActionBarPrimitive,
  useAuiState,
} from "@assistant-ui/react";
import {
  ArrowUpIcon,
  SquareIcon,
  CopyIcon,
  RefreshCwIcon,
  AlertCircleIcon,
  SparklesIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { cn } from "@/lib/utils";
import { TooltipIconButton } from "@/components/assistant-ui/tooltip-icon-button";
import { MarkdownText } from "@/components/assistant-ui/markdown-text";
import { ToolFallback } from "@/components/assistant-ui/tool-fallback";
import { WorkLogContent } from "@/assistant/data-uis/worklog";
import { StatusContent } from "@/assistant/data-uis/status";
import { cancelRun } from "@/assistant/api";

export type ThreadProps = {
  className?: string;
  greeting?: string;
  starterPrompts?: string[];
  readiness?: {
    ready: boolean;
    message: string | null;
    settingsUrl: string | null;
  } | null;
};

export const Thread: FC<ThreadProps> = ({
  className,
  greeting,
  starterPrompts = [],
  readiness,
}) => {
  const isComposerDisabled = readiness?.ready === false;

  return (
    <ThreadPrimitive.Root
      className={cn("flex h-full w-full flex-col overflow-hidden bg-background", className)}
    >
      <ThreadPrimitive.Viewport className="flex flex-1 flex-col overflow-y-auto px-4 py-4">
        <ThreadPrimitive.Empty>
          <div className="flex flex-1 flex-col items-center justify-center gap-6 px-4 py-8 text-center">
            <div className="flex max-w-md flex-col items-center gap-2">
              <div className="rounded-2xl bg-primary/10 p-3 text-primary">
                <SparklesIcon className="size-8" />
              </div>
              <h1 className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                {greeting || "أهلاً بك في المساعد الذكي"}
              </h1>
              <p className="text-xs sm:text-sm text-muted-foreground">
                اسألني عن أداء المبيعات، أصناف المنيو، أو تحليلات الطلبات والكومبوهات.
              </p>
            </div>

            {starterPrompts.length > 0 && (
              <div className="flex max-w-2xl flex-wrap justify-center gap-2">
                {starterPrompts.map((prompt) => (
                  <ThreadPrimitive.Suggestion
                    key={prompt}
                    prompt={prompt}
                    send
                    className="cursor-pointer rounded-full border border-border/60 bg-muted/40 px-3.5 py-2 text-xs font-medium text-foreground transition-all hover:border-primary/40 hover:bg-muted/80 active:scale-95 text-start"
                  >
                    {prompt}
                  </ThreadPrimitive.Suggestion>
                ))}
              </div>
            )}
          </div>
        </ThreadPrimitive.Empty>

        <ThreadPrimitive.Messages
          components={{
            UserMessage,
            AssistantMessage,
          }}
        />
      </ThreadPrimitive.Viewport>

      {isComposerDisabled && (
        <div className="px-4 pb-2">
          <Alert
            variant="destructive"
            className="border-destructive/40 bg-destructive/10 text-destructive"
          >
            <AlertCircleIcon className="size-4" />
            <AlertTitle>المساعد غير متاح</AlertTitle>
            <AlertDescription className="flex flex-wrap items-center gap-2 text-xs sm:text-sm text-destructive/90">
              <span>{readiness?.message || "المساعد غير متاح للاستخدام في الوقت الحالي."}</span>
              {readiness?.settingsUrl && (
                <a
                  href={readiness.settingsUrl}
                  className="font-semibold underline underline-offset-4 hover:opacity-80"
                >
                  الانتقال إلى الإعدادات
                </a>
              )}
            </AlertDescription>
          </Alert>
        </div>
      )}

      <div className="border-t border-border/40 p-4">
        <ComposerPrimitive.Root className="relative flex w-full items-end rounded-2xl border border-input bg-muted/40 p-2 shadow-xs focus-within:border-ring">
          <ComposerPrimitive.Input
            placeholder={
              isComposerDisabled ? "المساعد غير متاح حالياً..." : "اسأل المساعد الذكي..."
            }
            disabled={isComposerDisabled}
            className="max-h-36 min-h-10 flex-1 resize-none bg-transparent px-3 py-2 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50"
            rows={1}
            autoFocus={!isComposerDisabled}
          />
          <div className="flex items-center gap-1.5 ps-2">
            <ThreadPrimitive.If running>
              <ComposerPrimitive.Cancel
                asChild
                onClick={() => {
                  void cancelRun();
                }}
              >
                <Button
                  size="icon"
                  variant="ghost"
                  className="size-8 rounded-full"
                  onClick={() => {
                    void cancelRun();
                  }}
                >
                  <SquareIcon className="size-4 fill-foreground" />
                  <span className="sr-only">إيقاف</span>
                </Button>
              </ComposerPrimitive.Cancel>
            </ThreadPrimitive.If>
            <ThreadPrimitive.If running={false}>
              <ComposerPrimitive.Send asChild>
                <Button
                  size="icon"
                  disabled={isComposerDisabled}
                  className="size-8 rounded-full disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <ArrowUpIcon className="size-4" />
                  <span className="sr-only">إرسال</span>
                </Button>
              </ComposerPrimitive.Send>
            </ThreadPrimitive.If>
          </div>
        </ComposerPrimitive.Root>
      </div>
    </ThreadPrimitive.Root>
  );
};

const UserMessage: FC = () => {
  return (
    <MessagePrimitive.Root className="flex justify-end py-2">
      <div className="max-w-[80%] rounded-2xl bg-primary px-4 py-2.5 text-sm text-primary-foreground">
        <MessagePrimitive.Parts
          components={{
            Text: ({ text }) => <span className="whitespace-pre-wrap">{text}</span>,
          }}
        />
      </div>
    </MessagePrimitive.Root>
  );
};

const AssistantMessage: FC = () => {
  return (
    <MessagePrimitive.Root className="group flex flex-col py-2">
      <div className="max-w-[85%] rounded-2xl bg-muted/40 px-4 py-3 text-sm text-foreground">
        <MessagePrimitive.Parts
          components={{
            Text: MarkdownText,
            tools: {
              by_name: {},
              Fallback: ToolFallback,
            },
            data: {
              by_name: {
                worklog: WorkLogContent,
                status: StatusContent,
              },
            },
          }}
        />
        <MessagePrimitive.Error>
          <AssistantMessageError />
        </MessagePrimitive.Error>
      </div>
      <div className="mt-1 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
        <ActionBarPrimitive.Root>
          <ActionBarPrimitive.Copy asChild>
            <TooltipIconButton tooltip="نسخ">
              <CopyIcon className="size-3.5" />
            </TooltipIconButton>
          </ActionBarPrimitive.Copy>
          <ActionBarPrimitive.Reload asChild>
            <TooltipIconButton tooltip="إعادة المحاولة">
              <RefreshCwIcon className="size-3.5" />
            </TooltipIconButton>
          </ActionBarPrimitive.Reload>
        </ActionBarPrimitive.Root>
      </div>
    </MessagePrimitive.Root>
  );
};

const AssistantMessageError: FC = () => {
  const errorText = useAuiState((s) => {
    if (s.message.status?.type !== "incomplete" || s.message.status.reason !== "error") {
      return "حدث خطأ أثناء معالجة الطلب.";
    }
    const err = s.message.status.error;
    if (typeof err === "string") return err;
    if (
      typeof err === "object" &&
      err !== null &&
      "message" in err &&
      typeof (err as { message: unknown }).message === "string"
    ) {
      return (err as { message: string }).message;
    }
    return "حدث خطأ أثناء معالجة الطلب.";
  });

  return (
    <div className="mt-2 flex flex-col items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-destructive">
      <div className="flex items-center gap-2 text-xs sm:text-sm font-medium">
        <AlertCircleIcon className="size-4 shrink-0" />
        <span>{errorText}</span>
      </div>
      <ActionBarPrimitive.Root>
        <ActionBarPrimitive.Reload asChild>
          <Button
            variant="outline"
            size="sm"
            className="h-7 gap-1.5 border-destructive/30 text-xs text-destructive hover:bg-destructive/10 hover:text-destructive"
          >
            <RefreshCwIcon className="size-3" />
            <span>حاول تاني</span>
          </Button>
        </ActionBarPrimitive.Reload>
      </ActionBarPrimitive.Root>
    </div>
  );
};

