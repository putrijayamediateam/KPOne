import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 Discipline: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Discipline.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'Discipline.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Discipline'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useDisciplinePage\(props\)/);
    const shared = read('resources/js/composables/hr/useDisciplinePage.ts');
    assert.match(shared, /'\/hr\/discipline'/);
    assert.match(shared, /idempotency_key/);
});

test('a restricted list: no allegation or note text, nothing kept in the browser', () => {
    assert.doesNotMatch(tpl, /c\.(allegation|note|text|body|reply)/);
    assert.doesNotMatch(
        code,
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
    assert.doesNotMatch(
        read('resources/js/composables/hr/useDisciplinePage.ts'),
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
});

test('Open case stays off with the reason in words; the form starts empty', () => {
    assert.match(code, /Choose a staff file to continue\./);
    assert.match(code, /Choose the date it happened to continue\./);
    assert.match(
        code,
        /Write the allegation \(at least 10 characters\) to continue\./,
    );
    assert.match(tpl, /:confirm-disabled="confirmReason !== ''"/);
    assert.match(tpl, /You cannot open a case about yourself\./);
});
