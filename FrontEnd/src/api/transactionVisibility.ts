import { apiMutation, apiRequest } from './client';
import type { VisibilityMetadata } from './p2Operations';

export type VisibilityEvent = {
  id: string;
  previous_visibility: 'NORMAL' | 'RESTRICTED';
  new_visibility: 'NORMAL' | 'RESTRICTED';
  change_source: string;
  reason: string | null;
  changed_at: string;
  actor: { id: string; name: string };
};

export async function changeTransactionVisibility(
  transactionType: string,
  transactionId: string,
  visibilityStatus: 'NORMAL' | 'RESTRICTED',
  expectedVersion: number,
  restrictionReason?: string | null,
) {
  const response = await apiMutation<{ data: VisibilityMetadata }>(
    `/api/v1/transaction-visibility/${encodeURIComponent(transactionType)}/${encodeURIComponent(transactionId)}`,
    { visibility_status: visibilityStatus, restriction_reason: restrictionReason ?? null },
    { method: 'PATCH', expectedVersion },
  );
  return response.data;
}

export async function getTransactionVisibilityHistory(transactionType: string, transactionId: string) {
  const response = await apiRequest<{ data: VisibilityEvent[] }>(
    `/api/v1/transaction-visibility/${encodeURIComponent(transactionType)}/${encodeURIComponent(transactionId)}/history`,
  );
  return response.data;
}
