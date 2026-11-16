import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// V2 drug label page, the screen part only (owner, 2026-11-16; Daniel's OK). The printed label must stay pixel-identical,
// so the script, the label markup and the label CSS are Classic's.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Dispensary/Labels.vue');
const v2 = read('resources/js/pages/Dispensary/V2/Labels.vue');
const squash = (text) => text.replace(/\s+/g, ' ').trim();
const between = (source, start, end) => {
    const from = source.indexOf(start);
    assert.ok(from >= 0, `missing ${start}`);

    return source.slice(from, source.indexOf(end, from) + end.length);
};

test('registered as a V2 page; Classic imports nothing from V2; no app.ts change needed', () => {
    assert.match(read('config/v2-pages.php'), /'Dispensary\/Labels',/);
    assert.ok(!/\/V2\//.test(classic));
    assert.match(v2, /defineOptions\(\{ layout: BareLayout \}\);/);
    assert.match(
        read('resources/js/pages/Dispensary/V2/Partials/BareLayout.vue'),
        /<template>\s*<slot \/>\s*<\/template>/,
    );
});

test("the script is Classic's: props, print check, retire logic and window.print()", () => {
    const body = (source) =>
        between(source, 'const props = defineProps<{', 'window.print();');

    assert.equal(squash(body(v2)), squash(body(classic)));
    assert.equal(
        squash(
            between(v2, '} catch {', '</script>').split('// V2 (Daniel)')[0],
        ),
        squash(
            between(classic, '} catch {', '</script>').replace('</script>', ''),
        ),
    );
});

test('the label markup and the label CSS are byte for byte Classic (after whitespace)', () => {
    const labels = (source) =>
        between(
            source,
            '<section v-if="!labels.blocked.length" aria-label="Medicine labels">',
            '</section>',
        );

    assert.equal(squash(labels(v2)), squash(labels(classic)));
    const css = (source) =>
        source.slice(source.indexOf("/* DSP-10: the clinic's landscape label"));

    assert.equal(squash(css(v2)), squash(css(classic)));
    assert.match(
        v2,
        /`@page \{ size: \$\{labels\.pageSize\.width\}mm \$\{labels\.pageSize\.height\}mm; margin: 0; \}`/,
    );
});

test('every screen-only block is hidden in print; the preview sits below the card', () => {
    const template = v2.slice(v2.indexOf('<template>'));

    assert.match(template, /<section\s+class="print-toolbar /);
    assert.ok(
        template.indexOf('data-testid="label-screen-card"') <
            template.indexOf('aria-label="Medicine labels"'),
    );
    assert.match(v2, /@media print \{\s*\.print-toolbar \{\s*display: none;/);
});

test('a real Back to dispensing link to the case page', () => {
    assert.match(v2, /:href="caseUrl"/);
    assert.match(v2, /Back to\s+dispensing/);
    assert.match(v2, /\/\^\\\/dispensary\\\/\[0-9a-f-\]\{36\}\/i/);
});

test('the print button is truly off with its own reason in each of the four states, and busy says so', () => {
    for (const reason of [
        'You switched branch. Reload this visit to review the current authorised record.',
        'The saved record or access changed. Reload and review before printing.',
        'Printing is blocked: no labels in this selection will print. See the reasons above.',
        'There are no labels to print in this selection.',
    ]) {
        assert.ok(v2.includes(reason), reason);
    }

    assert.match(
        v2,
        /:aria-disabled="busy \|\| offReason \? 'true' : undefined"/,
    );
    assert.match(v2, /:aria-busy="busy"/);
    assert.match(v2, /Checking saved record…/);
    assert.ok(
        !/:disabled=/.test(v2),
        'never the disabled attribute, so it stays focusable',
    );
});

test('notes above the button in Daniel’s order; one alert, focused when it appears', () => {
    const card = between(
        v2,
        'data-testid="label-screen-card"',
        'data-testid="label-print"',
    );
    const order = [
        'data-testid="label-guidance"',
        'data-testid="label-reprint-note"',
        'data-testid="label-batch-notes"',
        'data-testid="label-alert"',
    ].map((marker) => card.indexOf(marker));

    assert.deepEqual(
        [...order].sort((a, b) => a - b),
        order,
    );
    assert.ok(order.every((index) => index >= 0));
    assert.match(
        v2,
        /nextTick\(\(\) => setTimeout\(\(\) => alertRef\.value\?\.focus\(\), 0\)\);/,
    );
});

test('44 px below 1024 only', () => {
    assert.match(v2, /const filledButton =\s*'inline-flex h-11 /);
    assert.match(v2, /const outlineButton =\s*'inline-flex h-11 /);
    assert.equal((v2.match(/lg:h-9/g) ?? []).length, 2);
});
