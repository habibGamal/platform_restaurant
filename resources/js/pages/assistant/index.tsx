import { useState, type ReactNode } from "react";
import { Head } from "@inertiajs/react";
import { AssistantRuntimeProvider } from "@assistant-ui/react";
import { useAssistantRuntime } from "@/assistant/runtime";
import { MenuToolUIs } from "@/assistant/tool-uis";
import { WorkLogUI } from "@/assistant/data-uis/worklog";
import { StatusUI } from "@/assistant/data-uis/status";
import { Thread } from "@/components/assistant-ui/thread";
import { ThreadList } from "@/components/assistant-ui/thread-list";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { Button } from "@/components/ui/button";
import { useAppearance } from "@/hooks/use-appearance";
import {
  BotIcon,
  PanelRightIcon,
  LayoutDashboardIcon,
  SunIcon,
  MoonIcon,
} from "lucide-react";

export type AssistantPageProps = {
  greeting: string;
  starterPrompts: string[];
  readiness: {
    ready: boolean;
    message: string | null;
    settingsUrl: string | null;
  } | null;
  can: {
    manageCombos: boolean;
  };
  panelUrl: string;
};

export default function AssistantPage({
  greeting,
  starterPrompts,
  readiness,
  can: _can,
  panelUrl,
}: AssistantPageProps) {
  const runtime = useAssistantRuntime();
  const [mobileOpen, setMobileOpen] = useState(false);
  const { resolvedAppearance, updateAppearance } = useAppearance();

  return (
    <div
      dir="rtl"
      lang="ar"
      className="flex h-screen w-screen overflow-hidden bg-background text-foreground"
    >
      <Head title="المساعد الذكي" />

      <AssistantRuntimeProvider runtime={runtime}>
        {/* Generative Tool UI Renderers */}
        <MenuToolUIs />

        {/* Data UI Renderers */}
        <WorkLogUI />
        <StatusUI />

        {/* Desktop Sidebar: start side (right in RTL) on >= 1024px */}
        <aside
          aria-label="قائمة المحادثات"
          className="hidden w-72 shrink-0 flex-col border-e border-border/40 bg-muted/20 lg:flex"
        >
          <div className="flex h-14 items-center justify-between border-b border-border/40 px-4">
            <span className="text-sm font-semibold text-foreground">المحادثات</span>
          </div>
          <div className="flex-1 overflow-y-auto p-3">
            <ThreadList />
          </div>
        </aside>

        {/* Main Content Area */}
        <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
          {/* Header */}
          <header className="flex h-14 shrink-0 items-center justify-between border-b border-border/40 bg-background px-4">
            <div className="flex items-center gap-2">
              {/* Mobile Drawer Trigger (< 1024px) */}
              <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                <SheetTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-9 lg:hidden"
                    aria-label="فتح قائمة المحادثات"
                  >
                    <PanelRightIcon className="size-5" />
                  </Button>
                </SheetTrigger>
                <SheetContent side="right" className="w-80 p-0">
                  <SheetHeader className="border-b border-border/40 px-4 py-3 text-start">
                    <SheetTitle className="text-sm font-semibold">المحادثات</SheetTitle>
                  </SheetHeader>
                  <div className="flex-1 overflow-y-auto p-3">
                    <ThreadList onItemSelect={() => setMobileOpen(false)} />
                  </div>
                </SheetContent>
              </Sheet>

              <div className="flex items-center gap-2">
                <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                  <BotIcon className="size-4" />
                </div>
                <h1 className="text-base font-semibold tracking-tight text-foreground">
                  المساعد الذكي
                </h1>
              </div>
            </div>

            {/* Navigation & Controls */}
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="icon"
                onClick={() =>
                  updateAppearance(resolvedAppearance === "dark" ? "light" : "dark")
                }
                className="size-8 rounded-lg text-muted-foreground hover:text-foreground"
                aria-label="تبديل المظهر"
              >
                {resolvedAppearance === "dark" ? (
                  <SunIcon className="size-4" />
                ) : (
                  <MoonIcon className="size-4" />
                )}
              </Button>

              <Button
                variant="ghost"
                size="sm"
                asChild
                className="h-8 gap-1.5 px-2.5 text-xs text-muted-foreground hover:text-foreground"
              >
                <a href={panelUrl}>
                  <LayoutDashboardIcon className="size-3.5" />
                  <span>لوحة التحكم</span>
                </a>
              </Button>
            </div>
          </header>

          {/* Chat Thread */}
          <main className="flex min-w-0 flex-1 flex-col overflow-hidden">
            <Thread
              greeting={greeting}
              starterPrompts={starterPrompts}
              readiness={readiness}
            />
          </main>
        </div>
      </AssistantRuntimeProvider>
    </div>
  );
}

AssistantPage.layout = (page: ReactNode) => page;
