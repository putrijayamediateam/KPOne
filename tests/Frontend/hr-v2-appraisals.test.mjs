import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 Appraisals: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Appraisals.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'Appraisals.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Appraisals'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useAppraisalsPage\(props\)/);
    const shared = read('resources/js/composables/hr/useAppraisalsPage.ts');
    assert.match(shared, /\/hr\/appraisals\/cycles/);
    assert.match(shared, /idempotency_key/);
});

test('forms start empty and Confirm stays off with the reason in words', () => {
    assert.match(code, /Enter a name to continue\./);
    assert.match(code, /Choose the first and last day to continue\./);
    assert.match(code, /Choose the staff to appraise to continue\./);
    assert.match(code, /Enter a title for every criterion to continue\./);
    assert.match(tpl, /:confirm-disabled="cycleMissing !== ''"/);
    assert.match(tpl, /:confirm-disabled="criteriaMissing !== ''"/);
});

test('closing a cycle is a red confirm; one filled action per tab; no score or ranking is shown', () => {
    assert.match(
        tpl,
        /title="Close this cycle\?"[^>]*tone="danger"|tone="danger"[^>]*title="Close this cycle\?"/,
    );
    assert.match(tpl, /Open a cycle/);
    assert.match(tpl, /Change criteria/);
    assert.doesNotMatch(tpl, /total score:|rank(ing)?:|average/i);
});
