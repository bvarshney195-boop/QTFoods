import { apiMutation, apiRequest } from './client';

export type HelpArticle = {
  id: string;
  slug: string;
  category: string;
  related_screen_code: string | null;
  title: string;
  summary: string;
  content_version: number;
  published_at: string;
  updated_at: string;
  sections?: Array<{ heading: string; content: string }>;
};

export type SupportCaseEvent = {
  id: string;
  sequence_number: number;
  event_type: string;
  status_from: string | null;
  status_to: string | null;
  message: string;
  actor: { id: string; name: string };
  created_at: string;
};

export type HelpSupportCase = {
  id: string;
  case_number: string;
  category: string;
  priority: string;
  affected_screen_code: string | null;
  subject: string;
  description: string;
  status: string;
  record_version: number;
  requester: { id: string; name: string };
  assigned_to: { id: string; name: string } | null;
  resolution_summary: string | null;
  resolved_at: string | null;
  resolved_by?: { id: string; name: string } | null;
  closed_at: string | null;
  closed_by?: { id: string; name: string } | null;
  created_at: string;
  updated_at: string;
  allowed_actions: string[];
  events?: SupportCaseEvent[];
};

export type HelpWorkspace = {
  articles: HelpArticle[];
  cases: HelpSupportCase[];
  summary: {
    published_articles: number;
    visible_cases: number;
    open_cases: number;
    critical_open_cases: number;
  };
  lookups: {
    case_categories: string[];
    priorities: string[];
    statuses: string[];
    article_categories: string[];
    screen_codes: string[];
  };
  allowed_actions: string[];
  is_support_manager: boolean;
};

export type HelpCaseCommandResult = {
  entity_type: string;
  id: string;
  case_number: string;
  status: string;
  record_version: number;
};

const root = '/api/v1/admin/help';

export function getHelpWorkspace(filters: { q?: string; article_category?: string; case_status?: string } = {}) {
  return apiRequest<HelpWorkspace>(withQuery(root, filters));
}

export async function getHelpArticle(slug: string) {
  return (await apiRequest<{ data: HelpArticle }>(`${root}/articles/${slug}`)).data;
}

export async function getHelpCase(id: string) {
  return (await apiRequest<{ data: HelpSupportCase }>(`${root}/cases/${id}`)).data;
}

export async function createHelpCase(body: {
  category: string;
  priority: string;
  affected_screen_code: string | null;
  subject: string;
  description: string;
}, key: string) {
  return (await apiMutation<{ data: HelpCaseCommandResult }>(`${root}/cases`, body, { idempotencyKey: key })).data;
}

export async function runHelpCaseCommand(
  record: Pick<HelpSupportCase, 'id' | 'record_version'>,
  action: 'comments' | 'start' | 'resolve' | 'reopen' | 'close',
  body: Record<string, string>,
  key: string,
) {
  return (await apiMutation<{ data: HelpCaseCommandResult }>(`${root}/cases/${record.id}/${action}`, body, {
    idempotencyKey: key,
    expectedVersion: record.record_version,
  })).data;
}

function withQuery(path: string, filters: Record<string, string | undefined>) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value) query.set(key, value); });
  return query.size ? `${path}?${query}` : path;
}
