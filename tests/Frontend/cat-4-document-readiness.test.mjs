import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// CAT-4 (owner decision 2026-11-05): a read-only document readiness card on Business details, with Daniel's
// design review points pinned.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/BusinessDetails/Index.vue');
const template = page.slice(page.indexOf('<template>'));
const service = read(
    'app/Domain/Organisation/BusinessDetails/DocumentReadinessService.php',
);

test('the card sits at the top of Business details, under the one h1, with its own h2', () => {
    assert.equal((template.match(/<h1/g) ?? []).length, 1);
    const card = template.indexOf('data-testid="document-readiness"');
    assert.ok(card > template.indexOf('<h1'));
    assert.ok(card < template.indexOf('<form'));
    assert.match(
        template,
        /aria-labelledby="document-readiness-heading"[\s\S]*<h2 id="document-readiness-heading"[^>]*>\s*Document readiness/,
    );
});

test('"Letters blocked" names the letters, and a missing footer is a warning', () => {
    assert.match(
        page,
        /`Letters blocked: \$\{row\.blockedLetters\.join\(', '\)\}`/,
    );
    assert.match(page, /'Needs footer'/);
    assert.match(
        page,
        /row\.status === 'letters_blocked'\s*\? 'danger'\s*: row\.status === 'needs_footer'\s*\? 'warning'\s*: 'success'/,
    );
    assert.match(service, /\? \['MC', 'referral', 'attendance'\]/);
    assert.match(service, /\? \['referral', 'attendance'\] : \[\]/);
    assert.match(
        service,
        /'status' => \$blockedLetters !== \[\] \? 'letters_blocked' : \(\$hasFooter \? 'ready' : 'needs_footer'\)/,
    );
});

test('at most two missing items, then "+N more" with the full list', () => {
    assert.match(
        page,
        /const shownMissing = \(row: Readiness\) => row\.missing\.slice\(0, 2\);/,
    );
    assert.match(
        page,
        /const hiddenMissing = \(row: Readiness\) => row\.missing\.slice\(2\);/,
    );
    assert.match(
        template,
        /\+\{\{\s*hiddenMissing\(row\)\.length\s*\}\}\s*more/,
    );
    assert.match(template, /`All missing: \$\{row\.missing\.join\(', '\)\}`/);
});

test('the active branch can jump to the fix; other branches say how to switch', () => {
    assert.match(template, /\(this branch\)/);
    assert.match(template, /:aria-label="`Fix below: \$\{row\.name\}`"/);
    assert.match(
        template,
        /Switch to \{\{ row\.name \}\} in the header to\s+edit\./,
    );

    for (const anchor of [
        '#print-footer-heading',
        '#logo',
        '#clinic-details-heading',
    ]) {
        assert.ok(page.includes(`'${anchor}'`), `no target ${anchor}`);
    }
});

test('no logo is fine, the empty state uses the shared component, and the page writes nothing new', () => {
    assert.match(template, /No logo \(optional\)/);
    assert.match(template, /title="No active branches\."/);
    assert.match(page, /\{ readiness: \(\) => \[\] \}/);
    assert.doesNotMatch(
        service,
        /->create\(|->save\(|->update\(|->delete\(|lockForUpdate|Storage::disk\('local'\)->put/,
    );
});

test('rows stack on a phone and line up from 768 px without widening the page', () => {
    assert.match(
        template,
        /class="grid min-w-0 gap-1 rounded-md border p-3 md:grid-cols-\[minmax\(0,14rem\)_minmax\(0,1fr\)_auto\] md:items-center md:gap-3"/,
    );
});
