import { execFileSync } from 'node:child_process';
import { chmodSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const resultsDirectory = resolve(repositoryRoot, 'quality-results');
const reportPath = resolve(resultsDirectory, 'semgrep-php.json');
const semgrepImage = 'semgrep/semgrep:1.172.0@sha256:65dcd4408adda7c183a6b4550cb1e9b19f7f627a6fbb7e0559bd466bedc44d7b';

mkdirSync(resultsDirectory, { recursive: true });
try {
  chmodSync(resultsDirectory, 0o777);
} catch {
  // Docker Desktop bind mounts do not implement POSIX modes.
}

execFileSync('docker', [
  'run',
  '--rm',
  '--mount', `type=bind,source=${repositoryRoot},target=/workspace,readonly`,
  '--mount', `type=bind,source=${resultsDirectory},target=/reports`,
  '--workdir', '/workspace',
  semgrepImage,
  'semgrep',
  'scan',
  '--config', 'p/php',
  '--config', 'p/security-audit',
  '--severity', 'ERROR',
  '--metrics', 'off',
  '--disable-version-check',
  '--error',
  '--json',
  '--output', '/reports/semgrep-php.json',
  '--exclude', 'BackEnd/vendor',
  '--exclude', 'BackEnd/storage',
  '--exclude', 'BackEnd/bootstrap/cache',
  'BackEnd/app',
  'BackEnd/routes',
  'BackEnd/config',
  'BackEnd/database',
], { cwd: repositoryRoot, stdio: 'inherit' });

const report = JSON.parse(readFileSync(reportPath, 'utf8'));
const findings = report.results ?? [];
const errors = report.errors ?? [];
const scanned = report.paths?.scanned?.length ?? 0;

if (errors.length > 0) {
  throw new Error(`Semgrep reported ${errors.length} scan error(s); PHP SAST evidence is incomplete.`);
}

console.log(`PHP SAST: ${scanned} files scanned, ${findings.length} blocking finding(s), 0 scan errors.`);
