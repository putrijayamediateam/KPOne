import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 of one restricted discipline case: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/DisciplineCase.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);
const shared = read('resources/js/composables/hr/useDisciplineCasePage.ts');

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'DisciplineCase.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/DisciplineCase'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useDisciplineCasePage\(props\)/);
    assert.match(shared, /expected_lock_version: props\.case\.lockVersion/);
    assert.match(shared, /idempotency_key/);
    assert.match(shared, /forceFormData/);
});

test('a restricted record: nothing stored in the browser, no case text in the title or any label, files only via the recorded link', () => {
    assert.doesNotMatch(
        code + shared,
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
    assert.match(
        read('resources/js/pages/HR/V2/DisciplineCase.vue'),
        /<Head title="Discipline case" \/>/,
    );
    assert.doesNotMatch(
        tpl,
        /aria-label="[^"]*(allegation|reply|note)\b[^"]*"\s*:|:aria-label="[^"]*\$\{/,
    );
    assert.match(tpl, /:href="`\$\{base\}\/files\/\$\{e\.file\.id\}`"/);
    assert.doesNotMatch(tpl, /<img|srcset|:src=/);
    assert.match(
        code,
        /Restricted: this page and every file opened here are recorded\./,
    );
});

test('every button stays off with the reason in words; the outcome is a red confirm that starts empty', () => {
    assert.match(code, /Write the note to continue\./);
    assert.match(code, /Choose a letter to continue\./);
    assert.match(code, /Type the reply to continue\./);
    assert.match(code, /Choose the date received to continue\./);
    assert.match(code, /Choose a file to continue\./);
    assert.match(
        code,
        /outcome\.code = ''; outcome\.letter = ''; outcome\.note = ''; outcome\.open = true;/,
    );
    assert.match(tpl, /title="Record the outcome\?"/);
    assert.match(tpl, /tone="danger"/);
    assert.match(tpl, /:confirm-disabled="closeReason !== ''"/);
});

test('the date received is a date only, limited to today by the field (no time, so no 00:00 or datetime-vs-date trap)', () => {
    assert.match(
        tpl,
        /<V2DateField v-model="reply\.receivedOn" label="Date received" required :max="props\.case\.today"/,
    );
    assert.doesNotMatch(tpl + code, /V2TimeField|T00:00|type="time"/);
});
