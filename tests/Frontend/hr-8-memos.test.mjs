import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-8 announcements and memos (owner, via B.E.R.N.I.S.S, 2026-11-10; design note docs/teams/hr/HR-8-DESIGN.md).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Memos.vue',
    records: 'resources/js/pages/HR/Records.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;
const script = parse(source.page).descriptor.scriptSetup.content;

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

test('My memos: Open, the PDF link, then the one primary "I have read this", disabled until opened with a hint', () => {
    const page = template('page');
    assert.match(page, /title="No memos for you\."/);
    assert.match(page, /data-test="memo-open"/);
    assert.match(
        page,
        /:href="pdfUrl\(memo\.id\)"[\s\S]{0,200}@click="markPdfOpened\(memo\.id\)"/,
    );
    assert.match(page, /:disabled="\s*!ready\(memo\)/);
    assert.match(page, />I have read this|'I have read this'/);
    assert.match(page, /:aria-describedby="\s*ready\(memo\) \? undefined/);
    // The hint names the missing step.
    assert.match(script, /'Open the memo first\.' : 'Open the PDF first\.'/);
    // A memo with no PDF needs only the memo opened.
    assert.match(script, /!m\.hasPdf \|\| m\.pdfOpened/);
    // The text is asked for from the server (the open is recorded there) and shown as plain text.
    assert.match(script, /fetch\(`\/hr\/memos\/\$\{memo\.id\}\/open`/);
    assert.match(script, /'X-XSRF-TOKEN': csrf\(\)/);
    assert.match(page, /whitespace-pre-wrap/);
    assert.doesNotMatch(source.page, /v-html/);
    assert.match(
        page,
        /This memo was replaced by a newer memo\. Please read the\s+newer one\./,
    );
    assert.match(page, /This memo was replaced and no longer takes\s+reads\./);
    assert.match(page, /This memo is closed\. It no longer takes new reads\./);
    // Only an open, unread memo offers the button.
    assert.match(
        page,
        /v-if="memo\.status === 'open' && !memo\.acknowledgedOn"/,
    );
    assert.doesNotMatch(source.page, /variant="default"/);
});

test('All memos: "N of M expected now have read", who hasn\'t as plain text with no red or ranking, and a withdrawal that keeps reads', () => {
    const page = template('page');
    assert.match(
        page,
        /\{\{ memo\.read \}\} of \{\{ memo\.expected \}\} expected now\s+have read/,
    );
    assert.match(page, /Who hasn't read yet \(\$\{memo\.notYet\.length\}\)/);
    assert.match(
        page,
        /\{\{ p\.name \}\}<\/span>[\s\S]{0,120}\{\{ p\.role[\s\S]{0,400}published \{\{ p\.publishedOn \}\}/,
    );
    const notYet = page.slice(page.indexOf('data-test="memo-not-yet"'));
    assert.doesNotMatch(
        notYet.slice(0, 800),
        /danger|text-red|bg-red|destructive/,
    );
    assert.match(page, /The people\s+below are asked to read the newer memo\./);

    for (const label of [
        'Write a memo',
        'Replace with a new memo',
        'Withdraw',
    ]) {
        assert.match(
            page,
            new RegExp(`variant="outline"[\\s\\S]{0,260}>${label}<`),
            label,
        );
    }

    assert.match(page, /Every read already given is kept\./);
    assert.match(page, /title="No memos yet\."/);
    assert.match(
        page,
        /Its replacement was withdrawn on\s+\{\{ memo\.replacementWithdrawnOn \}\}; this memo applies again\./,
    );
});

test('The publish form: title hint, plain text with a count, audience with a live count, open until, replaces and an optional PDF', () => {
    const page = template('page');
    assert.match(
        page,
        /Don't put a staff member's name, health or pay in\s+the title\./,
    );
    assert.match(page, /Plain text, \{\{ form\.body\.length \}\} of/);
    assert.match(page, /Branches \(none ticked means all\)/);
    assert.match(page, /Roles \(none ticked means all\)/);
    assert.match(page, /a ticked branch and a ticked role/);
    assert.match(page, /This memo goes to: \{\{ audienceWords \}\} \(about/);
    assert.match(page, /title="Publish this memo\?"/);
    assert.match(page, /A published memo cannot be edited; to correct it/);
    assert.match(page, /@submit\.prevent="confirmPublish = true"/);
    assert.match(page, /<legend/);
    assert.match(script, /\/hr\/memos\/audience\?/);
    assert.match(page, /Open until \(optional\)/);
    assert.match(page, /:min="memos\.options\.today"/);
    assert.match(page, /Replaces \(optional\)/);
    assert.match(page, /<DateField\s+id="memo-until"/);
    assert.match(
        page,
        /<FileChooseButton[\s\S]{0,200}accept="application\/pdf"/,
    );
    assert.doesNotMatch(page, /type="date"|type="file"/);
    assert.match(page, /PDF only, up to \{\{ memos\.options\.maxMb \}\} MB\./);
    assert.match(source.page, /class="[^"]*max-lg:min-h-11/);
});

test('My HR details links to the memos still to read, and every write sends an idempotency key', () => {
    const records = template('records');
    assert.match(records, /v-if="memosToRead"/);
    assert.match(records, /<Link href="\/hr\/memos">Open memos<\/Link>/);
    assert.match(source.records, /memosToRead\?: number;/);
    assert.equal((script.match(/idempotency_key:/g) ?? []).length, 3);
    assert.match(script, /expected_lock_version: memo\.lockVersion/);
    assert.match(script, /forceFormData: true/);
    assert.match(
        script,
        /errors\.value\.memo \?\? errors\.value\.records \?\? errors\.value\.audience/,
    );
});
