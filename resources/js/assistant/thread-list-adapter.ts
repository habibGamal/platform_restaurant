import { useMemo } from "react";
import { useAui } from "@assistant-ui/react";
import type {
  RemoteThreadListAdapter,
  RemoteThreadListResponse,
  RemoteThreadMetadata,
  RemoteThreadInitializeResponse,
  ThreadHistoryAdapter,
  GenericThreadHistoryAdapter,
  MessageFormatAdapter,
  ThreadMessage,
} from "@assistant-ui/core";
import { createAssistantStream, type AssistantStream } from "assistant-stream";
import {
  listThreads,
  createThread,
  getThread,
  updateThread,
  deleteThread,
  generateThreadTitle,
  getThreadMessages,
} from "@/assistant/api";

/**
 * Creates a ThreadHistoryAdapter for the chat runtime.
 * History is loaded from GET /assistant/api/threads/{id}/messages.
 * Append is a no-op because the server already persists run messages.
 */
export function createThreadHistoryAdapter(getAui: () => any): ThreadHistoryAdapter {
  return {
    async load() {
      return { messages: [] };
    },
    async append() {
      // no-op: server already stores messages
    },
    withFormat<TMessage, TStorageFormat extends Record<string, unknown>>(
      formatAdapter: MessageFormatAdapter<TMessage, TStorageFormat>
    ): GenericThreadHistoryAdapter<TMessage> {
      let pinnedRemoteId: string | undefined;

      const resolveRemoteId = (): string | undefined => {
        try {
          const aui = getAui();
          const live = aui?.threadListItem;
          if (live?.source) {
            const state = live.getState();
            return state.remoteId ?? state.id;
          }
        } catch {
          // outside aui context
        }
        return undefined;
      };

      return {
        pin() {
          pinnedRemoteId = resolveRemoteId();
        },
        async load() {
          const remoteId = pinnedRemoteId ?? resolveRemoteId();
          if (!remoteId) {
            return { messages: [] };
          }

          const rawMessages = await getThreadMessages(remoteId);
          const messages = (rawMessages || []).map((msg: any, index: number) => {
            if (
              formatAdapter &&
              typeof (formatAdapter as any).decode === "function" &&
              msg.content &&
              !msg.role
            ) {
              return formatAdapter.decode(msg);
            }
            return {
              parentId:
                msg.parentId ??
                msg.parent_id ??
                (index > 0 ? (rawMessages[index - 1] as any).id : null),
              message: msg,
            };
          });

          return { messages };
        },
        async append() {
          // no-op: server already stores messages
        },
      };
    },
  };
}

/**
 * RemoteThreadListAdapter connecting assistant-ui's thread list
 * to the backend routes (/assistant/api/threads/*).
 */
export const threadListAdapter: RemoteThreadListAdapter = {
  async list(): Promise<RemoteThreadListResponse> {
    const data = await listThreads();
    const threads: RemoteThreadMetadata[] = (data || []).map((t) => ({
      remoteId: t.id,
      title: t.title ?? undefined,
      status: t.status === "archived" ? ("archived" as const) : ("regular" as const),
      lastMessageAt: t.lastActivityAt ? new Date(t.lastActivityAt) : undefined,
      custom: { pinned: Boolean(t.pinned) },
    }));

    return { threads };
  },

  async rename(remoteId: string, newTitle: string): Promise<void> {
    await updateThread(remoteId, { title: newTitle });
  },

  async archive(remoteId: string): Promise<void> {
    await updateThread(remoteId, { status: "archived" });
  },

  async unarchive(remoteId: string): Promise<void> {
    await updateThread(remoteId, { status: "regular" });
  },

  async delete(remoteId: string): Promise<void> {
    await deleteThread(remoteId);
  },

  async initialize(_threadId: string): Promise<RemoteThreadInitializeResponse> {
    const res = await createThread();
    return {
      remoteId: res.id,
    };
  },

  async generateTitle(
    remoteId: string,
    _messages: readonly ThreadMessage[]
  ): Promise<AssistantStream> {
    let title = "";
    try {
      const res = await generateThreadTitle(remoteId);
      title = res.title ?? "";
    } catch (err) {
      console.error("Failed to generate thread title:", err);
    }

    return createAssistantStream((controller) => {
      if (title) {
        controller.appendText(title);
      }
      controller.close();
    });
  },

  async fetch(threadId: string): Promise<RemoteThreadMetadata> {
    const t = await getThread(threadId);
    return {
      remoteId: t.id,
      title: t.title ?? undefined,
      status: t.status === "archived" ? ("archived" as const) : ("regular" as const),
      lastMessageAt: t.lastActivityAt ? new Date(t.lastActivityAt) : undefined,
      custom: { pinned: Boolean(t.pinned) },
    };
  },

  unstable_useAdapters: function useAdapters() {
    const aui = useAui();
    return useMemo(
      () => ({
        history: createThreadHistoryAdapter(() => aui),
      }),
      [aui]
    );
  },
};
