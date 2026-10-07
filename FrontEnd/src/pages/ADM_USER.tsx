import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createRoleAssignment,
  createUser,
  deleteUser,
  inviteUser,
  listUsers,
  listUserSessions,
  resendInvitation,
  revokeInvitation,
  revokeUserSession,
  resetUserPassword,
  setUserMfaRequirement,
  sendUserVerification,
  updateRoleAssignment,
  updateUser,
  type UserStatus,
  type RoleAssignmentAdmin,
  type UserAdmin,
  type UserWorkspace,
} from '../api/foundationAdmin';
import type { DeviceSession } from '../api/identity';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';
import { formatZonedDateTime } from '../utils/dateTime';

type UserForm = {
  email: string;
  name: string;
  status: UserStatus;
  temporary_password: string;
  role_id: string;
  effective_from: string;
  effective_to: string;
};

function blankUser(): UserForm {
  return {
    email: '', name: '', status: 'ACTIVE', temporary_password: '', role_id: '', effective_from: '', effective_to: '',
  };
}

export default function ADM_USER() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<UserWorkspace | null>(null);
  const [creationMode, setCreationMode] = useState<'invite' | 'direct'>('invite');
  const [selected, setSelected] = useState<UserAdmin | null>(null);
  const [form, setForm] = useState<UserForm>(blankUser);
  const [assignmentRoleId, setAssignmentRoleId] = useState('');
  const [assignmentFrom, setAssignmentFrom] = useState('');
  const [assignmentTo, setAssignmentTo] = useState('');
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [busyAssignment, setBusyAssignment] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [identityLink, setIdentityLink] = useState<string | null>(null);
  const [deviceSessions, setDeviceSessions] = useState<DeviceSession[] | null>(null);
  const [passwordResetTarget, setPasswordResetTarget] = useState<UserAdmin | null>(null);
  const [temporaryPassword, setTemporaryPassword] = useState('');
  const [temporaryPasswordConfirmation, setTemporaryPasswordConfirmation] = useState('');
  const [pendingDelete, setPendingDelete] = useState<UserAdmin | null>(null);
  const [pendingMfa, setPendingMfa] = useState<{ user: UserAdmin; required: boolean } | null>(null);
  const commandKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listUsers({ q: search || undefined, status: status || undefined });
      setWorkspace(result);
      if (selectedId.current) {
        const updated = result.data.find((user) => user.id === selectedId.current) ?? null;
        setSelected(updated);
        if (updated) setForm(toForm(updated));
      }
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load users and role assignments.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, status]);

  useEffect(() => {
    setWorkspace(null);
    setSelected(null);
    selectedId.current = null;
    setForm(blankUser());
    setSuccess(null);
  }, [contextKey]);
  useEffect(() => { void refresh(); }, [refresh]);

  function choose(user: UserAdmin) {
    selectedId.current = user.id;
    setSelected(user);
    setForm(toForm(user));
    setIdentityLink(null);
    setDeviceSessions(null);
    closeSecurityDialogs();
    resetAssignmentForm();
    clearFeedback();
  }

  function startCreate() {
    selectedId.current = null;
    setSelected(null);
    setForm(blankUser());
    setCreationMode('invite');
    setIdentityLink(null);
    setDeviceSessions(null);
    closeSecurityDialogs();
    resetAssignmentForm();
    clearFeedback();
  }

  function startPasswordReset(user: UserAdmin) {
    clearFeedback();
    setTemporaryPassword('');
    setTemporaryPasswordConfirmation('');
    setPasswordResetTarget(user);
  }

  function change(patch: Partial<UserForm>) {
    setForm((current) => ({ ...current, ...patch }));
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  async function submitUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    setFieldErrors({});
    commandKey.current ??= globalThis.crypto.randomUUID();
    try {
      if (selected) {
        await updateUser(selected, {
          email: form.email.trim().toLowerCase(),
          name: form.name.trim(),
          status: form.status,
        }, commandKey.current);
        setSuccess(`${form.name.trim()} was saved with a new record version.`);
      } else if (creationMode === 'invite') {
        const result = await inviteUser({
          email: form.email.trim().toLowerCase(),
          name: form.name.trim(),
          role_id: form.role_id,
          effective_from: form.effective_from || null,
          effective_to: form.effective_to || null,
        }, commandKey.current);
        setIdentityLink(result.delivery?.preview_url ?? null);
        setSuccess('Invitation and initial plant role assignment were created atomically.');
        setForm(blankUser());
      } else {
        await createUser({
          email: form.email.trim().toLowerCase(),
          name: form.name.trim(),
          temporary_password: form.temporary_password,
          role_id: form.role_id,
          effective_from: form.effective_from || null,
          effective_to: form.effective_to || null,
        }, commandKey.current);
        setSuccess('User and initial plant role assignment were created atomically.');
        setForm(blankUser());
      }
      commandKey.current = null;
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to save this user.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function manageInvitation(action: 'resend' | 'revoke') {
    if (!selected?.invitation || busy) return;
    setBusy(true); clearFeedback();
    try {
      const result = action === 'resend'
        ? await resendInvitation(selected.invitation, globalThis.crypto.randomUUID())
        : await revokeInvitation(selected.invitation, globalThis.crypto.randomUUID());
      setIdentityLink(result.delivery?.preview_url ?? null);
      setSuccess(action === 'resend' ? 'Invitation token rotated and a new link was sent.' : 'Invitation revoked and the invited account was disabled.');
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, `Unable to ${action} this invitation.`));
    } finally {
      setBusy(false);
    }
  }

  async function sendVerification() {
    if (!selected || busy) return;
    setBusy(true); clearFeedback();
    try {
      const result = await sendUserVerification(selected.id, globalThis.crypto.randomUUID());
      setIdentityLink(result.delivery?.preview_url ?? null);
      setSuccess('A one-time email verification link was sent.');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to send an email verification link.'));
    } finally {
      setBusy(false);
    }
  }

  async function submitTemporaryPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!passwordResetTarget || busy) return;
    setBusy(true); clearFeedback();
    try {
      await resetUserPassword(
        passwordResetTarget,
        temporaryPassword,
        temporaryPasswordConfirmation,
        globalThis.crypto.randomUUID()
      );
      setPasswordResetTarget(null);
      setTemporaryPassword('');
      setTemporaryPasswordConfirmation('');
      setSuccess(`A temporary password was issued for ${passwordResetTarget.email}. The user must replace it at next sign-in.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to issue a temporary password.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function confirmMfaChange() {
    if (!pendingMfa || busy) return;
    const target = pendingMfa.user;
    const required = pendingMfa.required;
    setBusy(true); clearFeedback();
    try {
      await setUserMfaRequirement(target, required, globalThis.crypto.randomUUID());
      setPendingMfa(null);
      setSuccess(required
        ? `MFA is now required for ${target.email}; enrolment will be completed by the user after identity proof.`
        : `MFA was disabled for ${target.email}; authenticator credentials and active sessions were revoked.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, `Unable to ${required ? 'enable' : 'disable'} MFA.`));
      setPendingMfa(null);
    } finally {
      setBusy(false);
    }
  }

  async function confirmDelete() {
    if (!pendingDelete || busy) return;
    const target = pendingDelete;
    setBusy(true); clearFeedback();
    try {
      await deleteUser(target, globalThis.crypto.randomUUID());
      setPendingDelete(null);
      selectedId.current = null;
      setSelected(null);
      setForm(blankUser());
      setDeviceSessions(null);
      setSuccess(`${target.email} was deleted. Audit history was retained, all access was revoked, and the email can now be used for a new account.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to delete this user.'));
      setPendingDelete(null);
    } finally {
      setBusy(false);
    }
  }

  async function loadSessions() {
    if (!selected) return;
    setBusy(true); clearFeedback();
    try {
      setDeviceSessions((await listUserSessions(selected.id)).data);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this user\'s device sessions.'));
    } finally {
      setBusy(false);
    }
  }

  async function revokeSession(device: DeviceSession) {
    if (!selected || busy) return;
    setBusy(true); clearFeedback();
    try {
      const result = await revokeUserSession(selected.id, device.id);
      setSuccess('Device session revoked.');
      if (result.current) window.dispatchEvent(new Event('erp:session-expired'));
      else await loadSessions();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to revoke this device session.'));
    } finally {
      setBusy(false);
    }
  }

  async function addAssignment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected || busyAssignment || !assignmentRoleId) return;
    const key = globalThis.crypto.randomUUID();
    setBusyAssignment('new');
    setError(null);
    setSuccess(null);
    try {
      await createRoleAssignment(selected.id, {
        role_id: assignmentRoleId,
        effective_from: assignmentFrom || null,
        effective_to: assignmentTo || null,
      }, key);
      setSuccess('Role assignment added to the selected plant.');
      resetAssignmentForm();
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to add the role assignment.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusyAssignment(null);
    }
  }

  async function toggleAssignment(assignment: RoleAssignmentAdmin) {
    if (busyAssignment) return;
    setBusyAssignment(assignment.id);
    setError(null);
    setSuccess(null);
    try {
      await updateRoleAssignment(assignment, {
        role_id: assignment.role.id,
        is_active: !assignment.is_active,
        effective_from: assignment.effective_from,
        effective_to: assignment.effective_to,
      }, globalThis.crypto.randomUUID());
      setSuccess(`${assignment.role.name} assignment ${assignment.is_active ? 'deactivated' : 'reactivated'}.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to change the role assignment.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusyAssignment(null);
    }
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSearch(searchDraft.trim());
  }

  return (
    <>
      <PageHeader
        code="ADM-USER"
        batch="B01"
        title="Users & Assignments"
        description="Invitation-first identity, verified access, revocable devices, and effective-dated roles in the selected plant."
        onNew={workspace?.allowed_actions.some((action) => action === 'CREATE' || action === 'INVITE') ? startCreate : undefined}
      />
      <div className="live-notice"><span></span><b>Managed identity lifecycle</b> Expiring hashed links, verified email, MFA state, and revocable logical device sessions are live; raw credentials are never retained.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Scoped users</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>assigned to this plant</small></div>
        <div className="kpi"><span>Invitations</span><b>{loading && !workspace ? '-' : workspace?.summary.invited ?? 0}</b><small>awaiting acceptance</small></div>
        <div className="kpi"><span>Unverified</span><b>{loading && !workspace ? '-' : workspace?.summary.unverified ?? 0}</b><small>email action required</small></div>
        <div className="kpi"><span>Roles in use</span><b>{loading && !workspace ? '-' : workspace?.summary.roles_in_use ?? 0}</b><small>distinct authorities</small></div>
      </div>

      <div className="module-grid admin-workspace user-workspace" data-testid="user-workspace">
        <section className="panel">
          <div className="panel-head"><div><h3>User register</h3><span>{session.selected_context?.plant_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="admin-toolbar two-filter" onSubmit={submitSearch}>
            <label>Search<span><input aria-label="Search users" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Name or email" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label="Filter user status" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option><option>ACTIVE</option><option>INVITED</option><option>INACTIVE</option></select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading scoped users...</div>}
          {!loading && workspace && !workspace.data.length && <div className="empty-state">No users match the current filters.</div>}
          {workspace && Boolean(workspace.data.length) && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}>
            <table className="admin-table"><thead><tr><th>User</th><th>Status</th><th>Identity</th><th>Active roles</th><th>Sessions</th><th>Action</th></tr></thead><tbody>
              {workspace.data.map((user) => <tr key={user.id}>
                <td><b>{user.name}</b><small>{user.email}</small></td>
                <td><StatusBadge status={user.deleted_at ? 'DELETED' : user.status} /></td>
                <td><small>{user.email_verified ? 'Email verified' : 'Email unverified'}</small><small>{user.mfa_enabled ? 'Authenticator enrolled' : user.mfa_required ? 'MFA enrolment pending' : 'MFA optional'}</small></td>
                <td>{user.assignments.filter((assignment) => assignment.is_active).map((assignment) => assignment.role.name).join(', ') || 'None'}</td>
                <td>{user.active_session_count} active</td>
                <td><div className="admin-inline-actions user-register-actions"><button className="secondary compact-button" type="button" onClick={() => choose(user)}>Open</button>{user.allowed_actions.includes('RESET_PASSWORD') && user.id !== session.user.id && <button className="secondary compact-button" type="button" disabled={busy} onClick={() => startPasswordReset(user)}>Reset password</button>}</div></td>
              </tr>)}
            </tbody></table>
          </div>}
        </section>

        <aside className="panel admin-editor user-editor">
          <div className="panel-head"><h3>{selected ? 'User detail' : 'New user'}</h3><span>{selected ? `v${selected.record_version}` : creationMode === 'invite' ? 'Invitation' : 'Direct local account'}</span></div>
          {!selected && workspace?.allowed_actions.includes('INVITE') && workspace.allowed_actions.includes('CREATE') && <div className="identity-mode" role="group" aria-label="Account creation method"><button type="button" className={creationMode === 'invite' ? 'active' : ''} onClick={() => { setCreationMode('invite'); clearFeedback(); }}>Invite user</button><button type="button" className={creationMode === 'direct' ? 'active' : ''} onClick={() => { setCreationMode('direct'); clearFeedback(); }}>Direct account</button></div>}
          <form className="panel-body form-grid admin-form" onSubmit={submitUser} noValidate>
            {error && workspace && <div className="form-error full" role="alert"><span>{error}</span></div>}
            {success && <div className="form-success full" role="status"><span></span>{success}</div>}
            {identityLink && <a className="identity-preview full" href={identityLink}>Open development email link</a>}
            <label className="full">Name<input value={form.name} onChange={(event) => change({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
            <label className="full">Email<input type="email" value={form.email} readOnly={selected?.status === 'INVITED'} onChange={(event) => change({ email: event.target.value })} />{selected?.status === 'INVITED' && <span className="field-hint">Revoke and create a new invitation to correct this address.</span>}<FieldError value={fieldErrors.email} /></label>
            {selected ? <label className="full">Account status<select value={form.status} onChange={(event) => change({ status: event.target.value as UserStatus })}><option>ACTIVE</option><option disabled={selected.status !== 'INVITED'}>INVITED</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label> : <>
              {creationMode === 'direct' && <label className="full">Temporary password<input type="password" value={form.temporary_password} onChange={(event) => change({ temporary_password: event.target.value })} autoComplete="new-password" minLength={9} required /><span className="field-hint">At least 9 characters with upper/lowercase letters and a number; replacement is required at first sign-in</span><FieldError value={fieldErrors.temporary_password} /></label>}
              <label className="full">Initial role<select value={form.role_id} onChange={(event) => change({ role_id: event.target.value })}><option value="">Select an active role</option>{workspace?.lookups.roles.map((role) => <option key={role.id} value={role.id}>{role.name} ({role.code})</option>)}</select><FieldError value={fieldErrors.role_id} /></label>
              <label>Effective from<input type="datetime-local" value={form.effective_from} onChange={(event) => change({ effective_from: event.target.value })} /><FieldError value={fieldErrors.effective_from} /></label>
              <label>Effective to<input type="datetime-local" value={form.effective_to} onChange={(event) => change({ effective_to: event.target.value })} /><FieldError value={fieldErrors.effective_to} /></label>
            </>}
            <div className="form-actions full"><button className="primary" type="submit" disabled={busy || (selected ? !selected.allowed_actions.includes('UPDATE') : !workspace?.allowed_actions.includes(creationMode === 'invite' ? 'INVITE' : 'CREATE'))}>{busy ? 'Saving...' : selected ? 'Save account' : creationMode === 'invite' ? 'Send invitation' : 'Create user'}</button></div>
          </form>

          {selected && <div className="identity-admin">
            <div className="subsection-head"><div><b>Identity controls</b><small>{selected.email_verified ? 'Verified email' : 'Email verification pending'} · {selected.mfa_required ? `MFA required by ${selected.mfa_requirement_source === 'ROLE' ? 'role policy' : 'administrator'}` : selected.mfa_enabled ? 'Authenticator available as a sign-in method' : 'MFA optional'}{selected.password_change_required ? ' · temporary password must be replaced' : ''}</small></div></div>
            {selected.invitation && <div className="identity-fact"><span>Invitation</span><b>{selected.invitation.status} · sent {selected.invitation.delivery_count} time(s)</b></div>}
            <div className="admin-inline-actions">
              {selected.allowed_actions.includes('MANAGE_INVITATION') && selected.invitation && <><button className="secondary compact-button" type="button" disabled={busy} onClick={() => void manageInvitation('resend')}>Resend invitation</button><button className="danger-button compact-button" type="button" disabled={busy} onClick={() => void manageInvitation('revoke')}>Revoke invitation</button></>}
              {selected.allowed_actions.includes('SEND_VERIFICATION') && <button className="secondary compact-button" type="button" disabled={busy} onClick={() => void sendVerification()}>Send verification</button>}
              {selected.allowed_actions.includes('MANAGE_SESSIONS') && <button className="secondary compact-button" type="button" disabled={busy} onClick={() => void loadSessions()}>Review device sessions</button>}
              {selected.allowed_actions.includes('RESET_PASSWORD') && selected.id !== session.user.id && <button className="secondary compact-button" type="button" disabled={busy} onClick={() => startPasswordReset(selected)}>Set temporary password</button>}
              {selected.allowed_actions.includes('MANAGE_MFA') && selected.mfa_requirement_source === 'ROLE' && <button className="secondary compact-button" type="button" disabled title="ERP Administrator role policy requires MFA">MFA required by role</button>}
              {selected.allowed_actions.includes('MANAGE_MFA') && selected.mfa_requirement_source !== 'ROLE' && selected.id !== session.user.id && <button className={selected.mfa_enabled || selected.mfa_required ? 'danger-button compact-button' : 'secondary compact-button'} type="button" disabled={busy} onClick={() => { clearFeedback(); setPendingMfa({ user: selected, required: !(selected.mfa_enabled || selected.mfa_required) }); }}>{selected.mfa_enabled || selected.mfa_required ? 'Disable MFA' : 'Enable MFA'}</button>}
              {selected.allowed_actions.includes('DELETE') && selected.id !== session.user.id && <button className="danger-button compact-button" type="button" disabled={busy} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete user</button>}
            </div>
            {deviceSessions && <div className="admin-devices">{deviceSessions.length === 0 && <div className="empty-state">No device sessions recorded.</div>}{deviceSessions.map((device) => <div className="assignment-row" key={device.id}><div><b>{device.user_agent?.includes('Chrome') ? 'Chrome browser' : device.user_agent ?? 'Unknown browser'}</b><small>{device.ip_address ?? 'Unknown IP'} · {formatZonedDateTime(device.last_seen_at)}</small></div><div><StatusBadge status={device.status} />{device.status === 'ACTIVE' && <button className="danger-button compact-button" type="button" disabled={busy} onClick={() => void revokeSession(device)}>Revoke</button>}</div></div>)}</div>}
          </div>}

          {selected && <div className="assignment-admin">
            <div className="subsection-head"><div><b>Plant role assignments</b><small>Effective authority in this context</small></div></div>
            {selected.assignments.map((assignment) => <div className="assignment-row" key={assignment.id}>
              <div><b>{assignment.role.name}</b><small>{assignment.role.code} · v{assignment.record_version}</small><small>{dateRange(assignment)}</small></div>
              <div><StatusBadge status={assignment.is_active ? 'ACTIVE' : 'INACTIVE'} />{assignment.allowed_actions.includes('UPDATE') && <button className="secondary compact-button" type="button" disabled={Boolean(busyAssignment)} onClick={() => void toggleAssignment(assignment)}>{assignment.is_active ? 'Deactivate' : 'Reactivate'}</button>}</div>
            </div>)}
            {!selected.assignments.length && <div className="empty-state">No role assignments remain in this plant.</div>}

            {selected.allowed_actions.includes('ASSIGN_ROLE') && <form className="assignment-form" onSubmit={addAssignment}>
              <label>Additional role<select aria-label="Additional role" value={assignmentRoleId} onChange={(event) => setAssignmentRoleId(event.target.value)}><option value="">Select a role</option>{workspace?.lookups.roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select></label>
              <div><label>From<input aria-label="Assignment effective from" type="datetime-local" value={assignmentFrom} onChange={(event) => setAssignmentFrom(event.target.value)} /></label><label>To<input aria-label="Assignment effective to" type="datetime-local" value={assignmentTo} onChange={(event) => setAssignmentTo(event.target.value)} /></label></div>
              <button className="secondary" type="submit" disabled={!assignmentRoleId || Boolean(busyAssignment)}>{busyAssignment === 'new' ? 'Adding...' : 'Add assignment'}</button>
            </form>}
          </div>}
        </aside>
      </div>

      {passwordResetTarget && <div className="confirm-overlay" role="presentation"><section className="confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="temporary-password-title"><h3 id="temporary-password-title">Reset password for {passwordResetTarget.name}</h3><p>Set a write-only temporary password. Existing sessions and reset links will be revoked, and the user must choose a permanent password before opening ERP data.</p><form className="form-grid security-password" onSubmit={submitTemporaryPassword}><label className="full">Temporary password<input autoFocus type="password" autoComplete="new-password" minLength={9} value={temporaryPassword} onChange={(event) => setTemporaryPassword(event.target.value)} required /><span className="field-hint">At least 9 characters with upper/lowercase letters and a number</span><FieldError value={fieldErrors.temporary_password} /></label><label className="full">Confirm temporary password<input type="password" autoComplete="new-password" minLength={9} value={temporaryPasswordConfirmation} onChange={(event) => setTemporaryPasswordConfirmation(event.target.value)} required /></label><div className="form-actions full"><button className="secondary" type="button" onClick={() => { setPasswordResetTarget(null); setTemporaryPassword(''); setTemporaryPasswordConfirmation(''); clearFeedback(); }} disabled={busy}>Cancel</button><button className="primary" type="submit" disabled={busy || !temporaryPassword || temporaryPassword !== temporaryPasswordConfirmation}>{busy ? 'Resetting…' : 'Issue temporary password'}</button></div></form></section></div>}

      {pendingMfa && <div className="confirm-overlay" role="presentation"><section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="mfa-policy-title"><h3 id="mfa-policy-title">{pendingMfa.required ? 'Enable' : 'Disable'} MFA for {pendingMfa.user.name}?</h3><p>{pendingMfa.required ? 'All active sessions will end. The user must complete two independent factors and will enrol their own authenticator after identity proof when needed.' : 'All active sessions will end, and the user’s authenticator secret and unused recovery codes will be removed.'}</p><div className="form-actions"><button className="secondary" type="button" autoFocus onClick={() => setPendingMfa(null)} disabled={busy}>Cancel</button><button className={pendingMfa.required ? 'primary' : 'danger-button'} type="button" onClick={() => void confirmMfaChange()} disabled={busy}>{busy ? 'Applying…' : pendingMfa.required ? 'Enable MFA' : 'Disable MFA'}</button></div></section></div>}

      {pendingDelete && <div className="confirm-overlay" role="presentation"><section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="delete-user-title"><h3 id="delete-user-title">Delete {pendingDelete.name}?</h3><p>This immediately revokes the user’s sessions, tokens, invitations, MFA credentials, and role assignments. The identity remains in audit history, while its email address is released so a new account can use it.</p><div className="form-actions"><button className="secondary" type="button" autoFocus onClick={() => setPendingDelete(null)} disabled={busy}>Cancel</button><button className="danger-button" type="button" onClick={() => void confirmDelete()} disabled={busy}>{busy ? 'Deleting…' : 'Delete user'}</button></div></section></div>}
    </>
  );

  function resetAssignmentForm() {
    setAssignmentRoleId(''); setAssignmentFrom(''); setAssignmentTo('');
  }

  function clearFeedback() {
    commandKey.current = null; setError(null); setSuccess(null); setFieldErrors({}); setIdentityLink(null);
  }

  function closeSecurityDialogs() {
    setPasswordResetTarget(null); setTemporaryPassword(''); setTemporaryPasswordConfirmation('');
    setPendingDelete(null); setPendingMfa(null);
  }
}

function toForm(user: UserAdmin): UserForm {
  return { email: user.email, name: user.name, status: user.status, temporary_password: '', role_id: '', effective_from: '', effective_to: '' };
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

function dateRange(assignment: RoleAssignmentAdmin) {
  const from = assignment.effective_from ? formatZonedDateTime(assignment.effective_from) : 'Immediately';
  const to = assignment.effective_to ? formatZonedDateTime(assignment.effective_to) : 'No expiry';
  return `${from} to ${to}`;
}
