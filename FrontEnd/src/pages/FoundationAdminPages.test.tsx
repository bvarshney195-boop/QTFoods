import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  LocationWorkspace,
  OrganisationWorkspace,
  RoleWorkspace,
  UserWorkspace,
} from '../api/foundationAdmin';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import ADM_LOC from './ADM_LOC';
import ADM_ORG from './ADM_ORG';
import ADM_ROLE from './ADM_ROLE';
import ADM_USER from './ADM_USER';

const apiMocks = vi.hoisted(() => ({
  getOrganisation: vi.fn(),
  createCompany: vi.fn(),
  updateCompany: vi.fn(),
  createPlant: vi.fn(),
  updatePlant: vi.fn(),
  listLocations: vi.fn(),
  createLocation: vi.fn(),
  updateLocation: vi.fn(),
  listUsers: vi.fn(),
  createUser: vi.fn(),
  deleteUser: vi.fn(),
  inviteUser: vi.fn(),
  resetUserPassword: vi.fn(),
  setUserMfaRequirement: vi.fn(),
  updateUser: vi.fn(),
  createRoleAssignment: vi.fn(),
  updateRoleAssignment: vi.fn(),
  listRoles: vi.fn(),
  createRole: vi.fn(),
  updateRole: vi.fn(),
  syncRolePermissions: vi.fn(),
  createPermission: vi.fn(),
  updatePermission: vi.fn(),
  listUserSessions: vi.fn(),
  resendInvitation: vi.fn(),
  revokeInvitation: vi.fn(),
  revokeUserSession: vi.fn(),
  sendUserVerification: vi.fn(),
}));

vi.mock('../api/foundationAdmin', async () => {
  const actual = await vi.importActual<typeof import('../api/foundationAdmin')>('../api/foundationAdmin');
  return { ...actual, ...apiMocks };
});

describe('foundation administration workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('creates a plant from the live organisation workspace', async () => {
    apiMocks.getOrganisation.mockResolvedValue(organisationWorkspace());
    apiMocks.createPlant.mockResolvedValue(commandResult('plant'));
    renderPage(<ADM_ORG />);

    expect(await screen.findByText('Training Plant')).toBeInTheDocument();
    const organisationLayout = screen.getByTestId('organisation-workspace');
    expect(organisationLayout).toHaveClass('organisation-workspace');
    expect(organisationLayout.children[0]?.tagName).toBe('SECTION');
    expect(organisationLayout.children[1]?.tagName).toBe('ASIDE');
    await userEvent.setup().click(screen.getByRole('button', { name: '+ New' }));
    await userEvent.setup().type(screen.getByLabelText('Plant code'), 'pilot');
    await userEvent.setup().type(screen.getByLabelText('Plant name'), 'Pilot Plant');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(apiMocks.createPlant).toHaveBeenCalledWith({
      code: 'PILOT',
      name: 'Pilot Plant',
      timezone: 'Asia/Kolkata',
      status: 'ACTIVE',
    }, expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('Plant created');
  });

  it('creates a hierarchical plant location without prototype data', async () => {
    apiMocks.listLocations.mockResolvedValue(locationWorkspace());
    apiMocks.createLocation.mockResolvedValue(commandResult('location'));
    renderPage(<ADM_LOC />);

    expect(await screen.findByText('Return Quarantine')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: '+ New' }));
    await userEvent.setup().type(screen.getByLabelText('Location code'), 'bulk-01');
    await userEvent.setup().type(screen.getByLabelText('Name'), 'Bulk Store');
    await userEvent.setup().selectOptions(screen.getByLabelText('Location type'), 'ZONE');
    await userEvent.setup().selectOptions(screen.getByLabelText('Parent location'), 'loc-1');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Create location' }));

    await waitFor(() => expect(apiMocks.createLocation).toHaveBeenCalledWith(expect.objectContaining({
      code: 'BULK-01',
      name: 'Bulk Store',
      location_type: 'ZONE',
      parent_location_id: 'loc-1',
    }), expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('BULK-01 was created');
  });

  it('provisions a user with a write-only temporary password and initial role', async () => {
    apiMocks.listUsers.mockResolvedValue(userWorkspace());
    apiMocks.createUser.mockResolvedValue(commandResult('user'));
    renderPage(<ADM_USER />);

    expect(await screen.findByText('Demo ERP Administrator')).toBeInTheDocument();
    const userLayout = screen.getByTestId('user-workspace');
    expect(userLayout).toHaveClass('user-workspace');
    expect(userLayout.children[0]?.tagName).toBe('SECTION');
    expect(userLayout.children[1]?.tagName).toBe('ASIDE');
    await userEvent.setup().click(screen.getByRole('button', { name: '+ New' }));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Direct account' }));
    await userEvent.setup().type(screen.getByLabelText('Name'), 'Plant Auditor');
    await userEvent.setup().type(screen.getByLabelText('Email'), 'AUDITOR@EXAMPLE.LOCAL');
    await userEvent.setup().type(screen.getByLabelText(/Temporary password/), 'TempPass9');
    await userEvent.setup().selectOptions(screen.getByLabelText('Initial role'), 'role-admin');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Create user' }));

    await waitFor(() => expect(apiMocks.createUser).toHaveBeenCalledWith(expect.objectContaining({
      email: 'auditor@example.local',
      name: 'Plant Auditor',
      temporary_password: 'TempPass9',
      role_id: 'role-admin',
    }), expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('created atomically');
  });

  it('creates an invitation-first user without collecting a temporary password', async () => {
    apiMocks.listUsers.mockResolvedValue(userWorkspace());
    apiMocks.inviteUser.mockResolvedValue({
      ...commandResult('identity_invitation'),
      delivery: { channel: 'EMAIL', status: 'SENT', preview_url: '/?flow=invite&token=preview' },
    });
    renderPage(<ADM_USER />);

    await screen.findByText('Demo ERP Administrator');
    await userEvent.setup().click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.queryByLabelText(/Temporary password/)).not.toBeInTheDocument();
    await userEvent.setup().type(screen.getByLabelText('Name'), 'Invited Auditor');
    await userEvent.setup().type(screen.getByLabelText('Email'), 'INVITED@EXAMPLE.LOCAL');
    await userEvent.setup().selectOptions(screen.getByLabelText('Initial role'), 'role-admin');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() => expect(apiMocks.inviteUser).toHaveBeenCalledWith(expect.objectContaining({
      email: 'invited@example.local', name: 'Invited Auditor', role_id: 'role-admin',
    }), expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('Invitation and initial plant role assignment');
    expect(screen.getByRole('link', { name: 'Open development email link' })).toBeInTheDocument();
  });

  it('issues a write-only temporary password that must be replaced', async () => {
    apiMocks.listUsers.mockResolvedValue(userWorkspace());
    apiMocks.resetUserPassword.mockResolvedValue({
      ...commandResult('user'), record_version: 2, password_change_required: true,
    });
    renderPage(<ADM_USER />);

    const targetRow = (await screen.findByText('Plant Auditor')).closest('tr');
    await userEvent.setup().click(within(targetRow!).getByRole('button', { name: 'Reset password' }));
    const dialog = screen.getByRole('dialog', { name: 'Reset password for Plant Auditor' });
    await userEvent.setup().type(within(dialog).getByLabelText(/^Temporary password/), 'TempPass9x');
    await userEvent.setup().type(within(dialog).getByLabelText('Confirm temporary password'), 'TempPass9x');
    await userEvent.setup().click(within(dialog).getByRole('button', { name: 'Issue temporary password' }));

    await waitFor(() => expect(apiMocks.resetUserPassword).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'user-target', record_version: 1 }),
      'TempPass9x',
      'TempPass9x',
      expect.any(String)
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('must replace it at next sign-in');
  });

  it('requires confirmation before enabling MFA or deleting a user', async () => {
    apiMocks.listUsers.mockResolvedValue(userWorkspace());
    apiMocks.setUserMfaRequirement.mockResolvedValue({ ...commandResult('user'), record_version: 2, mfa_required: true });
    apiMocks.deleteUser.mockResolvedValue({ ...commandResult('user'), status: 'DELETED', record_version: 2, deleted: true });
    renderPage(<ADM_USER />);

    const targetRow = (await screen.findByText('Plant Auditor')).closest('tr');
    await userEvent.setup().click(within(targetRow!).getByRole('button', { name: 'Open' }));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Enable MFA' }));
    const mfaDialog = screen.getByRole('alertdialog', { name: 'Enable MFA for Plant Auditor?' });
    await userEvent.setup().click(within(mfaDialog).getByRole('button', { name: 'Enable MFA' }));
    await waitFor(() => expect(apiMocks.setUserMfaRequirement).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'user-target' }), true, expect.any(String)
    ));

    await userEvent.setup().click(screen.getByRole('button', { name: 'Delete user' }));
    const deleteDialog = screen.getByRole('alertdialog', { name: 'Delete Plant Auditor?' });
    await userEvent.setup().click(within(deleteDialog).getByRole('button', { name: 'Delete user' }));
    await waitFor(() => expect(apiMocks.deleteUser).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'user-target' }), expect.any(String)
    ));
  });

  it('replaces permissions on a scoped custom role using its current version', async () => {
    apiMocks.listRoles.mockResolvedValue(roleWorkspace());
    apiMocks.syncRolePermissions.mockResolvedValue({ ...commandResult('role'), record_version: 2 });
    renderPage(<ADM_ROLE />);

    const row = (await screen.findByText('Location Auditor')).closest('tr');
    expect(row).not.toBeNull();
    await userEvent.setup().click(within(row!).getByRole('button', { name: 'Open' }));
    await userEvent.setup().click(screen.getByRole('checkbox', { name: /Export locations/ }));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Save permissions' }));

    await waitFor(() => expect(apiMocks.syncRolePermissions).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'role-custom', record_version: 1 }),
      ['permission-view', 'permission-export'],
      expect.any(String)
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('permissions were replaced atomically');
  });
});

function renderPage(page: React.ReactNode) {
  return render(<ErpSessionContext.Provider value={session()}>{page}</ErpSessionContext.Provider>);
}

function session(): ErpSession {
  return {
    user: { id: 'user-admin', name: 'Demo ERP Administrator', email: 'admin.user@qtfoods.local' },
    roles: ['ERP_ADMIN'],
    allowed_screens: ['ADM-ORG', 'ADM-LOC', 'ADM-USER', 'ADM-ROLE'],
    allowed_actions: [],
    contexts: [],
    selected_context: {
      company_id: 'company-1', company_name: 'Q & T Foods Ltd',
      plant_id: 'plant-1', plant_name: 'Training Plant',
    },
  };
}

function organisationWorkspace(): OrganisationWorkspace {
  return {
    data: {
      company: {
        id: 'company-1', code: 'QTF', legal_name: 'Q & T Foods Ltd', display_name: 'Q & T Foods Ltd',
        status: 'ACTIVE', record_version: 1, allowed_actions: ['UPDATE'], created_at: now, updated_at: now,
      },
      plants: [{
        id: 'plant-1', company_id: 'company-1', code: 'TRAINING', name: 'Training Plant', timezone: 'Asia/Kolkata',
        status: 'ACTIVE', location_count: 4, active_user_count: 4, record_version: 1,
        allowed_actions: ['UPDATE'], created_at: now, updated_at: now,
      }],
      summary: { plant_count: 1, active_plants: 1, location_count: 4, active_users: 4 },
      allowed_actions: ['CREATE_COMPANY', 'CREATE_PLANT'],
    },
  };
}

function locationWorkspace(): LocationWorkspace {
  return {
    data: [{
      id: 'loc-1', company_id: 'company-1', plant_id: 'plant-1', code: 'RET-QA', name: 'Return Quarantine',
      description: null, location_type: 'RETURN_QUARANTINE', status: 'ACTIVE', parent: null,
      child_count: 0, position_count: 2, quantity_on_hand: '0.000000', record_version: 1,
      allowed_actions: ['UPDATE'], created_at: now, updated_at: now,
    }],
    summary: { total: 1, active: 1, with_stock_positions: 1, root_locations: 1 },
    lookups: { parents: [{ id: 'loc-1', code: 'RET-QA', name: 'Return Quarantine' }] },
    allowed_actions: ['CREATE'],
  };
}

function userWorkspace(): UserWorkspace {
  return {
    data: [{
      id: 'user-admin', email: 'admin.user@qtfoods.local', name: 'Demo ERP Administrator', status: 'ACTIVE',
      email_verified: true, email_verified_at: now, mfa_enabled: false, mfa_enabled_at: null,
      mfa_required: true, mfa_requirement_source: 'ROLE', password_change_required: false, deleted_at: null,
      last_login_at: now, active_session_count: 1, invitation: null,
      assignments: [], active_assignment_count: 1, record_version: 1,
      allowed_actions: ['UPDATE', 'ASSIGN_ROLE', 'MANAGE_MFA'], created_at: now, updated_at: now,
    }, {
      id: 'user-target', email: 'auditor@example.local', name: 'Plant Auditor', status: 'ACTIVE',
      email_verified: true, email_verified_at: now, mfa_enabled: false, mfa_enabled_at: null,
      mfa_required: false, mfa_requirement_source: 'NONE', password_change_required: false, deleted_at: null,
      last_login_at: null, active_session_count: 1, invitation: null,
      assignments: [], active_assignment_count: 1, record_version: 1,
      allowed_actions: ['UPDATE', 'ASSIGN_ROLE', 'MANAGE_SESSIONS', 'RESET_PASSWORD', 'MANAGE_MFA', 'DELETE'], created_at: now, updated_at: now,
    }],
    summary: { total: 2, active: 2, invited: 0, unverified: 0, active_assignments: 2, roles_in_use: 1 },
    lookups: { roles: [{ id: 'role-admin', code: 'ERP_ADMIN', name: 'ERP Administrator', is_system: true }] },
    allowed_actions: ['CREATE', 'INVITE'],
  };
}

function roleWorkspace(): RoleWorkspace {
  return {
    data: [{
      id: 'role-custom', company_id: 'company-1', code: 'LOCATION_AUDITOR', name: 'Location Auditor',
      description: 'Read and export locations.', status: 'ACTIVE', is_system: false,
      permission_ids: ['permission-view'], permission_count: 1, active_assignment_count: 0,
      record_version: 1, allowed_actions: ['UPDATE', 'SYNC_PERMISSIONS'], created_at: now, updated_at: now,
    }],
    permissions: [
      { id: 'permission-view', company_id: null, code: 'SCREEN:ADM-LOC:VIEW', name: 'View locations', description: null, status: 'ACTIVE', is_system: true, role_count: 1, record_version: 1, allowed_actions: [], created_at: now, updated_at: now },
      { id: 'permission-export', company_id: 'company-1', code: 'ACTION:ADM-LOC:EXPORT', name: 'Export locations', description: null, status: 'ACTIVE', is_system: false, role_count: 0, record_version: 1, allowed_actions: ['UPDATE'], created_at: now, updated_at: now },
    ],
    summary: { roles: 1, custom_roles: 1, permissions: 2, active_assignments: 0 },
    allowed_actions: ['CREATE_ROLE', 'CREATE_PERMISSION'],
  };
}

function commandResult(type: string) {
  return { entity_type: type, id: `${type}-new`, status: 'ACTIVE', record_version: 1 };
}

const now = '2026-09-09T10:00:00.000Z';
