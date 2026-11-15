#!/usr/bin/env node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../resources/js/', import.meta.url));
const patterns = {
    input: /<input\b/g,
    button: /<button\b/g,
    select: /<select\b/g,
    textarea: /<textarea\b/g,
    dialog: /<dialog\b|role="dialog"/g,
    table: /<table\b/g,
};

function walk(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return walk(path);
        return path.endsWith('.vue') ? [path] : [];
    });
}

function fail(message) {
    console.error(`ui:inventory: ${message}`);
    process.exit(2);
}

const args = process.argv.slice(2);
const unknown = args.filter((arg) => !arg.startsWith('--file=') && arg !== '--strict');
if (unknown.length) fail(`unknown argument(s): ${unknown.join(' ')} (use --file=<path> and/or --strict)`);

const files = walk(root).sort();
const fileArg = args.find((arg) => arg.startsWith('--file='))?.slice('--file='.length);
let target = null;
if (fileArg !== undefined) {
    // Fail closed: a mistyped path must never read as "0 remaining". Accept a path relative to
    // resources/js, to the repository root, or to the current directory.
    const wanted = fileArg.replaceAll('\\', '/');
    const candidates = [resolve(wanted), resolve(root, wanted), resolve(root, '../..', wanted)];
    target = files.find((file) => candidates.includes(file));
    if (!target) fail(`no .vue file under resources/js matches --file=${fileArg}`);
}

const rows = [];
for (const file of files) {
    const lines = readFileSync(file, 'utf8').split('\n');
    const counts = Object.fromEntries(Object.keys(patterns).map((key) => [key, 0]));
    lines.forEach((line, index) => {
        if (index > 0 && lines[index - 1].includes('native-ok')) return;
        for (const [key, pattern] of Object.entries(patterns)) {
            counts[key] += (line.match(pattern) || []).length;
        }
    });
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);
    if (total > 0) rows.push({ file: relative(root, file), ...counts, total });
}

const shown = target ? rows.filter((row) => row.file === relative(root, target)) : rows;
const remaining = shown.reduce((sum, row) => sum + row.total, 0);

if (shown.length) console.table(shown);
if (!target) {
    const byType = Object.keys(patterns).map((key) => `${key} ${shown.reduce((sum, row) => sum + row[key], 0)}`);
    console.log(`By type: ${byType.join(', ')}`);
}
console.log(`Remaining native elements${target ? ` in ${relative(root, target)}` : ''}: ${remaining}`);

if ((target || args.includes('--strict')) && remaining > 0) process.exitCode = 1;
