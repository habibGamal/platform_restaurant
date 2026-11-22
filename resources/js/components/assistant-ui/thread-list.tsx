import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import {
  AuiIf,
  ThreadListItemPrimitive,
  ThreadListPrimitive,
  useAuiState,
} from "@assistant-ui/react";
import {
  PlusIcon,
  SearchIcon,
} from "lucide-react";
import {
  forwardRef,
  useState,
  type ComponentPropsWithoutRef,
  type FC,
} from "react";

export const ThreadList: FC<{ onItemSelect?: () => void }> = ({ onItemSelect }) => {
  const [search, setSearch] = useState("");
  const hasThreads = useAuiState((s) => s.threads.threadIds.length > 0);

  return (
    <ThreadListRoot>
      <ThreadListNew onClick={onItemSelect} />
      {hasThreads && (
        <ThreadListSearch value={search} onValueChange={setSearch} />
      )}
      <ThreadListItems searchQuery={hasThreads ? search : ""} onItemSelect={onItemSelect} />
    </ThreadListRoot>
  );
};

export const ThreadListRoot: FC<
  ComponentPropsWithoutRef<typeof ThreadListPrimitive.Root>
> = ({ className, ...props }) => {
  return (
    <ThreadListPrimitive.Root
      data-slot="aui_thread-list-root"
      className={cn("flex flex-col gap-0.5", className)}
      {...props}
    />
  );
};

export const ThreadListNew: FC<
  ComponentPropsWithoutRef<typeof ThreadListPrimitive.New>
> = ({ className, ...props }) => {
  return (
    <ThreadListPrimitive.New asChild {...props}>
      <Button
        variant="outline"
        className={cn(
          "hover:bg-muted flex items-center justify-start gap-2 rounded-lg px-2.5 py-2 text-start text-sm",
          className,
        )}
      >
        <PlusIcon className="size-4" />
        <span>محادثة جديدة</span>
      </Button>
    </ThreadListPrimitive.New>
  );
};

export const ThreadListSearch = forwardRef<
  HTMLInputElement,
  Omit<ComponentPropsWithoutRef<typeof Input>, "value" | "onChange"> & {
    value: string;
    onValueChange: (value: string) => void;
  }
>(({ className, value, onValueChange, ...props }, ref) => {
  return (
    <div data-slot="aui_thread-list-search" className="relative px-0.5 py-1">
      <SearchIcon
        data-slot="aui_thread-list-search-icon"
        className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
      />
      <Input
        ref={ref}
        type="search"
        value={value}
        onChange={(event) => onValueChange(event.target.value)}
        aria-label="البحث في المحادثات"
        placeholder="بحث في المحادثات..."
        className={cn("h-8 ps-8 text-sm", className)}
        {...props}
      />
    </div>
  );
});

ThreadListSearch.displayName = "ThreadListSearch";

export const ThreadListItems: FC<
  ComponentPropsWithoutRef<"div"> & { searchQuery?: string; onItemSelect?: () => void }
> = ({ className, searchQuery = "", onItemSelect, ...props }) => {
  return (
    <div
      data-slot="aui_thread-list-items"
      className={cn("flex flex-col gap-0.5", className)}
      {...props}
    >
      <AuiIf condition={(s) => s.threads.isLoading}>
        <ThreadListSkeleton />
      </AuiIf>
      <AuiIf condition={(s) => !s.threads.isLoading}>
        <ThreadListPrimitive.Items>
          {({ threadListItem }) => {
            const query = searchQuery.trim().toLowerCase();
            const title = threadListItem.title?.toLowerCase() ?? "";
            if (query && !title.includes(query)) return null;

            return (
              <ThreadListItemPrimitive.Root
                key={threadListItem.id}
                className="group relative flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm hover:bg-muted data-[state=active]:bg-muted"
              >
                <ThreadListItemPrimitive.Trigger
                  onClick={onItemSelect}
                  className="flex min-w-0 flex-1 items-center gap-2 truncate text-start"
                >
                  <span className="truncate">
                    <ThreadListItemPrimitive.Title fallback="محادثة جديدة" />
                  </span>
                </ThreadListItemPrimitive.Trigger>
              </ThreadListItemPrimitive.Root>
            );
          }}
        </ThreadListPrimitive.Items>
      </AuiIf>
    </div>
  );
};

const ThreadListSkeleton: FC = () => {
  return (
    <div className="flex flex-col gap-1 p-1">
      <Skeleton className="h-8 w-full rounded-md" />
      <Skeleton className="h-8 w-full rounded-md" />
      <Skeleton className="h-8 w-full rounded-md" />
    </div>
  );
};
