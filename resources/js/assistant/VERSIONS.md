# Assistant Chat UI - Confirmed Versions and API Bindings

Confirmed from installed packages in `node_modules` on 2026-11-08.

## 1. Exact Installed & Pinned Versions

Pinned without ranges in `package.json`:
- `@assistant-ui/react`: `0.15.33`
- `@assistant-ui/ai-sdk`: `0.0.17`
- `ai`: `7.0.165`
- `@ai-sdk/react`: `4.0.169`
- `streamdown`: `2.7.1`
- `recharts`: `3.10.1`
- `@tanstack/react-table`: `9.3.1`

## 2. Render-Only Tool-UI Registration API

Confirmed from `node_modules/@assistant-ui/react/dist/index.d.ts` and `node_modules/@assistant-ui/core/dist/react/model-context/`:

- **Preferred Toolkit Authoring:** `defineToolkit` from `@assistant-ui/react`
  - Backend render-only tools declare `type: "backend"` with a `render: ({ args, result }) => ReactNode` function. No schemas uploaded, no browser code executed.
  - Mount via `Tools({ toolkit })` or pass to runtime config.
- **Component / Hook Registration:** `makeAssistantToolUI` and `useAssistantToolUI` from `@assistant-ui/react`
  - Signature: `makeAssistantToolUI({ toolName: string, render: ToolCallMessagePartComponent, display?: "standalone" | "inline" })`
  - Note: `makeAssistantToolUI` is marked `@deprecated` in `node_modules/@assistant-ui/core/dist/react/model-context/makeAssistantToolUI.d.ts` in favor of toolkit entries (`defineToolkit`) or per-message `MessagePrimitive.Parts` overrides (`components={{ tools: { by_name: { ... }, Fallback } }}`).

## 3. Data-Part Renderer API

Confirmed from `node_modules/@assistant-ui/react/dist/index.d.ts` and `node_modules/@assistant-ui/core/dist/react/model-context/`:

- **Components / Hooks:**
  - `makeAssistantDataUI` from `@assistant-ui/react`
  - `useAssistantDataUI` from `@assistant-ui/react`
  - `DataRenderers` from `@assistant-ui/react`
- **Shape:** `AssistantDataUIProps<T>`
  ```ts
  {
    name: string; // matches data part type, e.g. "worklog", "status"
    render: DataMessagePartComponent<T>; // ComponentType<DataMessagePartProps<T>>
  }
  ```
  `DataMessagePartProps<T>` contains `{ status, data, id }`.

## 4. Resumable Stream-ID Response Header

Confirmed from `node_modules/@assistant-ui/ai-sdk/dist/transport/resumable.d.ts`:

- **Header Name:** `x-resumable-stream-id`
- **Constant:** `RESUMABLE_STREAM_ID_HEADER` exported from `@assistant-ui/ai-sdk`
- **Transport Usage:**
  ```ts
  new AssistantChatTransport({
    api: "/assistant/api/chat",
    resumable: {
      storage: createResumableSessionStorage({ key: () => threadId }),
      resumeApi: (streamId) => `/assistant/api/runs/${streamId}/stream`,
    },
  });
  ```
  The server endpoint (`POST /assistant/api/chat`) returns `x-resumable-stream-id: <runId>` with `Content-Type: text/event-stream`.

## 5. ThreadHistoryAdapter.withFormat Format ID

Confirmed from `node_modules/assistant-cloud/dist/ai-sdk/index.d.ts` and `node_modules/@assistant-ui/ai-sdk/dist/adapters/aiSDKFormatAdapter.d.ts`:

- **Format ID:** `"ai-sdk/v6"`
- **Adapter Export:** `aiSDKV6FormatAdapter` from `assistant-cloud/ai-sdk` (re-exported internally in `@assistant-ui/ai-sdk`).
- **Adapter Contract:**
  `ThreadHistoryAdapter.withFormat(fmt)` receives `fmt` where `fmt.format === "ai-sdk/v6"`.
  Row storage format: `{ id: string, parent_id: string | null, format: "ai-sdk/v6", content: fmt.encode(item) }`.
  On load: `fmt.decode(row)`.

## 6. Vite-Plus Test-Runner Command

Confirmed from `npx vp --help` and local Vitest v5.0.5:

- **Command:** `vp test` (or `npx vp test`)
- Executes test files matching `**/*.{test,spec}.?(c|m)[jt]s?(x)` using Vitest.

## 7. Component Generation & Layout Notes

- The assistant-ui CLI was unable to download over network in this environment (transient ECONNRESET on npx), so `thread.tsx`, `thread-list.tsx`, `markdown-text.tsx`, `tool-fallback.tsx`, and `tooltip-icon-button.tsx` were generated directly under `resources/js/components/assistant-ui/` using the official schema definitions from `https://r.assistant-ui.com/` and `node_modules/@assistant-ui/react`.
- Existing files in `resources/js/components/ui/` were **NOT** overwritten or modified.
- All components use logical Tailwind classes only (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`), fully conforming to `dir="rtl"` and `lang="ar"`.
