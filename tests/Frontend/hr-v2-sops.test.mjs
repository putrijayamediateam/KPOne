import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 SOPs: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Sops.vue');
const classic = read('resources/js/pages/HR/Sops.vue');
const composable = read('resources/js/composables/hr/useSopsPage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-sops',
            source: template,
            filename: 'Sops.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Sops',/);
    assert.match(script, /useSopsPage\(props\)/);
    assert.match(classic, /useSopsPage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm|crypto\.randomUUID/);
    assert.doesNotMatch(source, /localStorage|sessionStorage/);
    assert.match(
        composable,
        /`\/hr\/sops\/versions\/\$\{sop\.versionId\}\/acknowledge`/,
    );
    assert.match(composable, /expected_lock_version: sop\.lockVersion/);
    assert.equal((composable.match(/idempotency_key:/g) ?? []).length, 3);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(classic, /<PageHeader\s+eyebrow="KPOne HR"\s+title="SOPs"/);
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('a SOP opens only through the audited route, and nothing loads ahead', () => {
    assert.match(composable, /`\/hr\/sops\/versions\/\$\{versionId\}\/open`/);
    assert.match(template, /:href="openUrl\(sop\.versionId\)"/);
    assert.doesNotMatch(template, /<iframe|<embed|<object|<img|<link\b/);
    assert.doesNotMatch(script, /prefetch|preload|new Image/);
});

test('acknowledge needs the SOP opened first, and says so', () => {
    assert.match(template, /:disabled="\s*!isOpened\(sop\) \|\|/);
    assert.match(template, />Open the SOP first\.</);
});

test('Daniel: one filled action: Publish an SOP; everything else is outline, Withdraw is red and confirms in a dialog', () => {
    assert.equal((template.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.match(
        template,
        /#actions[\s\S]*?:class="primaryButton"[\s\S]*?data-test="sop-publish"[\s\S]*?Publish an SOP/,
    );
    assert.match(template, /text-destructive/);
    assert.match(template, /title="Withdraw this SOP\?"[\s\S]*?tone="danger"/);
    assert.doesNotMatch(source, /OperationalConfirmDialog/);
});

test('Daniel: the publish dialog starts empty and the filled action waits for a title, a role and a PDF', () => {
    assert.match(script, /'Enter a title to continue\.'/);
    assert.match(script, /'Choose at least one role to continue\.'/);
    assert.match(script, /'Choose the PDF to continue\.'/);
    assert.match(template, /:confirm-disabled="publishMissing !== ''"/);
    assert.match(composable, /title: '',\s*roles: \[\],\s*file: null,/);
});

test('files use the file chooser with a spoken state line, never the bare file control', () => {
    assert.match(template, /<FileChooseButton/);
    assert.match(template, /status-id="v2-sop-file-status"/);
    assert.doesNotMatch(template, /type="file"/);
});

test('the names and wording Classic shows are kept; V2 adds no name', () => {
    const flat = template.replace(/\s+/g, ' ');
    assert.ok(flat.includes("Who hasn't acknowledged"));
    assert.ok(flat.includes('Its acknowledgements are kept.'));
    assert.match(flat, /\{\{ p\.name \}\}/);
    assert.match(flat, /\{\{ v\.publishedBy \}\}/);
    assert.doesNotMatch(template, /\.email|\.phone|\.identifier|\.bank/);
});
