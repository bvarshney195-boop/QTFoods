import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createPermission,
  createRole,
  listRoles,
  syncRolePermissions,
  updatePermission,
  updateRole,
  type DefinitionStatus,
  type PermissionAdmin,
  type RoleAdmin,
  type RoleWorkspace,
} from '../api/foundationAdmin';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';

type Editor = 'new-role' | 'role' | 'new-permission' | 'permission';
type DefinitionForm = { code: string; name: string; description: string; status: DefinitionStatus };

const blankDefinition = (): DefinitionForm => ({ code: '', name: '', description: '', status: 'ACTIVE' });

export default function ADM_ROLE() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<RoleWorkspace | null>(null);
  const [editor, setEditor] = useState<Editor>('new-role');
  const [selectedRole, setSelectedRole] = useState<RoleAdmin | null>(null);
  const [selectedPermission, setSelectedPermission] = useState<PermissionAdmin | null>(null);
  const [form, setForm] = useState<DefinitionForm>(blankDefinition);
  const [permissionIds, setPermissionIds] = useState<string[]>([]);
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [permissionSearch, setPermissionSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [permissionBusy, setPermissionBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const commandKey = useRef<string | null>(null);
  const selectedRoleId = useRef<string | null>(null);
  const selectedPermissionId = useRef<string | null>(null);
  const activeEditor = useRef<Editor>('new-role');

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listRoles({ q: search || undefined, status: status || undefined });
      setWorkspace(result);
      if (selectedRoleId.current) {
        const updated = result.data.find((role) => role.id === selectedRoleId.current) ?? null;
        setSelectedRole(updated);
        if (updated && activeEditor.current === 'role') {
          setForm(toRoleForm(updated));
          setPermissionIds(updated.permission_ids);
        }
      }
      if (selectedPermissionId.current) {
        const updated = result.permissions.find((permission) => permission.id === selectedPermissionId.current) ?? null;
        setSelectedPermission(updated);
        if (updated && activeEditor.current === 'permission') setForm(toPermissionForm(updated));
      }
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load roles and permissions.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, status]);

  useEffect(() => {
    setWorkspace(null);
    setEditor('new-role');
    activeEditor.current = 'new-role';
    setSelectedRole(null);
    setSelectedPermission(null);
    selectedRoleId.current = null;
    selectedPermissionId.current = null;
    setForm(blankDefinition());
    setPermissionIds([]);
    setSuccess(null);
  }, [contextKey]);
  useEffect(() => { void refresh(); }, [refresh]);

  function openRole(role: RoleAdmin) {
    activeEditor.current = 'role';
    selectedRoleId.current = role.id;
    selectedPermissionId.current = null;
    setEditor('role');
    setSelectedRole(role);
    setSelectedPermission(null);
    setForm(toRoleForm(role));
    setPermissionIds(role.permission_ids);
    clearFeedback();
  }

  function openPermission(permission: PermissionAdmin) {
    activeEditor.current = 'permission';
    selectedPermissionId.current = permission.id;
    selectedRoleId.current = null;
    setEditor('permission');
    setSelectedPermission(permission);
    setSelectedRole(null);
    setForm(toPermissionForm(permission));
    setPermissionIds([]);
    clearFeedback();
  }

  function start(editorType: 'new-role' | 'new-permission') {
    activeEditor.current = editorType;
    selectedRoleId.current = null;
    selectedPermissionId.current = null;
    setEditor(editorType);
    setSelectedRole(null);
    setSelectedPermission(null);
    setForm(blankDefinition());
    setPermissionIds([]);
    clearFeedback();
  }

  function change(patch: Partial<DefinitionForm>) {
    setForm((current) => ({ ...current, ...patch }));
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  async function submitDefinition(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    setFieldErrors({});
    commandKey.current ??= globalThis.crypto.randomUUID();
    try {
      if (editor === 'new-role') {
        await createRole({ code: form.code.trim().toUpperCase(), name: form.name.trim(), description: form.description.trim() || null }, commandKey.current);
        setSuccess('Scoped custom role created. Select it to assign permissions.');
        setForm(blankDefinition());
      } else if (editor === 'role' && selectedRole) {
        await updateRole(selectedRole, { name: form.name.trim(), description: form.description.trim() || null, status: form.status }, commandKey.current);
        setSuccess(`${selectedRole.code} was saved with a new record version.`);
      } else if (editor === 'new-permission') {
        await createPermission({ code: form.code.trim().toUpperCase(), name: form.name.trim(), description: form.description.trim() || null }, commandKey.current);
        setSuccess('Scoped custom permission created.');
        setForm(blankDefinition());
      } else if (selectedPermission) {
        await updatePermission(selectedPermission, { name: form.name.trim(), description: form.description.trim() || null, status: form.status }, commandKey.current);
        setSuccess(`${selectedPermission.code} was saved with a new record version.`);
      }
      commandKey.current = null;
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to save this authority definition.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function savePermissions() {
    if (!selectedRole || permissionBusy) return;
    setPermissionBusy(true);
    setError(null);
    setSuccess(null);
    try {
      await syncRolePermissions(selectedRole, permissionIds, globalThis.crypto.randomUUID());
      setSuccess(`${selectedRole.code} permissions were replaced atomically.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to update role permissions.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setPermissionBusy(false);
    }
  }

  function togglePermission(id: string) {
    setPermissionIds((current) => current.includes(id) ? current.filter((item) => item !== id) : [...current, id]);
    setError(null);
    setSuccess(null);
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSearch(searchDraft.trim());
  }

  const filteredPermissions = workspace?.permissions.filter((permission) => {
    const query = permissionSearch.trim().toLowerCase();
    return !query || `${permission.code} ${permission.name}`.toLowerCase().includes(query);
  }) ?? [];
  const canCreateRole = workspace?.allowed_actions.includes('CREATE_ROLE') ?? false;
  const canCreatePermission = workspace?.allowed_actions.includes('CREATE_PERMISSION') ?? false;
  const canSaveDefinition = editor === 'new-role'
    ? canCreateRole
    : editor === 'new-permission'
      ? canCreatePermission
      : editor === 'role'
        ? selectedRole?.allowed_actions.includes('UPDATE') ?? false
        : selectedPermission?.allowed_actions.includes('UPDATE') ?? false;

  return (
    <>
      <PageHeader
        code="ADM-ROLE"
        batch="B01"
        title="Roles & Permissions"
        description="Protected system authorities and company-scoped custom definitions with versioned permission assignment."
        onNew={canCreateRole ? () => start('new-role') : undefined}
      />
      <div className="live-notice"><span></span><b>Effective authority catalogue</b> Inactive roles and permissions stop contributing to sessions; protected system definitions remain immutable.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Roles</span><b>{loading && !workspace ? '-' : workspace?.summary.roles ?? 0}</b><small>visible in this organisation</small></div>
        <div className="kpi"><span>Custom roles</span><b>{loading && !workspace ? '-' : workspace?.summary.custom_roles ?? 0}</b><small>company scoped</small></div>
        <div className="kpi"><span>Permissions</span><b>{loading && !workspace ? '-' : workspace?.summary.permissions ?? 0}</b><small>system and custom</small></div>
        <div className="kpi"><span>Active assignments</span><b>{loading && !workspace ? '-' : workspace?.summary.active_assignments ?? 0}</b><small>selected plant</small></div>
      </div>

      <div className="module-grid admin-workspace role-workspace">
        <section className="panel">
          <div className="panel-head"><div><h3>Role register</h3><span>{session.selected_context?.plant_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="admin-toolbar two-filter" onSubmit={submitSearch}>
            <label>Search<span><input aria-label="Search roles" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Code, name, or description" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label="Filter role status" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option><option>ACTIVE</option><option>INACTIVE</option></select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading role definitions...</div>}
          {!loading && workspace && !workspace.data.length && <div className="empty-state">No roles match the current filters.</div>}
          {workspace && Boolean(workspace.data.length) && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}>
            <table className="admin-table"><thead><tr><th>Role</th><th>Scope</th><th>Status</th><th>Permissions</th><th>Assignments</th><th>Version</th><th>Action</th></tr></thead><tbody>
              {workspace.data.map((role) => <tr key={role.id}>
                <td><b>{role.name}</b><small>{role.code}</small></td>
                <td>{role.is_system ? 'System' : 'Organisation'}</td>
                <td><StatusBadge status={role.status} /></td>
                <td>{role.permission_count}</td>
                <td>{role.active_assignment_count}</td>
                <td>v{role.record_version}</td>
                <td><button className="secondary compact-button" type="button" onClick={() => openRole(role)}>Open</button></td>
              </tr>)}
            </tbody></table>
          </div>}
        </section>

        <aside className="panel admin-editor role-editor">
          <div className="panel-head"><h3>{editorTitle(editor, selectedRole, selectedPermission)}</h3><span>{selectedRole || selectedPermission ? `v${selectedRole?.record_version ?? selectedPermission?.record_version}` : 'Scoped definition'}</span></div>
          <form className="panel-body form-grid admin-form" onSubmit={submitDefinition} noValidate>
            {error && workspace && <div className="form-error full" role="alert"><span>{error}</span></div>}
            {success && <div className="form-success full" role="status"><span></span>{success}</div>}
            <label className="full">Code<input value={form.code} readOnly={editor === 'role' || editor === 'permission'} className={editor === 'role' || editor === 'permission' ? 'read-only' : ''} onChange={(event) => change({ code: event.target.value.toUpperCase() })} placeholder={editor.includes('permission') ? 'ACTION:MODULE:VERB' : 'ROLE_CODE'} /><FieldError value={fieldErrors.code} /></label>
            <label className="full">Name<input value={form.name} readOnly={isProtected(editor, selectedRole, selectedPermission)} className={isProtected(editor, selectedRole, selectedPermission) ? 'read-only' : ''} onChange={(event) => change({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
            <label className="full">Description<textarea rows={3} maxLength={2000} value={form.description} readOnly={isProtected(editor, selectedRole, selectedPermission)} className={isProtected(editor, selectedRole, selectedPermission) ? 'read-only' : ''} onChange={(event) => change({ description: event.target.value })} /><FieldError value={fieldErrors.description} /></label>
            {(editor === 'role' || editor === 'permission') && <label className="full">Lifecycle status<select value={form.status} disabled={isProtected(editor, selectedRole, selectedPermission)} onChange={(event) => change({ status: event.target.value as DefinitionStatus })}><option>ACTIVE</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label>}
            {isProtected(editor, selectedRole, selectedPermission) && <div className="callout full">This system definition is protected. Create a company-scoped custom definition for local authority changes.</div>}
            <div className="form-actions full">
              {canCreatePermission && !editor.includes('permission') && <button className="secondary" type="button" onClick={() => start('new-permission')}>New permission</button>}
              {!isProtected(editor, selectedRole, selectedPermission) && canSaveDefinition && <button className="primary" type="submit" disabled={busy}>{busy ? 'Saving...' : editor.startsWith('new-') ? 'Create definition' : 'Save definition'}</button>}
            </div>
          </form>

          {editor === 'role' && selectedRole && <div className="permission-matrix">
            <div className="subsection-head"><div><b>Assigned permissions</b><small>{permissionIds.length} selected</small></div>{selectedRole.allowed_actions.includes('SYNC_PERMISSIONS') && <button className="primary compact-button" type="button" onClick={() => void savePermissions()} disabled={permissionBusy}>{permissionBusy ? 'Saving...' : 'Save permissions'}</button>}</div>
            {selectedRole.is_system && <p className="callout">Changes to this shared role affect all users assigned to it. Keep ERP Admin role-management permissions to prevent lockout. Every update is versioned and audited.</p>}
            <input aria-label="Filter permission matrix" value={permissionSearch} onChange={(event) => setPermissionSearch(event.target.value)} placeholder="Filter permission codes" />
            <div className="permission-list">
              {filteredPermissions.map((permission) => <label key={permission.id}><input type="checkbox" checked={permissionIds.includes(permission.id)} disabled={!selectedRole.allowed_actions.includes('SYNC_PERMISSIONS') || permission.status !== 'ACTIVE' || (selectedRole.code === 'ERP_ADMIN' && permissionIds.includes(permission.id) && ['SCREEN:ADM-ROLE:VIEW', 'ACTION:ADM-ROLE:PERMISSIONS'].includes(permission.code))} onChange={() => togglePermission(permission.id)} /><span><b>{permission.name}</b><small>{permission.code}</small></span></label>)}
            </div>
          </div>}
        </aside>
      </div>

      {workspace && <section className="panel permission-catalog">
        <div className="panel-head"><div><h3>Permission catalogue</h3><span>{workspace.permissions.length} visible definitions</span></div>{canCreatePermission && <button className="secondary compact-button" type="button" onClick={() => start('new-permission')}>New permission</button>}</div>
        <div className="table-wrap"><table className="admin-table"><thead><tr><th>Permission</th><th>Scope</th><th>Status</th><th>Roles</th><th>Version</th><th>Action</th></tr></thead><tbody>
          {workspace.permissions.map((permission) => <tr key={permission.id}><td><b>{permission.name}</b><small>{permission.code}</small></td><td>{permission.is_system ? 'System' : 'Organisation'}</td><td><StatusBadge status={permission.status} /></td><td>{permission.role_count}</td><td>v{permission.record_version}</td><td><button className="secondary compact-button" type="button" onClick={() => openPermission(permission)}>Open</button></td></tr>)}
        </tbody></table></div>
      </section>}
    </>
  );

  function clearFeedback() {
    commandKey.current = null; setError(null); setSuccess(null); setFieldErrors({});
  }
}

function toRoleForm(role: RoleAdmin): DefinitionForm {
  return { code: role.code, name: role.name, description: role.description ?? '', status: role.status };
}

function toPermissionForm(permission: PermissionAdmin): DefinitionForm {
  return { code: permission.code, name: permission.name, description: permission.description ?? '', status: permission.status };
}

function editorTitle(editor: Editor, role: RoleAdmin | null, permission: PermissionAdmin | null) {
  if (editor === 'new-role') return 'New custom role';
  if (editor === 'new-permission') return 'New custom permission';
  if (editor === 'role') return role?.name ?? 'Role detail';
  return permission?.name ?? 'Permission detail';
}

function isProtected(editor: Editor, role: RoleAdmin | null, permission: PermissionAdmin | null) {
  return editor === 'role' ? Boolean(role?.is_system) : editor === 'permission' ? Boolean(permission?.is_system) : false;
}

function FieldError({ value }: { value?: string }) {
  return value ? <span className="field-error">{value}</span> : null;
}

function apiMessage(error: unknown, fallback: string) {
  return isApiError(error) ? error.message : fallback;
}

function apiFields(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.fields) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, values]) => [field, values[0] ?? 'Invalid value.']));
}
