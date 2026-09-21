import { apiDownload, apiMutation, apiRequest } from './client';

export type EnterpriseSearchResult = { type: string; id: string; title: string; code: string; subtitle: string; status: string; screen_code: string; href: string };
export type ServerSavedView = { id: string; screen_code: string; name: string; filters: Record<string, unknown> };
export type WorkspacePayload = { settings: Record<string, unknown>; saved_views: ServerSavedView[] };
export type NotificationPreferences = { urgent: boolean; high: boolean; overdue: boolean; include_completed: boolean };
export type NotificationItem = { id: string; title: string; description: string | null; priority: string; status: string; is_overdue: boolean; read_at: string | null; assignee_name: string | null; target: { screen_code: string; href: string } | null; created_at: string };
export type NotificationPayload = { data: NotificationItem[]; summary: { unread: number; total: number }; preferences: NotificationPreferences };
export type AnalyticsMetric = { code: string; label: string; current: number; previous: number; change_percent: number | null; target: number | null; format: 'currency' | 'number' };
export type AnalyticsPayload = { period: string; range: { from: string; to: string }; metrics: AnalyticsMetric[] };
export type ImportPreview = { id: string; status: 'VALID' | 'INVALID'; row_count: number; error_count: number; preview: Record<string, string>[]; errors: Array<{ row: number; field: string; message: string; value: string | null }> };

export const enterpriseSearch = async (q: string) => (await apiRequest<{ data: EnterpriseSearchResult[] }>(`/api/v1/experience/search?q=${encodeURIComponent(q)}`)).data;
export const getWorkspace = () => apiRequest<WorkspacePayload>('/api/v1/experience/workspace');
export const saveWorkspaceSetting = (key: string, value: unknown) => apiMutation('/api/v1/experience/workspace/settings', { key, value }, { method: 'PUT' });
export const createSavedView = async (screen_code: string, name: string, filters: Record<string, unknown>) => (await apiMutation<{ data: ServerSavedView }>('/api/v1/experience/workspace/views', { screen_code, name, filters })).data;
export const deleteSavedView = (id: string) => apiMutation(`/api/v1/experience/workspace/views/${id}`, undefined, { method: 'DELETE' });
export const getNotifications = () => apiRequest<NotificationPayload>('/api/v1/experience/notifications');
export const readNotification = (id: string) => apiMutation(`/api/v1/experience/notifications/${id}/read`, {});
export const dismissNotification = (id: string) => apiMutation(`/api/v1/experience/notifications/${id}/dismiss`, {});
export const saveNotificationPreferences = (preferences: NotificationPreferences) => apiMutation('/api/v1/experience/notification-preferences', preferences, { method: 'PUT' });
export const getAnalytics = (period: string) => apiRequest<AnalyticsPayload>(`/api/v1/experience/analytics?period=${period}`);
export const saveAnalyticsTarget = (metric_code: string, target_value: number) => apiMutation('/api/v1/experience/analytics/targets', { metric_code, target_value }, { method: 'PUT' });
export const previewImport = async (entityType: 'PARTIES' | 'ITEMS', file: File) => { const body = new FormData(); body.append('entity_type', entityType); body.append('file', file); return (await apiMutation<{ data: ImportPreview }>('/api/v1/experience/imports/preview', body)).data; };
export const commitImport = (id: string) => apiMutation(`/api/v1/experience/imports/${id}/commit`, {});
export const rollbackImport = (id: string) => apiMutation(`/api/v1/experience/imports/${id}/rollback`, {});
export const downloadImportErrors = (id: string) => apiDownload(`/api/v1/experience/imports/${id}/errors`);
