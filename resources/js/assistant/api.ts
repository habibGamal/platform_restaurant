import { cancel } from "@/routes/assistant/runs";

export class ApiError extends Error {
  constructor(
    public status: number,
    public override message: string,
    public data?: unknown
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Returns the decoded value of the XSRF-TOKEN cookie.
 */
export function xsrf(): string {
  if (typeof document === "undefined") {
    return "";
  }
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : "";
}

/**
 * Fetch wrapper that automatically sends same-origin credentials,
 * the X-XSRF-TOKEN header, and parses Arabic error messages from JSON payloads.
 */
export async function apiFetch<T = any>(
  url: string,
  options: RequestInit = {}
): Promise<T> {
  const token = xsrf();
  const headers = new Headers(options.headers);

  if (token && !headers.has("X-XSRF-TOKEN")) {
    headers.set("X-XSRF-TOKEN", token);
  }

  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }

  if (
    options.body &&
    typeof options.body === "string" &&
    !headers.has("Content-Type") &&
    (options.body.startsWith("{") || options.body.startsWith("["))
  ) {
    headers.set("Content-Type", "application/json");
  }

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers,
  });

  if (!response.ok) {
    let message = response.statusText || "حدث خطأ غير متوقع";
    let errorData: any = null;

    try {
      errorData = await response.json();
      if (errorData && typeof errorData.message === "string") {
        message = errorData.message;
      }
    } catch {
      // Non-JSON response
    }

    throw new ApiError(response.status, message, errorData);
  }

  if (response.status === 204) {
    return undefined as unknown as T;
  }

  const contentType = response.headers.get("content-type");
  if (contentType && contentType.includes("application/json")) {
    return (await response.json()) as T;
  }

  return (await response.text()) as unknown as T;
}

let activeRunId: string | null = null;

export function setActiveRunId(runId: string | null): void {
  activeRunId = runId;
}

export function getActiveRunId(): string | null {
  return activeRunId;
}

/**
 * Cancels a streaming run via POST /assistant/api/runs/{runId}/cancel.
 * If runId is omitted, targets the currently tracked active run.
 */
export async function cancelRun(runId?: string): Promise<void> {
  let targetId = runId ?? activeRunId;

  if (!targetId && typeof sessionStorage !== "undefined") {
    for (let i = 0; i < sessionStorage.length; i++) {
      const key = sessionStorage.key(i);
      if (key && key.startsWith("aui-resumable-stream-id:")) {
        const val = sessionStorage.getItem(key);
        if (val) {
          targetId = val;
          break;
        }
      }
    }
  }

  if (!targetId) {
    return;
  }

  try {
    await apiFetch(cancel.url({ runId: targetId }), {
      method: "POST",
    });
  } catch (error) {
    console.error("Failed to cancel run:", error);
  } finally {
    if (activeRunId === targetId) {
      activeRunId = null;
    }
  }
}

export const THREADS_API_BASE = "/assistant/api/threads";

export type ApiThreadItem = {
  id: string;
  title: string | null;
  status: "regular" | "archived";
  pinned: boolean;
  lastActivityAt: string | null;
};

export type ApiCreateThreadResponse = {
  id: string;
};

export type ApiTitleResponse = {
  title: string;
};

export async function listThreads(): Promise<ApiThreadItem[]> {
  return apiFetch<ApiThreadItem[]>(THREADS_API_BASE);
}

export async function createThread(
  data?: Record<string, unknown>
): Promise<ApiCreateThreadResponse> {
  return apiFetch<ApiCreateThreadResponse>(THREADS_API_BASE, {
    method: "POST",
    body: data ? JSON.stringify(data) : undefined,
  });
}

export async function getThread(id: string): Promise<ApiThreadItem> {
  return apiFetch<ApiThreadItem>(`${THREADS_API_BASE}/${id}`);
}

export async function updateThread(
  id: string,
  data: {
    title?: string;
    status?: "regular" | "archived";
    pinned?: boolean;
  }
): Promise<ApiThreadItem | void> {
  return apiFetch<ApiThreadItem | void>(`${THREADS_API_BASE}/${id}`, {
    method: "PATCH",
    body: JSON.stringify(data),
  });
}

export async function deleteThread(id: string): Promise<void> {
  return apiFetch<void>(`${THREADS_API_BASE}/${id}`, {
    method: "DELETE",
  });
}

export async function generateThreadTitle(id: string): Promise<ApiTitleResponse> {
  return apiFetch<ApiTitleResponse>(`${THREADS_API_BASE}/${id}/title`, {
    method: "POST",
  });
}

export async function getThreadMessages(id: string): Promise<any[]> {
  return apiFetch<any[]>(`${THREADS_API_BASE}/${id}/messages`);
}
