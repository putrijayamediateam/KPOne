import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    'resources/js/components/queue/QueueCallsToday.vue',
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'calls-today',
    inlineTemplate: true,
}).content;
const js = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const module = { exports: {} };
runInNewContext(js, { exports: module.exports, require });
const render = (branchName, stats) =>
    renderToString(
        createSSRApp(module.exports.default, {
            branchName,
            stats: {
                roomPagination: {
                    currentPage: 1,
                    lastPage: 1,
                    perPage: 25,
                    total: stats.byRoom.length,
                },
                ...stats,
            },
        }),
    );

test('calls summary renders room totals including recalls and distinguishes service recall counts', async () => {
    const html = await render('Synthetic branch', {
        operationalDate: '2026-11-26',
        total: 4,
        recalls: 1,
        byRoom: [
            { room: 'Room 1', calls: 3 },
            { room: 'No room', calls: 1 },
        ],
        byService: {
            consultation: { calls: 2, recalls: 1 },
            dispensary: { calls: 1, recalls: 0 },
        },
    });
    assert.match(html, /<details/);
    assert.match(html, /<summary/);
    assert.match(html, /Calls today.*?>4<\/span>/s);
    assert.match(html, /Synthetic branch · 2026-11-26/);
    assert.match(html, /Recalls included: 1/);
    assert.match(html, /Room 1<\/span>.*?>3 calls<\/span>/s);
    assert.match(html, /Consultation<\/span>.*?3 calls.*?Recalls included: 1/s);
    assert.match(html, /No room/);
    assert.doesNotMatch(html, /No calls recorded/);
});

test('empty branch summary contains no previous branch room or service rows', async () => {
    const html = await render('Empty branch', {
        operationalDate: '2026-11-27',
        total: 0,
        recalls: 0,
        byRoom: [],
        byService: {},
    });
    assert.match(html, /No calls recorded today at Empty branch/);
    assert.match(html, /2026-11-27/);
    assert.doesNotMatch(html, /By room|By service|Room 1/);
});

test('stats are wired to settings branch props and never rendered on the TV screen', () => {
    const settings = readFileSync(
        'resources/js/pages/QueueDisplay/Settings.vue',
        'utf8',
    );
    const screen = readFileSync(
        'resources/js/pages/QueueDisplay/Screen.vue',
        'utf8',
    );
    assert.match(settings, /callsToday: QueueCallStats/);
    assert.match(
        settings,
        /<QueueCallsToday\s+:key="branch.id"\s+:branch-name="branch.name"\s+:stats="callsToday"/,
    );
    assert.doesNotMatch(screen, /QueueCallsToday|callsToday/);
});
