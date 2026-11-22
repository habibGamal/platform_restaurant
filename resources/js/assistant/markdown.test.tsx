import { describe, it, expect } from "vitest";
import { renderToString } from "react-dom/server";
import { MarkdownText } from "@/components/assistant-ui/markdown-text";

describe("MarkdownText", () => {
  it("renders an incomplete table chunk without throwing", () => {
    const incompleteTable = "| الصنف | الكمية |\n|---|";
    expect(() => {
      renderToString(<MarkdownText text={incompleteTable} />);
    }).not.toThrow();
  });

  it("renders 2 header cells for a complete table", () => {
    const completeTable = "| الصنف | الكمية |\n|---|---|\n| برجر | 5 |";
    const html = renderToString(<MarkdownText text={completeTable} />);
    const thMatches = html.match(/<th\b/g);
    expect(thMatches).not.toBeNull();
    expect(thMatches?.length).toBe(2);
    expect(html).toContain("الصنف");
    expect(html).toContain("الكمية");
    expect(html).toContain("overflow-x-auto");
  });

  it("preserves mixed Arabic text and digits", () => {
    const mixed = "إجمالي المبيعات 1,250 ج.م بنسبة 15.5%";
    const html = renderToString(<MarkdownText text={mixed} />);
    expect(html).toContain("1,250");
    expect(html).toContain("15.5%");
  });
});

