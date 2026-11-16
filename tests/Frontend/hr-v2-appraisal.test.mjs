import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 of one appraisal: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Appraisal.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'Appraisal.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Appraisal'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useAppraisalPage\(props\)/);
    const shared = read('resources/js/composables/hr/useAppraisalPage.ts');
    assert.match(
        shared,
        /expected_lock_version: props\.appraisal\.lockVersion/,
    );
    assert.match(shared, /idempotency_key: form\.key/);
    assert.match(shared, /\/sign-off/);
    assert.doesNotMatch(code, /localStorage|sessionStorage|indexedDB/i);
});

test('Send stays off until every criterion is rated; sending and sign-off are confirms', () => {
    assert.match(tpl, /:disabled="form\.processing \|\| unrated > 0"/);
    assert.match(
        code,
        /Rate every criterion first \(\$\{unrated\.value\} left\)\./,
    );
    assert.match(tpl, /@click="confirmSend = true"/);
    assert.match(tpl, /title="Sign this appraisal off\?"/);
    assert.match(code, /Write your comment to continue\./);
});

test('no total score or ranking is shown; each criterion stands on its own', () => {
    assert.match(tpl, /KPOne adds no total\./);
    assert.doesNotMatch(tpl, /total score:|rank(ing)?:|average/i);
});
