import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const exceptions = new Map([
  ['https://github.com/advisories/GHSA-fc5q-39xc-vjpf', {
    package: 'picomatch',
    lockPath: 'node_modules/picomatch',
    expires: '2026-12-18',
    reason: 'No patched picomatch release exists; the dependency is confined to non-deployed build and test tooling.',
  }],
]);

const npmArguments = ['audit', '--audit-level=high', '--json'];
const npmCommand = process.env.npm_execpath
  ? { executable: process.execPath, arguments: [process.env.npm_execpath, ...npmArguments] }
  : { executable: 'npm', arguments: npmArguments };
const audit = spawnSync(npmCommand.executable, npmCommand.arguments, {
  cwd: process.cwd(),
  encoding: 'utf8',
  maxBuffer: 10 * 1024 * 1024,
});

if (audit.error) {
  console.error(`Unable to run npm audit: ${audit.error.message}`);
  process.exit(1);
}

let report;
try {
  report = JSON.parse(audit.stdout);
} catch {
  console.error('npm audit did not return valid JSON.');
  if (audit.stderr) console.error(audit.stderr.trim());
  process.exit(1);
}

if (report.error) {
  console.error(`npm audit failed: ${report.error.summary ?? report.error.message ?? 'unknown registry error'}`);
  process.exit(1);
}

const vulnerabilitiesByName = report.vulnerabilities ?? {};
const vulnerabilities = Object.values(vulnerabilitiesByName)
  .filter((finding) => ['high', 'critical'].includes(finding.severity));
const advisoryFindings = vulnerabilities.flatMap((finding) =>
  (finding.via ?? []).filter((via) => typeof via === 'object' && via.url)
    .map((via) => ({ ...via, package: finding.name })),
);

function advisoryUrls(name, visited = new Set()) {
  if (visited.has(name) || !vulnerabilitiesByName[name]) return [];
  const nextVisited = new Set(visited).add(name);
  return (vulnerabilitiesByName[name].via ?? []).flatMap((via) =>
    typeof via === 'string' ? advisoryUrls(via, nextVisited) : (via.url ? [via.url] : []),
  );
}

const unresolved = vulnerabilities.filter((finding) => advisoryUrls(finding.name).length === 0);
if (unresolved.length > 0) {
  console.error(`Unable to resolve advisory chains for: ${unresolved.map((finding) => finding.name).join(', ')}`);
  process.exit(1);
}

const reportedUrls = new Set(vulnerabilities.flatMap((finding) => advisoryUrls(finding.name)));
const unexpected = [...reportedUrls].filter((url) => !exceptions.has(url));

if (unexpected.length > 0) {
  console.error('Unexcepted npm security advisories:');
  for (const url of unexpected) console.error(`- ${url}`);
  process.exit(1);
}

if (vulnerabilities.length === 0) {
  console.log('No npm security vulnerability advisories found.');
  process.exit(0);
}

const lockPath = fileURLToPath(new URL('../FrontEnd/package-lock.json', import.meta.url));
const lock = JSON.parse(readFileSync(lockPath, 'utf8'));
const today = new Date().toISOString().slice(0, 10);

for (const finding of vulnerabilities) {
  for (const node of finding.nodes ?? []) {
    if (!lock.packages?.[node]?.dev) {
      console.error(`Security finding ${finding.name} reaches non-development dependency ${node}.`);
      process.exit(1);
    }
  }
}

for (const finding of advisoryFindings) {
  const exception = exceptions.get(finding.url);
  const lockedPackage = lock.packages?.[exception.lockPath];
  if (finding.package !== exception.package || !lockedPackage?.dev) {
    console.error(`Security exception ${finding.url} is not confined to the expected development-only package.`);
    process.exit(1);
  }
  if (today > exception.expires) {
    console.error(`Security exception ${finding.url} expired on ${exception.expires}.`);
    process.exit(1);
  }
  console.warn(`Temporary security exception: ${finding.url}`);
  console.warn(`Package: ${exception.package}@${lockedPackage.version}; expires: ${exception.expires}`);
  console.warn(exception.reason);
}

console.log('No unexcepted high or critical npm security advisories found.');
