import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  xsrf,
  apiFetch,
  cancelRun,
  setActiveRunId,
  getActiveRunId,
  listThreads,
  createThread,
  getThread,
  updateThread,
  deleteThread,
  generateThreadTitle,
  getThreadMessages,
} from "./api";

describe("api helpers", () => {
  const mockDocument = {
    cookie: "",
  };

  beforeEach(() => {
    mockDocument.cookie = "";
    vi.stubGlobal("document", mockDocument);
    setActiveRunId(null);
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  describe("xsrf", () => {
    it("returns empty string when no cookie is set", () => {
      mockDocument.cookie = "";
      expect(xsrf()).toBe("");
    });

    it("decodes URL-encoded XSRF-TOKEN cookie properly", () => {
      mockDocument.cookie = "XSRF-TOKEN=test%3Dtoken%2B123%40xyz";
      expect(xsrf()).toBe("test=token+123@xyz");
    });

    it("extracts XSRF-TOKEN when multiple cookies exist", () => {
      mockDocument.cookie = "foo=bar; XSRF-TOKEN=my-secret-token; baz=qux";
      expect(xsrf()).toBe("my-secret-token");
    });
  });

  describe("apiFetch", () => {
    it("sends same-origin credentials, Accept header, and X-XSRF-TOKEN", async () => {
      mockDocument.cookie = "XSRF-TOKEN=valid-token";
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({ success: true }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await apiFetch("/test-url");

      expect(fetchSpy).toHaveBeenCalledWith("/test-url", expect.objectContaining({
        credentials: "same-origin",
      }));
      const headers = fetchSpy.mock.calls[0][1].headers as Headers;
      expect(headers.get("X-XSRF-TOKEN")).toBe("valid-token");
      expect(headers.get("Accept")).toBe("application/json");
      expect(result).toEqual({ success: true });
    });

    it("handles 204 No Content returning undefined", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 204,
        headers: new Headers(),
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await apiFetch("/empty-url");
      expect(result).toBeUndefined();
    });

    it("throws ApiError with Arabic message on non-2xx response with JSON body", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: false,
        status: 409,
        statusText: "Conflict",
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({
          message: "هناك سؤال قيد المعالجة بالفعل في هذه المحادثة",
        }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      await expect(apiFetch("/conflict-url")).rejects.toThrowError(
        "هناك سؤال قيد المعالجة بالفعل في هذه المحادثة"
      );
    });

    it("falls back to statusText when non-2xx response is not JSON", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: false,
        status: 500,
        statusText: "Internal Server Error",
        headers: new Headers(),
        json: async () => {
          throw new Error("Invalid JSON");
        },
      });
      vi.stubGlobal("fetch", fetchSpy);

      await expect(apiFetch("/server-error")).rejects.toThrowError(
        "Internal Server Error"
      );
    });
  });

  describe("cancelRun", () => {
    it("calls POST /assistant/api/runs/{runId}/cancel with explicit runId", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 204,
        headers: new Headers(),
      });
      vi.stubGlobal("fetch", fetchSpy);

      await cancelRun("run-123");

      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/runs/run-123/cancel",
        expect.objectContaining({
          method: "POST",
          credentials: "same-origin",
        })
      );
    });

    it("calls cancel endpoint using activeRunId when not provided", async () => {
      setActiveRunId("tracked-run-456");
      expect(getActiveRunId()).toBe("tracked-run-456");

      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 204,
        headers: new Headers(),
      });
      vi.stubGlobal("fetch", fetchSpy);

      await cancelRun();

      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/runs/tracked-run-456/cancel",
        expect.objectContaining({
          method: "POST",
        })
      );
      expect(getActiveRunId()).toBeNull();
    });
  });

  describe("threads API helpers", () => {
    it("listThreads sends GET /assistant/api/threads", async () => {
      const threadsMock = [{ id: "t1", title: "محادثة", status: "regular", pinned: false, lastActivityAt: null }];
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => threadsMock,
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await listThreads();
      expect(fetchSpy).toHaveBeenCalledWith("/assistant/api/threads", expect.anything());
      expect(result).toEqual(threadsMock);
    });

    it("createThread sends POST /assistant/api/threads", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({ id: "new-thread-id" }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await createThread();
      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/threads",
        expect.objectContaining({ method: "POST" })
      );
      expect(result).toEqual({ id: "new-thread-id" });
    });

    it("getThread sends GET /assistant/api/threads/{id}", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({ id: "t1", title: "تفاصيل" }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await getThread("t1");
      expect(fetchSpy).toHaveBeenCalledWith("/assistant/api/threads/t1", expect.anything());
      expect(result).toEqual({ id: "t1", title: "تفاصيل" });
    });

    it("updateThread sends PATCH /assistant/api/threads/{id}", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({ id: "t1", title: "عنوان جديد" }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      await updateThread("t1", { title: "عنوان جديد" });
      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/threads/t1",
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({ title: "عنوان جديد" }),
        })
      );
    });

    it("deleteThread sends DELETE /assistant/api/threads/{id}", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 204,
        headers: new Headers(),
      });
      vi.stubGlobal("fetch", fetchSpy);

      await deleteThread("t1");
      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/threads/t1",
        expect.objectContaining({ method: "DELETE" })
      );
    });

    it("generateThreadTitle sends POST /assistant/api/threads/{id}/title", async () => {
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => ({ title: "عنوان تلقائي" }),
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await generateThreadTitle("t1");
      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/threads/t1/title",
        expect.objectContaining({ method: "POST" })
      );
      expect(result).toEqual({ title: "عنوان تلقائي" });
    });

    it("getThreadMessages sends GET /assistant/api/threads/{id}/messages", async () => {
      const messagesMock = [{ id: "m1", role: "user", parts: [{ type: "text", text: "مرحبا" }] }];
      const fetchSpy = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        headers: new Headers({ "content-type": "application/json" }),
        json: async () => messagesMock,
      });
      vi.stubGlobal("fetch", fetchSpy);

      const result = await getThreadMessages("t1");
      expect(fetchSpy).toHaveBeenCalledWith(
        "/assistant/api/threads/t1/messages",
        expect.anything()
      );
      expect(result).toEqual(messagesMock);
    });
  });
});
