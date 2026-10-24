# Recipe, Production Yield & Loss — Requirement Traceability

Baseline date: 2026-10-16
Delivery date: 2026-10-16
Functional sources: `QT_Foods_Recipe_Production_Loss_Framework.pdf` (5 pages), the earlier Generic Recipe & Production Loss prompt, and the Mandatory Implementation Addendum.
Baseline legend: **EXISTING**, **PARTIAL**, **MISSING**, **REQUIRES VERIFICATION**. These values describe the repository before this implementation.
Delivery legend: **IMPLEMENTED**, **VERIFIED**, **PENDING UAT**.

The PDF's 100 kg illustration is not a default or constant. All recipe, batch, percentage, piece-weight, conversion, loss, and yield values below are configuration or transaction data.

| ID | Requirement | Baseline | Database field/table mapping | Backend/service mapping | API mapping | React screen mapping | Automated evidence | Delivery |
|---|---|---|---|---|---|---|---|---|
| R-01 | Approved recipe per finished SKU; identity, revision and effective dates | PARTIAL | `recipes`, `recipe_components`, `production_orders.recipe_revision_snapshot` | `ProductMasterService`; `RecipeYieldService::standardSnapshot` | Recipe aggregate and lifecycle endpoints | `MD-REC` | `ProductMasterDataEndpointTest::test_recipe_commands_validate_bom_references_and_lifecycle` | VERIFIED |
| R-02 | Explicit recipe basis quantity/UOM; never assume 100 kg | MISSING | `recipes.basis_quantity`, `basis_uom_code` | `RecipeYieldCalculator::calculate` | `POST /manufacturing/recipes/{id}/preview` | `MD-REC` basis fields and preview | AT-01 | VERIFIED |
| R-03 | Multiple flours use total approved basis for baker's percentages | MISSING | `recipe_components.is_basis_component`, `bakers_percentage` | Basis aggregation in calculator and validation in `ProductMasterService` | Recipe write/preview | `MD-REC` component basis and baker's-% controls | AT-02 | VERIFIED |
| R-04 | Scalable, fixed, per-unit and conditional components | MISSING | `scaling_method`, `condition_code`, `quantity_per_unit` | `RecipeYieldCalculator`; MRP consumes calculated requirements | Recipe write/preview; planning/MRP endpoints | `MD-REC` method-specific component controls | AT-03 | VERIFIED |
| R-05 | Do not gross up component loss twice | PARTIAL | `recipe_components.loss_included_in_standard`, existing `waste_percent` | Explicit loss-gross-up rule in calculator and planning | Recipe write/preview | `MD-REC` included-in-standard control and preview flag | AT-05 | VERIFIED |
| R-06 | Theoretical input mass includes measured additions | MISSING | Order calculation snapshot; `production_stage_measurements` | `RecipeYieldCalculator`; `ManufacturingMeasurementService` | Recipe preview; stage measurement endpoint | `MD-REC` preview; `PRO-STAGE` readings | AT-01, AT-06 | VERIFIED |
| R-07 | Weight-piece theoretical complete units and residual | MISSING | `standard_piece_weight`; order theoretical snapshot fields | `RecipeYieldCalculator::output` | Recipe preview and order detail | `MD-REC`, `PRO-LOSS` | AT-04 | VERIFIED |
| R-08 | Expected formed units after approved handling loss | MISSING | `handling_loss_percent`, `handling_loss_in_standard`, `expected_formed_units` | Model-specific calculator path | Recipe preview and order detail | `MD-REC`, `PRO-LOSS` | AT-04, AT-05 | VERIFIED |
| R-09 | Expected good after rejection rule and explicit rounding | PARTIAL | `rejection_allowance_percent`, `output_rounding_mode`, `expected_good_units` | Deterministic `DOWN`, `UP`, `HALF_UP`, `NONE` policy | Recipe preview and order detail | `MD-REC`, `PRO-LOSS` | AT-04 | VERIFIED |
| R-10 | Alternative approved conversion/yield models | PARTIAL | `calculation_model`, `unit_conversion_rate`; item UOM conversions | `WEIGHT_PIECE`, `UNIT_CONVERSION`, `PERCENT_YIELD`, legacy strategy | Recipe write/preview | `MD-REC` model selector | AT-01 | VERIFIED |
| R-11 | Moisture/transformation is physical mass loss and not a second piece rejection | MISSING | `transformation_loss_percent`, `transformation_loss_in_standard`; stage kind `NORMAL_TRANSFORMATION_LOSS` | Calculator reports transformation separately; measurement balance classifies it | Recipe preview; stage measurements | `MD-REC`, `PRO-STAGE` | AT-05, AT-06 | VERIFIED |
| R-12 | First-pass good, recovered rework and final good | EXISTING | `production_output_events`; approved adjustment effects | `ManufacturingExecutionService::outputMetrics` | Order/output/rework endpoints | `PRO-LOSS` | AT-09 and full manufacturing chain | VERIFIED |
| R-13 | Output variance and guarded percentage vs expected good | MISSING | Order expected snapshot; batch-cost variance fields | `RecipeYieldCalculator::outputVariance`; execution metrics | Order/loss/cost detail | `PRO-ORDER`, `PRO-LOSS`, `COST-BATCH` | AT-04, AT-07 | VERIFIED |
| R-14 | Actual material variance vs original and like-for-like standard | PARTIAL | Original, normalised, net and variance columns on order/cost material lines | `ManufacturingMaterialReconciliationService` | Order and cost detail | `PRO-ORDER`, `COST-BATCH` | AT-08, AT-12 | VERIFIED |
| R-15 | Deterministic precision, rounding, zero-denominator exceptions | PARTIAL | `decimal(20,6)` persistence; exception-code fields | BCMath at 12 internal decimals; explicit output rounding and `ZERO_EXPECTED_OUTPUT` | Preview/order/cost payloads | Exception displayed instead of false percent | AT-04, AT-12 | VERIFIED |
| R-16 | Measurement templates linked to recipe, route revision and stage | MISSING | `stage_measurement_templates`, lines; stage template snapshot and revisions | `ManufacturingMeasurementService` | Create/approve template endpoints | `PRO-STAGE` template builder/governance | AT-06, AT-14 | VERIFIED |
| R-17 | Incoming, additions, output, loss, rejection, hold, rework, residual and unexplained with UOM | MISSING | Typed `production_stage_measurements` | Measurement recording and balance aggregation | `POST /manufacturing/stages/{id}/measurements` | `PRO-STAGE` reading form/history/balance | AT-06 | VERIFIED |
| R-18 | Operator/time/source/method; measured separate from estimated | PARTIAL | Measurement actor, timestamp, source, method and `value_source` | Immutable measurement command/query | Stage detail/measurement endpoint | `PRO-STAGE` badges and history | AT-06 | VERIFIED |
| R-19 | Mass, count, volume and pack dimensions remain explicit | PARTIAL | `quantity_dimension` on template, measurement and output rows | Dimension validation and dimension-specific aggregation | Stage/output payloads | `PRO-STAGE`, `PRO-LOSS` dimension controls | AT-06, AT-07 | VERIFIED |
| R-20 | Normal transformation loss is not rejected saleable output | MISSING | Separate measurement and output categories | Category-specific balance/metric rules | Stage/output endpoints | `PRO-STAGE`, `PRO-LOSS` | AT-05, AT-09 | VERIFIED |
| R-21 | Planned, reserved, issued, additional, returned, wasted, reworked and net consumed | PARTIAL | Aggregate columns plus append-only `production_material_postings` | Issue, return, WIP disposition and reconciliation commands | Material issue/return/disposition endpoints | `PRO-ORDER` reconciliation and WIP disposition form | AT-08, AT-11 | VERIFIED |
| R-22 | Partial planned issues | MISSING | Multiple `production_material_issues`; reservation index replaces one-to-one uniqueness | Locked partial FEFO issue path | `POST /manufacturing/orders/{id}/material-issues` | `PRO-ORDER` partial/additional issue form | AT-08, AT-11 | VERIFIED |
| R-23 | Returns preserve lot/owner and need independent QC before reuse | MISSING | Return quarantine/release postings with parent, lot and owner | Return validation plus independent release | Material return and release endpoints | `PRO-ORDER` return and approval controls | AT-08, AT-09 | VERIFIED |
| R-24 | Packaging BOM and actual usage variance | PARTIAL | `is_packaging`; per-unit requirements; packaging cost variance fields | Calculator, reconciliation and costing classify packaging | Recipe/order/cost payloads | `MD-REC`, `PRO-ORDER`, `COST-BATCH` | AT-03, AT-10, AT-12 | VERIFIED |
| R-25 | Exactly one authoritative ledger effect per issue, return, waste and FG receipt | PARTIAL | Unique movement link for inventory-changing postings; one append-only WIP posting for waste/rework; FG receipt movement | `StockPostingService` plus WIP disposition ledger | Material and packing commands | Movement/posting references in `PRO-ORDER`, `PACK-RUN` | AT-08, AT-11 | VERIFIED |
| R-26 | Separate normal loss, handling waste, rejection, hold, rework, giveaway and unexplained | PARTIAL | Output `loss_category`; stage measurement kinds; quality holds | Category metrics and validation | Output/stage/quality endpoints | `PRO-LOSS`, `PRO-STAGE`, `QC-SAFE` | AT-06, AT-07, AT-09 | VERIFIED |
| R-27 | Appropriate reasons and dispositions are mandatory | PARTIAL | Reason/disposition fields on output, measurement, material and adjustment rows | Contextual validation rules | Relevant mutation endpoints | Context-sensitive required controls | AT-08, AT-09, AT-13 | VERIFIED |
| R-28 | Held stock never becomes saleable before authorised QC release | EXISTING | Production quality state, active holds, released FG position | `ManufacturingQualityService` gates release and packing receipt | QC release and packing completion | `QC-LAB`, `QC-SAFE`, `PACK-RUN` | AT-09 | VERIFIED |
| R-29 | Target weight, samples, quantity, pack configuration and batch link | PARTIAL | Recipe target; `packing_runs` target/average/samples/configuration/order link | Packing validation and average calculation | Packing create/detail | `PACK-RUN` | AT-07, AT-09 | VERIFIED |
| R-30 | Standard vs actual ingredient quantity and cost | PARTIAL | Order and batch-cost material snapshots | Reconciliation plus cost finalisation | Order/cost detail | `PRO-ORDER`, `COST-BATCH` | AT-12 | VERIFIED |
| R-31 | Normal/abnormal loss, rework value, giveaway cost, packaging variance | MISSING | Dedicated `batch_costs` fields | `ManufacturingTraceCostService::calculateBatchCost` | Batch-cost create/detail | `COST-BATCH` | AT-10, AT-12 | VERIFIED |
| R-32 | Net material cost/good; full manufacturing cost only with all sources | PARTIAL | Nullable full cost and explicit completeness declaration | Cost service suppresses full cost unless declared complete | Batch-cost create/detail | `COST-BATCH` | AT-12 | VERIFIED |
| R-33 | Expected vs actual and history by recipe, SKU, batch, shift, plant, date, operator | PARTIAL | Recipe/shift/operator/time snapshots; plant scope | Filtered cost query and output metrics | `GET /costing/batches` filters; batch search | `COST-BATCH` historical comparison controls | AT-07, AT-15 | VERIFIED |
| R-34 | Original-plan and actual-basis-normalised efficiency variance | MISSING | Original/normalised/actual variance fields | Dynamic basis normalisation by scaling method | Order/cost detail | `PRO-ORDER`, `COST-BATCH` | AT-08, AT-12 | VERIFIED |
| R-35 | Expiry, unsold stock and customer returns remain outside manufacturing yield | EXISTING | Separate inventory/expiry/return tables | Existing separate services | Existing separate endpoint families | Existing inventory and return screens | Full backend suite | VERIFIED |
| R-36 | Recipe, batch, QC and stock permissions | PARTIAL | Existing screen/action permissions retained | Existing scope and permission middleware; independent actor checks | Every mutation route is permission gated | Actions shown only when authorised | AT-13, AT-15 | VERIFIED |
| R-37 | Recipe edits cannot alter historical batch snapshots | PARTIAL | Immutable `recipe_standard_snapshot` and revision fields | Referenced recipe update guard | Recipe update/order detail | `MD-REC`, `PRO-ORDER` | AT-14 | VERIFIED |
| R-38 | Independent approval and audited correction/reversal for completed transactions | PARTIAL | Adjustment headers/effects, audit and outbox rows | `ManufacturingAdjustmentService` requester/reviewer segregation | Adjustment request/review endpoints | `PRO-ORDER` correction journal | AT-13 | VERIFIED |
| R-39 | Never silently delete/rewrite a historical production posting | PARTIAL | Append-only events/postings/effects; no posting delete route | Closed-batch effects overlay originals | Adjustment-only correction API | Correction guidance and immutable journal | AT-13, AT-14 | VERIFIED |
| R-40 | Raw lot → batch → finished lot → dispatch traceability | EXISTING | Material posting/issue, genealogy edge, FG lot, shipment line | Trace graph and recall traversal | Lot trace/recall plus dispatch endpoints | `FG-LOT`, `TRACE-CASE` | AT-16 | VERIFIED |
| R-41 | Extend the five connected existing workflows | EXISTING | Existing master/planning/execution/QC/cost tables extended | Existing services modified; no duplicate module | Existing endpoint families extended | Existing `MD-*`, `PLAN-*`, `PRO-*`, `QC-*`, `COST-BATCH` screens | Regression suites | VERIFIED |
| R-42 | Additive migrations, historical protection, rollback/recovery docs | MISSING | Migration `2026_10_16_000044_add_recipe_yield_loss_framework` | Backward-compatible legacy defaults and snapshots | No endpoint removed | Existing routes/screens extended | Clean PG migrate/seed and rollback/forward drill | VERIFIED |
| R-43 | Reproducible tests record input, expected, actual, status and location | MISSING | N/A | Backend and frontend automated tests | API assertions | UI interaction assertions | `RECIPE_PRODUCTION_LOSS_ACCEPTANCE_EVIDENCE.md` | VERIFIED |
| R-44 | No merge/deploy before tests, reconciliation and authorised UAT | REQUIRES VERIFICATION | N/A | Release governance gate | N/A | N/A | Automated tests passed; authorised UAT not performed | PENDING UAT |

## Acceptance-test index

| ID | Mandatory scenario | Primary automated location |
|---|---|---|
| AT-01 | Multiple recipe bases and non-100 kg batches | `RecipeYieldCalculatorTest::test_non_one_hundred_basis_multi_flour_and_all_scaling_methods`; recipe preview endpoint test |
| AT-02 | Multi-flour baker's percentages | Same calculator test |
| AT-03 | Fixed, scalable, per-unit and conditional ingredients | Same calculator test |
| AT-04 | Expected output and rounding | Weight-piece and rounding-policy calculator tests |
| AT-05 | No double-counting moisture/process loss | Weight-piece and included-loss calculator tests |
| AT-06 | Measured vs estimated stage losses | Stage-template/mass-balance endpoint test |
| AT-07 | Underweight and overweight output | Failed-lab/QC test and stage/output classification test |
| AT-08 | Partial issues and returns | Material ledger endpoint test |
| AT-09 | Rework, rejection and QC holds | Full chain, rejection-specific and quality-gate endpoint tests |
| AT-10 | Packaging reconciliation | Calculator per-unit packaging and cost correction/costing test |
| AT-11 | Stock and WIP movement integrity | Material ledger endpoint test and full packing chain |
| AT-12 | Standard vs actual costing | Full chain and closed-batch/cost test |
| AT-13 | Closed-batch correction and independent approval | Closed-batch correction endpoint test |
| AT-14 | Historical recipe-version immutability | Closed-batch correction endpoint test |
| AT-15 | Cross-role and cross-plant protection | Permission/scope tests in product and manufacturing suites |
| AT-16 | Source lot to finished good to dispatch | Full manufacturing/packing/dispatch/trace test |

Detailed inputs, expected results, observed results and commands are in `docs/RECIPE_PRODUCTION_LOSS_ACCEPTANCE_EVIDENCE.md`.
