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
const compile = (source, imports = require) => {
    const module = { exports: {} };
    const js = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    runInNewContext(js, {
        exports: module.exports,
        require: imports,
        AbortController,
    });

    return module.exports;
};
const harness = () => {
    const pending = [];
    const module = compile(
        readFileSync('resources/js/composables/useRoomsToday.ts', 'utf8'),
        (name) =>
            name === '@/lib/json-client'
                ? {
                      requestJson: (url, init) =>
                          new Promise((resolve, reject) =>
                              pending.push({ url, init, resolve, reject }),
                          ),
                  }
                : require(name),
    );

    return { model: module.useRoomsToday(), pending };
};

test('own-only viewers never fetch rooms and changing scope cancels outstanding branch data', async () => {
    const { model, pending } = harness();
    const signal = new AbortController().signal;
    await model.poll(1, 'own', signal);
    assert.equal(pending.length, 0);
    const branch = model.poll(1, 'branch', signal);
    await model.poll(1, 'own', signal);
    assert.equal(pending[0].init.signal.aborted, true);
    pending[0].resolve({ rows: [{ doctorName: 'Previous branch doctor' }] });
    await branch;
    assert.equal(model.stats.value, null);
});

test('branch replacement aborts old requests, sends expected branch and rejects stale results', async () => {
    const { model, pending } = harness();
    const signal = new AbortController().signal;
    const old = model.poll(1, 'branch', signal);
    const latest = model.poll(2, 'branch', signal);
    assert.equal(pending[1].url, '/queue/rooms-today?expected_branch_id=2');
    assert.equal(pending[0].init.signal.aborted, true);
    pending[1].resolve({ rows: [], operationalDate: '2026-11-26' });
    await latest;
    pending[0].resolve({ rows: [{ doctorName: 'Old' }] });
    await old;
    assert.equal(model.stats.value.rows.length, 0);
    const failed = model.poll(2, 'branch', signal);
    pending[2].reject(new Error('network'));
    await failed;
    assert.equal(model.stats.value, null);
    assert.equal(model.unavailable.value, true);
});

test('lifecycle invalidation prevents publication and board does not await optional rooms reads', async () => {
    const { model, pending } = harness();
    const current = model.poll(
        1,
        'branch',
        new AbortController().signal,
        () => false,
    );
    pending[0].resolve({ rows: [] });
    await current;
    assert.equal(model.stats.value, null);
    const board = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    assert.match(board, /void roomsToday.poll\(/);
    assert.doesNotMatch(board, /await roomsToday.poll/);
    assert.match(board, /<QueueRoomsToday\s+v-if="live.scope === 'branch'"/);
});

const component = compile(
    compileScript(
        parse(
            readFileSync(
                'resources/js/components/queue/QueueRoomsToday.vue',
                'utf8',
            ),
        ).descriptor,
        { id: 'rooms-today', inlineTemplate: true },
    ).content,
).default;
const render = (stats, unavailable = false) =>
    renderToString(createSSRApp(component, { stats, unavailable }));
const doctor = (overrides) => ({
    doctorId: 1,
    doctorName: 'Synthetic doctor',
    roomName: 'Room 1',
    roomSource: 'roster',
    state: 'serving',
    servingNumber: 'A-012',
    waiting: 4,
    notReady: 1,
    held: 2,
    heldCap: 3,
    ...overrides,
});
test('room cards render service states, room sources and structural counts only', async () => {
    const html = await render({
        hasRooms: true,
        operationalDate: '2026-11-26',
        rows: [
            doctor({}),
            doctor({
                doctorId: 2,
                state: 'busy_elsewhere',
                roomSource: 'chosen',
                servingNumber: null,
            }),
            doctor({
                doctorId: 3,
                state: 'free',
                roomName: null,
                roomSource: null,
            }),
        ],
    });
    assert.match(html, /A-012/);
    assert.match(html, /Busy at another branch/);
    assert.match(html, /Free/);
    assert.match(html, /From approved roster/);
    assert.match(html, /Chosen by doctor/);
    assert.match(html, /No room assigned/);
    assert.match(html, /Not ready \(included in waiting\)/);
    assert.match(html, /2 of 3/);
    assert.doesNotMatch(html, /<button|<a /);
});

test('room summary distinguishes loading, unavailable, no rooms and no eligible doctors', async () => {
    assert.match(await render(null), /Loading rooms/);
    assert.match(await render(null, true), /temporarily unavailable/);
    const empty = await render({
        hasRooms: false,
        operationalDate: '2026-11-26',
        rows: [],
    });
    assert.match(empty, /No consultation rooms are set up/);
    assert.match(empty, /No doctors are available/);
});
