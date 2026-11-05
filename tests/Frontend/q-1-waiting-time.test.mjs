import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';
import { renderToString } from 'vue/server-renderer';

// Q-1 (owner decision 2026-11-05): a waiting patient's time turns amber from
// 30 minutes and red from 60 minutes on the Queue board and the Registration
// Waiting tab. Display only: no queue order, permission or backend change.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const helperSource = read('resources/js/lib/waiting-time.ts');
const helper = {};
Function(
    'exports',
    ts.transpileModule(helperSource, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.CommonJS,
        },
    }).outputText,
)(helper);

test('the limits are fixed at 30 and 60 minutes', () => {
    assert.equal(helper.WAITING_AMBER_MINUTES, 30);
    assert.equal(helper.WAITING_RED_MINUTES, 60);
});

test('tone changes exactly at the two limits', () => {
    const cases = [
        [0, 'normal'],
        [29, 'normal'],
        [30, 'amber'],
        [59, 'amber'],
        [60, 'red'],
        [600, 'red'],
    ];

    for (const [minutes, tone] of cases) {
        assert.equal(helper.waitingTone(minutes), tone, `${minutes} min`);
    }
});

test('an unknown time is never coloured', () => {
    for (const value of [null, undefined, Number.NaN, Infinity]) {
        assert.equal(helper.waitingTone(value), 'normal');
    }
});

test('amber and red carry screen-reader text so colour is not the only signal', () => {
    assert.equal(helper.waitingToneDescription('normal'), null);
    assert.equal(
        helper.waitingToneDescription('amber'),
        'long wait, 30 minutes or more',
    );
    assert.equal(
        helper.waitingToneDescription('red'),
        'very long wait, 60 minutes or more',
    );
});

test('the board colours only the duration chip and marks it for tests and screen readers', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );

    assert.match(board, /:class="durationClass\(rowWaitingTone\(row\)\)"/);
    assert.match(board, /:data-waiting-tone="rowWaitingTone\(row\)"/);
    assert.match(
        board,
        /class="sr-only"[\s\S]*?waitingToneDescription\(rowWaitingTone\(row\)\)/,
    );
    assert.match(board, /amber: 'bg-amber-100[^']*dark:bg-amber-950\/70[^']*'/);
    assert.match(board, /red: 'bg-red-100[^']*dark:bg-red-950\/70[^']*'/);
    assert.match(
        board,
        /const rowWaitingTone = \(row: PatientBoardRow\) =>\s*waitingTone\(row\.waitingMinutes\)/,
    );
    // Existing behaviour the change must keep.
    assert.match(board, /data-testid="call-readiness-badge"/);
    assert.match(board, /<WhatsAppLink/);
});

test('both boards pass waiting minutes for waiting rows only, never held, serving or removed rows', () => {
    const queue = read('resources/js/pages/Queue/Index.vue');
    assert.match(
        queue,
        /waitingMinutes:\s*!row\.isHeld && row\.status === 'waiting'\s*\?\s*elapsedMinutes\(row\)\s*:\s*null/,
    );

    const registration = read('resources/js/pages/Registration/Index.vue');
    assert.match(
        registration,
        /waitingMinutes:\s*!visit\.isHeld && visit\.queueStatus === 'waiting'\s*\?\s*visit\.durationMinutes\s*:\s*null/,
    );

    // The pages add no import, so the existing page render tests keep their stubs.
    for (const page of [queue, registration]) {
        assert.doesNotMatch(page, /waiting-time/);
    }
});

test('the TV and public QR pages do not use the waiting-time colours', () => {
    for (const path of [
        'resources/js/pages/QueueDisplay/Screen.vue',
        'resources/js/pages/PublicCheckIn/Status.vue',
    ]) {
        assert.doesNotMatch(
            read(path),
            /waiting-time|waitingTone|waitingMinutes/,
        );
    }
});

const renderBoard = async (rows) => {
    const path = 'resources/js/components/patient-board/PatientBoard.vue';
    const descriptor = parse(read(path), { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: 'q-1-patient-board',
        inlineTemplate: true,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const passthrough = Vue.defineComponent({
        inheritAttrs: false,
        setup:
            (_, { slots }) =>
            () =>
                Vue.h('div', slots.default?.()),
    });
    const empty = Vue.defineComponent({ setup: () => () => null });
    const icon = Vue.defineComponent({ setup: () => () => Vue.h('i') });
    const stubs = new Proxy(
        {},
        { get: (_, key) => (key === '__esModule' ? true : passthrough) },
    );
    const requireMap = {
        vue: Vue,
        '@inertiajs/vue3': { Link: passthrough, router: {} },
        '@lucide/vue': new Proxy({}, { get: () => icon }),
        '@/components/patient/WhatsAppLink.vue': { default: empty },
        '@/components/queue/CallReadinessActions.vue': { default: empty },
        '@/components/ui/button': { Button: passthrough },
        '@/components/ui/disclosure': { DisclosureText: passthrough },
        '@/components/ui/dropdown-menu': stubs,
        '@/components/ui/tooltip': stubs,
        '@/lib/waiting-time': helper,
    };
    const componentModule = { exports: {} };
    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(
        (specifier) => {
            if (specifier in requireMap) {
                return requireMap[specifier];
            }

            throw new Error(`Unexpected import: ${specifier}`);
        },
        componentModule,
        componentModule.exports,
    );

    return renderToString(
        Vue.createSSRApp(componentModule.exports.default, { rows }),
    );
};

const row = (key, overrides) => ({
    key,
    canWhatsapp: false,
    callHold: null,
    patientName: `Synthetic ${key}`,
    patientNumber: `KP-${key}`,
    visitNumber: `KPV-${key}`,
    queueNumber: 'A-001',
    arrivedDate: '5 Nov 2026',
    arrivedTime: '9:00 AM',
    visitNotes: null,
    doctorName: null,
    coverageLabel: 'Self-pay',
    panelNote: null,
    durationLabel: '—',
    priority: 'normal',
    statusLabel: 'Waiting',
    statusTone: 'waiting',
    can: {
        viewPatient: false,
        update: false,
        cancel: false,
        sendToWaiting: false,
        call: false,
        openConsultation: false,
    },
    source: null,
    ...overrides,
});

test('a rendered board colours amber and red waiting rows and leaves a serving row plain', async () => {
    const html = await renderBoard([
        row('1', { waitingMinutes: 45, durationLabel: 'Waiting 45 min' }),
        row('2', {
            waitingMinutes: 75,
            durationLabel: 'Waiting 1 hr 15 min',
        }),
        row('3', {
            waitingMinutes: null,
            durationLabel: 'Serving 90 min',
            statusTone: 'serving',
        }),
        row('4', { durationLabel: 'Waiting 2 min' }),
    ]);
    const tones = [...html.matchAll(/data-waiting-tone="(\w+)"/g)].map(
        (match) => match[1],
    );

    assert.deepEqual(tones, ['amber', 'red', 'normal', 'normal']);
    assert.match(html, /bg-amber-100[^"]*"[^>]*data-waiting-tone="amber"/);
    assert.match(html, /bg-red-100[^"]*"[^>]*data-waiting-tone="red"/);
    assert.equal(
        html.match(/class="sr-only"/g)?.length,
        2,
        'only the two coloured rows carry screen-reader text',
    );
    assert.match(html, /\(long wait, 30 minutes or more\)/);
    assert.match(html, /\(very long wait, 60 minutes or more\)/);
});
