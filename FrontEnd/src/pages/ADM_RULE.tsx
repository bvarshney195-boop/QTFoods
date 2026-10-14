import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import {
  createApprovalDelegation,
  createApprovalRule,
  deleteApprovalRule,
  escalateDueApprovals,
  getApprovalGovernance,
  revokeApprovalDelegation,
  updateApprovalRule,
  type ApprovalDelegation,
  type ApprovalGovernanceWorkspace,
  type ApprovalRule,
  type ApprovalRuleStatus,
  type ApprovalRuleWrite,
  type ApprovalWorkPriority,
} from '../api/approvalGovernance';
import { isApiError } from '../api/client';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { DeleteEntryDialog } from '../components/DeleteEntryDialog';
import { StatusBadge } from '../components/StatusBadge';

type RuleForm = ApprovalRuleWrite & { code: string };
type DelegationForm = {
  delegator_id: string;
  delegate_id: string;
  permission_code: string;
  effective_from: string;
  effective_to: string;
  reason: string;
};

const supportedRuleCodes: readonly string[] = [
  'UNSOLD_RETURN_LOSS_APPROVAL',
  'PURCHASE_REQUISITION_APPROVAL',
];

export default function ADM_RULE() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<ApprovalGovernanceWorkspace | null>(null);
  const [selectedRule, setSelectedRule] = useState<ApprovalRule | null>(null);
  const [creatingRule, setCreatingRule] = useState(false);
  const [ruleForm, setRuleForm] = useState<RuleForm>(blankRule());
  const [delegationForm, setDelegationForm] = useState<DelegationForm>(blankDelegation());
  const [loading, setLoading] = useState(true);
  const [ruleBusy, setRuleBusy] = useState(false);
  const [delegationBusy, setDelegationBusy] = useState(false);
  const [escalationBusy, setEscalationBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [pendingDelete, setPendingDelete] = useState<ApprovalRule | null>(null);
  const selectedRuleId = useRef<string | null>(null);
  const ruleKey = useRef<string | null>(null);
  const delegationKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const next = await getApprovalGovernance();
      setWorkspace(next);
      const preferred = selectedRuleId.current
        ? next.data.find((rule) => rule.id === selectedRuleId.current)
        : next.data.find((rule) => rule.scope === 'PLANT' && rule.is_effective)
          ?? next.data.find((rule) => rule.is_effective)
          ?? next.data[0];
      if (preferred && !creatingRule) {
        selectedRuleId.current = preferred.id;
        setSelectedRule(preferred);
        setRuleForm(toRuleForm(preferred));
      }
      setDelegationForm((current) => populateDelegation(current, next));
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load approval governance.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, creatingRule]);

  useEffect(() => {
    selectedRuleId.current = null;
    setSelectedRule(null);
    setCreatingRule(false);
    setRuleForm(blankRule());
    setDelegationForm(blankDelegation());
    setSuccess(null);
  }, [contextKey]);
  useEffect(() => { void refresh(); }, [refresh]);

  function openRule(rule: ApprovalRule) {
    selectedRuleId.current = rule.id;
    setSelectedRule(rule);
    setCreatingRule(false);
    setRuleForm(toRuleForm(rule));
    clearFeedback();
  }

  function startOverride(preferredCode?: string) {
    const code = preferredCode && missingRuleCodes.includes(preferredCode)
      ? preferredCode
      : missingRuleCodes[0] ?? supportedRuleCodes[0];
    const template = workspace?.data.find((rule) => rule.code === code && rule.is_effective)
      ?? workspace?.data.find((rule) => rule.code === code);
    selectedRuleId.current = null;
    setSelectedRule(null);
    setCreatingRule(true);
    setRuleForm(template ? { ...toRuleForm(template), status: 'ACTIVE' } : blankRule(code));
    clearFeedback();
  }

  function changeRuleType(code: string) {
    setRuleForm(blankRule(code));
    ruleKey.current = null;
    clearMessages();
  }

  function changeRule(patch: Partial<RuleForm>) {
    setRuleForm((current) => ({ ...current, ...patch }));
    ruleKey.current = null;
    clearMessages();
  }

  function changeBand(index: number, patch: Partial<RuleForm['bands'][number]>) {
    setRuleForm((current) => ({
      ...current,
      bands: current.bands.map((band, candidate) => candidate === index ? { ...band, ...patch } : band),
    }));
    ruleKey.current = null;
    clearMessages();
  }

  function addBand() {
    const previous = ruleForm.bands.at(-1);
    const previousMinimum = Number(previous?.minimum_value ?? '0');
    const boundary = String(Number.isFinite(previousMinimum) ? previousMinimum + 100 : 100);
    setRuleForm((current) => ({
      ...current,
      bands: [
        ...current.bands.map((band, index) => index === current.bands.length - 1 && band.maximum_value === null
          ? { ...band, maximum_value: boundary }
          : band),
        {
          name: 'Additional authority band',
          minimum_value: boundary,
          maximum_value: null,
          required_permission: workspace?.lookups.permissions[0]?.code ?? '',
          escalation_permission: workspace?.lookups.permissions.find((item) => item.code.includes('ESCALATED'))?.code ?? '',
          work_priority: 'HIGH',
          due_hours: 24,
          escalate_after_hours: 12,
        },
      ],
    }));
    ruleKey.current = null;
    clearMessages();
  }

  function removeBand(index: number) {
    if (ruleForm.bands.length === 1) return;
    const bands = ruleForm.bands.filter((_, candidate) => candidate !== index).map((band) => ({ ...band }));
    bands[0].minimum_value = '0';
    for (let candidate = 1; candidate < bands.length; candidate += 1) {
      bands[candidate].minimum_value = bands[candidate - 1].maximum_value ?? bands[candidate].minimum_value;
    }
    bands[bands.length - 1] = { ...bands[bands.length - 1], maximum_value: null };
    setRuleForm((current) => ({ ...current, bands }));
    ruleKey.current = null;
    clearMessages();
  }

  async function saveRule(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (ruleBusy) return;
    setRuleBusy(true);
    clearMessages();
    ruleKey.current ??= globalThis.crypto.randomUUID();
    const body = sanitiseRule(ruleForm);
    try {
      const result = creatingRule
        ? await createApprovalRule({ ...body, code: ruleForm.code }, ruleKey.current)
        : selectedRule
          ? await updateApprovalRule(selectedRule, body, ruleKey.current)
          : null;
      if (!result) return;
      selectedRuleId.current = result.id;
      setCreatingRule(false);
      ruleKey.current = null;
      setSuccess(creatingRule
        ? 'Plant approval-rule override created and activated for future submissions.'
        : `${ruleForm.name} saved as policy version ${result.record_version}.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to save this approval rule.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setRuleBusy(false);
    }
  }

  async function confirmDelete() {
    if (!pendingDelete || ruleBusy) return;
    const target = pendingDelete;
    setRuleBusy(true);
    clearMessages();
    try {
      await deleteApprovalRule(target, globalThis.crypto.randomUUID());
      setPendingDelete(null);
      selectedRuleId.current = null;
      setSelectedRule(null);
      setCreatingRule(false);
      setRuleForm(blankRule());
      await refresh();
      setSuccess(`${target.name} was deleted.`);
    } catch (caught) {
      setPendingDelete(null);
      setError(apiMessage(caught, 'Unable to delete this approval rule.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setRuleBusy(false);
    }
  }

  function changeDelegation(patch: Partial<DelegationForm>) {
    setDelegationForm((current) => ({ ...current, ...patch }));
    delegationKey.current = null;
    clearMessages();
  }

  async function saveDelegation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (delegationBusy) return;
    setDelegationBusy(true);
    clearMessages();
    delegationKey.current ??= globalThis.crypto.randomUUID();
    try {
      await createApprovalDelegation({
        ...delegationForm,
        effective_from: new Date(delegationForm.effective_from).toISOString(),
        effective_to: new Date(delegationForm.effective_to).toISOString(),
        reason: delegationForm.reason.trim(),
      }, delegationKey.current);
      delegationKey.current = null;
      setSuccess('Temporary approval authority delegated for the selected effective window.');
      setDelegationForm(populateDelegation(blankDelegation(), workspace));
      await refresh();
      window.dispatchEvent(new Event('erp:session-refresh'));
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to create this approval delegation.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setDelegationBusy(false);
    }
  }

  async function revoke(delegation: ApprovalDelegation) {
    if (delegationBusy) return;
    setDelegationBusy(true);
    clearMessages();
    try {
      await revokeApprovalDelegation(delegation, globalThis.crypto.randomUUID());
      setSuccess('Delegated approval authority revoked immediately.');
      await refresh();
      window.dispatchEvent(new Event('erp:session-refresh'));
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to revoke this approval delegation.'));
    } finally {
      setDelegationBusy(false);
    }
  }

  async function escalateDue() {
    if (escalationBusy) return;
    setEscalationBusy(true);
    clearMessages();
    try {
      const result = await escalateDueApprovals(globalThis.crypto.randomUUID());
      setSuccess(`${result.escalated_count ?? 0} overdue approval${result.escalated_count === 1 ? '' : 's'} routed to escalation authority.`);
      await refresh();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to run overdue approval escalation.'));
    } finally {
      setEscalationBusy(false);
    }
  }

  const missingRuleCodes = supportedRuleCodes.filter((code) =>
    !workspace?.data.some((rule) => rule.scope === 'PLANT' && rule.code === code));
  const canCreate = Boolean(workspace?.allowed_actions.includes('CREATE_RULE') && missingRuleCodes.length);
  const editable = creatingRule || Boolean(selectedRule?.allowed_actions.includes('UPDATE'));

  return (
    <>
      <PageHeader
        code="ADM-RULE"
        batch="B04"
        title="Approval Rules"
        description="Versioned authority bands, temporary delegated authority, SLA routing, and controlled escalation."
        onNew={canCreate ? () => startOverride() : undefined}
      />
      <div className="live-notice approval-governance-notice">
        <span></span><b>Server-authoritative approval policy</b>
        Submitted approvals retain their policy snapshot; rule edits affect only future submissions.
        {workspace?.allowed_actions.includes('ESCALATE_DUE') && <button className="secondary compact-button" type="button" onClick={() => void escalateDue()} disabled={escalationBusy}>{escalationBusy ? 'Escalating...' : 'Escalate due approvals'}</button>}
      </div>

      <div className="kpi-grid approval-kpis">
        <div className="kpi"><span>Rules</span><b>{loading && !workspace ? '-' : workspace?.summary.rules ?? 0}</b><small>{workspace?.summary.plant_overrides ?? 0} plant override</small></div>
        <div className="kpi"><span>Pending approvals</span><b>{loading && !workspace ? '-' : workspace?.summary.pending_approvals ?? 0}</b><small>selected plant</small></div>
        <div className="kpi"><span>Escalated</span><b>{loading && !workspace ? '-' : workspace?.summary.escalated_approvals ?? 0}</b><small>awaiting escalation authority</small></div>
        <div className="kpi"><span>Delegations</span><b>{loading && !workspace ? '-' : workspace?.summary.active_delegations ?? 0}</b><small>currently effective</small></div>
      </div>

      {(error || success) && <div className={error ? 'form-error panel-message' : 'form-success panel-message'} role={error ? 'alert' : 'status'}><span></span>{error ?? success}</div>}

      <div className="module-grid admin-workspace approval-rule-workspace">
        <section className="panel">
          <div className="panel-head"><div><h3>Rule register</h3><span>Resolution order: plant, organisation, protected global fallback</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          {loading && !workspace && <div className="empty-state">Loading approval policies...</div>}
          {workspace && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="admin-table approval-rule-table"><thead><tr><th>Rule</th><th>Scope</th><th>State</th><th>Bands</th><th>Open</th><th>Version</th><th>Action</th></tr></thead><tbody>
            {workspace.data.map((rule) => <tr key={rule.id} className={rule.is_effective ? 'effective-rule' : ''}><td><b>{rule.name}</b><small>{rule.code}</small></td><td>{rule.scope}{rule.is_system ? ' · protected' : ''}</td><td><StatusBadge status={rule.status} />{rule.is_effective && <small>Effective</small>}</td><td>{rule.bands.length}</td><td>{rule.pending_request_count}</td><td>v{rule.record_version}</td><td><button className="secondary compact-button" type="button" onClick={() => openRule(rule)}>Open</button></td></tr>)}
          </tbody></table></div>}
        </section>

        <aside className="panel admin-editor approval-rule-editor">
          <div className="panel-head"><div><h3>{creatingRule ? 'New plant override' : selectedRule?.name ?? 'Approval rule'}</h3><span>{creatingRule ? 'Version 1' : selectedRule ? `${selectedRule.scope} · v${selectedRule.record_version}` : 'Select a rule'}</span></div></div>
          {(creatingRule || selectedRule) && <form className="panel-body form-grid admin-form" onSubmit={saveRule} noValidate>
            <label className="full">Rule code{creatingRule
              ? <select aria-label="Rule code" value={ruleForm.code} onChange={(event) => changeRuleType(event.target.value)}>{missingRuleCodes.map((code) => <option key={code} value={code}>{ruleTypeLabel(code)}</option>)}</select>
              : <input className="read-only" readOnly value={ruleForm.code} aria-label="Rule code" />}
            </label>
            <label className="full">Name<input value={ruleForm.name} readOnly={!editable} onChange={(event) => changeRule({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
            <label className="full">Description<textarea rows={3} maxLength={2000} value={ruleForm.description ?? ''} readOnly={!editable} onChange={(event) => changeRule({ description: event.target.value })} /></label>
            <label className="full">Lifecycle status<select value={ruleForm.status} disabled={!editable} onChange={(event) => changeRule({ status: event.target.value as ApprovalRuleStatus })}><option>ACTIVE</option><option>INACTIVE</option></select></label>
            {selectedRule?.is_system && <div className="callout full">This protected global fallback cannot be edited. Create a plant override to change routing locally.</div>}

            <div className="authority-band-list full">
              <div className="subsection-head"><div><b>Authority bands</b><small>Minimum inclusive · maximum exclusive · final band unbounded</small></div>{editable && ruleForm.bands.length < 10 && <button className="secondary compact-button" type="button" onClick={addBand}>Add band</button>}</div>
              {ruleForm.bands.map((band, index) => <div className="authority-band" key={`${index}-${band.name}`}>
                <div className="authority-band-head"><b>Band {index + 1}</b>{editable && ruleForm.bands.length > 1 && <button type="button" onClick={() => removeBand(index)}>Remove</button>}</div>
                <label>Name<input aria-label={`Band ${index + 1} name`} value={band.name} readOnly={!editable} onChange={(event) => changeBand(index, { name: event.target.value })} /></label>
                <div className="authority-range"><label>Minimum<input aria-label={`Band ${index + 1} minimum`} type="number" min="0" step="0.000001" value={band.minimum_value} readOnly={!editable} onChange={(event) => changeBand(index, { minimum_value: event.target.value })} /></label><label>Maximum<input aria-label={`Band ${index + 1} maximum`} type="number" min="0" step="0.000001" value={band.maximum_value ?? ''} readOnly={!editable || index === ruleForm.bands.length - 1} placeholder="Unbounded" onChange={(event) => changeBand(index, { maximum_value: event.target.value || null })} /></label></div>
                <label>Initial authority<select aria-label={`Band ${index + 1} initial authority`} value={band.required_permission} disabled={!editable} onChange={(event) => changeBand(index, { required_permission: event.target.value })}>{permissionOptions(workspace, band.required_permission)}</select></label>
                <label>Escalation authority<select aria-label={`Band ${index + 1} escalation authority`} value={band.escalation_permission} disabled={!editable} onChange={(event) => changeBand(index, { escalation_permission: event.target.value })}>{permissionOptions(workspace, band.escalation_permission)}</select></label>
                <div className="authority-range three"><label>Priority<select aria-label={`Band ${index + 1} priority`} value={band.work_priority} disabled={!editable} onChange={(event) => changeBand(index, { work_priority: event.target.value as ApprovalWorkPriority })}><option>URGENT</option><option>HIGH</option><option>NORMAL</option><option>LOW</option></select></label><label>Due hours<input aria-label={`Band ${index + 1} due hours`} type="number" min="1" max="8760" value={band.due_hours} readOnly={!editable} onChange={(event) => changeBand(index, { due_hours: Number(event.target.value) })} /></label><label>Escalate after<input aria-label={`Band ${index + 1} escalate after hours`} type="number" min="1" max="8760" value={band.escalate_after_hours} readOnly={!editable} onChange={(event) => changeBand(index, { escalate_after_hours: Number(event.target.value) })} /></label></div>
                <FieldError value={fieldErrors[`bands.${index}.minimum_value`] ?? fieldErrors[`bands.${index}.maximum_value`] ?? fieldErrors[`bands.${index}.required_permission`] ?? fieldErrors[`bands.${index}.escalation_permission`]} />
              </div>)}
            </div>
            <div className="form-actions full">
              {!editable && canCreate && <button className="secondary" type="button" onClick={() => startOverride(selectedRule?.code)}>Create plant override</button>}
              {session.roles.includes('ERP_ADMIN') && selectedRule && !selectedRule.is_system && <button className="danger-button" type="button" disabled={ruleBusy} onClick={() => { clearFeedback(); setPendingDelete(selectedRule); }}>Delete entry</button>}
              {editable && <button className="primary" type="submit" disabled={ruleBusy}>{ruleBusy ? 'Saving...' : creatingRule ? 'Create plant override' : 'Save policy version'}</button>}
            </div>
          </form>}
        </aside>
      </div>

      <section className="panel approval-delegations">
        <div className="panel-head"><div><h3>Temporary delegated authority</h3><span>No delegation chains; the delegator must retain the direct authority throughout the effective window.</span></div></div>
        {workspace?.allowed_actions.includes('CREATE_DELEGATION') && <form className="delegation-form" onSubmit={saveDelegation} noValidate>
          <label>Delegator<select aria-label="Delegator" value={delegationForm.delegator_id} onChange={(event) => changeDelegation({ delegator_id: event.target.value })}>{userOptions(workspace, true)}</select><FieldError value={fieldErrors.delegator_id} /></label>
          <label>Delegate<select aria-label="Delegate" value={delegationForm.delegate_id} onChange={(event) => changeDelegation({ delegate_id: event.target.value })}>{userOptions(workspace, false)}</select><FieldError value={fieldErrors.delegate_id} /></label>
          <label>Approval authority<select aria-label="Approval authority" value={delegationForm.permission_code} onChange={(event) => changeDelegation({ permission_code: event.target.value })}>{permissionOptions(workspace, delegationForm.permission_code)}</select><FieldError value={fieldErrors.permission_code} /></label>
          <label>Effective from<input aria-label="Delegation starts" type="datetime-local" value={delegationForm.effective_from} onChange={(event) => changeDelegation({ effective_from: event.target.value })} /></label>
          <label>Effective to<input aria-label="Delegation ends" type="datetime-local" value={delegationForm.effective_to} onChange={(event) => changeDelegation({ effective_to: event.target.value })} /><FieldError value={fieldErrors.effective_to ?? fieldErrors.effective_from} /></label>
          <label className="delegation-reason">Reason<input aria-label="Delegation reason" maxLength={2000} value={delegationForm.reason} onChange={(event) => changeDelegation({ reason: event.target.value })} /><FieldError value={fieldErrors.reason} /></label>
          <button className="primary" type="submit" disabled={delegationBusy}>{delegationBusy ? 'Delegating...' : 'Create delegation'}</button>
        </form>}
        {!workspace?.delegations.length && <div className="empty-state">No approval delegations have been recorded in this plant.</div>}
        {Boolean(workspace?.delegations.length) && <div className="table-wrap"><table className="admin-table delegation-table"><thead><tr><th>Authority</th><th>Delegator</th><th>Delegate</th><th>Window</th><th>State</th><th>Reason</th><th>Action</th></tr></thead><tbody>{workspace?.delegations.map((delegation) => <tr key={delegation.id}><td><b>{shortPermission(delegation.permission_code)}</b><small>{delegation.permission_code}</small></td><td><b>{delegation.delegator.name}</b><small>{delegation.delegator.email}</small></td><td><b>{delegation.delegate.name}</b><small>{delegation.delegate.email}</small></td><td>{formatDateTime(delegation.effective_from)}<small>to {formatDateTime(delegation.effective_to)}</small></td><td><StatusBadge status={delegation.effective_status} /></td><td>{delegation.reason}</td><td>{delegation.allowed_actions.includes('REVOKE') && <button className="danger-button compact-button" type="button" onClick={() => void revoke(delegation)} disabled={delegationBusy}>Revoke</button>}</td></tr>)}</tbody></table></div>}
      </section>
      {pendingDelete && <DeleteEntryDialog entryLabel={pendingDelete.name} entryCode={pendingDelete.code} busy={ruleBusy} onCancel={() => setPendingDelete(null)} onConfirm={() => void confirmDelete()} />}
    </>
  );

  function clearMessages() {
    setError(null); setSuccess(null); setFieldErrors({});
  }

  function clearFeedback() {
    ruleKey.current = null; delegationKey.current = null; clearMessages();
  }
}

function blankRule(code: string = supportedRuleCodes[0]): RuleForm {
  if (code === 'PURCHASE_REQUISITION_APPROVAL') {
    return {
      code,
      name: 'Purchase requisition approval',
      description: 'Plant policy routing purchase requisitions by estimated order value.',
      status: 'ACTIVE',
      bands: [
        {
          name: 'Standard requisition authority', minimum_value: '0', maximum_value: '100000',
          required_permission: 'ACTION:PUR-REQ:APPROVE',
          escalation_permission: 'ACTION:PUR-REQ:APPROVE-ESCALATED',
          work_priority: 'HIGH', due_hours: 24, escalate_after_hours: 24,
        },
        {
          name: 'High-value requisition authority', minimum_value: '100000', maximum_value: null,
          required_permission: 'ACTION:PUR-REQ:APPROVE-HIGH',
          escalation_permission: 'ACTION:PUR-REQ:APPROVE-ESCALATED',
          work_priority: 'URGENT', due_hours: 12, escalate_after_hours: 12,
        },
      ],
    };
  }

  return {
    code: supportedRuleCodes[0],
    name: 'Unsold return loss approval',
    description: 'Plant policy routing loss disposition by destroyed base quantity.',
    status: 'ACTIVE',
    bands: [{
      name: 'Standard loss authority', minimum_value: '0', maximum_value: null,
      required_permission: 'ACTION:RET-UNSOLD:APPROVE',
      escalation_permission: 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
      work_priority: 'HIGH', due_hours: 24, escalate_after_hours: 12,
    }],
  };
}

function ruleTypeLabel(code: string): string {
  return code === 'PURCHASE_REQUISITION_APPROVAL'
    ? 'Purchase requisition approval'
    : 'Unsold return loss approval';
}

function blankDelegation(): DelegationForm {
  const from = new Date();
  const to = new Date(from.getTime() + 24 * 60 * 60 * 1000);
  return {
    delegator_id: '', delegate_id: '', permission_code: 'ACTION:RET-UNSOLD:APPROVE',
    effective_from: localDateTime(from), effective_to: localDateTime(to), reason: '',
  };
}

function populateDelegation(form: DelegationForm, workspace: ApprovalGovernanceWorkspace | null): DelegationForm {
  if (!workspace) return form;
  const delegator = workspace.lookups.users.find((user) => user.approval_permissions.length > 0);
  const delegate = workspace.lookups.users.find((user) => user.id !== delegator?.id);
  return {
    ...form,
    delegator_id: form.delegator_id || delegator?.id || '',
    delegate_id: form.delegate_id || delegate?.id || '',
    permission_code: form.permission_code || workspace.lookups.permissions[0]?.code || '',
  };
}

function toRuleForm(rule: ApprovalRule): RuleForm {
  return {
    code: rule.code,
    name: rule.name,
    description: rule.description,
    status: rule.status,
    bands: rule.bands.map(({ id: _id, sequence: _sequence, ...band }) => ({ ...band })),
  };
}

function sanitiseRule(form: RuleForm): ApprovalRuleWrite {
  return {
    name: form.name.trim(),
    description: form.description?.trim() || null,
    status: form.status,
    bands: form.bands.map((band, index) => ({
      ...band,
      name: band.name.trim(),
      minimum_value: band.minimum_value || '0',
      maximum_value: index === form.bands.length - 1 ? null : band.maximum_value || null,
    })),
  };
}

function permissionOptions(workspace: ApprovalGovernanceWorkspace | null, current: string) {
  const permissions = workspace?.lookups.permissions ?? [];
  const all = current && !permissions.some((permission) => permission.code === current)
    ? [{ id: current, code: current, name: current }, ...permissions]
    : permissions;
  return all.map((permission) => <option key={permission.code} value={permission.code}>{permission.name} ({permission.code})</option>);
}

function userOptions(workspace: ApprovalGovernanceWorkspace | null, authorityOnly: boolean) {
  return (workspace?.lookups.users ?? []).filter((user) => !authorityOnly || user.approval_permissions.length > 0)
    .map((user) => <option key={user.id} value={user.id}>{user.name} ({user.email})</option>);
}

function localDateTime(date: Date): string {
  const offset = date.getTimezoneOffset() * 60_000;
  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function shortPermission(value: string): string {
  return value.replace('ACTION:RET-UNSOLD:', '').replaceAll('-', ' ');
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
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
