import type {
  UnsoldReturnApprovalDecisionResult,
  UnsoldReturnApprovalDetail,
  UnsoldReturnApprovalList,
  UnsoldReturnDetail,
  UnsoldReturnEvidenceUploadResult,
  UnsoldReturnLookups,
} from '../api/unsoldReturns';
import type { ErpSession } from '../types/session';

export const CASE_ID = '11111111-1111-4111-8111-111111111111';
export const LINE_ID = '22222222-2222-4222-8222-222222222222';
export const APPROVAL_ID = '33333333-3333-4333-8333-333333333333';

const companyId = '00000000-0000-4000-8000-000000000001';
const plantId = '00000000-0000-4000-8000-000000000101';
const party = {
  id: '00000000-0000-4000-8000-000000000501',
  code: 'DIST-NORTH',
  name: 'North Market Distributor',
};
const maker = {
  id: '00000000-0000-4000-8000-000000000201',
  code: 'SALES',
  name: 'Demo Sales Manager',
};
const sku = {
  id: '00000000-0000-4000-8000-000000000601',
  code: 'SKU-APPLE-100',
  name: 'Apple Snack Pack 100g',
};
const lot = {
  id: '00000000-0000-4000-8000-000000000701',
  code: 'FG-APPLE-2608A',
  name: null,
};

export function makeSession(actions: string[]): ErpSession {
  return {
    user: {
      id: '00000000-0000-4000-8000-000000000203',
      name: 'Demo Finance Manager',
      email: 'finance.user@qtfoods.local',
    },
    roles: ['FINANCE_REVIEWER'],
    allowed_screens: ['WRK-HOME', 'RET-UNSOLD'],
    allowed_actions: actions,
    contexts: [{
      company_id: companyId,
      company_name: 'Q & T Foods Ltd',
      plant_id: plantId,
      plant_name: 'Training Plant',
    }],
    selected_context: {
      company_id: companyId,
      company_name: 'Q & T Foods Ltd',
      plant_id: plantId,
      plant_name: 'Training Plant',
    },
  };
}

export function makeUnsoldReturnDetail(
  overrides: Partial<UnsoldReturnDetail> = {}
): UnsoldReturnDetail {
  const base: UnsoldReturnDetail = {
    id: CASE_ID,
    company_id: companyId,
    plant_id: plantId,
    party,
    sales_order_id: null,
    shipment_id: '00000000-0000-4000-8000-000000001001',
    invoice_id: null,
    invoice: null,
    status: 'REQUESTED',
    reason_code: 'UNSOLD_MARKET_RETURN',
    expected_return_date: '2026-09-12',
    sales_note: 'Distributor confirmed collection.',
    maker,
    received_at: null,
    loss_event_id: null,
    record_version: 4,
    created_at: '2026-09-08T08:00:00Z',
    updated_at: '2026-09-08T08:05:00Z',
    lines: [{
      id: LINE_ID,
      shipment_line_id: '00000000-0000-4000-8000-000000001101',
      sku,
      fg_lot: lot,
      requested_quantity: '10',
      received_quantity: '0',
      restock_quantity: '0',
      repack_quantity: '0',
      rework_quantity: '0',
      destroy_quantity: '0',
      uom_code: 'PACK',
      return_position: null,
      quality_reason_code: null,
      quality_reviewer_id: null,
    }],
    stock_movements: [],
    evidence: [{
      id: '44444444-4444-4444-8444-444444444444',
      category: 'RETURN_CONFIRMATION',
      case_record_version: 3,
      original_name: 'distributor-confirmation.txt',
      mime_type: 'text/plain',
      size_bytes: 31,
      sha256: '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
      notes: 'Confirmed by distributor',
      retention_policy: 'UNSOLD_RETURN_7Y',
      retention_until: '2033-09-08',
      legal_hold: false,
      uploader: maker,
      audit_event_id: '55555555-5555-4555-8555-555555555555',
      uploaded_at: '2026-09-08T08:05:00Z',
    }],
    finance: {
      stage: 'NOT_READY',
      credit_total: null,
      settlement_type: null,
      actions: [],
    },
    status_history: [{
      id: '66666666-6666-4666-8666-666666666666',
      from_status: null,
      to_status: 'REQUESTED',
      record_version: 1,
      actor: maker,
      changed_at: '2026-09-08T08:00:00Z',
    }],
    approval: null,
  };

  return { ...base, ...overrides };
}

export function makeUnsoldReturnLookups(): UnsoldReturnLookups {
  return {
    parties: [party],
    shipments: [{
      id: '00000000-0000-4000-8000-000000001001',
      number: 'SHP-2026-0001',
      party,
      status: 'DELIVERED',
      dispatched_at: '2026-09-01T08:30:00Z',
    }],
    invoices: [{
      id: '00000000-0000-4000-8000-000000001301',
      number: 'INV-2026-0001',
      status: 'POSTED',
      currency: 'INR',
      net_amount: '10000',
      tax_amount: '1800',
      gross_amount: '11800',
      outstanding_amount: '4000',
      issued_at: '2026-09-01T12:00:00Z',
      record_version: 1,
    }],
    shipment_lines: [],
    skus: [{ ...sku, uom_code: 'PACK' }],
    lots: [{
      id: lot.id,
      sku_id: sku.id,
      code: lot.code,
      manufacture_date: '2026-08-15',
      expiry_date: '2027-02-15',
    }],
    return_positions: [{
      id: '00000000-0000-4000-8000-000000001201',
      sku_id: sku.id,
      lot: { id: lot.id, code: lot.code },
      location: {
        id: '00000000-0000-4000-8000-000000000801',
        code: 'RET-QA',
        name: 'Return Quarantine',
      },
      quality_status: 'RETURN_QUARANTINE',
      quantity: '0',
      uom_code: 'PACK',
    }],
    return_locations: [],
  };
}

export function makeEvidenceUploadResult(): UnsoldReturnEvidenceUploadResult {
  return {
    return_case_id: CASE_ID,
    evidence_id: '77777777-7777-4777-8777-777777777777',
    category: 'QUALITY_REPORT',
    case_record_version: 4,
    record_version: 5,
    original_name: 'quality-note.txt',
    mime_type: 'text/plain',
    size_bytes: 22,
    sha256: '60303ae22b998861f256fff90d8d4c47443a88f05ac66e938d6cbedddff5892c',
    notes: 'QA hand-off',
    retention_policy: 'UNSOLD_RETURN_7Y',
    retention_until: '2033-09-08',
    legal_hold: false,
    audit_event_id: '88888888-8888-4888-8888-888888888888',
    uploaded_at: '2026-09-08T09:00:00Z',
  };
}

export function makeApprovalDetail(canDecide = true): UnsoldReturnApprovalDetail {
  return {
    id: APPROVAL_ID,
    entity_type: 'unsold_return_loss',
    entity_version: 7,
    record_version: 1,
    rule_code: 'UNSOLD_RETURN_LOSS_APPROVAL',
    status: 'PENDING',
    maker: {
      id: '00000000-0000-4000-8000-000000000202',
      code: 'OPERATIONS',
      name: 'Demo Operations Manager',
    },
    return_case: {
      id: CASE_ID,
      status: 'DISPOSITION_REVIEW',
      record_version: 7,
      party,
      reason_code: 'UNSOLD_MARKET_RETURN',
      expected_return_date: '2026-09-12',
    },
    destroy_quantity: '4',
    authority: {
      rule_id: '00000000-0000-4000-8000-000000001421',
      rule_version: 1,
      rule_name: 'Unsold return loss approval',
      band_id: '00000000-0000-4000-8000-000000001521',
      band_name: 'Standard loss authority',
      value: '4',
      uom: 'BASE',
      required_permission: 'ACTION:RET-UNSOLD:APPROVE',
      escalation_permission: 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
    },
    submission_number: 1,
    resubmission_of_id: null,
    due_at: '2026-09-09T10:00:00Z',
    escalate_at: '2026-09-09T10:00:00Z',
    escalated_at: null,
    escalation_count: 0,
    can_decide: canDecide,
    created_at: '2026-09-08T10:00:00Z',
    updated_at: '2026-09-08T10:00:00Z',
    summary: { destroy_quantity: '4' },
    lines: [{
      id: LINE_ID,
      sku,
      fg_lot: lot,
      received_quantity: '10',
      restock_quantity: '6',
      repack_quantity: '0',
      rework_quantity: '0',
      destroy_quantity: '4',
      uom_code: 'PACK',
      quality_reason_code: 'DAMAGED_RETURN',
    }],
    decisions: [],
  };
}

export function makeApprovalList(
  detail = makeApprovalDetail()
): UnsoldReturnApprovalList {
  return {
    data: [detail],
    meta: {
      current_page: 1,
      per_page: 12,
      total: 1,
      last_page: 1,
      from: 1,
      to: 1,
    },
    links: {
      first: '/approvals?page=1',
      last: '/approvals?page=1',
      prev: null,
      next: null,
    },
  };
}

export function makeApprovalDecisionResult(): UnsoldReturnApprovalDecisionResult {
  return {
    approval_request_id: APPROVAL_ID,
    decision: 'APPROVE',
    approval_status: 'APPROVED',
    approval_record_version: 2,
    return_case_id: CASE_ID,
    case_status: 'DISPOSITION_REVIEW',
    case_record_version: 8,
    stock_movement_ids: ['99999999-9999-4999-8999-999999999999'],
    authority_source: 'DIRECT',
    authority_permission: 'ACTION:RET-UNSOLD:APPROVE',
    delegation_id: null,
  };
}
