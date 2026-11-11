import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-10a letters (HR-PLAN 20.3; owner, 2026-11-10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Letters.vue',
    letter: 'resources/js/pages/HR/Letter.vue',
    records: 'resources/js/pages/HR/Records.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;
const script = (key) => parse(source[key]).descriptor.scriptSetup.content;

test('the new pages and the changed page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('issuing needs a server preview first, then a confirm; nothing is sent', () => {
    const page = template('page');
    const code = script('page');
    assert.match(code, /fetch\('\/hr\/letters\/preview'/);
    assert.match(
        page,
        /:disabled="preview\.text === null \|\| issue\.processing"/,
    );
    assert.match(page, /Preview the letter first\./);
    assert.match(page, /@submit\.prevent="confirmIssue = true"/);
    assert.match(code, /expected_template_version: preview\.version/);
    // A change to any choice clears the preview.
    assert.match(
        code,
        /watch\(\s*\(\) => \[issue\.worker, issue\.template, issue\.language, issue\.details\]/,
    );
    // No sending of any kind: no messages route is called from this page.
    assert.doesNotMatch(code, /\/hr\/messages|mailto:|wa\.me/);
});

test('void is destructive with a reason; given and retire confirm; only allowed staff files are offered', () => {
    const page = template('page');
    assert.match(page, /title="Void this letter\?"[\s\S]{0,400}destructive/);
    assert.match(page, /labelledby="void-reason-label"/);
    assert.match(page, /title="Record when it was given"/);
    assert.match(page, /cancel-label="Keep template"/);
    assert.match(script('page'), /\.filter\(\(w\) => w\.refusal === null\)/);
});

test('templates list the fields KPOne fills and say KPOne ships no wording', () => {
    const page = template('page');
    assert.match(page, /KPOne ships no letter wording\./);
    assert.match(page, /Fields KPOne fills: \{\{ fields \}\}/);
    assert.match(page, /\{details\} is only for warning and show-cause/);
});

test('the printed letter is a frozen copy, white on paper, with status banners and signature lines', () => {
    const letter = template('letter');
    assert.match(letter, /print:bg-white print:text-black/);
    assert.match(letter, /data-test="letter-status-banner"/);
    assert.match(script('letter'), /REPLACED: this letter was replaced/);
    assert.match(script('letter'), /VOID: this letter was voided/);
    assert.match(script('letter'), /Tandatangan dan cop klinik/);
    assert.match(letter, /class="print:hidden"|print:hidden/);
    assert.match(read('resources/js/app.ts'), /case name === 'HR\/Letter':/);
});

test('My HR details links to your letters', () => {
    assert.match(template('records'), /v-if="lettersMine"/);
    assert.match(template('records'), /href="\/hr\/letters"/);
    assert.match(source.records, /lettersMine\?: number;/);
});

test('Daniel round 1: given state, the "…" menu below 1024 px, the closing line, 30 mm stamp space, a real page margin', () => {
    const page = template('page');
    assert.match(page, /'Waiting to be given'/);
    assert.match(page, /data-test="letter-given-state"/);
    assert.match(page, /class="hidden lg:inline-flex"/);
    assert.match(
        page,
        /variant="outline"\s+size="icon"\s+class="min-h-11 min-w-11 lg:hidden"/,
    );
    assert.match(
        page,
        /:aria-label="`More actions for \$\{letter\.reference\}`"/,
    );
    assert.match(
        page,
        /variant="destructive"[\s\S]{0,120}@select="askVoid\(letter\)"/,
    );
    assert.match(page, /for="tpl-title-bm">Malay title \*/);
    const letter = template('letter');
    assert.match(letter, /data-test="letter-closing"/);
    assert.match(script('letter'), /closing: 'Yang benar,'/);
    assert.match(letter, /h-\[30mm\]/);
    assert.match(letter, /max-w-\[210mm\]/);
    assert.match(letter, /min-h-screen/);
    // @page sits outside @media print, so the 15 mm margin always applies to printed pages.
    assert.match(
        source.letter,
        /<style>\s*\/\*[^*]*\*\/\s*@page \{\s*size: A4;\s*margin: 15mm;/,
    );
});
