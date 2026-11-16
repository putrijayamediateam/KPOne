import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// Version 2 of one incident report: same requests as Classic (one shared composable), nothing of its own.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const v2 = read('resources/js/pages/HR/V2/Incident.vue');
const flat = (s) => s.replace(/\s+/g, ' ').replace(/ \./g, '.');
const tpl = flat(parse(v2).descriptor.template.content);
const code = flat(parse(v2).descriptor.scriptSetup.content);

test('the V2 page compiles and is registered', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'v2',
            source: parse(v2).descriptor.template.content,
            filename: 'Incident.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Incident'/);
});

test('V2 sends no request of its own; the composable holds them all', () => {
    assert.doesNotMatch(code, /fetch\(|router\.|useForm\(|axios|\.post\(/);
    assert.match(code, /useIncidentPage\(props\)/);
    const shared = read('resources/js/composables/hr/useIncidentPage.ts');
    assert.match(shared, /acknowledge/);
    assert.match(shared, /expected_lock_version/);
    assert.match(shared, /idempotency_key/);
    assert.doesNotMatch(
        code + shared,
        /localStorage|sessionStorage|indexedDB|prefetch/i,
    );
});

test('a sensitive record: photos open only through the existing link, never as thumbnails; the reporter never sees notes or outcome', () => {
    assert.match(tpl, /:href="`\$\{base\}\/photos\/\$\{p\.id\}`"/);
    assert.doesNotMatch(tpl, /<img|loading="|srcset|:src=/);
    assert.match(tpl, /v-if="!r\.manager" aria-labelledby="v2-status-title"/);
    assert.match(tpl, /HR's notes and actions are not shown here\./);
    assert.match(tpl, /Not shown to the person who reported it\./);
    assert.match(tpl, /v-if="r\.outcome"/);
});

test('buttons stay off with the reason in words; closing is a red confirm that starts with an empty outcome', () => {
    assert.match(code, /Write the note to continue\./);
    assert.match(code, /Choose a case to continue\./);
    assert.match(code, /Write the outcome to continue\./);
    assert.match(
        tpl,
        /title="Close this report\?"[^>]*tone="danger"|tone="danger"[^>]*title="Close this report\?"/,
    );
    assert.match(tpl, /closing\.outcome = ''; closing\.open = true;/);
    assert.match(tpl, /Mark every action done first\./);
});
