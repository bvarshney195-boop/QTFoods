import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  cancelOptimisation,
  createOptimisationPlan,
  decideOptimisation,
  getOptimisationPlan,
  listOptimisationPlans,
  reviseOptimisationInput,
  runOptimisationCommand,
  saveOptimisationOutcome,
  type DemandPlanLookup,
  type OptimisationInputCommand,
  type OptimisationPlan,
  type OptimisationWorkspace,
  type Recommendation,
} from '../api/optimisation';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import { SummaryStrip } from './ManufacturingWorkspaceShell';

type ScenarioForm = {
  plan_number: string; name: string; demand_plan_id: string; objective: string;
  service_level_target: string; safety_stock_percent: string; planning_lead_days: string;
  max_utilisation_percent: string; holding_cost_rate: string; shortage_penalty_rate: string;
  currency: string; assumptions: string;
};
type OutcomeForm = {
  result: string; actual_stock_quantity: string; actual_production_quantity: string;
  actual_service_level: string; actual_cost: string; currency: string; observed_on: string; notes: string;
};
type Editor =
  | { kind: 'SCENARIO'; mode: 'CREATE' | 'REVISE'; form: ScenarioForm }
  | { kind: 'DECISION'; action: 'approve' | 'reject'; notes: string }
  | { kind: 'CANCEL'; reason: string }
  | { kind: 'OUTCOME'; recommendation: Recommendation; form: OutcomeForm };

export function OptimisationWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<OptimisationWorkspace | null>(null);
  const [selected, setSelected] = useState<OptimisationPlan | null>(null);
  const [editor, setEditor] = useState<Editor | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [objective, setObjective] = useState('');
  const [sort, setSort] = useState('NEWEST');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const data = await listOptimisationPlans({ q: search || undefined, status: status || undefined, objective: objective || undefined, sort, page });
      setWorkspace(data); setError(null);
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to load governed optimisation plans.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, status, objective, sort, page]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    setSelected(null); setEditor(null); setSearch(''); setStatus(''); setObjective(''); setSort('NEWEST');
    setPage(1); setError(null); setSuccess(null); setFieldErrors({});
  }, [contextKey]);

  async function open(id: string) {
    setBusy(true); setEditor(null); clearFeedback();
    try { setSelected(await getOptimisationPlan(id)); }
    catch (caught) { setError(errorMessage(caught, 'Unable to load the optimisation plan.')); }
    finally { setBusy(false); }
  }

  function startCreate() {
    setSelected(null); clearFeedback();
    setEditor({ kind: 'SCENARIO', mode: 'CREATE', form: blankScenario(workspace?.lookups.released_demand_plans ?? []) });
  }

  function startRevise() {
    if (!selected) return;
    clearFeedback(); setEditor({ kind: 'SCENARIO', mode: 'REVISE', form: scenarioFromPlan(selected) });
  }

  async function submitEditor(event: FormEvent) {
    event.preventDefault();
    if (!editor) return;
    setBusy(true); clearFeedback();
    try {
      let planId = selected?.id;
      if (editor.kind === 'SCENARIO') {
        const validation = validateScenario(editor.form, editor.mode);
        if (Object.keys(validation).length) {
          setFieldErrors(validation); setError('Correct the highlighted scenario inputs.'); return;
        }
        const input = scenarioCommand(editor.form);
        const result = editor.mode === 'CREATE'
          ? await createOptimisationPlan({ plan_number: editor.form.plan_number.trim().toUpperCase(), name: editor.form.name.trim(), ...input }, crypto.randomUUID())
          : await reviseOptimisationInput(selected!, input, crypto.randomUUID());
        planId = result.id;
        setSuccess(editor.mode === 'CREATE'
          ? 'Immutable input version 1 captured from the released demand plan.'
          : `Input version ${result.input_version ?? ''} captured; earlier inputs and reviews remain unchanged.`);
      } else if (editor.kind === 'DECISION') {
        if (editor.notes.trim().length < 3) { setFieldErrors({ decision_notes: 'Record at least three characters of review evidence.' }); setError('Decision evidence is required.'); return; }
        await decideOptimisation(selected!, editor.action, editor.notes.trim(), crypto.randomUUID());
        setSuccess(editor.action === 'approve' ? 'Recommendation set independently approved.' : 'Recommendation set rejected; a new input version is required before resubmission.');
      } else if (editor.kind === 'CANCEL') {
        if (editor.reason.trim().length < 3) { setFieldErrors({ reason: 'Record at least three characters.' }); setError('Cancellation reason is required.'); return; }
        await cancelOptimisation(selected!, editor.reason.trim(), crypto.randomUUID());
        setSuccess('Optimisation plan cancelled with retained input and recommendation evidence.');
      } else {
        const validation = validateOutcome(editor.form);
        if (Object.keys(validation).length) { setFieldErrors(validation); setError('Correct the highlighted outcome fields.'); return; }
        await saveOptimisationOutcome(selected!, editor.recommendation.id, {
          ...editor.form,
          ...(editor.recommendation.outcome ? { expected_outcome_version: editor.recommendation.outcome.record_version } : {}),
        }, crypto.randomUUID());
        setSuccess(editor.recommendation.outcome ? 'Outcome observation replaced under optimistic version control.' : 'Execution outcome recorded against this recommendation.');
      }
      setEditor(null);
      await refresh();
      if (planId) setSelected(await getOptimisationPlan(planId));
    } catch (caught) {
      applyApiError(caught, setError, setFieldErrors, 'Unable to complete the optimisation command.');
    } finally {
      setBusy(false);
    }
  }

  async function run(action: 'generate' | 'submit' | 'complete') {
    if (!selected) return;
    setBusy(true); clearFeedback();
    try {
      await runOptimisationCommand(selected, action, crypto.randomUUID());
      const messages = {
        generate: 'Recommendations generated from the immutable input. Review every limitation before submission.',
        submit: 'Recommendation set submitted for an independent human decision.',
        complete: 'Optimisation plan completed after every recommendation received an outcome.',
      };
      await refresh(); setSelected(await getOptimisationPlan(selected.id)); setSuccess(messages[action]);
    } catch (caught) {
      applyApiError(caught, setError, setFieldErrors, 'Unable to complete the optimisation command.');
    } finally { setBusy(false); }
  }

  function clearFeedback() { setError(null); setSuccess(null); setFieldErrors({}); }
  function patchScenario(field: keyof ScenarioForm, value: string) {
    if (editor?.kind !== 'SCENARIO') return;
    setEditor({ ...editor, form: { ...editor.form, [field]: value } });
  }
  function patchOutcome(field: keyof OutcomeForm, value: string) {
    if (editor?.kind !== 'OUTCOME') return;
    setEditor({ ...editor, form: { ...editor.form, [field]: value } });
  }

  const lookups = workspace?.lookups;
  const canCreateAction = Boolean(workspace?.allowed_actions.includes('CREATE'));
  const hasReleasedDemandPlan = Boolean(lookups?.released_demand_plans.length);
  const canCreate = canCreateAction && hasReleasedDemandPlan;
  const emptyMessage = search || status || objective
    ? 'No optimisation plans match the current filters.'
    : !hasReleasedDemandPlan
      ? 'No released demand plan is available. Ask the operations planning team to release a plan in Demand planning before capturing an optimisation input.'
      : !canCreateAction
        ? 'No optimisation plans are available to your role. Ask the operations planning team to capture an approved scenario.'
        : 'No optimisation plans yet. Use New to capture an input from a released demand plan.';
  return <>
    <PageHeader code="OPT-PLAN" batch="P3 optimisation" title="Forecast & Optimisation"
      description="Version demand inputs, generate bounded recommendations, require human review, and measure outcomes."
      onNew={canCreate ? startCreate : undefined} />
    <div className="live-notice optimisation-notice"><span>GOVERNED</span>
      Recommendations are deterministic decision support. Inputs are checksum-versioned, limitations remain visible, approval never executes production, and outcomes are recorded separately.
    </div>
    {workspace?.summary ? <SummaryStrip summary={workspace.summary} /> : null}
    <div className="module-grid requisition-workspace optimisation-workspace">
      <section className="panel">
        <div className="requisition-toolbar optimisation-toolbar">
          <label>Search<input aria-label="Optimisation search" value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="Plan or demand source" /></label>
          <label>Status<select aria-label="Optimisation status" value={status} onChange={(event) => { setPage(1); setStatus(event.target.value); }}><option value="">All statuses</option>{lookups?.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>
          <label>Objective<select aria-label="Optimisation objective" value={objective} onChange={(event) => { setPage(1); setObjective(event.target.value); }}><option value="">All objectives</option>{lookups?.objectives.map((value) => <option key={value}>{label(value)}</option>)}</select></label>
          <label>Sort<select aria-label="Optimisation sort" value={sort} onChange={(event) => { setPage(1); setSort(event.target.value); }}>{lookups?.sorts.map((value) => <option key={value}>{label(value)}</option>)}</select></label>
        </div>
        {loading && !workspace ? <Empty text="Loading governed optimisation plans..." /> : <>
          <div className="table-wrap"><table className="requisition-table optimisation-register"><thead><tr><th>Plan</th><th>Demand source</th><th>Objective / horizon</th><th>Recommendations</th><th>Limitations</th><th>Status</th><th /></tr></thead><tbody>
            {workspace?.data.map((plan) => <tr key={plan.id} className={selected?.id === plan.id ? 'selected-row' : ''}>
              <td><b>{plan.plan_number}</b><small>{plan.name} · record v{plan.record_version}</small></td>
              <td><b>{plan.current_input.demand_plan.number}</b><small>input v{plan.current_input_version} · source v{plan.current_input.demand_plan.version_snapshot}</small></td>
              <td><b>{label(plan.objective)}</b><small>{date(plan.horizon_start)} – {date(plan.horizon_end)}</small></td>
              <td><b>{plan.recommendation_count} recommendations</b><small>{decimal(plan.production_quantity)} production · {plan.outcome_count} outcomes</small></td>
              <td><b className={plan.warning_count ? 'text-bad' : ''}>{plan.warning_count} warnings</b><small>{money(plan.estimated_cost, plan.currency)} estimate</small></td>
              <td><StatusBadge status={plan.status} /></td>
              <td><button type="button" className="secondary compact-button" disabled={busy} onClick={() => void open(plan.id)}>Open</button></td>
            </tr>)}
          </tbody></table></div>
          {!workspace?.data.length ? <Empty text={emptyMessage} /> : null}
          {workspace ? <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div> : null}
        </>}
      </section>
      <aside className="panel requisition-editor optimisation-editor"><div className="requisition-detail-body">
        {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
        {success ? <div className="form-success" role="status"><span />{success}</div> : null}
        {editor?.kind === 'SCENARIO' ? <ScenarioEditor editor={editor} lookups={lookups?.released_demand_plans ?? []} errors={fieldErrors} busy={busy} patch={patchScenario} submit={submitEditor} close={() => setEditor(null)} />
          : editor?.kind === 'DECISION' ? <DecisionEditor editor={editor} errors={fieldErrors} busy={busy} change={(notes) => setEditor({ ...editor, notes })} submit={submitEditor} close={() => setEditor(null)} />
            : editor?.kind === 'CANCEL' ? <CancelEditor editor={editor} errors={fieldErrors} busy={busy} change={(reason) => setEditor({ ...editor, reason })} submit={submitEditor} close={() => setEditor(null)} />
              : editor?.kind === 'OUTCOME' ? <OutcomeEditor editor={editor} outcomes={lookups?.outcomes ?? []} errors={fieldErrors} busy={busy} patch={patchOutcome} submit={submitEditor} close={() => setEditor(null)} />
                : selected ? <PlanDetail plan={selected} busy={busy} revise={startRevise} run={run}
                  decision={(action) => { clearFeedback(); setEditor({ kind: 'DECISION', action, notes: '' }); }}
                  cancel={() => { clearFeedback(); setEditor({ kind: 'CANCEL', reason: '' }); }}
                  outcome={(recommendation) => { clearFeedback(); setEditor({ kind: 'OUTCOME', recommendation, form: outcomeFromRecommendation(recommendation) }); }} />
                  : <Empty text={canCreate ? 'Choose a plan or capture a versioned scenario from released demand.' : 'Choose a plan to inspect its governed evidence.'} />}
      </div></aside>
    </div>
  </>;
}

function PlanDetail({ plan, busy, revise, run, decision, cancel, outcome }: {
  plan: OptimisationPlan; busy: boolean; revise: () => void;
  run: (action: 'generate' | 'submit' | 'complete') => Promise<void>;
  decision: (action: 'approve' | 'reject') => void; cancel: () => void;
  outcome: (recommendation: Recommendation) => void;
}) {
  const input = plan.current_input;
  return <div className="requisition-detail optimisation-detail">
    <div className="detail-status"><StatusBadge status={plan.status} /><b>{plan.plan_number}</b><span>record version {plan.record_version}</span></div>
    <dl className="control-definition">
      <Datum text="Demand source" value={`${input.demand_plan.number} · source v${input.demand_plan.version_snapshot}`} />
      <Datum text="Input version" value={`v${input.version_number} · ${input.line_count} lines`} />
      <Datum text="Objective" value={label(input.objective)} />
      <Datum text="Service / safety" value={`${decimal(input.service_level_target)}% / ${decimal(input.safety_stock_percent)}%`} />
      <Datum text="Lead / utilisation" value={`${input.planning_lead_days} days / ${decimal(input.max_utilisation_percent)}%`} />
      <Datum text="Cost parameters" value={`${decimal(input.holding_cost_rate)}% holding · ${money(input.shortage_penalty_rate, input.currency)} shortage`} />
      <Datum text="Input checksum" value={input.input_checksum} wide mono />
      <Datum text="Assumptions" value={input.assumptions || 'No additional assumptions recorded.'} wide />
    </dl>
    {input.lines?.length ? <section className="optimisation-section"><h4>Immutable input snapshot</h4><div className="table-wrap"><table><thead><tr><th>SKU / demand</th><th>Demand</th><th>Eligible stock</th><th>Cost</th><th>MRP evidence</th></tr></thead><tbody>{input.lines.map((line) => <tr key={line.id}>
      <td><b>{line.output_sku.code}</b><small>{line.demand_type} · {date(line.demand_date)}</small></td>
      <td>{decimal(line.demand_quantity)} {line.uom_code}</td><td>{decimal(line.available_quantity_snapshot)} {line.uom_code}</td>
      <td>{money(line.unit_cost_snapshot, input.currency)} / {line.uom_code}</td>
      <td><b className={line.material_shortage_count ? 'text-bad' : ''}>{line.material_shortage_count} shortages</b><small>{line.excluded_stock_position_count} stock positions excluded</small></td>
    </tr>)}</tbody></table></div></section> : null}
    {plan.recommendations?.length ? <section className="optimisation-section"><h4>Current recommendations and limitations</h4>{plan.recommendations.map((recommendation) => <RecommendationCard key={recommendation.id} recommendation={recommendation} busy={busy} outcome={outcome} />)}</section> : <div className="callout">Generate recommendations only after verifying the checksum-versioned input. No production, reservation, or financial posting occurs here.</div>}
    {plan.reviews?.length ? <section className="optimisation-section"><h4>Human review history</h4>{plan.reviews.map((review) => <div className="optimisation-review" key={review.id}><div><StatusBadge status={review.status} /><b>Round {review.review_round}</b><small>{review.submitted_by.name} · {dateTime(review.submitted_at)}</small></div><p>{review.decision_notes || 'Awaiting an independent decision.'}</p>{review.decided_by ? <small>Decided by {review.decided_by.name} · {dateTime(review.decided_at!)}</small> : null}</div>)}</section> : null}
    <div className="p2-action-grid optimisation-actions">
      {plan.allowed_actions.includes('REVISE') ? <button type="button" className="secondary" disabled={busy} onClick={revise}>Capture new input version</button> : null}
      {plan.allowed_actions.includes('GENERATE') ? <button type="button" className="primary" disabled={busy} onClick={() => void run('generate')}>Generate recommendations</button> : null}
      {plan.allowed_actions.includes('SUBMIT') ? <button type="button" className="primary" disabled={busy} onClick={() => void run('submit')}>Submit for review</button> : null}
      {plan.allowed_actions.includes('APPROVE') ? <button type="button" className="primary" disabled={busy} onClick={() => decision('approve')}>Approve recommendations</button> : null}
      {plan.allowed_actions.includes('REJECT') ? <button type="button" className="secondary" disabled={busy} onClick={() => decision('reject')}>Reject recommendations</button> : null}
      {plan.allowed_actions.includes('COMPLETE') ? <button type="button" className="primary" disabled={busy} onClick={() => void run('complete')}>Complete after outcomes</button> : null}
      {plan.allowed_actions.includes('CANCEL') ? <button type="button" className="secondary" disabled={busy} onClick={cancel}>Cancel plan</button> : null}
    </div>
  </div>;
}

function RecommendationCard({ recommendation, busy, outcome }: { recommendation: Recommendation; busy: boolean; outcome: (value: Recommendation) => void }) {
  return <article className="optimisation-recommendation">
    <div className="optimisation-recommendation-head"><div><StatusBadge status={recommendation.action_type} /><StatusBadge status={recommendation.priority} /></div><b>{recommendation.output_sku.code} · {date(recommendation.demand_date)}</b></div>
    <dl className="control-definition">
      <Datum text="Scenario target" value={`${decimal(recommendation.target_quantity)} ${recommendation.uom_code}`} />
      <Datum text="Stock allocation" value={`${decimal(recommendation.stock_allocation_quantity)} ${recommendation.uom_code}`} />
      <Datum text="Suggested production" value={`${decimal(recommendation.production_quantity)} ${recommendation.uom_code}`} />
      <Datum text="Window" value={`${date(recommendation.proposed_start_date)} – ${date(recommendation.proposed_end_date)}`} />
      <Datum text="Expected service" value={`${decimal(recommendation.expected_service_level)}%`} />
      <Datum text="Estimated cost" value={money(recommendation.estimated_cost, recommendation.currency)} />
    </dl>
    <p>{recommendation.rationale}</p>
    <div className="optimisation-limitations"><b>Recorded limitations</b>{recommendation.limitations.map((item) => <div key={item.id} className={`optimisation-limitation severity-${item.severity.toLowerCase()}`}><StatusBadge status={item.severity} /><span><b>{label(item.code)}</b><small>{item.description}</small></span></div>)}</div>
    {recommendation.outcome ? <div className="optimisation-outcome"><div><StatusBadge status={recommendation.outcome.result} /><b>Observed {date(recommendation.outcome.observed_on)}</b><small>outcome v{recommendation.outcome.record_version}</small></div><p>{recommendation.outcome.notes}</p><small>{decimal(recommendation.outcome.actual_stock_quantity)} stock + {decimal(recommendation.outcome.actual_production_quantity)} production · {decimal(recommendation.outcome.actual_service_level)}% service · {money(recommendation.outcome.actual_cost, recommendation.outcome.currency)}</small></div> : null}
    {recommendation.allowed_actions.includes('RECORD_OUTCOME') ? <button type="button" className="secondary compact-button" disabled={busy} onClick={() => outcome(recommendation)}>{recommendation.outcome ? 'Replace outcome observation' : 'Record execution outcome'}</button> : null}
  </article>;
}

function ScenarioEditor({ editor, lookups, errors, busy, patch, submit, close }: {
  editor: Extract<Editor, { kind: 'SCENARIO' }>; lookups: DemandPlanLookup[]; errors: Record<string, string>;
  busy: boolean; patch: (field: keyof ScenarioForm, value: string) => void; submit: (event: FormEvent) => void; close: () => void;
}) {
  const create = editor.mode === 'CREATE';
  return <form className="requisition-form optimisation-form" onSubmit={submit}><fieldset disabled={busy}>
    <div><h3>{create ? 'Capture optimisation input' : 'Capture new input version'}</h3><p>{create ? 'Create a governed scenario from an exact released demand-plan version.' : 'A new immutable snapshot will supersede the current scenario without deleting its recommendations or review evidence.'}</p></div>
    <div className="requisition-field-grid">
      {create ? <><label>Plan number<input value={editor.form.plan_number} onChange={(event) => patch('plan_number', event.target.value)} placeholder="OPT-2026-001" /><Field error={errors.plan_number} /></label><label>Name<input value={editor.form.name} onChange={(event) => patch('name', event.target.value)} /><Field error={errors.name} /></label></> : null}
      <label className="wide">Released demand plan<select value={editor.form.demand_plan_id} onChange={(event) => patch('demand_plan_id', event.target.value)}><option value="">Select released demand</option>{lookups.map((plan) => <option key={plan.id} value={plan.id}>{plan.number} · {plan.name} · v{plan.record_version}</option>)}</select><Field error={errors.demand_plan_id} /></label>
      <label>Objective<select value={editor.form.objective} onChange={(event) => patch('objective', event.target.value)}><option value="BALANCED">Balanced service and cost</option><option value="SERVICE">Service level</option><option value="COST">Cost</option><option value="INVENTORY">Inventory</option></select></label>
      <label>Service target %<input type="number" min="0.001" max="100" step="0.001" value={editor.form.service_level_target} onChange={(event) => patch('service_level_target', event.target.value)} /><Field error={errors.service_level_target} /></label>
      <label>Safety stock %<input type="number" min="0" max="100" step="0.001" value={editor.form.safety_stock_percent} onChange={(event) => patch('safety_stock_percent', event.target.value)} /><Field error={errors.safety_stock_percent} /></label>
      <label>Planning lead days<input type="number" min="0" max="365" step="1" value={editor.form.planning_lead_days} onChange={(event) => patch('planning_lead_days', event.target.value)} /><Field error={errors.planning_lead_days} /></label>
      <label>Maximum utilisation %<input type="number" min="0.001" max="100" step="0.001" value={editor.form.max_utilisation_percent} onChange={(event) => patch('max_utilisation_percent', event.target.value)} /><Field error={errors.max_utilisation_percent} /></label>
      <label>Annual holding rate %<input type="number" min="0" step="0.0001" value={editor.form.holding_cost_rate} onChange={(event) => patch('holding_cost_rate', event.target.value)} /><Field error={errors.holding_cost_rate} /></label>
      <label>Shortage penalty / unit<input type="number" min="0" step="0.000001" value={editor.form.shortage_penalty_rate} onChange={(event) => patch('shortage_penalty_rate', event.target.value)} /><Field error={errors.shortage_penalty_rate} /></label>
      <label>Currency<input maxLength={3} value={editor.form.currency} onChange={(event) => patch('currency', event.target.value.toUpperCase())} /><Field error={errors.currency} /></label>
      <label className="wide">Assumptions<textarea rows={3} value={editor.form.assumptions} onChange={(event) => patch('assumptions', event.target.value)} placeholder="State the business assumptions a reviewer must evaluate." /></label>
    </div>
    <div className="callout">Stock, quality, shelf life, latest MRP shortages, and latest matching finalized unit cost are captured now. Later source changes do not rewrite this version.</div>
    <FormActions close={close} submit={create ? 'Capture input version 1' : 'Capture new input version'} />
  </fieldset></form>;
}

function DecisionEditor({ editor, errors, busy, change, submit, close }: {
  editor: Extract<Editor, { kind: 'DECISION' }>; errors: Record<string, string>; busy: boolean;
  change: (value: string) => void; submit: (event: FormEvent) => void; close: () => void;
}) {
  return <form className="requisition-form requisition-decision" onSubmit={submit}><fieldset disabled={busy}><h3>{editor.action === 'approve' ? 'Approve recommendation set' : 'Reject recommendation set'}</h3><p>{editor.action === 'approve' ? 'Confirm that you reviewed the input checksum, algorithm scope, every limitation, and downstream execution controls.' : 'Explain what must change. A rejected set can only return through a new immutable input version.'}</p><label>Decision evidence<textarea rows={5} value={editor.notes} onChange={(event) => change(event.target.value)} /><Field error={errors.decision_notes} /></label><FormActions close={close} submit={editor.action === 'approve' ? 'Approve independently' : 'Reject with evidence'} /></fieldset></form>;
}

function CancelEditor({ editor, errors, busy, change, submit, close }: {
  editor: Extract<Editor, { kind: 'CANCEL' }>; errors: Record<string, string>; busy: boolean;
  change: (value: string) => void; submit: (event: FormEvent) => void; close: () => void;
}) {
  return <form className="requisition-form requisition-decision" onSubmit={submit}><fieldset disabled={busy}><h3>Cancel optimisation plan</h3><p>Cancellation closes the lifecycle but retains all checksum, recommendation, limitation, and review evidence.</p><label>Cancellation reason<textarea rows={5} value={editor.reason} onChange={(event) => change(event.target.value)} /><Field error={errors.reason} /></label><FormActions close={close} submit="Cancel plan" /></fieldset></form>;
}

function OutcomeEditor({ editor, outcomes, errors, busy, patch, submit, close }: {
  editor: Extract<Editor, { kind: 'OUTCOME' }>; outcomes: string[]; errors: Record<string, string>; busy: boolean;
  patch: (field: keyof OutcomeForm, value: string) => void; submit: (event: FormEvent) => void; close: () => void;
}) {
  return <form className="requisition-form optimisation-form" onSubmit={submit}><fieldset disabled={busy}><div><h3>{editor.recommendation.outcome ? 'Replace outcome observation' : 'Record execution outcome'}</h3><p>{editor.recommendation.output_sku.code} · recommendation {decimal(editor.recommendation.target_quantity)} {editor.recommendation.uom_code}. This records evidence; it does not post inventory or finance.</p></div><div className="requisition-field-grid">
    <label>Result<select value={editor.form.result} onChange={(event) => patch('result', event.target.value)}>{outcomes.map((value) => <option key={value}>{value}</option>)}</select></label>
    <label>Observed on<input type="date" value={editor.form.observed_on} onChange={(event) => patch('observed_on', event.target.value)} /><Field error={errors.observed_on} /></label>
    <label>Actual stock quantity<input type="number" min="0" step="0.000001" value={editor.form.actual_stock_quantity} onChange={(event) => patch('actual_stock_quantity', event.target.value)} /><Field error={errors.actual_stock_quantity} /></label>
    <label>Actual production quantity<input type="number" min="0" step="0.000001" value={editor.form.actual_production_quantity} onChange={(event) => patch('actual_production_quantity', event.target.value)} /><Field error={errors.actual_production_quantity} /></label>
    <label>Actual service %<input type="number" min="0.001" max="100" step="0.001" value={editor.form.actual_service_level} onChange={(event) => patch('actual_service_level', event.target.value)} /><Field error={errors.actual_service_level} /></label>
    <label>Actual cost<input type="number" min="0" step="0.000001" value={editor.form.actual_cost} onChange={(event) => patch('actual_cost', event.target.value)} /><Field error={errors.actual_cost} /></label>
    <label>Currency<input maxLength={3} value={editor.form.currency} onChange={(event) => patch('currency', event.target.value.toUpperCase())} /><Field error={errors.currency} /></label>
    <label className="wide">Outcome evidence<textarea rows={4} value={editor.form.notes} onChange={(event) => patch('notes', event.target.value)} /><Field error={errors.notes} /></label>
  </div><FormActions close={close} submit={editor.recommendation.outcome ? `Replace outcome v${editor.recommendation.outcome.record_version}` : 'Record outcome'} /></fieldset></form>;
}

function Datum({ text, value, wide = false, mono = false }: { text: string; value: string; wide?: boolean; mono?: boolean }) {
  return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd className={mono ? 'mono' : undefined}>{value}</dd></div>;
}
function Field({ error }: { error?: string }) { return error ? <small className="field-error">{error}</small> : null; }
function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }
function FormActions({ close, submit }: { close: () => void; submit: string }) { return <div className="form-actions"><button type="button" className="secondary" onClick={close}>Close</button><button type="submit" className="primary">{submit}</button></div>; }

function blankScenario(plans: DemandPlanLookup[]): ScenarioForm {
  return { plan_number: '', name: '', demand_plan_id: plans[0]?.id ?? '', objective: 'BALANCED', service_level_target: '97', safety_stock_percent: '5', planning_lead_days: '7', max_utilisation_percent: '85', holding_cost_rate: '18', shortage_penalty_rate: '0', currency: 'INR', assumptions: '' };
}
function scenarioFromPlan(plan: OptimisationPlan): ScenarioForm {
  const input = plan.current_input;
  return { plan_number: plan.plan_number, name: plan.name, demand_plan_id: input.demand_plan.id, objective: input.objective, service_level_target: input.service_level_target, safety_stock_percent: input.safety_stock_percent, planning_lead_days: String(input.planning_lead_days), max_utilisation_percent: input.max_utilisation_percent, holding_cost_rate: input.holding_cost_rate, shortage_penalty_rate: input.shortage_penalty_rate, currency: input.currency, assumptions: input.assumptions ?? '' };
}
function scenarioCommand(form: ScenarioForm): OptimisationInputCommand {
  return { demand_plan_id: form.demand_plan_id, objective: form.objective, service_level_target: form.service_level_target, safety_stock_percent: form.safety_stock_percent, planning_lead_days: Number(form.planning_lead_days), max_utilisation_percent: form.max_utilisation_percent, holding_cost_rate: form.holding_cost_rate, shortage_penalty_rate: form.shortage_penalty_rate, currency: form.currency.trim().toUpperCase(), assumptions: form.assumptions.trim() || null };
}
function outcomeFromRecommendation(recommendation: Recommendation): OutcomeForm {
  const current = recommendation.outcome;
  return { result: current?.result ?? 'ACHIEVED', actual_stock_quantity: current?.actual_stock_quantity ?? recommendation.stock_allocation_quantity, actual_production_quantity: current?.actual_production_quantity ?? recommendation.production_quantity, actual_service_level: current?.actual_service_level ?? recommendation.expected_service_level, actual_cost: current?.actual_cost ?? recommendation.estimated_cost, currency: current?.currency ?? recommendation.currency, observed_on: current?.observed_on ?? today(), notes: current?.notes ?? '' };
}
function validateScenario(form: ScenarioForm, mode: 'CREATE' | 'REVISE') {
  const errors: Record<string, string> = {};
  if (mode === 'CREATE' && !form.plan_number.trim()) errors.plan_number = 'Plan number is required.';
  if (mode === 'CREATE' && form.name.trim().length < 3) errors.name = 'Name must contain at least three characters.';
  if (!form.demand_plan_id) errors.demand_plan_id = 'Choose a released demand plan.';
  bounded(form.service_level_target, 0, 100, 'service_level_target', errors, false);
  bounded(form.safety_stock_percent, 0, 100, 'safety_stock_percent', errors, true);
  bounded(form.planning_lead_days, 0, 365, 'planning_lead_days', errors, true);
  bounded(form.max_utilisation_percent, 0, 100, 'max_utilisation_percent', errors, false);
  bounded(form.holding_cost_rate, 0, Number.MAX_SAFE_INTEGER, 'holding_cost_rate', errors, true);
  bounded(form.shortage_penalty_rate, 0, Number.MAX_SAFE_INTEGER, 'shortage_penalty_rate', errors, true);
  if (!/^[A-Z]{3}$/.test(form.currency.trim().toUpperCase())) errors.currency = 'Use a three-letter currency code.';
  return errors;
}
function validateOutcome(form: OutcomeForm) {
  const errors: Record<string, string> = {};
  bounded(form.actual_stock_quantity, 0, Number.MAX_SAFE_INTEGER, 'actual_stock_quantity', errors, true);
  bounded(form.actual_production_quantity, 0, Number.MAX_SAFE_INTEGER, 'actual_production_quantity', errors, true);
  bounded(form.actual_service_level, 0, 100, 'actual_service_level', errors, false);
  bounded(form.actual_cost, 0, Number.MAX_SAFE_INTEGER, 'actual_cost', errors, true);
  if (!form.observed_on) errors.observed_on = 'Observation date is required.';
  if (!/^[A-Z]{3}$/.test(form.currency.trim().toUpperCase())) errors.currency = 'Use a three-letter currency code.';
  if (form.notes.trim().length < 3) errors.notes = 'Record at least three characters of outcome evidence.';
  return errors;
}
function bounded(value: string, min: number, max: number, field: string, errors: Record<string, string>, inclusiveMin: boolean) {
  const parsed = Number(value); if (!Number.isFinite(parsed) || (inclusiveMin ? parsed < min : parsed <= min) || parsed > max) errors[field] = `Enter a value ${inclusiveMin ? 'from' : 'above'} ${min} through ${max === Number.MAX_SAFE_INTEGER ? 'the supported maximum' : max}.`;
}
function applyApiError(caught: unknown, setError: (value: string) => void, setFields: (value: Record<string, string>) => void, fallback: string) {
  const fields: Record<string, string> = {}; if (isApiError(caught)) Object.entries(caught.fields ?? {}).forEach(([key, values]) => { fields[key] = values[0] ?? ''; });
  setFields(fields); setError(errorMessage(caught, fallback));
}
function errorMessage(caught: unknown, fallback: string) { return isApiError(caught) ? caught.message : caught instanceof Error ? caught.message : fallback; }
function label(value: string) { return value.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function decimal(value: unknown) { const parsed = Number(value); return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 6 }).format(Number.isFinite(parsed) ? parsed : 0); }
function money(value: unknown, currency: string) { const parsed = Number(value); try { return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number.isFinite(parsed) ? parsed : 0); } catch { return `${currency} ${decimal(value)}`; } }
function date(value: string) { return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`)); }
function dateTime(value: string) { return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }
function today() { return new Date().toISOString().slice(0, 10); }
