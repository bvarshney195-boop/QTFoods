import { apiMutation, apiRequest } from './client';
import type { DeviceSessionWorkspace } from './identity';

export type LifecycleStatus = 'ACTIVE' | 'INACTIVE';
export type UserStatus = LifecycleStatus | 'INVITED';
export type DefinitionStatus = LifecycleStatus;
export type CompanyStatus = 'DRAFT' | LifecycleStatus;
export type PlantStatus = CompanyStatus;
export type LocationType =
  | 'WAREHOUSE'
  | 'ZONE'
  | 'BIN'
  | 'RETURN_QUARANTINE'
  | 'FINISHED_GOODS'
  | 'RAW_MATERIAL'
  | 'QUALITY_HOLD'
  | 'REPACK'
  | 'REWORK'
  | 'BLOCKED'
  | 'OTHER';

export type AdminCommandResult = {
  entity_type: string;
  id: string;
  status: string;
  record_version: number;
  company_id?: string;
  plant_id?: string;
  administrator_assignment_id?: string | null;
  role_assignment_id?: string;
  user_id?: string;
  permission_ids?: string[];
};

export type CompanyAdmin = {
  id: string;
  code: string;
  legal_name: string;
  display_name: string;
  status: CompanyStatus;
  record_version: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type PlantAdmin = {
  id: string;
  company_id: string;
  code: string;
  name: string;
  timezone: string;
  status: PlantStatus;
  location_count: number;
  active_user_count: number;
  record_version: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type OrganisationWorkspace = {
  data: {
    company: CompanyAdmin;
    plants: PlantAdmin[];
    summary: {
      plant_count: number;
      active_plants: number;
      location_count: number;
      active_users: number;
    };
    allowed_actions: ('CREATE_COMPANY' | 'CREATE_PLANT')[];
  };
};

export type LocationAdmin = {
  id: string;
  company_id: string;
  plant_id: string;
  code: string;
  name: string;
  description: string | null;
  location_type: LocationType;
  status: LifecycleStatus;
  parent: { id: string; code: string; name: string } | null;
  child_count: number;
  position_count: number;
  quantity_on_hand: string;
  record_version: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type LocationWorkspace = {
  data: LocationAdmin[];
  summary: {
    total: number;
    active: number;
    with_stock_positions: number;
    root_locations: number;
  };
  lookups: {
    parents: { id: string; code: string; name: string }[];
  };
  allowed_actions: ('CREATE')[];
};

export type RoleLookup = {
  id: string;
  code: string;
  name: string;
  is_system: boolean;
};

export type RoleAssignmentAdmin = {
  id: string;
  user: { id: string; name: string; email: string };
  role: { id: string; code: string; name: string; status: DefinitionStatus };
  company: { id: string; name: string };
  plant: { id: string; code: string; name: string };
  is_active: boolean;
  effective_from: string | null;
  effective_to: string | null;
  record_version: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type UserAdmin = {
  id: string;
  email: string;
  name: string;
  status: UserStatus;
  email_verified: boolean;
  email_verified_at: string | null;
  mfa_enabled: boolean;
  mfa_enabled_at: string | null;
  mfa_required: boolean;
  mfa_requirement_source: 'ROLE' | 'ADMIN' | 'NONE';
  password_change_required: boolean;
  deleted_at: string | null;
  last_login_at: string | null;
  active_session_count: number;
  invitation: {
    id: string;
    status: 'PENDING' | 'ACCEPTED' | 'REVOKED' | 'EXPIRED';
    expires_at: string;
    last_sent_at: string | null;
    last_delivery_status: 'SENT' | 'FAILED' | null;
    delivery_count: number;
    record_version: number;
  } | null;
  assignments: RoleAssignmentAdmin[];
  active_assignment_count: number;
  record_version: number;
  allowed_actions: ('UPDATE' | 'ASSIGN_ROLE' | 'MANAGE_INVITATION' | 'SEND_VERIFICATION' | 'MANAGE_SESSIONS' | 'RESET_PASSWORD' | 'MANAGE_MFA' | 'DELETE')[];
  created_at: string;
  updated_at: string;
};

export type UserWorkspace = {
  data: UserAdmin[];
  summary: {
    total: number;
    active: number;
    invited: number;
    unverified: number;
    active_assignments: number;
    roles_in_use: number;
  };
  lookups: { roles: RoleLookup[] };
  allowed_actions: ('CREATE' | 'INVITE')[];
};

export type IdentityAdminResult = AdminCommandResult & {
  expires_at?: string;
  delivery?: { channel: 'EMAIL'; status: 'SENT' | 'FAILED'; preview_url?: string };
};

export type RoleAdmin = {
  id: string;
  company_id: string | null;
  code: string;
  name: string;
  description: string | null;
  status: DefinitionStatus;
  is_system: boolean;
  permission_ids: string[];
  permission_count: number;
  active_assignment_count: number;
  record_version: number;
  allowed_actions: ('UPDATE' | 'SYNC_PERMISSIONS')[];
  created_at: string;
  updated_at: string;
};

export type PermissionAdmin = {
  id: string;
  company_id: string | null;
  code: string;
  name: string;
  description: string | null;
  status: DefinitionStatus;
  is_system: boolean;
  role_count: number;
  record_version: number;
  allowed_actions: ('UPDATE')[];
  created_at: string;
  updated_at: string;
};

export type RoleWorkspace = {
  data: RoleAdmin[];
  permissions: PermissionAdmin[];
  summary: {
    roles: number;
    custom_roles: number;
    permissions: number;
    active_assignments: number;
  };
  allowed_actions: ('CREATE_ROLE' | 'CREATE_PERMISSION')[];
};

export async function getOrganisation(): Promise<OrganisationWorkspace> {
  return apiRequest<OrganisationWorkspace>('/api/v1/admin/organisation');
}

export async function createCompany(
  body: {
    code: string;
    legal_name: string;
    display_name: string;
    plant_code: string;
    plant_name: string;
    timezone: string;
  },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/companies', body, {
    idempotencyKey,
  })).data;
}

export async function updateCompany(
  company: CompanyAdmin,
  body: Pick<CompanyAdmin, 'legal_name' | 'display_name' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/companies/${company.id}`, body, {
    expectedVersion: company.record_version,
    idempotencyKey,
  })).data;
}

export async function createPlant(
  body: Pick<PlantAdmin, 'code' | 'name' | 'timezone' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/plants', body, {
    idempotencyKey,
  })).data;
}

export async function updatePlant(
  plant: PlantAdmin,
  body: Pick<PlantAdmin, 'name' | 'timezone' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/plants/${plant.id}`, body, {
    expectedVersion: plant.record_version,
    idempotencyKey,
  })).data;
}

export async function listLocations(filters: { q?: string; status?: string; location_type?: string } = {}): Promise<LocationWorkspace> {
  const query = new URLSearchParams();
  if (filters.q?.trim()) query.set('q', filters.q.trim());
  if (filters.status) query.set('status', filters.status);
  if (filters.location_type) query.set('location_type', filters.location_type);
  const suffix = query.size ? `?${query.toString()}` : '';
  return apiRequest<LocationWorkspace>(`/api/v1/admin/locations${suffix}`);
}

export async function createLocation(
  body: Pick<LocationAdmin, 'code' | 'name' | 'description' | 'location_type' | 'status'> & { parent_location_id: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/locations', body, {
    idempotencyKey,
  })).data;
}

export async function updateLocation(
  location: LocationAdmin,
  body: Pick<LocationAdmin, 'name' | 'description' | 'location_type' | 'status'> & { parent_location_id: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/locations/${location.id}`, body, {
    expectedVersion: location.record_version,
    idempotencyKey,
  })).data;
}

export async function listUsers(filters: { q?: string; status?: string } = {}): Promise<UserWorkspace> {
  const query = new URLSearchParams();
  if (filters.q?.trim()) query.set('q', filters.q.trim());
  if (filters.status) query.set('status', filters.status);
  const suffix = query.size ? `?${query.toString()}` : '';
  return apiRequest<UserWorkspace>(`/api/v1/admin/users${suffix}`);
}

export async function createUser(
  body: {
    email: string;
    name: string;
    temporary_password: string;
    role_id: string;
    effective_from: string | null;
    effective_to: string | null;
  },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/users', body, {
    idempotencyKey,
  })).data;
}

export async function inviteUser(
  body: {
    email: string;
    name: string;
    role_id: string;
    effective_from: string | null;
    effective_to: string | null;
  },
  idempotencyKey: string
): Promise<IdentityAdminResult> {
  return (await apiMutation<{ data: IdentityAdminResult }>('/api/v1/admin/user-invitations', body, {
    idempotencyKey,
  })).data;
}

export async function resendInvitation(
  invitation: NonNullable<UserAdmin['invitation']>,
  idempotencyKey: string
): Promise<IdentityAdminResult> {
  return (await apiMutation<{ data: IdentityAdminResult }>(
    `/api/v1/admin/user-invitations/${invitation.id}/resend`, {}, {
      expectedVersion: invitation.record_version,
      idempotencyKey,
    }
  )).data;
}

export async function revokeInvitation(
  invitation: NonNullable<UserAdmin['invitation']>,
  idempotencyKey: string
): Promise<IdentityAdminResult> {
  return (await apiMutation<{ data: IdentityAdminResult }>(
    `/api/v1/admin/user-invitations/${invitation.id}/revoke`, {}, {
      expectedVersion: invitation.record_version,
      idempotencyKey,
    }
  )).data;
}

export async function sendUserVerification(userId: string, idempotencyKey: string): Promise<IdentityAdminResult> {
  return (await apiMutation<{ data: IdentityAdminResult }>(`/api/v1/admin/users/${userId}/verification`, {}, {
    idempotencyKey,
  })).data;
}

export async function listUserSessions(userId: string): Promise<DeviceSessionWorkspace> {
  return apiRequest<DeviceSessionWorkspace>(`/api/v1/admin/users/${userId}/sessions`);
}

export async function revokeUserSession(userId: string, sessionId: string) {
  return (await apiMutation<{ data: { id: string; revoked: true; current: boolean } }>(
    `/api/v1/admin/users/${userId}/sessions/${sessionId}/revoke`, {}
  )).data;
}

export async function updateUser(
  user: UserAdmin,
  body: Pick<UserAdmin, 'email' | 'name' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/users/${user.id}`, body, {
    expectedVersion: user.record_version,
    idempotencyKey,
  })).data;
}

export async function resetUserPassword(
  user: UserAdmin,
  temporaryPassword: string,
  confirmation: string,
  idempotencyKey: string
): Promise<AdminCommandResult & { password_change_required: true }> {
  return (await apiMutation<{ data: AdminCommandResult & { password_change_required: true } }>(
    `/api/v1/admin/users/${user.id}/password-reset`, {
      temporary_password: temporaryPassword,
      temporary_password_confirmation: confirmation,
    }, {
      expectedVersion: user.record_version,
      idempotencyKey,
    }
  )).data;
}

export async function setUserMfaRequirement(
  user: UserAdmin,
  required: boolean,
  idempotencyKey: string
): Promise<AdminCommandResult & { mfa_required: boolean }> {
  return (await apiMutation<{ data: AdminCommandResult & { mfa_required: boolean } }>(
    `/api/v1/admin/users/${user.id}/mfa`, { required }, {
      expectedVersion: user.record_version,
      idempotencyKey,
    }
  )).data;
}

export async function deleteUser(
  user: UserAdmin,
  idempotencyKey: string
): Promise<AdminCommandResult & { deleted: true }> {
  return (await apiMutation<{ data: AdminCommandResult & { deleted: true } }>(
    `/api/v1/admin/users/${user.id}/delete`, {}, {
      expectedVersion: user.record_version,
      idempotencyKey,
    }
  )).data;
}

export async function createRoleAssignment(
  userId: string,
  body: { role_id: string; effective_from: string | null; effective_to: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/users/${userId}/assignments`, body, {
    idempotencyKey,
  })).data;
}

export async function updateRoleAssignment(
  assignment: RoleAssignmentAdmin,
  body: { role_id: string; is_active: boolean; effective_from: string | null; effective_to: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/role-assignments/${assignment.id}`, body, {
    expectedVersion: assignment.record_version,
    idempotencyKey,
  })).data;
}

export async function listRoles(filters: { q?: string; status?: string } = {}): Promise<RoleWorkspace> {
  const query = new URLSearchParams();
  if (filters.q?.trim()) query.set('q', filters.q.trim());
  if (filters.status) query.set('status', filters.status);
  const suffix = query.size ? `?${query.toString()}` : '';
  return apiRequest<RoleWorkspace>(`/api/v1/admin/roles${suffix}`);
}

export async function createRole(
  body: { code: string; name: string; description: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/roles', body, {
    idempotencyKey,
  })).data;
}

export async function updateRole(
  role: RoleAdmin,
  body: Pick<RoleAdmin, 'name' | 'description' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/roles/${role.id}`, body, {
    expectedVersion: role.record_version,
    idempotencyKey,
  })).data;
}

export async function syncRolePermissions(
  role: RoleAdmin,
  permissionIds: string[],
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/roles/${role.id}/permissions`, {
    permission_ids: permissionIds,
  }, {
    expectedVersion: role.record_version,
    idempotencyKey,
  })).data;
}

export async function createPermission(
  body: { code: string; name: string; description: string | null },
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>('/api/v1/admin/permissions', body, {
    idempotencyKey,
  })).data;
}

export async function updatePermission(
  permission: PermissionAdmin,
  body: Pick<PermissionAdmin, 'name' | 'description' | 'status'>,
  idempotencyKey: string
): Promise<AdminCommandResult> {
  return (await apiMutation<{ data: AdminCommandResult }>(`/api/v1/admin/permissions/${permission.id}`, body, {
    expectedVersion: permission.record_version,
    idempotencyKey,
  })).data;
}
