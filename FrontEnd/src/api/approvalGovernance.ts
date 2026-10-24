import { apiMutation, apiRequest } from './client';

export type ApprovalRuleStatus = 'ACTIVE' | 'INACTIVE';
export type ApprovalWorkPriority = 'URGENT' | 'HIGH' | 'NORMAL' | 'LOW';

export type ApprovalRuleBand = {
  id: string;
  sequence: number;
  name: string;
  minimum_value: string;
  maximum_value: string | null;
  required_permission: string;
  escalation_permission: string;
  work_priority: ApprovalWorkPriority;
  due_hours: number;
  escalate_after_hours: number;
};

export type ApprovalRule = {
  id: string;
  company_id: string | null;
  plant_id: string | null;
  scope: 'GLOBAL' | 'COMPANY' | 'PLANT';
  code: string;
  name: string;
  description: string | null;
  entity_type: string;
  authority_metric: string;
  authority_uom: string;
  status: ApprovalRuleStatus;
  is_system: boolean;
  is_effective: boolean;
  record_version: number;
  bands: ApprovalRuleBand[];
  pending_request_count: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type ApprovalDelegation = {
  id: string;
  permission_code: string;
  delegator: { id: string; name: string; email: string };
  delegate: { id: string; name: string; email: string };
  effective_from: string;
  effective_to: string;
  reason: string;
  status: 'ACTIVE' | 'REVOKED';
  effective_status: 'SCHEDULED' | 'ACTIVE' | 'EXPIRED' | 'REVOKED';
  record_version: number;
  allowed_actions: ('REVOKE')[];
  created_at: string;
  updated_at: string;
};

export type ApprovalPermissionLookup = { id: string; code: string; name: string };
export type ApprovalUserLookup = {
  id: string;
  name: string;
  email: string;
  approval_permissions: string[];
};

export type ApprovalGovernanceWorkspace = {
  data: ApprovalRule[];
  delegations: ApprovalDelegation[];
  summary: {
    rules: number;
    plant_overrides: number;
    active_rules: number;
    pending_approvals: number;
    escalated_approvals: number;
    active_delegations: number;
  };
  lookups: {
    permissions: ApprovalPermissionLookup[];
    users: ApprovalUserLookup[];
  };
  allowed_actions: ('CREATE_RULE' | 'CREATE_DELEGATION' | 'ESCALATE_DUE')[];
};

export type ApprovalRuleWrite = {
  name: string;
  description: string | null;
  status: ApprovalRuleStatus;
  bands: Array<{
    name: string;
    minimum_value: string;
    maximum_value: string | null;
    required_permission: string;
    escalation_permission: string;
    work_priority: ApprovalWorkPriority;
    due_hours: number;
    escalate_after_hours: number;
  }>;
};

export type ApprovalGovernanceCommand = {
  entity_type: string;
  id: string;
  status: string;
  record_version: number;
  band_count?: number;
  delegator_id?: string;
  delegate_id?: string;
  permission_code?: string;
  escalated_count?: number;
  approval_request_ids?: string[];
};

export async function getApprovalGovernance(): Promise<ApprovalGovernanceWorkspace> {
  return apiRequest<ApprovalGovernanceWorkspace>('/api/v1/admin/approvals');
}

export async function createApprovalRule(
  body: ApprovalRuleWrite & { code: string },
  idempotencyKey: string
): Promise<ApprovalGovernanceCommand> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand }>('/api/v1/admin/approval-rules', body, {
    idempotencyKey,
  })).data;
}

export async function updateApprovalRule(
  rule: ApprovalRule,
  body: ApprovalRuleWrite,
  idempotencyKey: string
): Promise<ApprovalGovernanceCommand> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand }>(`/api/v1/admin/approval-rules/${rule.id}`, body, {
    expectedVersion: rule.record_version,
    idempotencyKey,
  })).data;
}

export async function deleteApprovalRule(
  rule: ApprovalRule,
  idempotencyKey: string
): Promise<ApprovalGovernanceCommand & { deleted: true }> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand & { deleted: true } }>(`/api/v1/admin/approval-rules/${rule.id}/delete`, {
    confirmed: true,
  }, {
    expectedVersion: rule.record_version,
    idempotencyKey,
  })).data;
}

export async function createApprovalDelegation(
  body: {
    delegator_id: string;
    delegate_id: string;
    permission_code: string;
    effective_from: string;
    effective_to: string;
    reason: string;
  },
  idempotencyKey: string
): Promise<ApprovalGovernanceCommand> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand }>('/api/v1/admin/approval-delegations', body, {
    idempotencyKey,
  })).data;
}

export async function revokeApprovalDelegation(
  delegation: ApprovalDelegation,
  idempotencyKey: string
): Promise<ApprovalGovernanceCommand> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand }>(
    `/api/v1/admin/approval-delegations/${delegation.id}/revoke`,
    {},
    { expectedVersion: delegation.record_version, idempotencyKey }
  )).data;
}

export async function escalateDueApprovals(idempotencyKey: string): Promise<ApprovalGovernanceCommand> {
  return (await apiMutation<{ data: ApprovalGovernanceCommand }>('/api/v1/admin/approvals/escalate-due', {}, {
    idempotencyKey,
  })).data;
}
