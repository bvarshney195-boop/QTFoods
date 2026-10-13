import { apiDownload, apiMutation, apiRequest } from './client';

export type P2Record = Record<string, unknown> & {
  id: string;
  status?: string;
  record_version?: number;
  allowed_actions?: string[];
  _kind?: string;
  _visibility_type?: string;
  visibility?: VisibilityMetadata;
};

export type VisibilityMetadata = {
  status: 'NORMAL' | 'RESTRICTED';
  is_restricted: boolean;
  record_version: number;
  restricted_by?: string | null;
  restricted_at?: string | null;
  restriction_reason?: string | null;
};

export type P2Workspace = {
  data: P2Record[];
  meta?: { current_page?: number; last_page?: number; total: number };
  summary?: Record<string, unknown>;
  lookups?: Record<string, unknown>;
  allowed_actions?: string[];
  operational_view?: { label: string; includes_restricted_entries: boolean; permission_filtered: boolean };
  [key: string]: unknown;
};

export type P2CommandResult = {
  id: string;
  entity_type?: string;
  status: string;
  record_version: number;
  [key: string]: unknown;
};

export function listP2(path: string, filters: Record<string, unknown> = {}) {
  const search = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const query = search.toString();
  return apiRequest<P2Workspace>(query ? `${path}?${query}` : path);
}

export async function getP2(path: string) {
  return (await apiRequest<{ data: P2Record }>(path)).data;
}

export async function commandP2(path: string, body: unknown, expectedVersion?: number) {
  return (await apiMutation<{ data: P2CommandResult }>(path, body, {
    idempotencyKey: crypto.randomUUID(),
    expectedVersion,
  })).data;
}

export async function uploadP2(path: string, body: FormData) {
  return (await apiMutation<{ data: P2CommandResult }>(path, body, {
    idempotencyKey: crypto.randomUUID(),
  })).data;
}

export function downloadP2(path: string) {
  return apiDownload(path);
}
