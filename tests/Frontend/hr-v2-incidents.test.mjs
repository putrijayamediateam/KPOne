import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 Incidents: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Incidents.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'Incidents.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Incidents'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useIncidentsPage\(props\)/);
    const shared = read('resources/js/composables/hr/useIncidentsPage.ts');
    assert.match(shared, /'\/hr\/incidents'/);
    assert.match(shared, /forceFormData: true/);
    assert.match(shared, /idempotency_key/);
});

test('the lists show no report text and nothing is kept in the browser', () => {
    assert.doesNotMatch(tpl, /r\.(description|note|text|body)/);
    assert.doesNotMatch(
        code,
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
    assert.match(
        tpl,
        /do not write the patient's name, IC number or clinical details/i,
    );
});

test('Send stays off with the reason in words; the form starts empty', () => {
    assert.match(code, /Choose what kind of incident it was to continue\./);
    assert.match(
        code,
        /Choose the date and the time it happened to continue\./,
    );
    assert.match(code, /Describe what happened to continue\./);
    assert.match(tpl, /:confirm-disabled="confirmReason !== ''"/);
    assert.match(tpl, /Send to the Director only/);
});

test('a date alone never saves a time nobody chose; a future time is refused', () => {
    assert.match(
        code,
        /form\.occurredAt = date && time \? `\$\{date\}T\$\{time\}` : ''/,
    );
    assert.doesNotMatch(code, /00:00/);
    assert.match(code, /whenDate\.value > todayDate\.value/);
    assert.match(code, /The date cannot be in the future\./);
});
