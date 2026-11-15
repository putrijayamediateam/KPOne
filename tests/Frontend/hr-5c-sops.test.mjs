import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-5c SOPs (owner 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.2 and 12.6, Daniel's early look
// folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Sops.vue',
    records: 'resources/js/pages/HR/Records.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the new page and the changed page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('SOPs to read: an outline Open, then the one primary "I have read and understood", disabled until opened with a hint', () => {
    const page = template('page');
    assert.match(
        page,
        /<Button as-child variant="outline"[\s\S]{0,200}:href="openUrl\(sop\.versionId\)"[\s\S]{0,300}@click="markOpened\(sop\.versionId\)"/,
    );
    assert.match(page, /:disabled="\s*!isOpened\(sop\)/);
    assert.match(page, /I have read and understood/);
    assert.match(page, />Open the SOP first\.<\/span/);
    assert.match(page, /:aria-describedby="\s*isOpened\(sop\)/);
    assert.match(
        page,
        /Version \{\{ sop\.version \}\} is new\. Please read it again\./,
    );
    assert.match(
        page,
        /You acknowledged version \{\{ sop\.earlier\.version \}\}/,
    );
    assert.match(page, /Acknowledged on \{\{ sop\.acknowledgedOn \}\}\./);
    assert.match(page, /title="No SOPs for your role yet\."/);
    // One primary on the card: Open is outline, the acknowledgement is the default button.
    assert.doesNotMatch(page, /variant="default"/);
});

test('All SOPs: "N of M acknowledged", who hasn\'t as plain text with no red or ranking, outline actions and a withdrawal that keeps acknowledgements', () => {
    const page = template('page');
    assert.match(
        page,
        /\{\{ sop\.acknowledged \}\} of \{\{ sop\.audience \}\}\s*acknowledged/,
    );
    assert.match(
        page,
        /Who hasn't acknowledged version \{\{ sop\.version \}\}/,
    );
    assert.match(
        page,
        /\{\{ p\.name \}\}<\/span>[\s\S]{0,120}\{\{ p\.role[\s\S]{0,400}published \{\{ p\.publishedOn \}\}/,
    );
    assert.doesNotMatch(source.page, /danger|text-red|bg-red|destructive/);

    for (const label of ['Publish an SOP', 'New version', 'Withdraw']) {
        assert.match(
            page,
            new RegExp(`variant="outline"[\\s\\S]{0,200}>${label}<`),
            label,
        );
    }

    assert.match(page, /Its versions and acknowledgements are kept\./);
    // At 0 there is no empty who-hasn't list, only the versions (Daniel, HR-5c nit).
    assert.match(
        page,
        /sop\.notYet\.length\s*\?\s*`Who hasn't acknowledged \(\$\{sop\.notYet\.length\}\)`\s*:\s*'Show versions'/,
    );
    assert.match(page, /<section\s+v-if="sop\.notYet\.length"/);
    assert.match(page, /title="No SOPs published yet\."/);
    assert.match(page, /accept="application\/pdf"/);
    assert.match(page, /PDF only, up to \{\{ sops\.options\.maxMb \}\} MB\./);
});

test('My HR details links to the SOPs still to read, and every write sends an idempotency key', () => {
    const records = template('records');
    assert.match(records, /v-if="sopsToRead"/);
    assert.match(records, /<Link href="\/hr\/sops">Open SOPs<\/Link>/);
    assert.match(source.records, /sopsToRead\?: number;/);
    const script = parse(source.page).descriptor.scriptSetup.content;
    assert.equal((script.match(/idempotency_key:/g) ?? []).length, 3);
    assert.match(script, /expected_lock_version: sop\.lockVersion/);
    assert.match(script, /forceFormData: true/);
    // A stale page ("records") shows as well as an SOP refusal (Suresh, S1).
    assert.match(
        script,
        /const failure = computed\(\(\) => errors\.value\.sop \?\? errors\.value\.records\)/,
    );
    assert.equal((source.page.match(/errors\.sop/g) ?? []).length, 0);
    assert.match(source.page, /class="[^"]*max-lg:min-h-11/);
});
