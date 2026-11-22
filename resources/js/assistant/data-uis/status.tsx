import { type FC } from "react";
import { makeAssistantDataUI, type DataMessagePartProps } from "@assistant-ui/react";
import { Spinner } from "@/components/ui/spinner";

export type StatusData = {
  kind: string;
  text: string;
};

export const StatusContent: FC<DataMessagePartProps<StatusData>> = ({ data }) => {
  if (!data?.text) {
    return null;
  }

  return (
    <div className="my-1.5 flex items-center gap-2 rounded-lg border border-border/40 bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground animate-pulse">
      <Spinner className="size-3.5 text-primary shrink-0" />
      <span>{data.text}</span>
    </div>
  );
};

export const StatusUI = makeAssistantDataUI<StatusData>({
  name: "status",
  render: StatusContent,
});
