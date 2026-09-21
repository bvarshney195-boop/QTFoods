import { listStock } from './inventoryFoundation';
import { listP2 } from './p2Operations';
import { listPurchaseOrders } from './procurementSourcing';

export type BusinessMetric = {
  key: string;
  label: string;
  value: number | string;
  note: string;
  screen: string;
  tone?: 'good' | 'warn' | 'bad';
};

export type BusinessDashboard = {
  metrics: BusinessMetric[];
  refreshed_at: string;
  unavailable: number;
};

type MetricLoader = { screen: string; load: () => Promise<BusinessMetric[]> };

export async function loadBusinessDashboard(allowedScreens: string[]): Promise<BusinessDashboard> {
  const allowed = new Set(allowedScreens);
  const loaders: MetricLoader[] = [
    { screen: 'BI-PROFIT', load: async () => {
      const result = await listP2('/api/v1/reports/profitability');
      return [
        metric('revenue', 'Sales value', money(result.summary?.revenue), 'Controlled order revenue', 'BI-PROFIT'),
        metric('margin', 'Gross margin', money(result.summary?.gross_margin), `${result.summary?.missing_cost_snapshots ?? 0} missing cost snapshots`, 'BI-PROFIT', Number(result.summary?.missing_cost_snapshots ?? 0) ? 'warn' : 'good'),
      ];
    } },
    { screen: 'FIN-AR', load: async () => {
      const result = await listP2('/api/v1/finance/receivables');
      return [metric('receivables', 'Receivables', money(result.summary?.outstanding_amount), `${money(result.summary?.overdue_amount)} overdue`, 'FIN-AR', Number(result.summary?.overdue_amount ?? 0) > 0 ? 'warn' : 'good')];
    } },
    { screen: 'INV-STK', load: async () => {
      const result = await listStock();
      return [metric('stock', 'Available stock', quantity(result.summary.available), `${result.summary.expiring_30_lots} lots expire within 30 days`, 'INV-STK', result.summary.expiring_30_lots ? 'warn' : 'good')];
    } },
    { screen: 'PUR-PO', load: async () => {
      const result = await listPurchaseOrders();
      return [metric('commitments', 'Purchase commitments', money(result.summary.committed_total), `${result.summary.issued} issued purchase orders`, 'PUR-PO')];
    } },
    { screen: 'CRM-ORDER', load: async () => {
      const result = await listP2('/api/v1/sales/orders');
      return [metric('orders', 'Sales orders', Number(result.summary?.total ?? 0), `${result.summary?.released ?? 0} released · ${result.summary?.draft ?? 0} draft`, 'CRM-ORDER')];
    } },
  ].filter((entry) => allowed.has(entry.screen));

  const settled = await Promise.allSettled(loaders.map((entry) => entry.load()));
  return {
    metrics: settled.flatMap((result) => result.status === 'fulfilled' ? result.value : []),
    refreshed_at: new Date().toISOString(),
    unavailable: settled.filter((result) => result.status === 'rejected').length,
  };
}

function metric(key: string, label: string, value: number | string, note: string, screen: string, tone?: BusinessMetric['tone']): BusinessMetric {
  return { key, label, value, note, screen, tone };
}

function money(value: unknown): string {
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(Number(value ?? 0));
}

function quantity(value: unknown): string {
  return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Number(value ?? 0));
}
