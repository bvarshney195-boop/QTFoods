import { apiMutation, apiRequest } from './client';

export type WorkItemKind = 'APPROVAL' | 'TASK' | 'EXCEPTION';
export type WorkItemPriority = 'URGENT' | 'HIGH' | 'NORMAL' | 'LOW';
export type WorkItemStatus = 'OPEN' | 'COMPLETED' | 'CANCELLED';
export type WorkItemAction = 'CLAIM' | 'ASSIGN' | 'COMPLETE' | 'OPEN';

export type WorkItem = {
  id: string;
  kind: WorkItemKind;
  title: string;
  description: string | null;
  priority: WorkItemPriority;
  status: WorkItemStatus;
  assignee: { id: string; name: string; email: string } | null;
  source: { type: string; id: string | null } | null;
  target: { screen_code: string; record_id: string | null; href: string } | null;
  due_at: string | null;
  is_overdue: boolean;
  age_minutes: number;
  age_bucket: 'UNDER_4_HOURS' | 'TODAY' | 'ONE_TO_THREE_DAYS' | 'OVER_THREE_DAYS';
  creator: { id: string; name: string | null };
  completed_at: string | null;
  completed_by: { id: string; name: string | null } | null;
  completion_note: string | null;
  record_version: number;
  allowed_actions: WorkItemAction[];
  created_at: string;
  updated_at: string;
};

export type WorkQueue = {
  data: WorkItem[];
  summary: {
    open_total: number;
    assigned_to_me: number;
    unassigned: number;
    approvals: number;
    exceptions: number;
    overdue: number;
    high_priority: number;
    older_than_three_days: number;
    created_7d: number;
    completed_7d: number;
    closure_rate_7d: number;
  };
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
};

export type WorkQueueFilters = {
  kind?: WorkItemKind;
  assignment?: 'ALL' | 'MINE' | 'UNASSIGNED';
  overdue?: boolean;
  q?: string;
};

export type WorkItemMutationResult = {
  id: string;
  kind: WorkItemKind;
  status: WorkItemStatus;
  assigned_user_id: string | null;
  completed_by: string | null;
  completed_at: string | null;
  record_version: number;
};

export async function listWorkItems(filters: WorkQueueFilters = {}): Promise<WorkQueue> {
  const query = new URLSearchParams();
  query.set('per_page', '50');
  if (filters.kind) query.set('kind', filters.kind);
  if (filters.assignment && filters.assignment !== 'ALL') {
    query.set('assignment', filters.assignment);
  }
  if (filters.overdue) query.set('overdue', '1');
  if (filters.q?.trim()) query.set('q', filters.q.trim());

  return apiRequest<WorkQueue>(`/api/v1/work/tasks?${query.toString()}`);
}

export async function claimWorkItem(
  id: string,
  version: number,
  idempotencyKey: string
): Promise<WorkItemMutationResult> {
  return (await apiMutation<{ data: WorkItemMutationResult }>(
    `/api/v1/work/tasks/${id}/claim`,
    {},
    { expectedVersion: version, idempotencyKey }
  )).data;
}

export async function completeWorkItem(
  id: string,
  version: number,
  idempotencyKey: string,
  completionNote?: string
): Promise<WorkItemMutationResult> {
  return (await apiMutation<{ data: WorkItemMutationResult }>(
    `/api/v1/work/tasks/${id}/complete`,
    { completion_note: completionNote ?? null },
    { expectedVersion: version, idempotencyKey }
  )).data;
}
