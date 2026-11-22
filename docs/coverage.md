# Documentation Coverage

Reviewed against commit `2966d76ad9dbfd193582ee0f2d143aad33aee412` and working tree changes on 2026-11-08.

---

## Pages

| Page | Status | Verified By | Gaps and Next Steps |
|---|---|---|---|
| [Index / Hub](README.md) | Complete | Link and structure review | None |
| [Product Overview](overview.md) | Complete | Source inspection of models, roles, and business rules | None |
| [Getting Started](getting-started.md) | Complete | Executed `discover.py`, tested test runners and artisan commands | None |
| [Architecture](architecture.md) | Complete | Traced controllers, jobs, and cross-cutting providers | None |
| [Data Model](data-model.md) | Complete | Analyzed 28 database migrations and warehouse models | None |
| [Interfaces & APIs](interfaces.md) | Complete | Inspected `routes/api.php`, controllers, and console commands | None |
| [Configuration](configuration.md) | Complete | Cross-referenced `.env.example` and all files in `config/` | None |
| [Development Guide](development.md) | Complete | Verified Composer scripts, Pint config, PHPStan, and CI | None |
| [Feature Catalog](features/README.md) | Complete | Catalogs all 10 domain capabilities and cross-feature flows | None |
| [Deployment](deployment.md) | Complete (Recommended) | Derived from container, queue, and runtime requirements | Confirm production hosting choices with ops team |
| [Operations & Troubleshooting](operations.md) | Complete | Traced failure paths, error codes, and runbooks | None |
| [Changelog](../CHANGELOG.md) | Complete | Curated git commit history from 2026-10 to 2026-11 | None |

---

## Features

| Feature | Page | User Guide | Technical Flow | Verified By |
|---|---|---|---|---|
| POS Pairing & Heartbeat | [pos-pairing-and-heartbeat.md](features/pos-pairing-and-heartbeat.md) | Complete | Complete | Source review & [`PairingCodeServiceTest.php`](../tests/Feature/Tenancy/PairingCodeServiceTest.php) |
| Batch Ingestion Pipeline | [batch-ingestion.md](features/batch-ingestion.md) | Complete | Complete | Source review & [`IngestBatchTest.php`](../tests/Feature/Ingestion/Api/IngestBatchTest.php) |
| Crash Recovery & Worker Fencing | [crash-recovery-and-fencing.md](features/crash-recovery-and-fencing.md) | Complete | Complete | Source review & [`RecoverIngestBatchesCommand.php`](../app/Console/Commands/RecoverIngestBatchesCommand.php) |
| Warehouse, Dimensions & Aggregates | [warehouse-and-aggregates.md](features/warehouse-and-aggregates.md) | Complete | Complete | Source review & [`BranchDayRebuilderTest.php`](../tests/Feature/Aggregation/BranchDayRebuilderTest.php) |
| Metrics Query Engine & KPI Catalog | [metrics-query-and-kpis.md](features/metrics-query-and-kpis.md) | Complete | Complete | Source review & [`MetricsQueryTest.php`](../tests/Feature/Metrics/MetricsQueryTest.php) |
| Production Analytics Reports | [analytics-reports.md](features/analytics-reports.md) | Complete | Complete | Source review & [`ReportPagesTest.php`](../tests/Feature/App/ReportPagesTest.php) |
| Targets Planning & Branch Ranking | [targets-and-ranking.md](features/targets-and-ranking.md) | Complete | Complete | Source review & [`BranchRankingReportTest.php`](../tests/Feature/Reports/BranchRanking/BranchRankingReportTest.php) |
| Tenancy & Access Control | [tenancy-and-access-control.md](features/tenancy-and-access-control.md) | Complete | Complete | Source review & [`OrganizationScopeTest.php`](../tests/Feature/Tenancy/OrganizationScopeTest.php) |
| AI Restaurant Analyst Assistant | [ai-assistant.md](features/ai-assistant.md) | Complete | Complete | Source review & [`BufferedChatCompletionsGatewayTest.php`](../tests/Feature/Ai/BufferedChatCompletionsGatewayTest.php) |
| Menu Engineering & Intelligence | [menu-engineering-and-roles.md](features/menu-engineering-and-roles.md) | Complete | Complete | Source review & [`BuildMenuIntelligenceTest.php`](../tests/Feature/Menu/Intelligence/BuildMenuIntelligenceTest.php) |

---

## Open Questions

1. **Production Cloud Provider:** The repository does not define the live hosting environment (e.g. AWS ECS/EKS, Laravel Cloud, or dedicated Forge servers). (Blocks: Finalizing deployment automation; Ask: Infrastructure Lead)
2. **Historical Data Archive Policy:** Warehouse order rows (`wh_orders`) grow continuously; confirm whether cold order data older than 3 years should be archived to cold S3 storage or partitioned. (Blocks: Long-term database retention policy; Ask: Data Architect)
3. **Hardware Barcode & Kitchen Printer Protocols:** The paired POS `larament` manages local printers; confirm whether the central platform should ever issue remote print commands via the heartbeat channel. (Blocks: Future POS command extension; Ask: Product Owner)
