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
const compile = (source) => {
    const script = compileScript(parse(source).descriptor, {
        id: 'queue-context',
        inlineTemplate: true,
    }).content;
    const exports = {};
    runInNewContext(
        ts.transpileModule(script, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports, require },
    );

    return exports.default;
};
const component = compile(
    readFileSync('resources/js/components/queue/QueueCallContext.vue', 'utf8'),
);
const render = (props = {}) => renderToString(createSSRApp(component, props));
const block = {
    mode: 'own',
    state: 'ready',
    reason: null,
    doctorUserId: 1,
    doctorName: 'Synthetic doctor',
    candidate: {
        queueNumber: 'A-014',
        patientName: 'Synthetic <script>Patient</script>',
        roomName: 'Destination 2',
    },
};
const room = (source) => ({
    rooms: [{ id: 1, name: 'Selection 1' }],
    currentRoomId: 1,
    currentRoomSource: source,
});

test('ready candidate is escaped and destination remains distinct from current room provenance', async () => {
    const html = await render({ block, roomChoice: room('roster') });
    assert.match(html, /Next patient:/);
    assert.match(html, /A-014/);
    assert.match(html, /&lt;script&gt;Patient&lt;\/script&gt;/);
    assert.doesNotMatch(html, /<script>/);
    assert.match(html, /Room selection:.*Selection 1/s);
    assert.match(html, /From the approved roster/);
    assert.match(html, /Call destination: Destination 2/);
    assert.doesNotMatch(html, /<button|<a /);
});

test('manual and absent provenance are explicit without inferring a roster', async () => {
    assert.match(
        await render({ roomChoice: room('chosen') }),
        /Selected manually/,
    );
    const unknown = await render({ roomChoice: room(null) });
    assert.match(unknown, /Selection 1/);
    assert.doesNotMatch(unknown, /roster|Selected manually/);
    assert.match(
        await render({ roomChoice: { ...room(null), currentRoomId: null } }),
        /No room selected/,
    );
    const missing = await render({
        roomChoice: { ...room('roster'), rooms: [] },
    });
    assert.match(missing, /Room selection unavailable/);
    assert.doesNotMatch(missing, /From the approved roster/);
});

test('nonready or absent blocks never reveal a retained candidate and retain the backend reason', async () => {
    for (const state of [
        'no_doctor',
        'doctor_unavailable',
        'busy',
        'no_room',
        'none_waiting',
        'all_not_ready',
    ]) {
        const html = await render({
            block: { ...block, state, reason: 'Synthetic refusal' },
        });
        assert.match(html, /Synthetic refusal/);
        assert.doesNotMatch(html, /A-014|Patient|Destination 2/);
    }

    assert.doesNotMatch(await render(), /Room and next patient context/);
    assert.doesNotMatch(
        await render({ roomChoice: room('chosen') }),
        /Next patient/,
    );
    assert.match(
        await render({ block: { ...block, mode: 'branch' } }),
        /Next for Synthetic doctor:/,
    );
    assert.match(
        await render({
            block: {
                ...block,
                candidate: { ...block.candidate, roomName: null },
            },
        }),
        /Call destination: Counter/,
    );
});

test('actual related TV link follows only the existing shared capability', async () => {
    const pageSource = readFileSync(
        'resources/js/pages/Queue/Index.vue',
        'utf8',
    );
    const link = pageSource
        .match(
            /<Link\s+v-if="page\.props\.workspace\?\.navigation\.queueDisplayScreen"[\s\S]*?<\/Link\s*>/,
        )[0]
        .replace(/\bLink\b/g, 'a');
    const view = compile(
        `<script setup>defineProps(['page']);</script><template>${link}</template>`,
    );

    for (const allowed of [false, true]) {
        const html = await renderToString(
            createSSRApp(view, {
                page: {
                    props: {
                        workspace: {
                            navigation: { queueDisplayScreen: allowed },
                        },
                    },
                },
            }),
        );
        assert.equal(html.includes('href="/queue-display"'), allowed);
    }

    const absent = await renderToString(
        createSSRApp(view, { page: { props: { workspace: null } } }),
    );
    assert.doesNotMatch(absent, /href=/);
    assert.match(
        pageSource,
        /<QueueCallContext\s+v-if="activeTab === 'waiting'"\s+:block="live\.callNext"\s+:room-choice="roomChoice"/,
    );
});
