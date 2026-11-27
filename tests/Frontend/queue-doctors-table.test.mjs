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
    'resources/js/components/queue/QueueRoomsToday.vue',
    'utf8',
);
const compile = (inlineTemplate = true) => {
    const module = { exports: {} };
    const code = compileScript(parse(source).descriptor, {
        id: 'doctors-table',
        inlineTemplate,
    }).content;
    runInNewContext(
        ts.transpileModule(code, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, require },
    );

    return module.exports.default;
};
const component = compile();
const doctor = (extra = {}) => ({
    doctorId: 1,
    doctorName: 'Synthetic doctor',
    roomName: 'Room one',
    roomSource: 'roster',
    state: 'serving',
    servingNumber: 'A-012',
    waiting: 4,
    notReady: 1,
    held: 2,
    heldCap: 3,
    ...extra,
});
const stats = (rows = [doctor()], extra = {}) => ({
    operationalDate: '2026-11-27',
    hasRooms: true,
    rows,
    pagination: {
        currentPage: 1,
        lastPage: 1,
        perPage: 25,
        total: rows.length,
    },
    ...extra,
});
const render = (data, props = {}) =>
    renderToString(
        createSSRApp(component, {
            stats: data,
            unavailable: false,
            presentation: 'table',
            ...props,
        }),
    );

test('actual Doctors table uses canonical columns and preserves complete count meanings while default cards remain unchanged', async () => {
    const table = await render(stats());
    assert.deepEqual(
        [...table.matchAll(/<th scope="col"[^>]*>(.*?)<\/th>/g)].map((m) =>
            m[1].trim(),
        ),
        ['Doctor', 'Room', 'Now', 'Waiting for them'],
    );
    assert.match(table, /Serving[\s\S]*?A-012/);
    assert.match(table, /Held consultations:[\s\S]*?2 of 3/);
    assert.match(table, /1<\/span> not ready \(included in waiting\)/);
    assert.match(table, />4<\/p>/);
    const cards = await render(stats(), { presentation: undefined });
    assert.doesNotMatch(cards, /<table/);
    assert.match(cards, /<ul/);
    assert.match(cards, /Not ready \(included in waiting\)/);
});

test('Now uses only explicit service state and never treats unknown or held counts as availability', async () => {
    const html = await render(
        stats([
            doctor({ doctorId: 1, state: 'serving', servingNumber: null }),
            doctor({
                doctorId: 2,
                state: 'busy_elsewhere',
                servingNumber: 'HIDDEN_NUMBER',
            }),
            doctor({ doctorId: 3, state: 'free', held: 3 }),
            doctor({
                doctorId: 4,
                state: 'unexpected',
                servingNumber: 'HIDDEN_UNKNOWN',
            }),
        ]),
    );
    assert.match(html, /Serving/);
    assert.match(html, /Busy at another branch/);
    assert.match(html, /Free[\s\S]*?No active consultation/);
    assert.match(html, /Held consultations:[\s\S]*?3 of 3/);
    assert.match(html, /Not available/);
    assert.doesNotMatch(
        html,
        /HIDDEN_NUMBER|HIDDEN_UNKNOWN|null|Available now/,
    );
});

test('room provenance and escaped structural fields do not infer missing rooms or render unrelated patient data', async () => {
    const html = await render(
        stats([
            doctor({
                doctorId: 1,
                doctorName: '<img src=x>',
                roomName: '<script>room</script>',
                roomSource: 'chosen',
                patientName: 'DO_NOT_RENDER_PATIENT',
            }),
            doctor({ doctorId: 2, roomName: null, roomSource: null }),
            doctor({ doctorId: 3, roomSource: 'roster' }),
            doctor({ doctorId: 4, roomSource: 'unexpected' }),
        ]),
    );
    assert.match(html, /&lt;img src=x&gt;/);
    assert.match(html, /&lt;script&gt;room&lt;\/script&gt;/);
    assert.match(html, /No room assigned/);
    assert.equal((html.match(/Chosen by doctor/g) ?? []).length, 1);
    assert.equal((html.match(/From approved roster/g) ?? []).length, 1);
    assert.doesNotMatch(html, /<img src=x>|<script>room|DO_NOT_RENDER_PATIENT/);
});

test('table empty, loading and error states retain the same persistent summary; default empty wording is preserved', async () => {
    assert.match(
        await render(stats([])),
        /No eligible doctors for this branch today/,
    );
    assert.match(
        await render(stats([]), { presentation: undefined }),
        /No doctors are available for consultations/,
    );

    for (const [data, unavailable, text] of [
        [null, false, 'Loading rooms…'],
        [null, true, 'Rooms are temporarily unavailable.'],
    ]) {
        const html = await render(data, { unavailable });
        assert.match(html, /<summary/);
        assert.ok(html.includes(text));
        assert.doesNotMatch(html, /<table/);
    }
});

test('independent page metadata remains total-based and explicit pagination focuses summary before emitting', async () => {
    const first = await render(
        stats(
            Array.from({ length: 25 }, (_, i) => doctor({ doctorId: i + 1 })),
            {
                pagination: {
                    currentPage: 1,
                    lastPage: 2,
                    perPage: 25,
                    total: 27,
                },
            },
        ),
    );
    assert.equal((first.match(/data-label="Doctor"/g) ?? []).length, 25);
    assert.match(first, /25 shown · 27 eligible doctors · Page 1 of 2/);
    const last = await render(
        stats([doctor(), doctor({ doctorId: 2 })], {
            pagination: { currentPage: 2, lastPage: 2, perPage: 25, total: 27 },
        }),
    );
    assert.match(last, /2 shown · 27 eligible doctors · Page 2 of 2/);
    const events = [];
    const setup = compile(false).setup(
        { stats: null, unavailable: false, presentation: 'table' },
        { expose() {}, emit: (...args) => events.push(args) },
    );
    setup.summary.value = { focus: () => events.push(['focus']) };
    assert.equal(events.length, 0);
    setup.selectPage(2);
    assert.deepEqual(events, [['focus'], ['page', 2]]);
    const board = readFileSync('resources/js/pages/Queue/Index.vue', 'utf8');
    assert.match(
        board,
        /<QueueRoomsToday\s+v-if="live.scope === 'branch'"\s+presentation="table"/,
    );
    assert.match(board, /void roomsToday.poll\(/);
    assert.doesNotMatch(board, /await roomsToday.poll/);
});
