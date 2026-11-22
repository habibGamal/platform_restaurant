import { useMemo } from "react";
import {
  useAui,
  useRemoteThreadListRuntime,
  type AssistantRuntime,
} from "@assistant-ui/react";
import {
  AssistantChatTransport,
  createResumableSessionStorage,
  useChatRuntime,
} from "@assistant-ui/ai-sdk";
import { chat } from "@/routes/assistant";
import { stream } from "@/routes/assistant/runs";
import { xsrf, setActiveRunId, cancelRun } from "@/assistant/api";
import { threadListAdapter, createThreadHistoryAdapter } from "@/assistant/thread-list-adapter";

export { threadListAdapter } from "@/assistant/thread-list-adapter";
export { cancelRun, xsrf } from "@/assistant/api";

function ResumableThreadRuntime(): AssistantRuntime {
  const aui = useAui();
  const history = useMemo(() => createThreadHistoryAdapter(() => aui), [aui]);

  const transport = useMemo(
    () =>
      new AssistantChatTransport({
        api: chat.url(),
        credentials: "same-origin",
        headers: () => ({
          "X-XSRF-TOKEN": xsrf(),
        }),
        resumable: {
          storage: createResumableSessionStorage({
            key: () => {
              const item = aui.threadListItem.getState();
              return `aui-resumable-stream-id:${item.remoteId ?? item.id}`;
            },
          }),
          resumeApi: (streamId) => stream.url({ runId: streamId }),
        },
        fetch: async (input, init) => {
          const res = await fetch(input, init);
          const streamId = res.headers.get("x-resumable-stream-id");
          if (streamId) {
            setActiveRunId(streamId);
          }
          return res;
        },
      }),
    [aui],
  );

  return useChatRuntime({
    transport,
    adapters: { history },
    onResumeError: (error) => {
      console.error("Could not resume the previous response", error);
    },
  });
}

export function useAssistantRuntime(): AssistantRuntime {
  return useRemoteThreadListRuntime({
    adapter: threadListAdapter,
    runtimeHook: ResumableThreadRuntime,
  });
}
