import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const javascript = ts.transpileModule(
    read('resources/js/lib/queue-display.ts'),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const module = { exports: {} };
runInNewContext(javascript, {
    exports: module.exports,
    module,
    Date,
    Intl,
    URLSearchParams,
});
const { announcementText, roomLabel } = module.exports;

const dispensary = read('resources/js/pages/Dispensary/Show.vue');
const consultation = read('resources/js/pages/Clinical/Show.vue');
const queue = read('resources/js/pages/Queue/Index.vue');
const board = read('resources/js/components/patient-board/PatientBoard.vue');
const screen = read('resources/js/pages/QueueDisplay/Screen.vue');

test('a call without a room names the place for its service', () => {
    assert.equal(roomLabel(null), 'Sila ke kaunter');
    assert.equal(roomLabel(null, 'dispensary'), 'Farmasi');
    assert.equal(roomLabel(null, 'treatment'), 'Bilik rawatan');
    assert.equal(roomLabel('Farmasi 2', 'dispensary'), 'Farmasi 2');
    assert.equal(
        announcementText(
            { number: '012', room: null, service: 'dispensary' },
            'en',
        ),
        'zero, one, two. Pharmacy.',
    );
    assert.equal(
        announcementText(
            { number: '012', room: 'Treatment Room 1', service: 'treatment' },
            'ms',
        ),
        'kosong, satu, dua. Treatment Room 1.',
    );
    assert.ok(screen.includes('roomLabel(current.room, current.service)'));
});

test('dispensary staff call the patient with Panggil and pick a room only when there are several', () => {
    assert.ok(dispensary.includes('dispensary.tvCall?.canCall'));
    assert.ok(dispensary.includes('/call`'));
    assert.ok(dispensary.includes('Panggil'));
    assert.ok(dispensary.includes('dispensary.tvCall.rooms.length > 1'));
    assert.match(
        dispensary,
        /<OperationalSelect[\s\S]*?label="Dispensary room"/,
    );
});

test('the doctor calls to a treatment room from the consultation and stays on it', () => {
    assert.ok(consultation.includes('/queue/treatment-call'));
    assert.ok(consultation.includes("from: 'consultation'"));
    assert.ok(consultation.includes('Ke bilik rawatan'));
    assert.ok(consultation.includes('clinical.queue.canRecall'));
});

test('staff call a serving patient to a treatment room from the queue board', () => {
    assert.ok(board.includes('>Call to Treatment Room</DropdownMenuItem'));
    assert.ok(board.includes("$emit('callTreatment', row)"));
    assert.ok(queue.includes('@call-treatment="openTreatmentCall"'));
    assert.ok(queue.includes('/queue/treatment-call'));
    assert.doesNotMatch(queue, /<select\b/);
});
