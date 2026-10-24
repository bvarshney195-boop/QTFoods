# Recipe, Production Yield & Loss — Implementation and Operations Handoff

Delivery date: 2026-10-16
Release state: implemented and automated verification in progress; authorised UAT, merge and deployment have **not** been performed.

## Delivery summary

The existing product master, manufacturing planning, production execution, quality, packing, traceability and batch-costing modules now share one generic recipe/yield contract. The PDF's 100 kg flour example is treated only as an illustration: no example quantity, percentage, yield or product identity is hardcoded.

Implemented capabilities include:

- configurable recipe bases and `WEIGHT_PIECE`, `UNIT_CONVERSION`, `PERCENT_YIELD` and backward-compatible `LEGACY_YIELD` models;
- scalable, fixed, per-unit and conditional ingredients, multi-flour baker's percentages and packaging components;
- deterministic theoretical input, complete-unit, residual, formed-unit, expected-good and variance calculations;
- route-stage measurement templates with revision snapshots and measured/estimated provenance;
- planned, reserved, issued, additionally issued, returned, wasted, rework-in, rework-out and net-consumed material reconciliation;
- distinct process loss, handling waste, rejected output, quality hold, rework, overweight giveaway and unexplained variance classifications;
- target weight, physical samples, packing configuration and immutable batch/lot genealogy;
- original-plan and actual-basis-normalised material/cost variance, historical cost filters and guarded full-unit cost;
- append-only, independently approved closed-batch corrections;
- source-lot → production batch → finished lot → actual dispatch traceability.

The requirement-level status and component mapping are in `docs/RECIPE_PRODUCTION_LOSS_TRACEABILITY.md`. Test inputs and observed outputs are in `docs/RECIPE_PRODUCTION_LOSS_ACCEPTANCE_EVIDENCE.md`.

## Calculation contract

### Recipe and ingredient scaling

Let `R` be the approved recipe-basis quantity and `B` the requested batch-basis quantity.

`scaling factor = B / R`

- `SCALABLE`: approved component quantity × scaling factor, unless baker's percentage is supplied.
- Baker's percentage: requested batch basis × percentage / 100. Multiple basis flours therefore share the same approved total basis.
- `FIXED`: approved component quantity, unchanged by batch scale.
- `PER_UNIT`: quantity per unit × planned output units.
- `CONDITIONAL`: zero unless its normalised condition code is active; when active it scales like a scalable component.
- Component waste is grossed up only when `loss_included_in_standard=false`.
- Packaging is reconciled like any other component but does not contribute to food input mass.

`theoretical input mass = Σ(calculated food-component quantity × approved mass-conversion factor) + measured mass additions`

Preview additions must already use the recipe-basis UOM. Component conversion supports same-UOM, direct item conversion and the inverse of a direct item conversion.

### Output models

For `WEIGHT_PIECE`:

- `theoretical complete units = floor(theoretical input mass / standard pre-process piece weight)`
- `theoretical residual = theoretical input mass − theoretical complete units × piece weight`
- `usable mass after handling = theoretical input mass − applicable approved handling loss`
- `expected formed units = floor(usable mass after handling / standard pre-process piece weight)`
- `expected good before rounding = expected formed units × (1 − rejection allowance / 100)`
- `expected good units = approved rounding policy(expected good before rounding)`

For `UNIT_CONVERSION`, theoretical units are requested basis × approved conversion rate. Applicable handling and rejection rules then apply.

For `PERCENT_YIELD`, theoretical output is normalised input mass × optional conversion rate and formed output applies the approved yield percentage. The rejection rule then applies.

`LEGACY_YIELD` preserves existing plans by using planned output, or scaled approved recipe output when a planned value is unavailable.

Normal transformation/moisture loss is reported independently. It is never deducted from piece count a second time, and handling loss is not reapplied when it is marked as already included in the standard.

### Actual output and variance

- First-pass good is the sum of direct good-output events.
- Recovered rework is added only after an approved recovered disposition.
- Final good = first-pass good + recovered rework + approved output correction overlays.
- QC-approved good is exposed only after the batch passes its quality gates and release.
- `output variance = actual final good − expected good`
- `output variance % = output variance / expected good × 100`

When expected good is zero, percentage is null and the response carries `ZERO_EXPECTED_OUTPUT`.

### Material normalisation

Original planned quantities never change. For like-for-like efficiency reporting:

- actual basis is the sum of net-consumed approved basis components converted to the recipe-basis UOM;
- scalable and active conditional standards use actual basis / planned basis;
- fixed standards remain fixed;
- per-unit standards use final good units when available;
- `original-plan variance = adjusted net consumed − original planned quantity`;
- `efficiency variance = adjusted net consumed − normalised standard quantity`.

The API reports `NO_BASIS_COMPONENT`, `MISSING_BASIS_CONVERSION` or `ZERO_PLANNED_BASIS` instead of inventing a normalisation factor. In those cases the original standard remains visible.

### Precision and rounding

- BCMath internal calculations use 12 decimal places.
- Persisted and API quantities use 6 decimal places unless a higher-scale factor is required.
- Scaling factors and per-unit/conversion rates use 9 persisted/API decimal places.
- Weight-piece theoretical and formed counts always floor.
- Expected-good rounding is explicit: `DOWN`, `UP`, `HALF_UP` or `NONE`.
- Negative quantities are rejected; percentage inputs must be between 0 and 100; required divisors/rates must be greater than zero.
- A zero denominator is never coerced to zero percent; it produces a null percentage plus an exception code.

## Backend implementation

### New focused services

- `RecipeYieldCalculator`: pure reusable decimal arithmetic.
- `RecipeYieldService`: scoped recipe loading, UOM normalisation, preview and immutable standard snapshots.
- `ManufacturingMeasurementService`: template governance, readings and dimension-specific balance.
- `ManufacturingMaterialReconciliationService`: actual material categories, actual-basis normalisation and dual variance.
- `ManufacturingAdjustmentService`: request/review segregation and append-only correction effects.

### Existing services extended

- `ProductMasterService` / `ProductMasterQuery`: recipe fields, validations, preview data and referenced-revision immutability.
- `ManufacturingPlanningService`: generic calculated requirements flow into MRP and production-order snapshots.
- `ManufacturingExecutionService` / `ManufacturingExecutionQuery`: material postings, WIP dispositions, output classifications, adjustment overlays and UI aggregates.
- `ManufacturingQualityService`: hold/release gates and packing evidence.
- `ManufacturingTraceCostService`: cost categories, normalised variance, full-cost guard, history filters and dispatch-aware trace.

All stock-changing commands continue through the existing stock-posting service. WIP waste/rework classification does not post stock again because its source material was already issued out of raw inventory.

## Migration inventory

One additive migration was added: `BackEnd/database/migrations/2026_10_16_000044_add_recipe_yield_loss_framework.php`.

| Area | Migration effect |
|---|---|
| Recipe header | Adds basis/UOM, calculation model, piece and target weights, pieces/pack, handling/transformation/rejection rules, output rounding and conversion rate. Existing rows are backfilled from approved output quantity/UOM and retain `LEGACY_YIELD`. |
| Recipe components | Adds scaling method, baker's percentage, basis flag, condition, per-unit quantity, included-loss flag and packaging flag. |
| Routes and orders | Adds route revision; recipe/route/standard snapshots, shift, scale, theoretical and expected output fields on production orders. |
| Material reconciliation | Adds original/reserved/additional/returned/wasted/rework/net/normalised/variance fields; changes issue reservation uniqueness to an index for partial issues. |
| Stage measurement | Creates `stage_measurement_templates`, `stage_measurement_template_lines` and `production_stage_measurements`; adds the approved template snapshot to order stages. |
| Material ledger | Creates append-only `production_material_postings`; extends genealogy to reference authoritative material postings. PostgreSQL checks require one stock movement for inventory-changing postings and none for WIP-only waste/rework classifications. |
| Corrections | Creates `production_batch_adjustments` and immutable `production_batch_adjustment_effects`. |
| Output and packing | Adds output category/dimension/source/weight evidence and packing target, sample average, individual samples and configuration. |
| Costing | Adds expected/actual output, loss/rework/giveaway/packaging metrics, normalised material variance and guarded per-good-unit costs. |

The migration uses scoped foreign keys, check constraints, uniqueness rules and legacy backfills. No existing endpoint or table is replaced.

## API changes

All paths below are under `/api/v1` and retain authentication, device, company/plant context, screen and action gates.

### New command endpoints

| Endpoint | Purpose | Permission gate |
|---|---|---|
| `POST /manufacturing/recipes/{id}/preview` | Calculate requirements and output without posting inventory | `MD-REC` update |
| `POST /manufacturing/orders/{id}/material-issues` | Partial planned or reasoned additional issue | `PRO-ORDER` issue |
| `POST /manufacturing/orders/{id}/material-returns` | Return against the original material posting into quarantine | `PRO-ORDER` issue |
| `POST /manufacturing/orders/{id}/material-dispositions` | Record WIP `WASTE`, `REWORK_OUT` or `REWORK_IN` | `PRO-ORDER` issue |
| `POST /manufacturing/material-returns/{postingId}/release` | Independently release inspected return to reusable stock | `QC-SAFE` release |
| `POST /manufacturing/stages/{stageId}/measurement-templates` | Create a recipe/route/stage template revision | `MD-REC` update |
| `POST /manufacturing/measurement-templates/{templateId}/approve` | Independently approve a template | `MD-REC` lifecycle |
| `POST /manufacturing/stages/{stageId}/measurements` | Record immutable measured/estimated stage values | `PRO-STAGE` complete |
| `POST /manufacturing/orders/{id}/adjustments` | Request a completed-batch correction | `PRO-ORDER` complete |
| `POST /manufacturing/batch-adjustments/{id}/review` | Independently approve or reject a correction | `QC-SAFE` release |

### Extended responses/commands

- Recipe create/update/detail now carry the full calculation standard and component scaling metadata.
- Production order detail includes standard snapshots, output metrics, material reconciliation, measurement evidence and adjustment journal.
- Output recording accepts category, disposition, source, dimension and weight data, with contextual reason/disposition requirements.
- Packing run create/detail preserves target weight, direct samples, calculated average and configuration.
- Batch costing accepts `all_source_costs_included` and returns loss/rework/giveaway/packaging metrics plus both material variance views.
- `GET /costing/batches` supports recipe version, output SKU, shift, operator and date filters in addition to plant context.
- Lot trace includes dispatched shipment records reached through the actual allocated finished lot.

## React changes

| Screen | User-visible change |
|---|---|
| `MD-REC` | Recipe model/basis, weights, loss flags, rounding, scaling method, baker's %, basis/packaging flags, condition/per-unit inputs and interactive preview. |
| `PRO-ORDER` | Planned/additional issue, quarantined return, WIP waste/rework disposition, complete material reconciliation, expected/actual output and closed-batch correction journal. |
| `PRO-STAGE` | Template definition/approval, measured or estimated readings, method/source metadata and mass/count/volume/pack balance. |
| `PRO-LOSS` | First-pass/recovered/final good, expected variance and distinct loss/rework/giveaway categories. |
| `QC-LAB` / `QC-SAFE` | Existing lab, deviation, hold and release flows enforce the final-saleable gate and independent approvals. |
| `PACK-RUN` / `FG-LOT` | Target/average weight, individual samples, packing configuration, batch link and input-lot genealogy. |
| `COST-BATCH` | Original vs normalised vs actual material cost, classified losses, per-good-unit costs, completeness warning and historical filters. |
| `TRACE-CASE` | Raw-to-finished genealogy and dispatch/recall evidence. |

## Role-wise operating instructions

### Recipe/master-data author

1. In `MD-REC`, create a new recipe revision and select the calculation model appropriate to the process.
2. Set a real basis quantity/UOM; for baker's percentage recipes, mark every approved flour/basis component and enter percentages against the total basis.
3. Select each component's scaling method. Mark packaging and per-unit quantities explicitly.
4. Mark a loss as included in standard only when the approved input requirement already incorporates it.
5. Configure piece/target weights, handling, transformation, rejection and rounding only where the chosen process model uses them.
6. Run Preview with a non-standard batch size, planned units, active conditions and any measured additions before lifecycle approval.
7. Never edit a referenced revision. Create and approve a new revision for future batches.

### Planner

1. Schedule the finished SKU and quantity using the approved recipe and route versions.
2. Review calculated component requirements and packaging demand before release.
3. Resolve missing UOM conversions or basis exceptions in master data; do not override them with a 100 kg assumption.

### Production operator

1. Create and release the production order; verify the snapshotted recipe/route revisions and expected output.
2. Post partial planned issues as consumed. Use Additional only with a required reason and source lot/position.
3. Record unused material as a return to quarantine. Do not treat it as immediately reusable.
4. Classify line-side material as waste or rework with reason and disposition; do not create manual duplicate stock movements.
5. Start each stage, enter required readings with physical dimension, source and method, and identify estimates explicitly.
6. Record good, loss, rejection, hold-related and rework output in their correct categories. Resolve rework before completion.
7. Complete only after the displayed mass/yield reconciliation is understood and explained.

### QA/QC reviewer

1. Approve measurement templates independently from their author.
2. Review lab results, deviations, holds, returns and rework evidence. A return recorder cannot release their own return.
3. Release quality-held production only after every blocking sample/deviation/hold is resolved.
4. Approve reusable returned stock into the specified position only after lot, owner and quality checks.
5. Review completed-batch adjustment requests independently; reject unsupported or reconciliation-forcing corrections.

### Packing/warehouse operator

1. Select only a quality-released batch and effective approved artwork.
2. Record packed quantity, finished lot, manufacture/expiry dates, destination, direct weight samples and packing configuration.
3. Complete the run once. Completion atomically receives finished stock and creates genealogy; do not add a second manual receipt.

### Cost/finance reviewer

1. Calculate a cost snapshot after the batch is complete and material/output postings are reconciled.
2. Enter component unit cost and applicable labour/overhead rates.
3. Set `all_source_costs_included=true` only after all applicable source costs have been supplied; otherwise use the net material cost and leave full manufacturing cost suppressed.
4. Compare original-plan variance with like-for-like efficiency variance, including packaging and giveaway cost.
5. Use recipe/SKU/batch/shift/plant/date/operator filters for history. Correct closed costs through an independently approved cost adjustment, never by editing the snapshot.

### Administrator/auditor

1. Assign the existing screen/action permissions according to segregation-of-duties policy and maintain correct plant scope.
2. Review audit and outbox events, adjustment effects, material postings and stock-movement identifiers during reconciliation.
3. Use lot trace to follow source material to finished lot and actual dispatch, and use the existing recall workflow when containment is required.
4. Never delete or rewrite a completed production posting to force a balance.

## Rollback and recovery

### Before go-live or while the new tables contain no business data

1. Take and verify a database backup.
2. Stop writers and record the current migration status.
3. Roll back exactly migration `000044` with `php artisan migrate:rollback --step=1 --force`.
4. Confirm the application version is also rolled back and run the existing smoke/regression suite.
5. To recover forward, deploy the implementation again and run `php artisan migrate --force`.

The rollback/forward sequence was exercised on the disposable PostgreSQL verification database.

### After business use starts

Do **not** run the destructive `down()` migration: it removes the new evidence, posting and adjustment tables/columns and is intended only for pre-production rollback. Keep the database online, stop affected writes if necessary, take a fresh backup, reproduce on a restored copy, and deliver a forward-only corrective migration. Restore from the verified backup only under the authorised disaster-recovery procedure. Historical production postings and approved effects must remain append-only.

## Unresolved limitations and release gates

1. Authorised business UAT, production reconciliation sign-off, merge and deployment remain pending.
2. Legacy order snapshots without an identified basis component report `NO_BASIS_COMPONENT` and retain original-plan normalisation until a valid new/revised recipe is used.
3. `all_source_costs_included` is an authorised declaration; the service does not independently discover omitted utilities, depreciation or other external costs.
4. Component WIP rework transfer is recorded within the source batch lineage. A separately governed destination-batch transfer is still required if QT Foods needs cross-batch material rework.
5. UOM normalisation supports same-UOM, direct and reverse item conversions, not a multi-hop conversion graph.
6. The migration's `down()` path deletes framework data. It is safe only before business use or against a verified disposable/restored database; after go-live, use backup plus a forward fix.
7. Post-production expiry, unsold stock and customer returns intentionally remain in their existing inventory/returns workflows and are not folded into manufacturing yield.

## Release checklist

- [x] Requirement traceability completed.
- [x] Additive migration implemented and rollback/forward rehearsed on disposable PostgreSQL.
- [x] Backend calculation, API, integration, permission and PostgreSQL tests implemented.
- [x] Frontend interaction tests and production build completed.
- [x] Stock/WIP/FG ledger effects and trace chain reconciled in automated tests.
- [ ] Authorised role-wise UAT completed and signed.
- [ ] Deployment backup/recovery evidence approved.
- [ ] Merge/release approval granted.
- [ ] Production deployment completed.

No merge or deployment should occur while any unchecked gate remains.
