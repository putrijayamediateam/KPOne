import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 Letters: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Letters.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = parse(v2).descriptor.template.content;
const code = parse(v2).descriptor.scriptSetup.content;

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({ id: 'v2', source: tpl, filename: 'Letters.vue' })
            .errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Letters'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useLettersPage\(props\)/);
    const shared = read('resources/js/composables/hr/useLettersPage.ts');
    assert.match(shared, /\/hr\/letters\/preview/);
    assert.doesNotMatch(code, /\/hr\/messages|mailto:|wa\.me/);
});

test('issuing stays off until the preview is made, with the reason in words', () => {
    const t = flat(code);
    assert.match(t, /Preview the letter to continue\./);
    assert.match(flat(tpl), /:confirm-disabled="issueMissing !== ''"/);
    assert.match(flat(tpl), /@click="loadPreview"/);
});

test('void and retire are red confirms; reasons start empty; given is a dated confirm', () => {
    const t = flat(tpl);
    assert.match(
        t,
        /title="Void this letter\?"[^>]*confirm-label="Void" tone="danger"/,
    );
    assert.match(t, /title="Retire this template\?"/);
    assert.match(t, /cancel-label="Keep template" tone="danger"/);
    assert.match(t, /title="Record when it was given"/);
    assert.match(flat(code), /Pick a reason to continue\./);
    assert.match(flat(code), /Choose the date to continue\./);
});

test('one filled action per tab; no text of an issued letter is shown', () => {
    const t = flat(tpl);
    assert.match(t, /Issue a letter/);
    assert.match(t, /New template/);
    assert.match(t, /Waiting to be given/);
    assert.doesNotMatch(t, /letter\.(text|body)/);
});
