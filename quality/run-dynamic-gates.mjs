import { execFileSync } from 'node:child_process';
import {
  chmodSync,
  mkdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const composeFile = resolve(repositoryRoot, 'BackEnd/docker-compose.e2e.yml');
const resultsDirectory = resolve(repositoryRoot, 'quality-results');
const authConfigPath = resolve(resultsDirectory, '.zap-auth.prop');
const diagnosticsPath = resolve(resultsDirectory, 'stack-diagnostics.txt');
const projectName = 'qtfoods-erp-quality';
const healthUrl = 'http://127.0.0.1:18000/api/health';
const apiUrl = 'http://127.0.0.1:18000';

function compose(args, capture = false) {
  return execFileSync(
    'docker',
    ['compose', '--project-name', projectName, '--file', composeFile, ...args],
    {
      cwd: repositoryRoot,
      encoding: 'utf8',
      stdio: capture ? 'pipe' : 'inherit',
    },
  ) || '';
}

function stopStack() {
  compose(['down', '--volumes', '--remove-orphans', '--timeout', '10']);
}

function prepareResults() {
  mkdirSync(resultsDirectory, { recursive: true });
  try {
    chmodSync(resultsDirectory, 0o777);
  } catch {
    // Docker Desktop bind mounts do not implement POSIX modes.
  }

  for (const name of [
    '.zap-auth.prop',
    'k6-summary.json',
    'zap-report.html',
    'zap-report.json',
    'zap-report.md',
    'stack-diagnostics.txt',
  ]) {
    rmSync(resolve(resultsDirectory, name), { force: true });
  }
}

async function waitForHealth() {
  const deadline = Date.now() + 180_000;
  let lastError = 'service did not respond';

  while (Date.now() < deadline) {
    try {
      const response = await fetch(healthUrl);
      if (response.ok) {
        const body = await response.json();
        if (body.status === 'ok') return;
      }
      lastError = `HTTP ${response.status}`;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }

    await new Promise((resolveDelay) => setTimeout(resolveDelay, 1_000));
  }

  throw new Error(`Quality backend did not become healthy: ${lastError}`);
}

async function createAuthenticatedZapConfig() {
  const cookies = new Map();

  async function requestJson(path, init = {}) {
    const headers = new Headers(init.headers || {});
    headers.set('Accept', 'application/json');
    if (cookies.size > 0) {
      headers.set('Cookie', [...cookies].map(([name, value]) => `${name}=${value}`).join('; '));
    }

    const response = await fetch(`${apiUrl}${path}`, { ...init, headers });
    if (typeof response.headers.getSetCookie !== 'function') {
      throw new Error('The dynamic gate requires Node.js 20 or newer for safe Set-Cookie handling.');
    }
    for (const setCookie of response.headers.getSetCookie()) {
      const pair = setCookie.slice(0, setCookie.indexOf(';'));
      const separator = pair.indexOf('=');
      if (separator > 0) {
        cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
      }
    }

    const text = await response.text();
    if (!response.ok) {
      throw new Error(`${init.method || 'GET'} ${path} returned HTTP ${response.status}.`);
    }
    return text ? JSON.parse(text) : {};
  }

  const csrfPayload = await requestJson('/api/v1/auth/csrf');
  const csrfToken = csrfPayload?.data?.csrf_token;
  if (typeof csrfToken !== 'string' || !csrfToken) {
    throw new Error('CSRF bootstrap did not return a token.');
  }

  const mutationHeaders = {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken,
  };
  const loginPayload = await requestJson('/api/v1/auth/login', {
    method: 'POST',
    headers: mutationHeaders,
    body: JSON.stringify({
      email: 'admin.user@qtfoods.local',
      method: 'password',
      password: 'prototype',
    }),
  });
  let authenticatedPayload = loginPayload?.data;
  if (!Array.isArray(authenticatedPayload?.contexts)) {
    if (authenticatedPayload?.phase !== 'SELECT_SECOND_FACTOR'
      || typeof authenticatedPayload?.challenge_id !== 'string') {
      throw new Error('Administrator login did not return the required MFA challenge.');
    }

    const emailChallengePayload = await requestJson('/api/v1/auth/challenge', {
      method: 'POST',
      headers: mutationHeaders,
      body: JSON.stringify({
        challenge_id: authenticatedPayload.challenge_id,
        action: 'select_email_otp',
      }),
    });
    const emailChallenge = emailChallengePayload?.data;
    const previewCode = emailChallenge?.delivery?.preview_code;
    if (emailChallenge?.phase !== 'EMAIL_OTP_SECOND'
      || typeof emailChallenge?.challenge_id !== 'string'
      || typeof previewCode !== 'string'
      || !/^\d{6}$/.test(previewCode)) {
      throw new Error('Disposable MFA bootstrap did not return a valid email OTP preview.');
    }

    const verifiedPayload = await requestJson('/api/v1/auth/challenge', {
      method: 'POST',
      headers: mutationHeaders,
      body: JSON.stringify({
        challenge_id: emailChallenge.challenge_id,
        action: 'verify_email_otp',
        code: previewCode,
      }),
    });
    authenticatedPayload = verifiedPayload?.data;
  }

  const contexts = authenticatedPayload?.contexts;
  if (!Array.isArray(contexts) || contexts.length === 0) {
    throw new Error('MFA-authenticated administrator returned no authorised contexts.');
  }

  // Successful login rotates Laravel's session and invalidates the anonymous token.
  const authenticatedCsrfPayload = await requestJson('/api/v1/auth/csrf');
  const authenticatedCsrfToken = authenticatedCsrfPayload?.data?.csrf_token;
  if (typeof authenticatedCsrfToken !== 'string' || !authenticatedCsrfToken) {
    throw new Error('Authenticated CSRF bootstrap did not return a token.');
  }

  const context = contexts.find((candidate) => candidate.plant_name === 'Training Plant') || contexts[0];
  await requestJson('/api/v1/contexts/select', {
    method: 'POST',
    headers: {
      ...mutationHeaders,
      'X-CSRF-TOKEN': authenticatedCsrfToken,
    },
    body: JSON.stringify({ company_id: context.company_id, plant_id: context.plant_id }),
  });

  const sessionCookie = [...cookies].map(([name, value]) => `${name}=${value}`).join('; ');
  if (!sessionCookie.includes('qt_foods_erp_session=')) {
    throw new Error('Authenticated bootstrap did not issue the ERP session cookie.');
  }

  const propertyValue = (value) => String(value).replaceAll('\\', '\\\\').replace(/[\r\n]/g, '');
  const config = [
    'replacer.full_list(0).description=authenticated ERP session',
    'replacer.full_list(0).enabled=true',
    'replacer.full_list(0).matchtype=REQ_HEADER',
    'replacer.full_list(0).matchstr=Cookie',
    'replacer.full_list(0).regex=false',
    `replacer.full_list(0).replacement=${propertyValue(sessionCookie)}`,
    'replacer.full_list(1).description=ERP CSRF token',
    'replacer.full_list(1).enabled=true',
    'replacer.full_list(1).matchtype=REQ_HEADER',
    'replacer.full_list(1).matchstr=X-CSRF-TOKEN',
    'replacer.full_list(1).regex=false',
    `replacer.full_list(1).replacement=${propertyValue(authenticatedCsrfToken)}`,
    '',
  ].join('\n');

  writeFileSync(authConfigPath, config, { encoding: 'utf8', mode: 0o644 });
}

function captureCompose(args, label) {
  try {
    return compose(args, true).trimEnd();
  } catch (error) {
    const output = [error?.stdout, error?.stderr]
      .filter((value) => typeof value === 'string' && value.length > 0)
      .join('\n')
      .trimEnd();
    const reason = error instanceof Error ? error.message : String(error);
    return [`Unable to collect ${label}: ${reason}`, output].filter(Boolean).join('\n');
  }
}

function saveFailureDiagnostics() {
  const diagnostics = [
    `Captured: ${new Date().toISOString()}`,
    '',
    '$ docker compose ps -a',
    captureCompose(['ps', '-a'], 'dynamic-quality Compose service state'),
    '',
    '$ docker compose logs --no-color --tail 500',
    captureCompose(['logs', '--no-color', '--tail', '500'], 'dynamic-quality Compose service logs'),
    '',
  ].join('\n');

  writeFileSync(diagnosticsPath, diagnostics, 'utf8');
  process.stderr.write(`Dynamic-quality stack diagnostics saved to ${diagnosticsPath}.\n`);
}

async function main() {
  prepareResults();
  let failed = false;

  try {
    stopStack();
    compose([
      'up',
      '--detach',
      '--force-recreate',
      '--remove-orphans',
      '--wait',
      '--wait-timeout',
      '120',
      'postgres',
      'redis',
    ]);
    compose(['up', '--detach', '--build', '--force-recreate', '--no-deps', 'migrate']);
    compose(['wait', 'migrate']);
    compose([
      'up',
      '--detach',
      '--build',
      '--force-recreate',
      '--no-deps',
      'app',
      'worker',
      'scheduler',
    ]);
    await waitForHealth();

    compose(['--profile', 'quality', 'run', '--rm', '--no-deps', 'k6']);
    await createAuthenticatedZapConfig();
    try {
      compose(['--profile', 'quality', 'run', '--rm', '--no-deps', 'zap']);
    } finally {
      rmSync(authConfigPath, { force: true });
    }

    execFileSync(
      process.execPath,
      [
        resolve(repositoryRoot, 'quality/security/check-zap-report.mjs'),
        resolve(resultsDirectory, 'zap-report.json'),
        resolve(repositoryRoot, 'quality/security/zap-policy.json'),
      ],
      { cwd: repositoryRoot, stdio: 'inherit' },
    );
  } catch (error) {
    failed = true;
    saveFailureDiagnostics();
    throw error;
  } finally {
    rmSync(authConfigPath, { force: true });
    try {
      stopStack();
    } catch (error) {
      if (!failed) throw error;
    }
  }
}

main().catch((error) => {
  const message = error instanceof Error ? error.message : String(error);
  console.error(`Dynamic quality gates failed: ${message}`);
  process.exitCode = 1;
});
