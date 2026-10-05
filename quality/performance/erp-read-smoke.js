import { check, fail } from 'k6';
import exec from 'k6/execution';
import http from 'k6/http';

const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:18000').replace(/\/$/, '');

const readEndpoints = [
  { name: 'GET /api/v1/me', path: '/api/v1/me' },
  { name: 'GET /api/v1/work/tasks', path: '/api/v1/work/tasks?per_page=10' },
  { name: 'GET /api/v1/admin/organisation', path: '/api/v1/admin/organisation' },
];

export const options = {
  noCookiesReset: true,
  scenarios: {
    authenticated_read_smoke: {
      executor: 'constant-arrival-rate',
      exec: 'authenticatedReadSmoke',
      rate: 10,
      timeUnit: '1s',
      duration: '30s',
      preAllocatedVUs: 4,
      maxVUs: 20,
      gracefulStop: '5s',
    },
  },
  thresholds: {
    checks: ['rate>0.99'],
    dropped_iterations: ['count==0'],
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<750', 'p(99)<1500'],
    'http_req_duration{name:GET /api/v1/me}': ['p(95)<500'],
    'http_req_duration{name:GET /api/v1/work/tasks}': ['p(95)<1000'],
    'http_req_duration{name:GET /api/v1/admin/organisation}': ['p(95)<1000'],
  },
};

export function setup() {
  const csrfResponse = http.get(`${BASE_URL}/api/v1/auth/csrf`, {
    headers: { Accept: 'application/json' },
    tags: { name: 'GET /api/v1/auth/csrf [setup]' },
  });
  requireStatus(csrfResponse, 200, 'CSRF bootstrap');
  const csrfToken = csrfResponse.json('data.csrf_token');
  if (typeof csrfToken !== 'string' || !csrfToken) {
    fail('CSRF bootstrap did not return a token.');
  }

  const mutationParams = {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
  };
  const loginResponse = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({
      email: 'admin.user@qtfoods.local',
      method: 'password',
      password: 'prototype',
    }),
    { ...mutationParams, tags: { name: 'POST /api/v1/auth/login [setup]' } },
  );
  requireOneOfStatuses(loginResponse, [200, 202], 'administrator primary authentication');

  let authenticatedPayload = loginResponse.json('data');
  if (!Array.isArray(authenticatedPayload?.contexts)) {
    if (authenticatedPayload?.phase !== 'SELECT_SECOND_FACTOR'
      || typeof authenticatedPayload?.challenge_id !== 'string') {
      fail('Administrator login did not return the required MFA challenge.');
    }

    const emailChallengeResponse = http.post(
      `${BASE_URL}/api/v1/auth/challenge`,
      JSON.stringify({
        challenge_id: authenticatedPayload.challenge_id,
        action: 'select_email_otp',
      }),
      { ...mutationParams, tags: { name: 'POST /api/v1/auth/challenge [select email OTP]' } },
    );
    requireStatus(emailChallengeResponse, 202, 'email OTP second-factor selection');
    const emailChallenge = emailChallengeResponse.json('data');
    const previewCode = emailChallenge?.delivery?.preview_code;
    if (emailChallenge?.phase !== 'EMAIL_OTP_SECOND'
      || typeof emailChallenge?.challenge_id !== 'string'
      || typeof previewCode !== 'string'
      || !/^\d{6}$/.test(previewCode)) {
      fail('Disposable MFA bootstrap did not return a valid email OTP preview.');
    }

    const verificationResponse = http.post(
      `${BASE_URL}/api/v1/auth/challenge`,
      JSON.stringify({
        challenge_id: emailChallenge.challenge_id,
        action: 'verify_email_otp',
        code: previewCode,
      }),
      { ...mutationParams, tags: { name: 'POST /api/v1/auth/challenge [verify email OTP]' } },
    );
    requireStatus(verificationResponse, 200, 'administrator email OTP verification');
    authenticatedPayload = verificationResponse.json('data');
  }

  const contexts = authenticatedPayload?.contexts;
  if (!Array.isArray(contexts) || contexts.length === 0) {
    fail('MFA-authenticated administrator returned no authorised contexts.');
  }

  // Laravel rotates the session (and therefore its CSRF token) after login.
  const authenticatedCsrfResponse = http.get(`${BASE_URL}/api/v1/auth/csrf`, {
    headers: { Accept: 'application/json' },
    tags: { name: 'GET /api/v1/auth/csrf [authenticated setup]' },
  });
  requireStatus(authenticatedCsrfResponse, 200, 'authenticated CSRF bootstrap');
  const authenticatedCsrfToken = authenticatedCsrfResponse.json('data.csrf_token');
  if (typeof authenticatedCsrfToken !== 'string' || !authenticatedCsrfToken) {
    fail('Authenticated CSRF bootstrap did not return a token.');
  }

  const selected = contexts.find((context) => context.plant_name === 'Training Plant') || contexts[0];
  const contextResponse = http.post(
    `${BASE_URL}/api/v1/contexts/select`,
    JSON.stringify({ company_id: selected.company_id, plant_id: selected.plant_id }),
    {
      headers: {
        ...mutationParams.headers,
        'X-CSRF-TOKEN': authenticatedCsrfToken,
      },
      tags: { name: 'POST /api/v1/contexts/select [setup]' },
    },
  );
  requireStatus(contextResponse, 200, 'context selection');

  return {
    cookies: http.cookieJar().cookiesForURL(BASE_URL),
  };
}

export function authenticatedReadSmoke(data) {
  const jar = http.cookieJar();
  for (const [name, values] of Object.entries(data.cookies)) {
    if (Array.isArray(values) && values.length > 0) {
      jar.set(BASE_URL, name, values[0]);
    }
  }

  const endpoint = readEndpoints[exec.scenario.iterationInTest % readEndpoints.length];
  const response = http.get(`${BASE_URL}${endpoint.path}`, {
    headers: { Accept: 'application/json' },
    tags: { name: endpoint.name },
  });

  check(response, {
    [`${endpoint.name} returns 200`]: (candidate) => candidate.status === 200,
    [`${endpoint.name} returns JSON`]: (candidate) =>
      String(candidate.headers['Content-Type'] || '').toLowerCase().includes('application/json'),
  });
}

function requireStatus(response, expected, step) {
  if (response.status !== expected) {
    fail(`${step} returned HTTP ${response.status}; expected ${expected}.`);
  }
}

function requireOneOfStatuses(response, expected, step) {
  if (!expected.includes(response.status)) {
    fail(`${step} returned HTTP ${response.status}; expected one of ${expected.join(', ')}.`);
  }
}
