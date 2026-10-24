import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectName = 'qtfoods-erp-e2e';
const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const composeFile = resolve(
  dirname(fileURLToPath(import.meta.url)),
  '../../../BackEnd/docker-compose.e2e.yml'
);
const diagnosticsFile = resolve(frontendRoot, 'test-results/e2e-stack-diagnostics.txt');

function compose(args: string[], capture = false): string {
  return execFileSync(
    'docker',
    ['compose', '--project-name', projectName, '--file', composeFile, ...args],
    {
      encoding: 'utf8',
      stdio: capture ? 'pipe' : 'inherit',
    }
  ) ?? '';
}

export function stopE2EStack(): void {
  compose(['down', '--volumes', '--remove-orphans']);
}

function captureCompose(args: string[], label: string): string {
  try {
    return compose(args, true).trimEnd();
  } catch (error) {
    const commandError = error as { stdout?: string; stderr?: string };
    const output = [commandError.stdout, commandError.stderr]
      .filter((value): value is string => typeof value === 'string' && value.length > 0)
      .join('\n')
      .trimEnd();
    const reason = error instanceof Error ? error.message : String(error);

    return [`Unable to collect ${label}: ${reason}`, output].filter(Boolean).join('\n');
  }
}

export function saveE2EStackDiagnostics(overwrite = true): void {
  if (!overwrite && existsSync(diagnosticsFile)) return;

  mkdirSync(dirname(diagnosticsFile), { recursive: true });
  const diagnostics = [
    `Captured: ${new Date().toISOString()}`,
    '',
    '$ docker compose ps -a',
    captureCompose(['ps', '-a'], 'E2E Compose service state'),
    '',
    '$ docker compose logs --no-color --tail 500',
    captureCompose(['logs', '--no-color', '--tail', '500'], 'E2E Compose service logs'),
    '',
  ].join('\n');

  writeFileSync(diagnosticsFile, diagnostics, 'utf8');
  process.stderr.write(`E2E stack diagnostics saved to ${diagnosticsFile}.\n`);
}

export async function startE2EStack(): Promise<void> {
  rmSync(diagnosticsFile, { force: true });

  try {
    stopE2EStack();
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
  } catch (error) {
    saveE2EStackDiagnostics();
    try {
      stopE2EStack();
    } finally {
      throw error;
    }
  }
}

async function waitForHealth(): Promise<void> {
  const deadline = Date.now() + 180_000;
  let lastError = 'service did not respond';

  while (Date.now() < deadline) {
    try {
      const response = await fetch('http://127.0.0.1:18000/api/health');
      if (response.ok) {
        const body = await response.json() as { status?: string };
        if (body.status === 'ok') return;
      }
      lastError = 'HTTP ' + response.status;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
    }

    await new Promise((resolveDelay) => setTimeout(resolveDelay, 1_000));
  }

  throw new Error('E2E backend did not become healthy: ' + lastError);
}
