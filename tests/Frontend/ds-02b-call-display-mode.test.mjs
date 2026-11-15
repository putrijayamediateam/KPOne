import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(read('resources/js/lib/queue-display.ts'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    { exports: module.exports, module, Date, Intl, URLSearchParams },
);
const { announcementText, callLabel } = module.exports;

test('a call shows the number unless the feed carries a name', () => {
    assert.equal(callLabel({ number: 'B-001' }), 'B-001');
    assert.equal(callLabel({ number: 'B-001', name: null }), 'B-001');
    assert.equal(
        callLabel({ number: 'B-001', name: ' Aminah binti Test ' }),
        'Aminah binti Test',
    );
});

test('the spoken call reads the name as written, otherwise the number digit by digit', () => {
    assert.equal(
        announcementText(
            { number: 'A-002', name: 'Aminah binti Test', room: 'Pharmacy' },
            'en',
        ),
        'Aminah binti Test. Pharmacy.',
    );
    assert.equal(
        announcementText({ number: 'B-001', room: 'Pharmacy' }, 'en'),
        'B, zero, zero, one. Pharmacy.',
    );
});

test('the TV screen and settings use the label and the mode choice', () => {
    const screen = read('resources/js/pages/QueueDisplay/Screen.vue');
    const settings = read('resources/js/pages/QueueDisplay/Settings.vue');
    assert.match(screen, /callLabel\(current\)/);
    assert.match(screen, /callLabel\(call\)/);
    assert.match(settings, /v-model="settingsForm\.call_display_mode"/);
    assert.match(settings, /value="name"/);
});
