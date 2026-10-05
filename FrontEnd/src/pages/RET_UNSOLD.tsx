import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createUnsoldReturn,
  getUnsoldReturnLookups,
  listUnsoldReturns,
  type ShipmentLineLookup,
  type UnsoldReturnApprovalDecisionResult,
  type UnsoldReturnList,
  type UnsoldReturnLookups,
} from '../api/unsoldReturns';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';
import { UnsoldReturnApprovalInbox } from '../components/UnsoldReturnApprovalInbox';
import { UnsoldReturnCasePanel } from '../components/UnsoldReturnCasePanel';

type ReturnForm = {
  partyId: string;
  shipmentId: string;
  invoiceId: string;
  shipmentLineId: string;
  quantity: string;
  reasonCode: string;
  expectedReturnDate: string;
  salesNote: string;
};

const emptyLookups: UnsoldReturnLookups = {
  parties: [],
  shipments: [],
  invoices: [],
  shipment_lines: [],
  skus: [],
  lots: [],
  return_positions: [],
  return_locations: [],
};

export default function RET_UNSOLD() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const canCreate = session.allowed_actions.includes('ACTION:RET-UNSOLD:CREATE');
  const [form, setForm] = useState<ReturnForm>(newReturnForm);
  const [lookups, setLookups] = useState<UnsoldReturnLookups>(emptyLookups);
  const [lookupsLoading, setLookupsLoading] = useState(true);
  const [lookupError, setLookupError] = useState<string | null>(null);
  const [lookupReload, setLookupReload] = useState(0);
  const [returns, setReturns] = useState<UnsoldReturnList | null>(null);
  const [returnsLoading, setReturnsLoading] = useState(true);
  const [returnsError, setReturnsError] = useState<string | null>(null);
  const [selectedCaseId, setSelectedCaseId] = useState<string | null>(recordFromHash);
  const [caseRefreshToken, setCaseRefreshToken] = useState(0);
  const [approvalRefreshToken, setApprovalRefreshToken] = useState(0);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const idempotencyKey = useRef<string | null>(null);

  const selectedLine = useMemo(
    () => lookups.shipment_lines.find((line) => line.id === form.shipmentLineId) ?? null,
    [form.shipmentLineId, lookups.shipment_lines]
  );
  const selectedInvoice = useMemo(
    () => lookups.invoices.find((invoice) => invoice.id === form.invoiceId) ?? null,
    [form.invoiceId, lookups.invoices]
  );
  const selectedSkuId = selectedLine?.sku.id ?? '';
  const selectedLotId = selectedLine?.fg_lot?.id ?? '';

  const refreshReturns = useCallback(async () => {
    setReturnsLoading(true);
    setReturnsError(null);
    try {
      setReturns(await listUnsoldReturns(10));
    } catch (caught) {
      setReturnsError(isApiError(caught) ? caught.message : 'Unable to load unsold return cases.');
    } finally {
      setReturnsLoading(false);
    }
  }, [contextKey]);

  const handleCaseChanged = useCallback(async () => {
    setApprovalRefreshToken((value) => value + 1);
    await refreshReturns();
  }, [refreshReturns]);

  const handleApprovalDecision = useCallback(async (result: UnsoldReturnApprovalDecisionResult) => {
    setSelectedCaseId(result.return_case_id);
    setCaseRefreshToken((value) => value + 1);
    await refreshReturns();
  }, [refreshReturns]);

  useEffect(() => {
    setForm(newReturnForm());
    setFieldErrors({});
    setSubmitError(null);
    setSuccess(null);
    setSelectedCaseId(recordFromHash());
    idempotencyKey.current = null;
    void refreshReturns();
  }, [contextKey, refreshReturns]);

  useEffect(() => {
    let active = true;
    setLookupsLoading(true);
    setLookupError(null);

    getUnsoldReturnLookups({
      party_id: form.partyId || undefined,
      shipment_id: form.shipmentId || undefined,
      sku_id: selectedSkuId || undefined,
      lot_id: selectedLotId || undefined,
    })
      .then((data) => {
        if (active) setLookups(data);
      })
      .catch((caught) => {
        if (active) {
          setLookupError(isApiError(caught) ? caught.message : 'Unable to load return reference data.');
        }
      })
      .finally(() => {
        if (active) setLookupsLoading(false);
      });

    return () => { active = false; };
  }, [contextKey, form.partyId, form.shipmentId, selectedSkuId, selectedLotId, lookupReload]);

  function change(patch: Partial<ReturnForm>) {
    idempotencyKey.current = null;
    setForm((current) => ({ ...current, ...patch }));
    setFieldErrors({});
    setSubmitError(null);
    setSuccess(null);
  }

  function selectParty(partyId: string) {
    change({ partyId, shipmentId: '', invoiceId: '', shipmentLineId: '', quantity: '' });
  }

  function selectShipment(shipmentId: string) {
    change({ shipmentId, invoiceId: '', shipmentLineId: '', quantity: '' });
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || submitting) return;

    const errors = validate(form, selectedLine);
    if (Object.keys(errors).length) {
      setFieldErrors(errors);
      setSubmitError('Correct the highlighted fields before submitting.');
      return;
    }

    setSubmitting(true);
    setSubmitError(null);
    setSuccess(null);
    idempotencyKey.current ??= globalThis.crypto.randomUUID();

    try {
      const result = await createUnsoldReturn({
        party_id: form.partyId,
        shipment_id: form.shipmentId,
        invoice_id: form.invoiceId || undefined,
        reason_code: form.reasonCode,
        expected_return_date: form.expectedReturnDate,
        sales_note: form.salesNote.trim() || undefined,
        lines: [{
          shipment_line_id: form.shipmentLineId,
          sku_id: selectedLine!.sku.id,
          fg_lot_id: selectedLine!.fg_lot?.id,
          requested_quantity: form.quantity.trim(),
          uom_code: selectedLine!.uom_code,
        }],
      }, idempotencyKey.current);

      setSuccess(`Return ${shortId(result.return_case_id)} created in ${displayStatus(result.status)} state.`);
      setSelectedCaseId(result.return_case_id);
      setForm(newReturnForm());
      setFieldErrors({});
      idempotencyKey.current = null;
      await refreshReturns();
    } catch (caught) {
      if (isApiError(caught)) {
        setSubmitError(caught.message);
        setFieldErrors(mapApiFieldErrors(caught.fields));
      } else {
        setSubmitError('Unable to submit the return request.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <PageHeader
        code="RET-UNSOLD"
        batch="B16"
        title="Unsold Sales Return & Loss"
        description="Sales initiates an unsold return; physical receipt, Quality disposition and loss recognition remain separate controlled actions."
      />
      <div className="live-notice"><span></span><b>Scoped reference data</b> Customer, shipment, SKU, lot and return-quarantine options come from the selected ERP context.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Return cases</span><b>{returnsLoading ? '-' : returns?.meta.total ?? 0}</b><small>selected company and plant</small></div>
        <div className="kpi"><span>Eligible invoices</span><b>{lookupsLoading ? '-' : lookups.invoices.length}</b><small>{form.shipmentId ? 'selected shipment' : 'choose a shipment'}</small></div>
        <div className="kpi"><span>Eligible shipments</span><b>{lookupsLoading ? '-' : lookups.shipments.length}</b><small>{form.partyId ? 'selected customer' : 'selected plant'}</small></div>
        <div className="kpi"><span>Quarantine routes</span><b>{lookupsLoading ? '-' : lookups.return_positions.length}</b><small>{selectedLine ? 'selected SKU and lot' : 'choose a shipment line'}</small></div>
      </div>

      <div className="module-grid">
        <section className="panel">
          <div className="panel-head"><h3>Create unsold return request</h3><span>Sales to return request</span></div>
          {!canCreate ? (
            <div className="empty-state">Your active role can review return cases but cannot create a Sales return request.</div>
          ) : (
            <form className="panel-body form-grid" onSubmit={submit} noValidate>
              {lookupError && (
                <div className="form-error full" role="alert">
                  <span>{lookupError}</span>
                  <button type="button" onClick={() => setLookupReload((value) => value + 1)}>Retry</button>
                </div>
              )}
              {submitError && <div className="form-error full" role="alert"><span>{submitError}</span></div>}
              {success && <div className="form-success full" role="status"><span></span>{success}</div>}

              <label>
                Customer / Distributor
                <select
                  value={form.partyId}
                  onChange={(event) => selectParty(event.target.value)}
                  aria-invalid={Boolean(fieldErrors.partyId)}
                  disabled={lookupsLoading && !lookups.parties.length}
                >
                  <option value="">Select an active customer</option>
                  {lookups.parties.map((party) => (
                    <option key={party.id} value={party.id}>{party.code} - {party.name}</option>
                  ))}
                </select>
                <FieldError message={fieldErrors.partyId} />
              </label>

              <label>
                Original shipment
                <select
                  value={form.shipmentId}
                  onChange={(event) => selectShipment(event.target.value)}
                  aria-invalid={Boolean(fieldErrors.shipmentId)}
                  disabled={!form.partyId || lookupsLoading}
                >
                  <option value="">{form.partyId ? 'Select an eligible shipment' : 'Choose a customer first'}</option>
                  {lookups.shipments.map((shipment) => (
                    <option key={shipment.id} value={shipment.id}>{shipment.number} - {displayStatus(shipment.status)}</option>
                  ))}
                </select>
                <FieldError message={fieldErrors.shipmentId} />
              </label>

              <label className="full">
                Source invoice (optional at request)
                <select
                  value={form.invoiceId}
                  onChange={(event) => change({ invoiceId: event.target.value })}
                  aria-invalid={Boolean(fieldErrors.invoiceId)}
                  disabled={!form.shipmentId || lookupsLoading}
                >
                  <option value="">{form.shipmentId ? 'Finance can link an invoice after loss posting' : 'Choose a shipment first'}</option>
                  {lookups.invoices.map((invoice) => (
                    <option key={invoice.id} value={invoice.id}>
                      {invoice.number} - {displayStatus(invoice.status)} - {invoice.currency} {formatMoney(invoice.gross_amount)} ({formatMoney(invoice.outstanding_amount)} open)
                    </option>
                  ))}
                </select>
                <FieldError message={fieldErrors.invoiceId} />
              </label>

              <label className="full">
                Shipment SKU / finished-goods lot
                <select
                  value={form.shipmentLineId}
                  onChange={(event) => change({ shipmentLineId: event.target.value, quantity: '' })}
                  aria-invalid={Boolean(fieldErrors.shipmentLineId)}
                  disabled={!form.shipmentId || lookupsLoading}
                >
                  <option value="">{form.shipmentId ? 'Select a shipped SKU and lot' : 'Choose a shipment first'}</option>
                  {lookups.shipment_lines.map((line) => (
                    <option key={line.id} value={line.id} disabled={Number(line.available_to_return) <= 0}>
                      {line.sku.code} - {line.sku.name} / {line.fg_lot?.code ?? 'No lot'} / {formatQuantity(line.available_to_return)} {line.uom_code} available
                    </option>
                  ))}
                </select>
                <FieldError message={fieldErrors.shipmentLineId} />
              </label>

              <label>
                Unsold quantity
                <input
                  type="number"
                  min="0.000001"
                  max={selectedLine?.available_to_return}
                  step="0.000001"
                  value={form.quantity}
                  onChange={(event) => change({ quantity: event.target.value })}
                  placeholder={selectedLine ? `Maximum ${formatQuantity(selectedLine.available_to_return)}` : 'Choose a shipment line'}
                  aria-invalid={Boolean(fieldErrors.quantity)}
                  disabled={!selectedLine}
                />
                <FieldError message={fieldErrors.quantity} />
              </label>

              <label>
                Reason
                <select value={form.reasonCode} onChange={(event) => change({ reasonCode: event.target.value })} aria-invalid={Boolean(fieldErrors.reasonCode)}>
                  <option value="UNSOLD_MARKET_RETURN">Unsold market return</option>
                  <option value="SHORT_SHELF_LIFE">Short shelf life</option>
                  <option value="DAMAGED_AT_CUSTOMER">Damaged at customer</option>
                </select>
                <FieldError message={fieldErrors.reasonCode} />
              </label>

              <label>
                Expected return date
                <input
                  type="date"
                  value={form.expectedReturnDate}
                  onChange={(event) => change({ expectedReturnDate: event.target.value })}
                  aria-invalid={Boolean(fieldErrors.expectedReturnDate)}
                />
                <FieldError message={fieldErrors.expectedReturnDate} />
              </label>

              <label className="full">
                Sales note
                <textarea
                  value={form.salesNote}
                  onChange={(event) => change({ salesNote: event.target.value })}
                  maxLength={2000}
                  rows={3}
                  placeholder="Add the distributor confirmation or collection instructions."
                  aria-invalid={Boolean(fieldErrors.salesNote)}
                />
                <span className="field-hint">{form.salesNote.length}/2000 characters</span>
                <FieldError message={fieldErrors.salesNote} />
              </label>

              <div className="form-actions full">
                <button className="secondary" type="button" onClick={() => change(newReturnForm())} disabled={submitting}>Clear</button>
                <button className="primary" type="submit" disabled={submitting || lookupsLoading || Boolean(lookupError)}>
                  {submitting ? 'Submitting...' : 'Submit return request'}
                </button>
              </div>
            </form>
          )}
        </section>

        <aside className="panel focus-card">
          <div className="eyebrow">LIVE LOOKUP CHAIN</div>
          <h3>{selectedLine ? selectedLine.sku.name : 'Choose a shipment line'}</h3>
          {!selectedLine ? (
            <p>Select the customer and original shipment to resolve the exact shipped SKU and finished-goods lot.</p>
          ) : (
            <>
              <dl className="lookup-facts">
                <div><dt>SKU</dt><dd>{selectedLine.sku.code}</dd></div>
                <div><dt>FG lot</dt><dd>{selectedLine.fg_lot?.code ?? 'Not recorded'}</dd></div>
                <div><dt>Returnable</dt><dd>{formatQuantity(selectedLine.available_to_return)} {selectedLine.uom_code}</dd></div>
                <div><dt>Lot expiry</dt><dd>{selectedLine.fg_lot?.expiry_date ?? 'Not recorded'}</dd></div>
                <div><dt>Source invoice</dt><dd>{selectedInvoice?.number ?? 'Finance to confirm'}</dd></div>
                <div><dt>Invoice open</dt><dd>{selectedInvoice ? `${selectedInvoice.currency} ${formatMoney(selectedInvoice.outstanding_amount)}` : 'Not linked'}</dd></div>
              </dl>
              <div className="lookup-route">
                <b>Stores quarantine destination</b>
                {lookupsLoading ? (
                  <span>Resolving route...</span>
                ) : lookups.return_positions.length ? (
                  lookups.return_positions.map((position) => (
                    <span key={position.id}>{position.location.code} - {position.location.name}</span>
                  ))
                ) : (
                  <span className="text-warn">No matching return-quarantine position is configured.</span>
                )}
              </div>
            </>
          )}
          <div className="callout">Sales records the expected return only. Stores will confirm the actual receipt into the resolved quarantine position.</div>
        </aside>
      </div>

      <section className="panel return-list" style={{ marginTop: 16 }}>
        <div className="panel-head">
          <h3>Recent unsold return cases</h3>
          <button className="secondary compact-button" type="button" onClick={() => void refreshReturns()} disabled={returnsLoading}>Refresh</button>
        </div>
        {returnsError && <div className="form-error panel-message" role="alert"><span>{returnsError}</span><button type="button" onClick={() => void refreshReturns()}>Retry</button></div>}
        {returnsLoading && <div className="empty-state">Loading scoped return cases...</div>}
        {!returnsLoading && !returnsError && !returns?.data.length && <div className="empty-state">No unsold return cases exist in this company and plant yet.</div>}
        {!returnsLoading && !returnsError && Boolean(returns?.data.length) && (
          <div className="table-wrap">
            <table>
              <thead><tr><th>Case</th><th>Customer</th><th>Status</th><th>Requested</th><th>Received</th><th>Expected return</th><th>Version</th></tr></thead>
              <tbody>
                {returns!.data.map((returnCase) => (
                  <tr key={returnCase.id}>
                    <td><button className="case-link" type="button" onClick={() => setSelectedCaseId(returnCase.id)} aria-pressed={selectedCaseId === returnCase.id}>{shortId(returnCase.id)}</button><small>{returnCase.reason_code}</small></td>
                    <td>{returnCase.party.name ?? returnCase.party.id}<small>{returnCase.party.code}</small></td>
                    <td><StatusBadge status={displayStatus(returnCase.status)} /></td>
                    <td>{formatQuantity(returnCase.quantities.requested)}</td>
                    <td>{formatQuantity(returnCase.quantities.received)}</td>
                    <td>{returnCase.expected_return_date ?? '-'}</td>
                    <td>v{returnCase.record_version}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <UnsoldReturnApprovalInbox
        refreshToken={approvalRefreshToken}
        onOpenCase={(caseId) => {
          setSelectedCaseId(caseId);
          setCaseRefreshToken((value) => value + 1);
        }}
        onDecision={handleApprovalDecision}
      />

      <UnsoldReturnCasePanel
        caseId={selectedCaseId}
        refreshToken={caseRefreshToken}
        onClose={() => {
          setSelectedCaseId(null);
          if (window.location.hash.startsWith('#RET-UNSOLD?')) {
            window.history.replaceState(null, '', '#RET-UNSOLD');
          }
        }}
        onChanged={handleCaseChanged}
      />

      <section className="panel" style={{ marginTop: 16 }}>
        <div className="panel-head"><h3>Return-to-loss workflow</h3><span>Controlled state chain</span></div>
        <div className="process-strip panel-body">
          {[
            ['1. Sales request', 'Customer / shipment / SKU / lot / quantity / reason'],
            ['2. Physical return', 'Stores confirms actual received quantity'],
            ['3. Return quarantine', 'Blocked from ordinary sale/use'],
            ['4. Quality disposition', 'Restock / repack / rework / destroy'],
            ['5. Loss event', 'Only approved destroyed quantity'],
            ['6. Finance resolution', 'Invoice + credit + tax + receivable/refund/replacement'],
          ].map(([title, detail]) => <div className="step-card" key={title}><b>{title}</b><span>{detail}</span></div>)}
        </div>
      </section>
    </>
  );
}

function recordFromHash(): string | null {
  const [, query = ''] = window.location.hash.replace(/^#/, '').split('?', 2);
  const record = new URLSearchParams(query).get('record');

  return record && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(record)
    ? record
    : null;
}

function FieldError({ message }: { message?: string }) {
  return message ? <span className="field-error">{message}</span> : null;
}

function newReturnForm(): ReturnForm {
  const expected = new Date();
  expected.setDate(expected.getDate() + 7);

  return {
    partyId: '',
    shipmentId: '',
    invoiceId: '',
    shipmentLineId: '',
    quantity: '',
    reasonCode: 'UNSOLD_MARKET_RETURN',
    expectedReturnDate: expected.toISOString().slice(0, 10),
    salesNote: '',
  };
}

function validate(form: ReturnForm, line: ShipmentLineLookup | null): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!form.partyId) errors.partyId = 'Select the customer returning the goods.';
  if (!form.shipmentId) errors.shipmentId = 'Select the original shipment.';
  if (!form.shipmentLineId || !line) errors.shipmentLineId = 'Select the shipped SKU and lot.';
  if (!/^\d+(\.\d{1,6})?$/.test(form.quantity.trim()) || Number(form.quantity) <= 0) {
    errors.quantity = 'Enter a positive quantity with no more than six decimal places.';
  } else if (line && Number(form.quantity) > Number(line.available_to_return)) {
    errors.quantity = `Quantity cannot exceed ${formatQuantity(line.available_to_return)} ${line.uom_code}.`;
  }
  if (!form.reasonCode) errors.reasonCode = 'Select a return reason.';
  if (!form.expectedReturnDate) errors.expectedReturnDate = 'Enter the expected physical return date.';
  if (form.salesNote.length > 2000) errors.salesNote = 'Sales note may not exceed 2000 characters.';

  return errors;
}

function mapApiFieldErrors(fields?: Record<string, string[]>): Record<string, string> {
  if (!fields) return {};
  const names: Record<string, string> = {
    party_id: 'partyId',
    shipment_id: 'shipmentId',
    invoice_id: 'invoiceId',
    reason_code: 'reasonCode',
    expected_return_date: 'expectedReturnDate',
    sales_note: 'salesNote',
    'lines.0.shipment_line_id': 'shipmentLineId',
    'lines.0.sku_id': 'shipmentLineId',
    'lines.0.fg_lot_id': 'shipmentLineId',
    'lines.0.uom_code': 'shipmentLineId',
    'lines.0.requested_quantity': 'quantity',
  };

  return Object.entries(fields).reduce<Record<string, string>>((errors, [name, messages]) => {
    errors[names[name] ?? name] = messages[0] ?? 'This value is invalid.';
    return errors;
  }, {});
}

function displayStatus(status: string): string {
  return status.replaceAll('_', ' ');
}

function formatQuantity(quantity: string): string {
  const value = Number(quantity);
  return Number.isFinite(value) ? value.toLocaleString(undefined, { maximumFractionDigits: 6 }) : quantity;
}

function formatMoney(amount: string): string {
  const value = Number(amount);
  return Number.isFinite(value)
    ? value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : amount;
}

function shortId(id: string): string {
  return `RET-${id.slice(0, 8).toUpperCase()}`;
}
