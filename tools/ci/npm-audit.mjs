// The JavaScript packages' audit for CI (D65, D85): like `npm audit --audit-level=high`, except that an
// advisory listed in npm-audit-allow.json, each with its reason, is reported but does not fail the check.
// Every other high or critical advisory fails it, and an allowed advisory that no longer applies is named,
// so its entry can be removed.
//
//   node ../../tools/ci/npm-audit.mjs     (from apps/shop-server, which holds npm-audit-allow.json)
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const allowed = JSON.parse(readFileSync(join(process.cwd(), 'npm-audit-allow.json'), 'utf8'));

let report;
try {
    report = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
} catch (error) {
    // npm audit exits non-zero when it finds anything; its report is still on stdout.
    report = error.stdout;
    if (!report) throw error;
}
const vulnerabilities = JSON.parse(report).vulnerabilities ?? {};

const failing = new Map();
const seen = new Set();
for (const [name, entry] of Object.entries(vulnerabilities)) {
    for (const via of entry.via) {
        // A string names another vulnerable package this one depends on; that package is listed itself.
        if (typeof via === 'string' || !['high', 'critical'].includes(via.severity)) continue;
        const id = String(via.url ?? '').split('/').pop() || `${name}: ${via.title}`;
        if (id in allowed) {
            seen.add(id);
        } else {
            failing.set(id, `${via.severity}: ${via.name} ${via.range}, ${via.title} (${via.url})`);
        }
    }
}

for (const id of seen) console.log(`Allowed, with its reason in npm-audit-allow.json: ${id}`);
for (const id of Object.keys(allowed)) {
    if (!seen.has(id)) console.log(`No longer found, so remove it from npm-audit-allow.json: ${id}`);
}
if (failing.size > 0) {
    for (const line of failing.values()) console.error(line);
    console.error(`${failing.size} high or critical advisories in the JavaScript packages.`);
    process.exit(1);
}
console.log('No high or critical advisories, beyond the allowed ones.');
