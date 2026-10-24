import type { ScreenDefinition } from '../types/screen';

export const screenRegistry: ScreenDefinition[] = [
  {
    "code": "ACC-LOGIN",
    "title": "Secure Sign In",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Verified named-user authentication, recovery links, TOTP challenge, and revocable device session."
  },
  {
    "code": "ACC-CTX",
    "title": "Company & Plant Context",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Choose only the authorised company and plant context before protected work."
  },
  {
    "code": "WRK-HOME",
    "title": "Operations Control Room",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Role-aware work queue, approvals, exceptions, freshness and drill-through."
  },
  {
    "code": "ADM-ORG",
    "title": "Organisation Setup",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Lifecycle-safe company and plant administration with scoped, versioned and audited writes."
  },
  {
    "code": "ADM-LOC",
    "title": "Locations & Hierarchy",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Plant-scoped warehouse hierarchy with parent, type, stock and lifecycle safeguards."
  },
  {
    "code": "ADM-USER",
    "title": "Users & Assignments",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Invitation-first identity, verification/MFA state, device controls, and effective-dated plant roles."
  },
  {
    "code": "ADM-ROLE",
    "title": "Roles & Permissions",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Protected system authority and company-scoped custom roles, permissions and matrices."
  },
  {
    "code": "ADM-RULE",
    "title": "Approval Rules",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Versioned plant approval policies, authority bands, delegated authority and SLA escalation."
  },
  {
    "code": "ADM-AUD",
    "title": "Audit & Evidence",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Search audit events by request, actor, scope, entity, outcome and evidence."
  },
  {
    "code": "ADM-INT",
    "title": "Integrations",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Integration endpoints, outbox events, acknowledgements, retries and quarantine."
  },
  {
    "code": "ADM-HELP",
    "title": "Help & Support",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Role-relevant guidance and scoped, versioned requester/support-manager case history."
  },
  {
    "code": "BI-REP",
    "title": "Controlled Reports",
    "area": "Foundation / Admin",
    "batch": "B04",
    "description": "Immutable scoped read models with explicit cutoff, freshness, checksums, totals and deterministic exports."
  },
  {
    "code": "MD-PARTY",
    "title": "Parties",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Company-scoped party identities, roles, addresses, contacts, tax registrations, commercial terms, and controlled lifecycle."
  },
  {
    "code": "MD-BRAND",
    "title": "Brands & Agreements",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Company brands and effective-dated party agreements with governed lifecycle and audit evidence."
  },
  {
    "code": "MD-ITEM",
    "title": "Items & UOM",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Catalog items, lot policy, base units, and explicit UOM conversions above stock-bearing SKUs."
  },
  {
    "code": "MD-SKU",
    "title": "SKUs & Packs",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Stock-bearing SKUs, barcodes, selling units, packs, and inventory-safe lifecycle controls."
  },
  {
    "code": "PUR-REQ",
    "title": "Purchase Requisitions",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Purchase Requisitions workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "PUR-RFQ",
    "title": "RFQ & Comparison",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "RFQ & Comparison workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "PUR-PO",
    "title": "Purchase Orders",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Purchase Orders workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "INB-GATE",
    "title": "Gate Entry",
    "area": "Master / Procurement / Stock",
    "batch": "P1 Procure-to-pay",
    "description": "Live supplier-arrival register linked to issued purchase orders, plant scope, concurrency, cancellation evidence and audit."
  },
  {
    "code": "INB-GRN",
    "title": "GRN / Receipt",
    "area": "Master / Procurement / Stock",
    "batch": "P1 Procure-to-pay",
    "description": "Partial goods receipts with purchase lots, Quality Hold stock posting, remaining-quantity control and incoming-QC handoff."
  },
  {
    "code": "QC-IN",
    "title": "Incoming QC",
    "area": "Master / Procurement / Stock",
    "batch": "P1 Procure-to-pay",
    "description": "Incoming lot inspection that atomically splits held quantities into released and rejected inventory movements."
  },
  {
    "code": "INB-RETURN",
    "title": "Supplier Returns",
    "area": "Master / Procurement / Stock",
    "batch": "P1 Procure-to-pay",
    "description": "Controlled supplier returns limited to QC-rejected on-hand lots with immutable stock-issue evidence."
  },
  {
    "code": "INV-STK",
    "title": "Stock & Lots",
    "area": "Master / Procurement / Stock",
    "batch": "B11",
    "description": "Live owner, lot, expiry, quality, availability and reservation registers with controlled workplace updates."
  },
  {
    "code": "INV-ISS",
    "title": "Issue / Return",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Issue / Return workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "INV-TRF",
    "title": "Stock Transfers",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Stock Transfers workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "INV-COUNT",
    "title": "Stock Counts",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Stock Counts workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "INV-EXP",
    "title": "Expiry / Disposal",
    "area": "Master / Procurement / Stock",
    "batch": "B05-B07",
    "description": "Expiry / Disposal workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "MD-REC",
    "title": "Recipes / BOM",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Versioned recipes with effective yields, active output/component SKUs, and ordered BOM lines."
  },
  {
    "code": "MD-ROUTE",
    "title": "Production Routes",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Item production routes with ordered work-centre operations and governed standard times."
  },
  {
    "code": "MD-SPEC",
    "title": "Quality Standards",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Effective item/SKU standards with typed parameters, test methods, and acceptance limits."
  },
  {
    "code": "PLAN-DEM",
    "title": "Demand Planning",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Time-phased manufactured-SKU demand with governed release into material planning."
  },
  {
    "code": "PLAN-MRP",
    "title": "MRP",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Effective-recipe explosion with inventory netting and immutable material-shortage snapshots."
  },
  {
    "code": "PLAN-SCH",
    "title": "Production Schedule",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Finite work-centre capacity scheduling with atomic FEFO material reservation and release."
  },
  {
    "code": "PRO-ORDER",
    "title": "Production Orders",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Schedule-to-batch release with FEFO material issue, route snapshots, controlled completion and audit."
  },
  {
    "code": "PRO-STAGE",
    "title": "Stage Execution",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Sequential route-stage start and completion with actual work-centre time and immutable events."
  },
  {
    "code": "PRO-LOSS",
    "title": "Yield / Loss / Rework",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Full planned-output accounting across good yield, process loss and resolved rework."
  },
  {
    "code": "QC-LAB",
    "title": "Lab & Samples",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Versioned specification sampling, parameter evaluation and automatic deviation creation."
  },
  {
    "code": "QC-SAFE",
    "title": "Safety / Deviations",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Food-safety holds, corrective-action deviations and gated batch quality release."
  },
  {
    "code": "PACK-ART",
    "title": "Artwork & Coding",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Controlled label revisions, effective dates, barcodes and batch-code templates."
  },
  {
    "code": "PACK-RUN",
    "title": "Packing Run",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Quality-released output packing with artwork validation, finished-stock receipt and genealogy."
  },
  {
    "code": "FG-LOT",
    "title": "Finished Goods",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Finished-lot stock, coding, production provenance and upstream input-lot visibility."
  },
  {
    "code": "TRACE-CASE",
    "title": "Trace / Recall",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Bidirectional lot genealogy, customer exposure, recall containment and closure evidence."
  },
  {
    "code": "COST-BATCH",
    "title": "Batch Cost",
    "area": "Manufacturing / Quality",
    "batch": "B08-B12",
    "description": "Immutable batch material, conversion, yield, unit-cost and plan-to-actual variance snapshots."
  },
  {
    "code": "CRM-LEAD",
    "title": "Enquiries & Leads",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Enquiries & Leads workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "CRM-PRICE",
    "title": "Pricing & Credit",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Pricing & Credit workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "CRM-ORDER",
    "title": "Sales Orders",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Sales Orders workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "CON-WORK",
    "title": "Third-Party Work",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Third-Party Work workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "DSP-PICK",
    "title": "Allocation & Picking",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Allocation & Picking workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "DSP-LOAD",
    "title": "Load & Dispatch",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Load & Dispatch workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "DSP-POD",
    "title": "Delivery / POD",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Delivery / POD workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "RET-CASE",
    "title": "Returns & Claims",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Returns & Claims workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "RET-UNSOLD",
    "title": "Unsold Sales Return & Loss",
    "area": "Sales / Dispatch",
    "batch": "B16",
    "description": "Sales-initiated return of unsold market stock, physical return receipt, Quality disposition and controlled loss recognition."
  },
  {
    "code": "FIN-AR",
    "title": "Receivables",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Receivables workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "BI-PROFIT",
    "title": "Profitability",
    "area": "Sales / Dispatch",
    "batch": "B13-B17",
    "description": "Profitability workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "FIN-AP",
    "title": "Payables",
    "area": "Finance / Support",
    "batch": "P1 Procure-to-pay",
    "description": "Three-way matched supplier invoices, calculated taxes, maker-checker payment proposals, settlement allocation and bank reconciliation."
  },
  {
    "code": "FIN-EXP",
    "title": "Expenses",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "Expenses workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "FIN-GL",
    "title": "Ledger & Period Close",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "Ledger & Period Close workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "COST-OH",
    "title": "Overheads",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "Overheads workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "ASSET-REG",
    "title": "Assets",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "Assets workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "HR-PAY",
    "title": "People & Payroll",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "People & Payroll workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "ENG-MNT",
    "title": "Maintenance",
    "area": "Finance / Support",
    "batch": "B18-B21",
    "description": "Maintenance workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "SCALE-PLANT",
    "title": "Multi-Plant Control",
    "area": "Scale",
    "batch": "B22-B24",
    "description": "Multi-Plant Control workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "PORTAL-EXT",
    "title": "Partner Portal",
    "area": "Scale",
    "batch": "B22-B24",
    "description": "Partner-isolated workspace with explicit entitlements and no implicit internal approval."
  },
  {
    "code": "OPT-PLAN",
    "title": "Optimisation",
    "area": "Scale",
    "batch": "B22-B24",
    "description": "Forecasts and recommendations with versioned inputs, limitations and human review."
  },
  {
    "code": "FIN-SIM",
    "title": "Simulation workspace",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Completely isolated training/simulation data with zero live posting."
  },
  {
    "code": "FIN-ADJ",
    "title": "Finance adjustments",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Finance Adjustments workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "FIN-LEGACY",
    "title": "Historical import centre",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Staged historical bill import with mapping, validation and reconciliation."
  },
  {
    "code": "FIN-ARCH",
    "title": "Bill archive",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Bill Archive workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "FIN-OPEN",
    "title": "Opening reconciliation",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Opening Reconciliation workflow with server-authoritative scope, state, version, approval and audit."
  },
  {
    "code": "FIN-SUP",
    "title": "Audit/support",
    "area": "Finance Supplement",
    "batch": "Supplement",
    "description": "Finance Audit / Support workflow with server-authoritative scope, state, version, approval and audit."
  }
];
