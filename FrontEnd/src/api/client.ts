export type ApiError = {
  code: string;
  message: string;
  request_id?: string;
  fields?: Record<string, string[]>;
};

export type ApiRequestInit = RequestInit & {
  idempotencyKey?: string;
  expectedVersion?: string | number;
  correlationId?: string;
};

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '');
let csrfToken: string | null = null;

function apiUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) return path;
  return `${apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`;
}

export async function apiRequest<T>(
  path: string,
  init: ApiRequestInit = {}
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body !== undefined && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  if (init.idempotencyKey) headers.set('Idempotency-Key', init.idempotencyKey);
  if (init.expectedVersion !== undefined) headers.set('If-Match', String(init.expectedVersion));
  if (init.correlationId) headers.set('X-Correlation-ID', init.correlationId);

  const response = await fetch(apiUrl(path), {
    ...init,
    headers,
    credentials: 'include',
  });

  if (!response.ok) {
    await throwResponseError(path, response);
  }

  if (response.status === 204) return undefined as T;

  return response.json() as Promise<T>;
}

export async function apiDownload(path: string, correlationId?: string): Promise<Blob> {
  const headers = new Headers({ Accept: 'application/octet-stream' });
  if (correlationId) headers.set('X-Correlation-ID', correlationId);

  const response = await fetch(apiUrl(path), {
    headers,
    credentials: 'include',
  });

  if (!response.ok) {
    await throwResponseError(path, response);
  }

  return response.blob();
}

export async function apiMutation<T>(
  path: string,
  body: unknown,
  init: Omit<ApiRequestInit, 'body'> = {}
): Promise<T> {
  const token = await ensureCsrfToken();

  try {
    return await requestMutation<T>(path, body, token, init);
  } catch (error) {
    if (isApiError(error) && error.code === 'HTTP_419') {
      csrfToken = null;
      return requestMutation<T>(path, body, await ensureCsrfToken(), init);
    }

    throw error;
  }
}

export function isApiError(error: unknown): error is ApiError {
  return typeof error === 'object'
    && error !== null
    && 'code' in error
    && 'message' in error;
}

export function resetApiClientAuthentication(): void {
  csrfToken = null;
}

async function requestMutation<T>(
  path: string,
  body: unknown,
  csrf: string,
  init: Omit<ApiRequestInit, 'body'>
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('X-CSRF-TOKEN', csrf);

  return apiRequest<T>(path, {
    ...init,
    method: init.method ?? 'POST',
    headers,
    body: body instanceof FormData ? body : JSON.stringify(body),
  });
}

async function ensureCsrfToken(): Promise<string> {
  if (csrfToken) return csrfToken;

  const response = await apiRequest<{ data: { csrf_token: string } }>('/api/v1/auth/csrf');
  csrfToken = response.data.csrf_token;

  return csrfToken;
}

async function throwResponseError(path: string, response: Response): Promise<never> {
  let payload: { error?: ApiError } | undefined;
  try {
    payload = await response.json();
  } catch {
    // ignore malformed error body
  }
  const error = payload?.error ?? {
    code: `HTTP_${response.status}`,
    message: 'Request failed.',
  };
  if (/\b(?:lines|items|allocations|entries)\.\d+\.[a-z0-9_]+\b/i.test(error.message)) {
    error.message = 'Check the highlighted field and correct the value before continuing.';
  }

  if (typeof window !== 'undefined' && path !== '/api/v1/me') {
    if (error.code === 'UNAUTHENTICATED') {
      window.dispatchEvent(new Event('erp:session-expired'));
    }
    if (error.code === 'CONTEXT_REQUIRED') {
      window.dispatchEvent(new Event('erp:context-required'));
    }
  }

  throw error;
}
