import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  AuditEventDetail,
  AuditWorkspace,
  OutboxEventDetail,
  OutboxWorkspace,
} from '../api/controlOperations';
import ADM_AUD from './ADM_AUD';
import ADM_INT from './ADM_INT';

const apiMocks = vi.hoisted(() => ({
  listAuditEvents: vi.fn(),
  getAuditEvent: vi.fn(),
  getAuditEvidence: vi.fn(),
  listOutboxEvents: vi.fn(),
  getOutboxEvent: vi.fn(),
  processDueOutbox: vi.fn(),
  retryOutboxEvent: vi.fn(),
  quarantineOutboxEvent: vi.fn(),
}));

vi.mock('../api/controlOperations', async () => {
  const actual = await vi.importActual<typeof import('../api/controlOperations')>('../api/controlOperations');
  return { ...actual, ...apiMocks };
});

describe('control operations workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:audit-evidence') });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
    apiMocks.listAuditEvents.mockResolvedValue(auditWorkspace());
    apiMocks.getAuditEvent.mockResolvedValue(auditDetail());
    apiMocks.getAuditEvidence.mockResolvedValue(new Blob(['%PDF evidence'], { type: 'application/pdf' }));
    apiMocks.listOutboxEvents.mockResolvedValue(outboxWorkspace());
    apiMocks.getOutboxEvent.mockResolvedValue(outboxDetail());
  });

  it('searches immutable audit events and opens retained evidence in the authenticated viewer', async () => {
    render(<ADM_AUD />);

    expect(await screen.findByText('Demo ERP Administrator')).toBeInTheDocument();
    await userEvent.setup().type(screen.getByLabelText('Search audit'), 'evidence');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(apiMocks.listAuditEvents).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'evidence' })));

    await userEvent.setup().click(screen.getByRole('button', { name: 'Open' }));
    expect(await screen.findByText('receipt.pdf')).toBeInTheDocument();
    expect(screen.getByText(/SHA-256 abc123/)).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'View' }));

    await waitFor(() => expect(apiMocks.getAuditEvidence).toHaveBeenCalledWith('audit-1', 'evidence-1'));
    expect(await screen.findByLabelText('receipt.pdf')).toHaveAttribute('data', 'blob:audit-evidence');
  });

  it('runs a bounded outbox batch and reports receiver delivery outcomes', async () => {
    apiMocks.processDueOutbox.mockResolvedValue({
      entity_type: 'outbox_processing_batch', id: 'batch-1', status: 'COMPLETED', record_version: 1,
      claimed: 3, delivered: 2, retry_scheduled: 1, quarantined: 0,
    });
    render(<ADM_INT />);

    expect((await screen.findAllByText('sales.unsold_return.created')).length).toBeGreaterThan(0);
    await userEvent.setup().click(screen.getByRole('button', { name: 'Process due now' }));

    await waitFor(() => expect(apiMocks.processDueOutbox).toHaveBeenCalledWith(50, expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('2 delivered, 1 scheduled for retry');
  });

  it('retries and manually quarantines events using the current optimistic version', async () => {
    apiMocks.retryOutboxEvent.mockResolvedValue({ entity_type: 'outbox_event', id: 'outbox-1', status: 'PENDING', record_version: 6 });
    apiMocks.quarantineOutboxEvent.mockResolvedValue({ entity_type: 'outbox_event', id: 'outbox-1', status: 'QUARANTINED', record_version: 6 });
    render(<ADM_INT />);

    await screen.findAllByText('sales.unsold_return.created');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Open' }));
    expect(await screen.findByText('Attempt 2 · http')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Retry event' }));

    await waitFor(() => expect(apiMocks.retryOutboxEvent).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'outbox-1', record_version: 5 }),
      expect.any(String)
    ));

    apiMocks.getOutboxEvent.mockResolvedValue({ ...outboxDetail(), status: 'PENDING', allowed_actions: ['QUARANTINE'] });
    await userEvent.setup().click(screen.getByRole('button', { name: 'Open' }));
    await userEvent.setup().type(screen.getByLabelText('Quarantine reason'), 'Receiver contract retired');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Move to quarantine' }));
    await waitFor(() => expect(apiMocks.quarantineOutboxEvent).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'outbox-1' }),
      'Receiver contract retired',
      expect.any(String)
    ));
  });

  it('shows standalone retention without offering external delivery processing', async () => {
    const workspace = outboxWorkspace();
    apiMocks.listOutboxEvents.mockResolvedValue({
      ...workspace,
      runtime: {
        ...workspace.runtime,
        transport: 'standalone',
        delivery_enabled: false,
        oldest_due_at: null,
      },
      allowed_actions: [],
    });

    render(<ADM_INT />);

    expect(await screen.findByText('Standalone retention')).toBeInTheDocument();
    expect(screen.getByText(/no external delivery or acknowledgement is claimed/i)).toBeInTheDocument();
    expect(screen.getByText('retained locally')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Process due now' })).not.toBeInTheDocument();
  });
});

function auditWorkspace(): AuditWorkspace {
  return {
    data: [{
      id: 'audit-1', request_id: 'request-1', correlation_id: 'correlation-1', trace_id: 'trace-1', span_id: 'span-1',
      command: 'UPLOAD_UNSOLD_RETURN_EVIDENCE', entity_type: 'unsold_return_evidence',
      entity_id: 'evidence-1', entity_version: 2,
      actor: { id: 'admin-1', name: 'Demo ERP Administrator', email: 'admin@qtfoods.local' },
      outcome: 'SUCCESS', reason_code: 'RETURN_CONFIRMATION', evidence_count: 1,
      event_at: now, posted_at: now,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 8, success: 7, failure: 1, evidence: 1 },
    lookups: { commands: ['UPLOAD_UNSOLD_RETURN_EVIDENCE'], entity_types: ['unsold_return_evidence'], outcomes: ['SUCCESS', 'FAILURE', 'DENIED'] },
    allowed_actions: ['VIEW_EVIDENCE'],
  };
}

function auditDetail(): AuditEventDetail {
  return {
    ...auditWorkspace().data[0],
    company: { id: 'company-1', code: 'QTF', name: 'Q & T Foods' },
    plant: { id: 'plant-1', code: 'TRAINING', name: 'Training Plant' },
    safe_diff: { original_name: { from: null, to: 'receipt.pdf' } },
    created_at: now,
    evidence: [{
      id: 'evidence-1', return_case_id: 'case-1', category: 'RETURN_CONFIRMATION',
      original_name: 'receipt.pdf', mime_type: 'application/pdf', size_bytes: 1234,
      sha256: 'abc123', notes: 'Signed receipt', retention_policy: 'UNSOLD_RETURN_7Y',
      retention_until: '2033-09-09', legal_hold: false, uploaded_at: now,
      uploaded_by: { id: 'admin-1', name: 'Demo ERP Administrator' },
      allowed_actions: ['VIEW', 'DOWNLOAD'],
    }],
  };
}

function outboxWorkspace(): OutboxWorkspace {
  return {
    data: [{
      id: 'outbox-1', event_type: 'sales.unsold_return.created', aggregate_type: 'unsold_return_case',
      aggregate_id: 'case-1', business_key: 'case-1:1', request_id: 'request-1', correlation_id: 'correlation-1', trace_id: 'trace-1', span_id: 'span-1',
      status: 'QUARANTINED', attempts: 2, record_version: 5, next_retry_at: null,
      last_attempt_at: now, delivered_at: null, acknowledged_at: null, acknowledgement_id: null,
      last_error_code: 'RuntimeException', last_error_message: 'Receiver unavailable',
      quarantined_at: now, quarantine_reason: 'Retry policy exhausted', created_at: now, updated_at: now,
      attempt_history_count: 2, allowed_actions: ['RETRY'],
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 4, pending: 1, processing: 0, retry: 1, delivered: 1, quarantined: 1 },
    runtime: { queue_connection: 'redis', transport: 'http', delivery_enabled: true, batch_size: 50, max_attempts: 8, base_retry_seconds: 30, lock_timeout_seconds: 300, last_attempt_at: now, oldest_due_at: now, evidence_disk: 'evidence', evidence_driver: 's3' },
    lookups: { statuses: ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'], event_types: ['sales.unsold_return.created'] },
    allowed_actions: ['PROCESS_DUE'],
  };
}

function outboxDetail(): OutboxEventDetail {
  return {
    ...outboxWorkspace().data[0], payload: { return_case_id: 'case-1' }, transport_response: null,
    worker_lock: null,
    attempt_history: [{
      id: 'attempt-2', attempt_number: 2, worker_id: 'worker-1', transport: 'http',
      outcome: 'QUARANTINED', error_code: 'RuntimeException', error_message: 'Receiver unavailable',
      acknowledgement_id: null, response: null, started_at: now, completed_at: now,
    }],
  };
}

const now = '2026-09-09T10:00:00.000Z';
