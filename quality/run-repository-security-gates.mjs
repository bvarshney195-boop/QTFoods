import { execFileSync, spawnSync } from 'node:child_process';
import { chmodSync, mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const resultsDirectory = resolve(repositoryRoot, 'quality-results');
const cacheDirectory = resolve(tmpdir(), 'qtfoods-trivy-cache-0.74.0');
const trivyImage = 'aquasec/trivy:0.74.0@sha256:62b1e65e8869bc4b4c6aa4fa2b21595256c7c2f6018a9d9ad61caf87187c1969';
const suffix = String(process.env.GITHUB_SHA || 'local').slice(0, 12).replace(/[^A-Za-z0-9_.-]/g, '-');
const releaseImages = [
  { target: 'app', image: `qtfoods-quality-app:${suffix}` },
  { target: 'web', image: `qtfoods-quality-web:${suffix}` },
  { target: 'recovery', image: `qtfoods-quality-recovery:${suffix}` },
];

for (const directory of [resultsDirectory, cacheDirectory]) {
  mkdirSync(directory, { recursive: true });
  try {
    chmodSync(directory, 0o777);
  } catch {
    // Docker Desktop bind mounts do not implement POSIX modes.
  }
}

function docker(args) {
  execFileSync('docker', args, { cwd: repositoryRoot, stdio: 'inherit' });
}

function trivyMounts(includeDockerSocket = false) {
  const args = [
    '--mount', `type=bind,source=${repositoryRoot},target=/workspace,readonly`,
    '--mount', `type=bind,source=${resultsDirectory},target=/reports`,
    '--mount', `type=bind,source=${cacheDirectory},target=/root/.cache/trivy`,
  ];
  if (includeDockerSocket) {
    args.push('--volume', '/var/run/docker.sock:/var/run/docker.sock');
  }
  return args;
}

function runTrivy(args, includeDockerSocket = false, allowGateFailure = false) {
  const command = [
    'run',
    '--rm',
    ...trivyMounts(includeDockerSocket),
    trivyImage,
    ...args,
  ];

  if (!allowGateFailure) {
    docker(command);
    return 0;
  }

  const result = spawnSync('docker', command, {
    cwd: repositoryRoot,
    stdio: 'inherit',
  });
  if (result.error) throw result.error;
  return result.status ?? 1;
}

const commonVulnerabilityOptions = [
  '--severity', 'HIGH,CRITICAL',
  '--ignore-unfixed',
  '--timeout', '15m',
  '--skip-db-update',
  '--skip-java-db-update',
  '--skip-check-update',
];
const filesystemOptions = [
  '--scanners', 'vuln,secret,misconfig',
  ...commonVulnerabilityOptions,
  '--skip-dirs', '/workspace/.git',
  '--skip-dirs', '/workspace/.idea',
  '--skip-dirs', '/workspace/BackEnd/vendor',
  '--skip-dirs', '/workspace/BackEnd/storage/logs',
  '--skip-dirs', '/workspace/FrontEnd/dist',
  '--skip-dirs', '/workspace/FrontEnd/node_modules',
  '--skip-dirs', '/workspace/quality-results',
];

function reportSummary(reportName, label) {
  const report = JSON.parse(readFileSync(resolve(resultsDirectory, reportName), 'utf8'));
  const totals = (report.Results || []).reduce((summary, result) => ({
    vulnerabilities: summary.vulnerabilities + (result.Vulnerabilities?.length || 0),
    misconfigurations: summary.misconfigurations + (result.Misconfigurations?.length || 0),
    secrets: summary.secrets + (result.Secrets?.length || 0),
  }), { vulnerabilities: 0, misconfigurations: 0, secrets: 0 });

  console.log(
    `${label}: ${totals.vulnerabilities} fixed High/Critical vulnerabilities, `
    + `${totals.misconfigurations} High/Critical misconfigurations, ${totals.secrets} secrets.`,
  );
}

runTrivy([
  'image',
  '--download-db-only',
  '--timeout', '15m',
]);

let gateFailed = runTrivy([
  'fs',
  ...filesystemOptions,
  '--exit-code', '1',
  '--format', 'json',
  '--output', '/reports/trivy-filesystem.json',
  '/workspace',
], false, true) !== 0;
reportSummary('trivy-filesystem.json', 'Repository filesystem');

for (const release of releaseImages) {
  docker([
    'build',
    '--pull',
    '--no-cache-filter', release.target,
    '--file', resolve(repositoryRoot, 'BackEnd/Dockerfile.production'),
    '--target', release.target,
    '--tag', release.image,
    resolve(repositoryRoot, 'BackEnd'),
  ]);

  const reportName = `trivy-image-${release.target}.json`;
  const status = runTrivy([
    'image',
    '--scanners', 'vuln',
    ...commonVulnerabilityOptions,
    '--exit-code', '1',
    '--format', 'json',
    '--output', `/reports/${reportName}`,
    release.image,
  ], true, true);
  reportSummary(reportName, `Production image (${release.target})`);
  gateFailed = gateFailed || status !== 0;
}

if (gateFailed) {
  throw new Error('Trivy found a fixed High/Critical vulnerability, secret, or repository misconfiguration.');
}

console.log('Repository and all three production image targets passed the Trivy High/Critical gates.');
