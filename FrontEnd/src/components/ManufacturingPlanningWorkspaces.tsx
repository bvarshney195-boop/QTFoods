import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { isApiError } from '../api/client';
import {
  cancelDemandPlan, cancelMrpRun, cancelProductionSchedule, createDemandPlan, createProductionSchedule,
  getDemandPlan, getMrpRun, getProductionSchedule, listDemandPlans, listMrpRuns, listProductionSchedules,
  releaseDemandPlan, releaseProductionSchedule, runMrp, updateDemandPlan, updateProductionSchedule,
  type DemandPlan, type DemandWorkspace, type MrpRun, type MrpWorkspace, type ProductionSchedule,
  type ScheduleWorkspace, type SchedulableMrpRun,
} from '../api/manufacturingPlanning';
import { useErpSession } from '../app/ErpSessionContext';
import { statusLabel } from '../utils/displayText';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type Feedback = { error: string | null; success: string | null; fields: Record<string, string> };
const emptyFeedback = (): Feedback => ({ error: null, success: null, fields: {} });

export function DemandPlanningWorkspace() {
  const contextKey = useContextKey();
  const [workspace, setWorkspace] = useState<DemandWorkspace | null>(null);
  const [selected, setSelected] = useState<DemandPlan | null>(null);
  const [form, setForm] = useState(blankDemand());
  const [editing, setEditing] = useState(false);
  const [search, setSearch] = useState(''); const [status, setStatus] = useState(''); const [sort, setSort] = useState('NEWEST'); const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(emptyFeedback()); const key = useRef<string | null>(null);
  const refresh = useCallback(async () => {
    setLoading(true);
    try { setWorkspace(await listDemandPlans({ q: search || undefined, status: status || undefined, sort, page })); }
    catch (error) { fail(setFeedback, error, 'Unable to load demand plans.'); }
    finally { setLoading(false); }
  }, [contextKey, search, status, sort, page]);
  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => { setSelected(null); setEditing(false); setSearch(''); setStatus(''); setSort('NEWEST'); setPage(1); setFeedback(emptyFeedback()); }, [contextKey]);
  useEffect(() => { key.current = null; }, [form, selected?.record_version]);
  async function choose(id: string) { setFeedback(emptyFeedback()); try { setSelected(await getDemandPlan(id)); setEditing(false); } catch (error) { fail(setFeedback, error, 'Unable to load demand-plan detail.'); } }
  function startNew() { setSelected(null); setForm(blankDemand()); setEditing(true); setFeedback(emptyFeedback()); }
  function startEdit() {
    if (!selected?.lines) return;
    setForm({
      plan_number: selected.plan_number, name: selected.name, horizon_start: selected.horizon_start,
      horizon_end: selected.horizon_end, notes: selected.notes ?? '',
      lines: selected.lines.map((line) => ({ output_sku_id: line.output_sku.id, demand_date: line.demand_date, demand_type: line.demand_type, quantity: line.quantity, notes: line.notes ?? '' })),
    });
    setEditing(true); setFeedback(emptyFeedback());
  }
  async function save(event: FormEvent) {
    event.preventDefault();
    const fields: Record<string, string> = {};
    if (!form.plan_number.trim()) fields.plan_number = 'Plan number is required.';
    if (!form.name.trim()) fields.name = 'Plan name is required.';
    if (!form.lines.length) fields.lines = 'Add at least one demand line.';
    form.lines.forEach((line, index) => { if (!line.output_sku_id) fields[`lines.${index}.output_sku_id`] = 'Choose an output SKU.'; if (number(line.quantity) <= 0) fields[`lines.${index}.quantity`] = 'Enter a positive quantity.'; });
    if (Object.keys(fields).length) { setFeedback({ error: 'Correct the highlighted demand fields.', success: null, fields }); return; }
    setBusy(true);
    try {
      key.current ??= crypto.randomUUID();
      const body = { name: form.name.trim(), horizon_start: form.horizon_start, horizon_end: form.horizon_end, notes: nullable(form.notes), lines: form.lines.map((line) => ({ ...line, notes: nullable(line.notes) })) };
      const result = selected
        ? await updateDemandPlan(selected, body, key.current)
        : await createDemandPlan({ ...body, plan_number: form.plan_number.trim().toUpperCase() }, key.current);
      key.current = null; await refresh(); setSelected(await getDemandPlan(result.id)); setEditing(false);
      setFeedback({ error: null, success: selected ? 'Demand plan updated.' : 'Draft demand plan created.', fields: {} });
    } catch (error) { fail(setFeedback, error, 'Unable to save the demand plan.'); }
    finally { setBusy(false); }
  }
  async function command(action: 'RELEASE' | 'CANCEL') {
    if (!selected) return;
    const reason = action === 'CANCEL' ? window.prompt('Cancellation reason') : null;
    if (action === 'CANCEL' && !reason?.trim()) return;
    setBusy(true);
    try {
      action === 'RELEASE'
        ? await releaseDemandPlan(selected, crypto.randomUUID())
        : await cancelDemandPlan(selected, reason!.trim(), crypto.randomUUID());
      await refresh(); setSelected(await getDemandPlan(selected.id));
      setFeedback({ error: null, success: action === 'RELEASE' ? 'Demand released to MRP.' : 'Demand plan cancelled.', fields: {} });
    } catch (error) { fail(setFeedback, error, 'Unable to complete the demand command.'); }
    finally { setBusy(false); }
  }

  return <PlanningLayout code="PLAN-DEM" title="Demand Planning" description="Create time-phased finished-goods demand and release it only when active recipes and routes are ready." notice="Released demand is immutable and becomes the controlled source for one active MRP run." loading={loading} feedback={feedback} workspace={workspace} search={search} setSearch={setSearch} status={status} setStatus={setStatus} sort={sort} setSort={setSort} page={page} setPage={setPage} onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
    headings={['Plan', 'Horizon', 'Lines', 'MRP', 'Owner', 'Status']} rows={workspace?.data.map((row) => [<><b>{row.plan_number}</b><small>{row.name} - v{row.record_version}</small></>, `${date(row.horizon_start)} - ${date(row.horizon_end)}`, String(row.line_count), row.active_mrp_count ? 'Active run' : 'Not run', row.created_by.name, <StatusBadge status={row.status} />]) ?? []} ids={workspace?.data.map((row) => row.id) ?? []} selectedId={selected?.id} onOpen={choose}>
    {editing ? <form className="requisition-form" onSubmit={save}><fieldset disabled={busy}><div className="requisition-field-grid"><label>Plan number<input disabled={!!selected} value={form.plan_number} onChange={(e) => setForm({ ...form, plan_number: e.target.value })} /><Field text={feedback.fields.plan_number} /></label><label>Plan name<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /><Field text={feedback.fields.name} /></label><label>Horizon start<input type="date" value={form.horizon_start} onChange={(e) => setForm({ ...form, horizon_start: e.target.value })} /></label><label>Horizon end<input type="date" value={form.horizon_end} onChange={(e) => setForm({ ...form, horizon_end: e.target.value })} /></label><label className="wide">Notes<textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label></div>
      <div className="requisition-lines-head"><div><h4>Time-phased demand</h4><small>Quantities use each SKU's base UOM.</small></div><button className="secondary compact-button" type="button" onClick={() => setForm({ ...form, lines: [...form.lines, blankDemandLine(form.horizon_end)] })}>Add line</button></div>
      {form.lines.map((line, index) => <div className="requisition-line-card" key={index}><div className="requisition-line-grid"><label>Output SKU<select aria-label={`Demand line ${index + 1} output SKU`} value={line.output_sku_id} onChange={(e) => patchDemandLine(setForm, index, { output_sku_id: e.target.value })}><option value="">Select SKU</option>{workspace?.lookups.output_skus.map((sku) => <option key={sku.id} value={sku.id} disabled={sku.eligible === false}>{sku.code} - {sku.name} ({sku.uom_code}){sku.eligible === false ? ` — ${sku.eligibility_issues?.join('; ')}` : ''}</option>)}</select><Field text={feedback.fields[`lines.${index}.output_sku_id`]} /></label><label>Demand date<input type="date" value={line.demand_date} onChange={(e) => patchDemandLine(setForm, index, { demand_date: e.target.value })} /></label><label>Demand type<select value={line.demand_type} onChange={(e) => patchDemandLine(setForm, index, { demand_type: e.target.value })}>{workspace?.lookups.demand_types.map((type) => <option key={type}>{type}</option>)}</select></label><label>Quantity<input aria-label={`Demand line ${index + 1} quantity`} type="number" min="0.000001" step="0.000001" value={line.quantity} onChange={(e) => patchDemandLine(setForm, index, { quantity: e.target.value })} /><Field text={feedback.fields[`lines.${index}.quantity`]} /></label><label className="wide">Line note<input value={line.notes} onChange={(e) => patchDemandLine(setForm, index, { notes: e.target.value })} /></label></div>{form.lines.length > 1 ? <button className="secondary compact-button" type="button" onClick={() => setForm({ ...form, lines: form.lines.filter((_, position) => position !== index) })}>Remove line</button> : null}</div>)}<Field text={feedback.fields.lines} /><FormActions onClose={() => setEditing(false)} submit={selected ? 'Save demand plan' : 'Create demand plan'} /></fieldset></form>
      : selected ? <Detail><DetailHead status={selected.status} version={selected.record_version} /><dl className="control-definition"><Datum text="Horizon" value={`${date(selected.horizon_start)} - ${date(selected.horizon_end)}`} /><Datum text="Owner" value={selected.created_by.name} /><Datum text="MRP" value={selected.active_mrp_count ? 'Active run exists' : 'No active run'} /><Datum wide text="Notes" value={selected.notes ?? 'No notes'} /></dl><LineTable heads={['Line', 'Output SKU', 'Date / type', 'Quantity']} rows={selected.lines?.map((line) => [String(line.line_number), <><b>{line.output_sku.code}</b><small>{line.output_sku.name}</small></>, <>{date(line.demand_date)}<small>{line.demand_type}</small></>, `${decimal(line.quantity)} ${line.uom_code}`]) ?? []} /><div className="form-actions">{selected.allowed_actions.includes('UPDATE') ? <button className="secondary" disabled={busy} onClick={startEdit}>Edit draft</button> : null}{selected.allowed_actions.includes('RELEASE') ? <button className="primary" disabled={busy} onClick={() => void command('RELEASE')}>Release to MRP</button> : null}{selected.allowed_actions.includes('CANCEL') ? <button className="secondary" disabled={busy} onClick={() => void command('CANCEL')}>Cancel</button> : null}</div></Detail>
        : <Empty text="Choose a demand plan or create a time-phased requirement." />}
  </PlanningLayout>;
}

export function MrpPlanningWorkspace() {
  const contextKey = useContextKey(); const [workspace, setWorkspace] = useState<MrpWorkspace | null>(null); const [selected, setSelected] = useState<MrpRun | null>(null);
  const [form, setForm] = useState(blankMrp()); const [editing, setEditing] = useState(false); const [search, setSearch] = useState(''); const [status, setStatus] = useState(''); const [sort, setSort] = useState('NEWEST'); const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false); const [feedback, setFeedback] = useState<Feedback>(emptyFeedback()); const key = useRef<string | null>(null);
  const refresh = useCallback(async () => { setLoading(true); try { setWorkspace(await listMrpRuns({ q: search || undefined, status: status || undefined, sort, page })); } catch (error) { fail(setFeedback, error, 'Unable to load MRP runs.'); } finally { setLoading(false); } }, [contextKey, search, status, sort, page]);
  useEffect(() => { void refresh(); }, [refresh]); useEffect(() => { setSelected(null); setEditing(false); setSearch(''); setStatus(''); setSort('NEWEST'); setPage(1); setFeedback(emptyFeedback()); }, [contextKey]); useEffect(() => { key.current = null; }, [form]);
  async function choose(id: string) { setFeedback(emptyFeedback()); try { setSelected(await getMrpRun(id)); setEditing(false); } catch (error) { fail(setFeedback, error, 'Unable to load MRP detail.'); } }
  function startRun() { setSelected(null); setForm(blankMrp()); setEditing(true); setFeedback(emptyFeedback()); }
  async function execute(event: FormEvent) {
    event.preventDefault(); const fields: Record<string, string> = {};
    if (!form.run_number.trim()) fields.run_number = 'MRP run number is required.'; if (!form.demand_plan_id) fields.demand_plan_id = 'Choose a released demand plan.';
    if (Object.keys(fields).length) { setFeedback({ error: 'Correct the highlighted MRP fields.', success: null, fields }); return; }
    setBusy(true); try { key.current ??= crypto.randomUUID(); const result = await runMrp({ run_number: form.run_number.trim().toUpperCase(), demand_plan_id: form.demand_plan_id, run_date: form.run_date }, key.current); key.current = null; await refresh(); setSelected(await getMrpRun(result.id)); setEditing(false); setFeedback({ error: null, success: 'MRP completed with immutable recipe and inventory snapshots.', fields: {} }); } catch (error) { fail(setFeedback, error, 'Unable to run MRP.'); } finally { setBusy(false); }
  }
  async function cancel() { if (!selected) return; const reason = window.prompt('Cancellation reason'); if (!reason?.trim()) return; setBusy(true); try { await cancelMrpRun(selected, reason.trim(), crypto.randomUUID()); await refresh(); setSelected(await getMrpRun(selected.id)); setFeedback({ error: null, success: 'MRP run cancelled.', fields: {} }); } catch (error) { fail(setFeedback, error, 'Unable to cancel the MRP run.'); } finally { setBusy(false); } }
  return <PlanningLayout code="PLAN-MRP" title="Material Requirements Planning" description="Explode released demand through effective recipes and net requirements against reservable company-owned stock." notice="Every run snapshots recipe revisions, gross requirements, on-hand, existing reservations, availability, and shortages." loading={loading} feedback={feedback} workspace={workspace} search={search} setSearch={setSearch} status={status} setStatus={setStatus} sort={sort} setSort={setSort} page={page} setPage={setPage} onNew={workspace?.allowed_actions.includes('RUN') ? startRun : undefined}
    headings={['MRP run', 'Demand plan', 'Run date', 'Orders', 'Shortage', 'Status']} rows={workspace?.data.map((row) => [<><b>{row.run_number}</b><small>v{row.record_version}</small></>, row.demand_plan.number, date(row.run_date), String(row.planned_order_count), `${decimal(row.shortage_quantity)} base UOM`, <StatusBadge status={row.status} />]) ?? []} ids={workspace?.data.map((row) => row.id) ?? []} selectedId={selected?.id} onOpen={choose}>
    {editing ? <form className="requisition-form" onSubmit={execute}><fieldset disabled={busy}><div className="requisition-field-grid"><label>MRP run number<input value={form.run_number} onChange={(e) => setForm({ ...form, run_number: e.target.value })} /><Field text={feedback.fields.run_number} /></label><label>Released demand plan<select value={form.demand_plan_id} onChange={(e) => setForm({ ...form, demand_plan_id: e.target.value })}><option value="">Select demand plan</option>{workspace?.lookups.released_demand_plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.number} - {plan.name}</option>)}</select><Field text={feedback.fields.demand_plan_id} /></label><label>Planning date<input type="date" value={form.run_date} onChange={(e) => setForm({ ...form, run_date: e.target.value })} /></label></div><FormActions onClose={() => setEditing(false)} submit="Run MRP" /></fieldset></form>
      : selected ? <Detail><DetailHead status={selected.status} version={selected.record_version} /><dl className="control-definition"><Datum text="Demand plan" value={`${selected.demand_plan.number} - v${selected.demand_plan.version}`} /><Datum text="Run date" value={date(selected.run_date)} /><Datum text="Planned orders" value={String(selected.planned_order_count)} /><Datum text="Net shortage" value={decimal(selected.shortage_quantity)} /></dl>{selected.planned_orders?.map((order) => <div className="requisition-line-card" key={order.id}><b>{order.output_sku.code} - {order.output_sku.name}</b><small>{decimal(order.planned_quantity)} {order.uom_code} due {date(order.due_date)} - {order.recipe.code} rev {order.recipe.revision}</small><LineTable heads={['Material', 'Gross', 'Available', 'Shortage']} rows={order.materials.map((material) => [<><b>{material.component_sku.code}</b><small>{material.component_sku.name}</small></>, `${decimal(material.gross_requirement)} ${material.uom_code}`, decimal(material.available_snapshot), decimal(material.shortage_quantity)])} /></div>)}{selected.allowed_actions.includes('CANCEL') ? <div className="form-actions"><button className="secondary" disabled={busy} onClick={() => void cancel()}>Cancel MRP run</button></div> : null}</Detail>
        : <Empty text="Choose an MRP run or run a released demand plan." />}
  </PlanningLayout>;
}

export function ProductionScheduleWorkspace() {
  const contextKey = useContextKey(); const [workspace, setWorkspace] = useState<ScheduleWorkspace | null>(null); const [selected, setSelected] = useState<ProductionSchedule | null>(null);
  const [form, setForm] = useState(blankSchedule()); const [editing, setEditing] = useState(false); const [search, setSearch] = useState(''); const [status, setStatus] = useState(''); const [sort, setSort] = useState('NEWEST'); const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false); const [feedback, setFeedback] = useState<Feedback>(emptyFeedback()); const key = useRef<string | null>(null);
  const refresh = useCallback(async () => { setLoading(true); try { setWorkspace(await listProductionSchedules({ q: search || undefined, status: status || undefined, sort, page })); } catch (error) { fail(setFeedback, error, 'Unable to load production schedules.'); } finally { setLoading(false); } }, [contextKey, search, status, sort, page]);
  useEffect(() => { void refresh(); }, [refresh]); useEffect(() => { setSelected(null); setEditing(false); setSearch(''); setStatus(''); setSort('NEWEST'); setPage(1); setFeedback(emptyFeedback()); }, [contextKey]); useEffect(() => { key.current = null; }, [form, selected?.record_version]);
  async function choose(id: string) { setFeedback(emptyFeedback()); try { setSelected(await getProductionSchedule(id)); setEditing(false); } catch (error) { fail(setFeedback, error, 'Unable to load schedule detail.'); } }
  function startNew() { setSelected(null); setForm(blankSchedule()); setEditing(true); setFeedback(emptyFeedback()); }
  function selectRun(id: string) { const run = workspace?.lookups.mrp_runs.find((value) => value.id === id); setForm(fromRun(run)); }
  function startEdit() {
    if (!selected?.lines || !selected.capacities) return;
    setForm({ schedule_number: selected.schedule_number, mrp_run_id: selected.mrp_run.id, horizon_start: selected.horizon_start, horizon_end: selected.horizon_end, notes: selected.notes ?? '', lines: selected.lines.map((line) => ({ mrp_planned_order_id: line.mrp_planned_order_id, label: `${line.output_sku.code} - ${line.output_sku.name}`, planned_quantity: line.planned_quantity, uom_code: line.uom_code, planned_start_date: line.planned_start_date, planned_end_date: line.planned_end_date, work_centers: [...new Set(line.operations.map((operation) => operation.work_center_code))] })), capacities: selected.capacities.map((capacity) => ({ work_center_code: capacity.work_center_code, daily_capacity_minutes: capacity.daily_capacity_minutes })) });
    setEditing(true); setFeedback(emptyFeedback());
  }
  async function save(event: FormEvent) {
    event.preventDefault(); const fields: Record<string, string> = {};
    if (!form.schedule_number.trim()) fields.schedule_number = 'Schedule number is required.'; if (!form.mrp_run_id) fields.mrp_run_id = 'Choose a completed MRP run.'; if (!form.lines.length) fields.lines = 'Select at least one planned order.';
    form.capacities.forEach((capacity, index) => { if (number(capacity.daily_capacity_minutes) <= 0) fields[`capacities.${index}.daily_capacity_minutes`] = 'Enter positive daily minutes.'; });
    if (Object.keys(fields).length) { setFeedback({ error: 'Correct the highlighted schedule fields.', success: null, fields }); return; }
    setBusy(true); try { key.current ??= crypto.randomUUID(); const body = { horizon_start: form.horizon_start, horizon_end: form.horizon_end, notes: nullable(form.notes), lines: form.lines.map(({ label: _label, planned_quantity: _quantity, uom_code: _uom, work_centers: _centers, ...line }) => line), capacities: form.capacities }; const result = selected ? await updateProductionSchedule(selected, body, key.current) : await createProductionSchedule({ ...body, schedule_number: form.schedule_number.trim().toUpperCase(), mrp_run_id: form.mrp_run_id }, key.current); key.current = null; await refresh(); setSelected(await getProductionSchedule(result.id)); setEditing(false); setFeedback({ error: null, success: selected ? 'Draft schedule recalculated.' : 'Capacity schedule created.', fields: {} }); } catch (error) { fail(setFeedback, error, 'Unable to save the production schedule.'); } finally { setBusy(false); }
  }
  async function command(action: 'RELEASE' | 'CANCEL') { if (!selected) return; const reason = action === 'CANCEL' ? window.prompt('Cancellation reason') : null; if (action === 'CANCEL' && !reason?.trim()) return; setBusy(true); try { action === 'RELEASE' ? await releaseProductionSchedule(selected, crypto.randomUUID()) : await cancelProductionSchedule(selected, reason!.trim(), crypto.randomUUID()); await refresh(); setSelected(await getProductionSchedule(selected.id)); setFeedback({ error: null, success: action === 'RELEASE' ? 'Schedule released and materials reserved by FEFO.' : 'Schedule cancelled and linked reservations released.', fields: {} }); } catch (error) { fail(setFeedback, error, 'Unable to complete the schedule command.'); } finally { setBusy(false); } }
  return <PlanningLayout code="PLAN-SCH" title="Production Schedule" description="Load MRP orders onto route work centers, test finite daily capacity, and reserve eligible material by FEFO." notice="Release is atomic: every capacity must fit and every material requirement must be fully reservable at the planned start date." loading={loading} feedback={feedback} workspace={workspace} search={search} setSearch={setSearch} status={status} setStatus={setStatus} sort={sort} setSort={setSort} page={page} setPage={setPage} onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
    headings={['Schedule', 'MRP run', 'Horizon', 'Orders', 'Reservations', 'Status']} rows={workspace?.data.map((row) => [<><b>{row.schedule_number}</b><small>v{row.record_version}</small></>, row.mrp_run.number, `${date(row.horizon_start)} - ${date(row.horizon_end)}`, String(row.line_count), `${row.active_reservation_count} / ${decimal(row.reserved_quantity)}`, <StatusBadge status={row.status} />]) ?? []} ids={workspace?.data.map((row) => row.id) ?? []} selectedId={selected?.id} onOpen={choose}>
    {editing ? <form className="requisition-form" onSubmit={save}><fieldset disabled={busy}><div className="requisition-field-grid"><label>Schedule number<input disabled={!!selected} value={form.schedule_number} onChange={(e) => setForm({ ...form, schedule_number: e.target.value })} /><Field text={feedback.fields.schedule_number} /></label><label>Completed MRP run<select disabled={!!selected} value={form.mrp_run_id} onChange={(e) => selectRun(e.target.value)}><option value="">Select MRP run</option>{selected ? <option value={selected.mrp_run.id}>{selected.mrp_run.number}</option> : null}{workspace?.lookups.mrp_runs.map((run) => <option key={run.id} value={run.id}>{run.number} - {run.demand_plan_number}</option>)}</select><Field text={feedback.fields.mrp_run_id} /></label><label>Horizon start<input type="date" value={form.horizon_start} onChange={(e) => setScheduleHorizonStart(setForm, e.target.value)} /></label><label>Horizon end<input type="date" value={form.horizon_end} onChange={(e) => setScheduleHorizonEnd(setForm, e.target.value)} /></label><label className="wide">Notes<textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></label></div>
      <div className="requisition-lines-head"><div><h4>Planned orders</h4><small>Line dates must stay inside the schedule horizon.</small></div></div>{form.lines.map((line, index) => <div className="requisition-line-card" key={line.mrp_planned_order_id}><b>{line.label}</b><small>{decimal(line.planned_quantity)} {line.uom_code}</small><div className="requisition-line-grid"><label>Start date<input type="date" value={line.planned_start_date} onChange={(e) => patchScheduleLine(setForm, index, { planned_start_date: e.target.value })} /></label><label>End date<input type="date" value={line.planned_end_date} onChange={(e) => patchScheduleLine(setForm, index, { planned_end_date: e.target.value })} /></label></div>{!selected && form.lines.length > 1 ? <button className="secondary compact-button" type="button" onClick={() => removeScheduleLine(setForm, index)}>Exclude order</button> : null}</div>)}<Field text={feedback.fields.lines} />
      {form.capacities.length ? <><div className="requisition-lines-head"><div><h4>Daily work-center capacity</h4><small>Available minutes are daily minutes times weekdays in the horizon.</small></div></div>{form.capacities.map((capacity, index) => <div className="requisition-line-card" key={capacity.work_center_code}><b>{capacity.work_center_code}</b><label>Daily capacity minutes<input aria-label={`${capacity.work_center_code} daily capacity minutes`} type="number" min="0.001" step="0.001" value={capacity.daily_capacity_minutes} onChange={(e) => patchCapacity(setForm, index, e.target.value)} /><Field text={feedback.fields[`capacities.${index}.daily_capacity_minutes`]} /></label></div>)}</> : null}<FormActions onClose={() => setEditing(false)} submit={selected ? 'Recalculate schedule' : 'Create schedule'} /></fieldset></form>
      : selected ? <Detail><DetailHead status={selected.status} version={selected.record_version} /><dl className="control-definition"><Datum text="MRP run" value={selected.mrp_run.number} /><Datum text="Demand plan" value={selected.demand_plan_number} /><Datum text="Horizon" value={`${date(selected.horizon_start)} - ${date(selected.horizon_end)}`} /><Datum text="Reserved material" value={decimal(selected.reserved_quantity)} /></dl><LineTable heads={['Work center', 'Required', 'Available', 'Utilisation']} rows={selected.capacities?.map((capacity) => [<><b>{capacity.work_center_code}</b>{capacity.is_overloaded ? <small>OVERLOADED</small> : null}</>, decimal(capacity.required_minutes), decimal(capacity.available_minutes), `${decimal(capacity.utilisation_percent)}%`]) ?? []} />{selected.lines?.map((line) => <div className="requisition-line-card" key={line.id}><b>{line.output_sku.code} - {line.output_sku.name}</b><small>{decimal(line.planned_quantity)} {line.uom_code} / {line.route.code} / {date(line.planned_start_date)} to {date(line.planned_end_date)}</small><LineTable heads={['Material', 'Required', 'MRP shortage', 'Reserved']} rows={line.materials.map((material) => [material.component_sku.code, `${decimal(material.gross_requirement)} ${material.uom_code}`, decimal(material.mrp_shortage_quantity), decimal(material.reserved_quantity)])} /></div>)}{selected.reservations?.length ? <><h4>FEFO material reservations</h4><LineTable heads={['Reservation', 'Item / lot', 'Expiry', 'Quantity']} rows={selected.reservations.map((reservation) => [<><b>{reservation.number}</b><small>{statusLabel(reservation.status)}</small></>, `${reservation.item_code} / ${reservation.lot_code}`, reservation.expiry_date ? date(reservation.expiry_date) : 'No expiry', `${decimal(reservation.quantity)} ${reservation.uom_code}`])} /></> : null}<div className="form-actions">{selected.allowed_actions.includes('UPDATE') ? <button className="secondary" disabled={busy} onClick={startEdit}>Edit capacity plan</button> : null}{selected.allowed_actions.includes('RELEASE') ? <button className="primary" disabled={busy} onClick={() => void command('RELEASE')}>Release and reserve</button> : null}{selected.allowed_actions.includes('CANCEL') ? <button className="secondary" disabled={busy} onClick={() => void command('CANCEL')}>Cancel</button> : null}</div></Detail>
        : <Empty text="Choose a production schedule or schedule unslotted MRP orders." />}
  </PlanningLayout>;
}

function PlanningLayout({ code, title, description, notice, loading, feedback, workspace, search, setSearch, status, setStatus, sort, setSort, page, setPage, onNew, headings, rows, ids, selectedId, onOpen, children }: {
  code: string; title: string; description: string; notice: string; loading: boolean; feedback: Feedback;
  workspace: { lookups: { statuses: string[]; sorts: string[] }; meta: { current_page: number; last_page: number; total: number } } | null;
  search: string; setSearch: (value: string) => void; status: string; setStatus: (value: string) => void;
  sort: string; setSort: (value: string) => void; page: number; setPage: React.Dispatch<React.SetStateAction<number>>;
  onNew?: () => void; headings: string[]; rows: ReactNode[][]; ids: string[]; selectedId?: string;
  onOpen: (id: string) => void; children: ReactNode;
}) {
  return <>
    <PageHeader code={code} batch="P1 Manufacturing planning" title={title} description={description} onNew={onNew} />
    <div className="live-notice procurement-notice"><span>LIVE</span>{notice}</div>
    <div className="module-grid requisition-workspace">
      <section className="panel">
        <div className="requisition-toolbar">
          <label>Search<input value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="Plan, run or schedule" /></label>
          <label>Status<select value={status} onChange={(event) => { setPage(1); setStatus(event.target.value); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
          <label>Sort<select value={sort} onChange={(event) => { setPage(1); setSort(event.target.value); }}>{workspace?.lookups.sorts.map((item) => <option key={item}>{item}</option>)}</select></label>
        </div>
        {loading && !workspace ? <Empty text={`Loading ${title.toLowerCase()}...`} /> : <>
          <div className="table-wrap"><table className="requisition-table"><thead><tr>{headings.map((heading) => <th key={heading}>{heading}</th>)}<th /></tr></thead><tbody>{rows.map((cells, index) => <tr key={ids[index]} className={selectedId === ids[index] ? 'selected-row' : ''}>{cells.map((cell, position) => <td key={position}>{cell}</td>)}<td><button className="secondary compact-button" type="button" onClick={() => void onOpen(ids[index])}>Open</button></td></tr>)}</tbody></table></div>
          {!rows.length ? <Empty text={`No ${title.toLowerCase()} match the current filters.`} /> : null}
          {workspace ? <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div> : null}
        </>}
      </section>
      <aside className="panel requisition-editor"><div className="requisition-detail-body">{feedback.error ? <div className="form-error" role="alert"><span>{feedback.error}</span></div> : null}{feedback.success ? <div className="form-success" role="status"><span />{feedback.success}</div> : null}{children}</div></aside>
    </div>
  </>;
}

function Detail({ children }: { children: ReactNode }) { return <div className="requisition-detail">{children}</div>; }
function DetailHead({ status, version }: { status: string; version: number }) { return <div className="detail-status"><StatusBadge status={status} /><span>record version {version}</span></div>; }
function Datum({ text, value, wide = false }: { text: string; value: string; wide?: boolean }) { return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd>{value}</dd></div>; }
function Field({ text }: { text?: string }) { return text ? <small className="field-error">{text}</small> : null; }
function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }
function FormActions({ onClose, submit }: { onClose: () => void; submit: string }) { return <div className="form-actions"><button className="secondary" type="button" onClick={onClose}>Close</button><button className="primary" type="submit">{submit}</button></div>; }
function LineTable({ heads, rows }: { heads: string[]; rows: ReactNode[][] }) { return <div className="table-wrap"><table><thead><tr>{heads.map((head) => <th key={head}>{head}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={index}>{row.map((cell, position) => <td key={position}>{cell}</td>)}</tr>)}</tbody></table></div>; }
function useContextKey() { const session = useErpSession(); return `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`; }
function fail(set: React.Dispatch<React.SetStateAction<Feedback>>, error: unknown, fallback: string) { const api = isApiError(error) ? error : null; const fields: Record<string, string> = {}; Object.entries(api?.fields ?? {}).forEach(([key, values]) => { fields[key] = values[0] ?? ''; }); set({ error: api?.message ?? (error instanceof Error ? error.message : fallback), success: null, fields }); }
function nullable(value: string) { const trimmed = value.trim(); return trimmed || null; }
function number(value: unknown) { const parsed = Number(value); return Number.isFinite(parsed) ? parsed : 0; }
function decimal(value: unknown) { return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 6 }).format(number(value)); }
function date(value: string) { return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)); }
function today() { return new Date().toISOString().slice(0, 10); }
function addDays(value: string, days: number) { const result = new Date(`${value}T00:00:00Z`); result.setUTCDate(result.getUTCDate() + days); return result.toISOString().slice(0, 10); }

type DemandForm = ReturnType<typeof blankDemand>;
function blankDemandLine(demandDate = addDays(today(), 7)) { return { output_sku_id: '', demand_date: demandDate, demand_type: 'FIRM', quantity: '', notes: '' }; }
function blankDemand() { return { plan_number: '', name: '', horizon_start: today(), horizon_end: addDays(today(), 14), notes: '', lines: [blankDemandLine()] }; }
function patchDemandLine(set: React.Dispatch<React.SetStateAction<DemandForm>>, index: number, patch: Partial<DemandForm['lines'][number]>) { set((value) => ({ ...value, lines: value.lines.map((line, position) => position === index ? { ...line, ...patch } : line) })); }
function blankMrp() { return { run_number: '', demand_plan_id: '', run_date: today() }; }

type ScheduleForm = ReturnType<typeof blankSchedule>;
function blankSchedule() { return { schedule_number: '', mrp_run_id: '', horizon_start: today(), horizon_end: addDays(today(), 7), notes: '', lines: [] as { mrp_planned_order_id: string; label: string; planned_quantity: string; uom_code: string; planned_start_date: string; planned_end_date: string; work_centers: string[] }[], capacities: [] as { work_center_code: string; daily_capacity_minutes: string }[] }; }
function fromRun(run: SchedulableMrpRun | undefined): ScheduleForm {
  if (!run) return blankSchedule();
  const start = today(); const dueDates = run.planned_orders.map((order) => order.due_date).sort(); const end = dueDates.at(-1) ?? addDays(start, 7);
  const centers = [...new Set(run.planned_orders.flatMap((order) => order.work_centers))].sort();
  return { schedule_number: '', mrp_run_id: run.id, horizon_start: start, horizon_end: end < start ? start : end, notes: '', lines: run.planned_orders.map((order) => ({ mrp_planned_order_id: order.id, label: `${order.output_sku.code} - ${order.output_sku.name}`, planned_quantity: order.planned_quantity, uom_code: order.uom_code, planned_start_date: start, planned_end_date: order.due_date < start ? start : order.due_date, work_centers: order.work_centers })), capacities: centers.map((center) => ({ work_center_code: center, daily_capacity_minutes: '480' })) };
}
function patchScheduleLine(set: React.Dispatch<React.SetStateAction<ScheduleForm>>, index: number, patch: Partial<ScheduleForm['lines'][number]>) { set((value) => ({ ...value, lines: value.lines.map((line, position) => position === index ? { ...line, ...patch } : line) })); }
function patchCapacity(set: React.Dispatch<React.SetStateAction<ScheduleForm>>, index: number, minutes: string) { set((value) => ({ ...value, capacities: value.capacities.map((capacity, position) => position === index ? { ...capacity, daily_capacity_minutes: minutes } : capacity) })); }
function removeScheduleLine(set: React.Dispatch<React.SetStateAction<ScheduleForm>>, index: number) { set((value) => { const lines = value.lines.filter((_, position) => position !== index); const required = new Set(lines.flatMap((line) => line.work_centers)); return { ...value, lines, capacities: value.capacities.filter((capacity) => required.has(capacity.work_center_code)) }; }); }
function setScheduleHorizonStart(set: React.Dispatch<React.SetStateAction<ScheduleForm>>, start: string) { set((value) => ({ ...value, horizon_start: start, lines: value.lines.map((line) => ({ ...line, planned_start_date: line.planned_start_date < start ? start : line.planned_start_date, planned_end_date: line.planned_end_date < start ? start : line.planned_end_date })) })); }
function setScheduleHorizonEnd(set: React.Dispatch<React.SetStateAction<ScheduleForm>>, end: string) { set((value) => ({ ...value, horizon_end: end, lines: value.lines.map((line) => ({ ...line, planned_start_date: line.planned_start_date > end ? end : line.planned_start_date, planned_end_date: line.planned_end_date > end ? end : line.planned_end_date })) })); }
