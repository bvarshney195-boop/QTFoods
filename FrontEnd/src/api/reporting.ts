import { apiDownload, apiMutation, apiRequest } from './client';

export type ReportParameterDefinition = {
  key: string;
  label: string;
  type: 'BOOLEAN';
  default: boolean;
};

export type ReportColumn = {
  key: string;
  label: string;
  type: 'TEXT' | 'DATE' | 'MONEY' | 'QUANTITY' | 'PERCENT';
};

export type ReportDefinition = {
  code: string;
  title: string;
  description: string;
  freshness_source: string;
  historical_cutoff: boolean;
  parameters: ReportParameterDefinition[];
  columns: ReportColumn[];
};

export type ReportExport = {
  id: string;
  format: 'CSV' | 'JSON';
  file_name: string;
  mime_type: string;
  row_count: number;
  size_bytes: number;
  sha256: string;
  created_at: string;
  created_by: { id: string; name: string };
};

export type ReportRow = {
  id: string;
  row_number: number;
  group_key: string | null;
  data: Record<string, string | number | boolean | null>;
};

export type ReportRun = {
  id: string;
  run_number: string;
  report_code: string;
  report_title: string;
  status: 'GENERATED';
  as_of_at: string;
  source_freshness_at: string;
  parameters: Record<string, boolean>;
  columns: ReportColumn[];
  row_count: number;
  totals: Record<string, string | number>;
  sha256: string;
  record_version: number;
  generated_at: string;
  created_at: string;
  created_by: { id: string; name: string };
  definition?: ReportDefinition;
  rows?: ReportRow[];
  exports?: ReportExport[];
  allowed_actions?: string[];
};

export type ReportingWorkspace = {
  data: ReportRun[];
  meta: { total: number };
  summary: {
    total_runs: number;
    rows_snapshotted: number;
    exports_created: number;
    latest_freshness_at: string | null;
  };
  definitions: ReportDefinition[];
  allowed_actions: string[];
};

export type ReportCommandResult = {
  entity_type: string;
  id: string;
  status: string;
  record_version: number;
  report_code?: string;
  run_number?: string;
  report_run_id?: string;
  format?: 'CSV' | 'JSON';
  file_name?: string;
  row_count: number;
  size_bytes?: number;
  sha256: string;
};

const root = '/api/v1/reports';

export function listReportRuns(filters: { q?: string; report_code?: string } = {}) {
  return apiRequest<ReportingWorkspace>(withQuery(root, filters));
}

export async function getReportRun(id: string) {
  return (await apiRequest<{ data: ReportRun }>(`${root}/runs/${id}`)).data;
}

export async function generateReport(
  body: { run_number: string; report_code: string; as_of_date: string; parameters: Record<string, boolean> },
  key: string,
) {
  return (await apiMutation<{ data: ReportCommandResult }>(`${root}/runs`, body, { idempotencyKey: key })).data;
}

export async function createReportExport(id: string, format: 'CSV' | 'JSON', key: string) {
  return (await apiMutation<{ data: ReportCommandResult }>(`${root}/runs/${id}/exports`, { format }, {
    idempotencyKey: key,
  })).data;
}

export function downloadReportExport(id: string) {
  return apiDownload(`${root}/exports/${id}/download`);
}

function withQuery(path: string, filters: Record<string, string | undefined>) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value) query.set(key, value); });
  return query.size ? `${path}?${query}` : path;
}
