import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Memos: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Memos.vue');
const classic = read('resources/js/pages/HR/Memos.vue');
const composable = read('resources/js/composables/hr/useMemosPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;
const flat = template.replace(/\s+/g, ' ');

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-memos',
            source: template,
            filename: 'Memos.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Memos',/);
    assert.match(script, /useMemosPage\(props\)/);
    assert.match(classic, /useMemosPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm|crypto\.randomUUID/);
    assert.doesNotMatch(source, /localStorage|sessionStorage|v-html/);
    assert.match(composable, /`\/hr\/memos\/\$\{memo\.id\}\/open`/);
    assert.match(composable, /'X-XSRF-TOKEN': csrf\(\)/);
    assert.match(composable, /\/hr\/memos\/audience\?/);
    assert.match(composable, /expected_lock_version: memo\.lockVersion/);
    assert.equal((composable.match(/idempotency_key:/g) ?? []).length, 3);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(classic, /<PageHeader\s+eyebrow="KPOne HR"\s+title="Memos"/);
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('a memo text is fetched only when a person presses Open; nothing loads ahead', () => {
    assert.match(flat, /@click="openMemo\(memo, true\)"/);
    assert.match(flat, /@click="openMemo\(memo, false\)"/);
    assert.match(flat, /:href="pdfUrl\(memo\.id\)"/);
    assert.doesNotMatch(template, /<iframe|<embed|<object|<img|<link\b/);
    assert.doesNotMatch(
        script,
        /onMounted|onBeforeMount|new Image|rel="prefetch"|rel="preload"/,
    );
    // The text sits in an element that renders only after Open.
    assert.match(flat, /v-if="bodies\[memo\.id\] !== undefined"/);
});

test('I have read this needs the memo (and its PDF) opened first, and says so', () => {
    assert.match(flat, /:disabled="\s*!ready\(memo\) \|\|/);
    assert.match(flat, />\{\{ hint\(memo\) \}\}</);
});

test('Daniel: one filled action: Write a memo; everything else is outline and Withdraw is red in a V2 dialog', () => {
    assert.equal((template.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.match(
        template,
        /#actions[\s\S]*?:class="primaryButton"[\s\S]*?data-test="memo-publish"[\s\S]*?Write a memo/,
    );
    assert.match(template, /text-destructive/);
    assert.match(template, /title="Withdraw this memo\?"[\s\S]*?tone="danger"/);
    assert.doesNotMatch(source, /OperationalConfirmDialog/);
});

test('Daniel: the manager sentence is on All memos only', () => {
    assert.match(
        script,
        /props\.memos\.tab === 'manage'\s*\?\s*'Write a memo for all staff/,
    );
    assert.match(
        script,
        /'Memos from HR\. Open each one, then press I have read this\.'/,
    );
});

test('Daniel: the publish dialog starts empty; the faded Publish names what is missing, and the reason moves on', () => {
    assert.match(script, /'Enter a title to continue\.'/);
    assert.match(script, /'Write the memo to continue\.'/);
    assert.ok(
        script.indexOf('Enter a title to continue.') <
            script.indexOf('Write the memo to continue.'),
    );
    assert.match(template, /:confirm-disabled="publishMissing !== ''"/);
    assert.match(composable, /title: '',\s*body: '',\s*roles: \[\],/);
    // Where it goes is said in words, with a live count, before the button is pressed.
    assert.match(flat, /This memo goes to: \{\{ audienceWords \}\}/);
    assert.match(flat, /data-test="memo-publish-note"/);
});

test('Daniel: Withdraw asks for a reason, empty at the start, and the red button waits for one', () => {
    assert.match(script, /'Pick a reason to continue\.'/);
    assert.match(flat, /:confirm-disabled="withdrawMissing !== ''"/);
    assert.match(flat, /:model-value="withdrawReason \|\| null"/);
    assert.match(composable, /withdrawReason\.value = '';/);
});

test('files use the file chooser with a spoken state line, never the bare file control', () => {
    assert.match(template, /<FileChooseButton/);
    assert.match(template, /status-id="v2-memo-file-status"/);
    assert.doesNotMatch(template, /type="file"/);
});

test('the names Classic shows are kept; V2 adds no name', () => {
    assert.match(flat, /\{\{ p\.name \}\}/);
    assert.match(flat, /\{\{ memo\.publishedBy \}\}/);
    assert.match(flat, /\{\{ memo\.withdrawnBy/);
    assert.doesNotMatch(template, /\.email|\.phone|\.identifier|\.bank/);
});
