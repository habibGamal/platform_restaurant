import { memo, type FC } from "react";
import type { TextMessagePartProps } from "@assistant-ui/react";
import { Streamdown, type Components } from "streamdown";
import { cn } from "@/lib/utils";

export type MarkdownTextProps = Partial<TextMessagePartProps> & {
  className?: string;
};

const components: Components = {
  table: ({ node: _node, className: tableClass, ...props }) => (
    <div className="my-2 max-w-full overflow-x-auto rounded-lg border border-border/40">
      <table
        className={cn("w-full border-collapse text-xs sm:text-sm text-start", tableClass)}
        {...props}
      />
    </div>
  ),
  th: ({ node: _node, className: thClass, ...props }) => (
    <th
      className={cn(
        "border-b border-border/60 bg-muted/60 px-3 py-2 text-start font-semibold text-foreground whitespace-nowrap",
        thClass,
      )}
      {...props}
    />
  ),
  td: ({ node: _node, className: tdClass, ...props }) => (
    <td
      className={cn(
        "border-b border-border/30 px-3 py-1.5 text-start [unicode-bidi:plaintext]",
        tdClass,
      )}
      {...props}
    />
  ),
};

const MarkdownTextImpl: FC<MarkdownTextProps> = ({ text = "", className }) => {
  return (
    <div
      className={cn(
        "aui-md leading-relaxed [direction:rtl] text-start [unicode-bidi:isolate]",
        "[&_table]:w-full [&_table]:border-collapse [&_th]:text-start [&_td]:text-start",
        "[&_code]:[direction:ltr] [&_code]:inline-block [&_pre]:[direction:ltr]",
        className,
      )}
    >
      <Streamdown
        dir="rtl"
        mode="streaming"
        parseIncompleteMarkdown
        components={components}
      >
        {text}
      </Streamdown>
    </div>
  );
};

export const MarkdownText = memo(MarkdownTextImpl);


