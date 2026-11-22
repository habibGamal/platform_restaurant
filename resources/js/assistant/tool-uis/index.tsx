import type { ReactElement } from "react";
import { useAssistantToolUI } from "@assistant-ui/react";
import { RankItemsCard } from "./rank-items";
import { ItemCard } from "./item-card";
import { AffinitiesCard } from "./affinities";
import { KpiSeriesCard } from "./kpi-series";
import { ComboCandidatesCard } from "./combo-candidates";

export { RankItemsCard } from "./rank-items";
export { ItemCard } from "./item-card";
export { AffinitiesCard } from "./affinities";
export { KpiSeriesCard } from "./kpi-series";
export { ComboCandidatesCard } from "./combo-candidates";
export {
  FallbackCard,
  RunningSkeleton,
  ToolErrorBoundary,
  TOOL_LABELS,
} from "./fallback";

/**
 * Registers all Generative UI tool renderers for the Assistant Chat UI
 * when mounted inside an AssistantRuntimeProvider.
 */
export function MenuToolUIs(): ReactElement {
  useAssistantToolUI({
    toolName: "rank_items",
    render: RankItemsCard,
    display: "standalone",
  });

  useAssistantToolUI({
    toolName: "get_item_card",
    render: ItemCard,
    display: "standalone",
  });

  useAssistantToolUI({
    toolName: "get_affinities",
    render: AffinitiesCard,
    display: "standalone",
  });

  useAssistantToolUI({
    toolName: "get_kpi_series",
    render: KpiSeriesCard,
    display: "standalone",
  });

  useAssistantToolUI({
    toolName: "get_combo_candidates",
    render: ComboCandidatesCard,
    display: "standalone",
  });

  return <></>;
}
