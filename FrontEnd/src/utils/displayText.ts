const roleNames: Record<string, string> = {
  SALES_MANAGER: 'Sales Manager',
  OPERATIONS_MANAGER: 'Operations Manager',
  FINANCE_REVIEWER: 'Finance Manager',
  ERP_ADMIN: 'ERP Administrator',
  BI_ANALYST: 'BI Analyst',
  PARTNER_PORTAL: 'Partner User',
};

const screenNames: Record<string, string> = {
  'ACC-LOGIN': 'Sign in',
  'ACC-CTX': 'Choose workplace',
  'WRK-HOME': 'My work',
  'ADM-ORG': 'Companies & plants',
  'ADM-LOC': 'Locations',
  'ADM-USER': 'Users',
  'ADM-ROLE': 'Roles & access',
  'ADM-RULE': 'Approval settings',
  'ADM-AUD': 'Activity history',
  'ADM-INT': 'Integration monitor',
  'ADM-HELP': 'Help & support',
  'BI-REP': 'Reports',
  'MD-PARTY': 'Customers & suppliers',
  'MD-BRAND': 'Brands & agreements',
  'MD-ITEM': 'Items & units',
  'MD-SKU': 'Products & packs',
  'PUR-REQ': 'Purchase requisitions',
  'PUR-RFQ': 'Supplier quotations',
  'PUR-PO': 'Purchase orders',
  'INB-GATE': 'Supplier arrivals',
  'INB-GRN': 'Goods receipts',
  'QC-IN': 'Incoming quality checks',
  'INB-RETURN': 'Supplier returns',
  'INV-STK': 'Stock overview',
  'INV-ISS': 'Stock issues & returns',
  'INV-TRF': 'Stock transfers',
  'INV-COUNT': 'Stock counts',
  'INV-EXP': 'Expiry & disposal',
  'MD-REC': 'Recipes & materials',
  'MD-ROUTE': 'Production steps',
  'MD-SPEC': 'Quality standards',
  'PLAN-DEM': 'Demand planning',
  'PLAN-MRP': 'Material planning',
  'PLAN-SCH': 'Production schedule',
  'PRO-ORDER': 'Production orders',
  'PRO-STAGE': 'Production stages',
  'PRO-LOSS': 'Yield, loss & rework',
  'QC-LAB': 'Lab testing',
  'QC-SAFE': 'Food safety',
  'PACK-ART': 'Labels & coding',
  'PACK-RUN': 'Packing',
  'FG-LOT': 'Finished goods',
  'TRACE-CASE': 'Traceability & recalls',
  'COST-BATCH': 'Production costs',
  'CRM-LEAD': 'Enquiries & leads',
  'CRM-PRICE': 'Pricing & customer credit',
  'CRM-ORDER': 'Sales orders',
  'CON-WORK': 'Contract work',
  'DSP-PICK': 'Order picking',
  'DSP-LOAD': 'Loading & dispatch',
  'DSP-POD': 'Delivery confirmation',
  'RET-CASE': 'Returns & claims',
  'RET-UNSOLD': 'Unsold returns',
  'FIN-AR': 'Customer accounts',
  'BI-PROFIT': 'Profitability',
  'FIN-AP': 'Supplier invoices & payments',
  'FIN-EXP': 'Expenses',
  'FIN-GL': 'General ledger',
  'COST-OH': 'Overhead costs',
  'ASSET-REG': 'Fixed assets',
  'HR-PAY': 'Employees & payroll',
  'ENG-MNT': 'Equipment maintenance',
  'SCALE-PLANT': 'Multi-plant operations',
  'PORTAL-EXT': 'Partner portal',
  'OPT-PLAN': 'Planning recommendations',
  'FIN-SIM': 'Financial scenarios',
  'FIN-ADJ': 'Finance adjustments',
  'FIN-LEGACY': 'Historical data import',
  'FIN-ARCH': 'Bill archive',
  'FIN-OPEN': 'Opening balances',
  'FIN-SUP': 'Finance review tools',
};

export function roleLabel(role: string): string {
  return roleNames[role] ?? sentenceCase(role);
}

export function statusLabel(status: string): string {
  return sentenceCase(status);
}

export function screenLabel(code: string, fallback: string): string {
  return screenNames[code] ?? fallback;
}

function sentenceCase(value: string): string {
  const words = value
    .trim()
    .replaceAll('_', ' ')
    .replaceAll('-', ' ')
    .toLowerCase();

  return words ? words[0].toUpperCase() + words.slice(1) : value;
}
