import { apiDownload, apiMutation, apiRequest } from './client';

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };

export type AuditActor = { id: string; name: string; email: string | null };
export type AuditEvidence = {
  id: string;
  return_case_id: string;
  category: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  sha256: string;
  notes: string | null;
  retention_policy: string;
  retention_until: string;
  legal_hold: boolean;
  uploaded_at: string;
  uploaded_by: { id: string; name: string };
  allowed_actions: ('VIEW' | 'DOWNLOAD')[];
};
export type AuditEvent = {
  id: string;
  request_id: string | null;
  correlation_id: string | null;
  trace_id: string | null;
  span_id: string | null;
  command: string;
  entity_type: string;
  entity_id: string;
  entity_version: number | null;
  actor: AuditActor;
  outcome: string;
  reason_code: string | null;
  evidence_count: number;
  event_at: string;
  posted_at: string;
};
export type AuditEventDetail = AuditEvent & {
  company: { id: string; code: string; name: string };
  plant: { id: string; code: string; name: string };
  safe_diff: unknown;
  evidence: AuditEvidence[];
  created_at: string;
};
export type AuditWorkspace = {
  data: AuditEvent[];
  meta: PageMeta;
  summary: { total: number; success: number; failure: number; evidence: number };
  lookups: { commands: string[]; entity_types: string[]; outcomes: string[] };
  allowed_actions: ('VIEW_EVIDENCE')[];
};
export type AuditFilters = {
  page?: number;
  per_page?: number;
  q?: string;
  command?: string;
  entity_type?: string;
  outcome?: string;
  from?: string;
  to?: string;
  sort?: 'NEWEST' | 'OLDEST';
};

export async function listAuditEvents(filters: AuditFilters = {}): Promise<AuditWorkspace> {
  return apiRequest<AuditWorkspace>(withQuery('/api/v1/admin/audit', filters));
}

export async function getAuditEvent(id: string): Promise<AuditEventDetail> {
  return (await apiRequest<{ data: AuditEventDetail }>(`/api/v1/admin/audit/${id}`)).data;
}

export async function getAuditEvidence(
  auditId: string,
  evidenceId: string,
  download = false
): Promise<Blob> {
  return apiDownload(
    `/api/v1/admin/audit/${auditId}/evidence/${evidenceId}${download ? '?download=1' : ''}`,
    globalThis.crypto.randomUUID()
  );
}

export type OutboxStatus = 'PENDING' | 'PROCESSING' | 'RETRY' | 'DELIVERED' | 'QUARANTINED';
export type OutboxEvent = {
  id: string;
  event_type: string;
  aggregate_type: string;
  aggregate_id: string;
  business_key: string;
  request_id: string | null;
  correlation_id: string | null;
  trace_id: string | null;
  span_id: string | null;
  status: OutboxStatus;
  attempts: number;
  record_version: number;
  next_retry_at: string | null;
  last_attempt_at: string | null;
  delivered_at: string | null;
  acknowledged_at: string | null;
  acknowledgement_id: string | null;
  last_error_code: string | null;
  last_error_message: string | null;
  quarantined_at: string | null;
  quarantine_reason: string | null;
  created_at: string;
  updated_at: string;
  attempt_history_count: number;
  allowed_actions: ('RETRY' | 'QUARANTINE')[];
};
export type OutboxAttempt = {
  id: string;
  attempt_number: number;
  worker_id: string;
  transport: string;
  outcome: string;
  error_code: string | null;
  error_message: string | null;
  acknowledgement_id: string | null;
  response: unknown;
  started_at: string;
  completed_at: string;
};
export type OutboxEventDetail = OutboxEvent & {
  payload: unknown;
  transport_response: unknown;
  worker_lock: { worker_id: string; locked_at: string } | null;
  attempt_history: OutboxAttempt[];
};
export type OutboxWorkspace = {
  data: OutboxEvent[];
  meta: PageMeta;
  summary: Record<'total' | 'pending' | 'processing' | 'retry' | 'delivered' | 'quarantined', number>;
  runtime: {
    queue_connection: string;
    transport: string;
    delivery_enabled: boolean;
    batch_size: number;
    max_attempts: number;
    base_retry_seconds: number;
    lock_timeout_seconds: number;
    last_attempt_at: string | null;
    oldest_due_at: string | null;
    evidence_disk: string;
    evidence_driver: string;
  };
  lookups: { statuses: OutboxStatus[]; event_types: string[] };
  allowed_actions: ('PROCESS_DUE')[];
};
export type OutboxFilters = {
  page?: number;
  per_page?: number;
  q?: string;
  status?: string;
  event_type?: string;
  sort?: 'NEWEST' | 'OLDEST' | 'NEXT_RETRY';
};
export type OutboxCommand = {
  entity_type: string;
  id: string;
  status: string;
  record_version: number;
  claimed?: number;
  delivered?: number;
  retry_scheduled?: number;
  quarantined?: number;
  delivery_enabled?: boolean;
};

export async function listOutboxEvents(filters: OutboxFilters = {}): Promise<OutboxWorkspace> {
  return apiRequest<OutboxWorkspace>(withQuery('/api/v1/admin/integrations', filters));
}

export async function getOutboxEvent(id: string): Promise<OutboxEventDetail> {
  return (await apiRequest<{ data: OutboxEventDetail }>(`/api/v1/admin/outbox-events/${id}`)).data;
}

export async function processDueOutbox(limit: number, idempotencyKey: string): Promise<OutboxCommand> {
  return (await apiMutation<{ data: OutboxCommand }>('/api/v1/admin/integrations/process-due', { limit }, {
    idempotencyKey,
  })).data;
}

export async function retryOutboxEvent(event: OutboxEvent, idempotencyKey: string): Promise<OutboxCommand> {
  return (await apiMutation<{ data: OutboxCommand }>(`/api/v1/admin/outbox-events/${event.id}/retry`, {}, {
    idempotencyKey,
    expectedVersion: event.record_version,
  })).data;
}

export async function quarantineOutboxEvent(
  event: OutboxEvent,
  reason: string,
  idempotencyKey: string
): Promise<OutboxCommand> {
  return (await apiMutation<{ data: OutboxCommand }>(`/api/v1/admin/outbox-events/${event.id}/quarantine`, { reason }, {
    idempotencyKey,
    expectedVersion: event.record_version,
  })).data;
}

function withQuery(path: string, filters: Record<string, unknown>): string {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
  });
  const encoded = query.toString();
  return encoded ? `${path}?${encoded}` : path;
}
