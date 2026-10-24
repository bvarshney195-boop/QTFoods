import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const reportPath = resolve(process.argv[2] || 'quality-results/zap-report.json');
const policyPath = resolve(process.argv[3] || 'quality/security/zap-policy.json');
const report = JSON.parse(readFileSync(reportPath, 'utf8'));
const policy = JSON.parse(readFileSync(policyPath, 'utf8'));

if (!Number.isInteger(policy.failAtRiskCode) || policy.failAtRiskCode < 0) {
  throw new Error('ZAP policy failAtRiskCode must be a non-negative integer.');
}

const sites = Array.isArray(report.site) ? report.site : [];
if (sites.length === 0) {
  throw new Error('ZAP report contains no scanned sites.');
}

const alerts = sites.flatMap((site) => Array.isArray(site.alerts) ? site.alerts : []);
const failures = alerts.filter((alert) => Number(alert.riskcode) >= policy.failAtRiskCode);
const counts = new Map();

for (const alert of alerts) {
  const risk = String(alert.riskdesc || alert.risk || 'Unknown').split(' ')[0];
  counts.set(risk, (counts.get(risk) || 0) + 1);
}

const summary = [...counts.entries()]
  .sort(([left], [right]) => left.localeCompare(right))
  .map(([risk, count]) => `${risk}=${count}`)
  .join(', ') || 'no alerts';
console.log(`ZAP report policy: ${summary}; fail at ${policy.failAtRiskName} (risk code ${policy.failAtRiskCode}) or above.`);

if (failures.length > 0) {
  for (const alert of failures) {
    const instances = Array.isArray(alert.instances) ? alert.instances : [];
    const sample = instances[0]?.uri || 'unknown URI';
    console.error(`FAIL [${alert.pluginid || alert.pluginId || 'unknown'}] ${alert.name || alert.alert || 'Unnamed alert'} at ${sample}`);
  }
  process.exitCode = 1;
}
