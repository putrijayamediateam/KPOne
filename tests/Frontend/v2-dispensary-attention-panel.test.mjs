import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// V2 doctor's Dispensary attention panel (owner, 2026-11-16; Daniel's OK). Classic panel untouched.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read(
    'resources/js/pages/Clinical/Partials/DispensaryAttentionPanel.vue',
);
const v2 = read(
    'resources/js/pages/Dispensary/V2/Partials/DispensaryAttentionPanel.vue',
);
const page = read('resources/js/pages/Clinical/V2/Show.vue');
const classicPage = read('resources/js/pages/Clinical/Show.vue');
const template = (source) => source.slice(source.indexOf('<template>'));

test('the V2 consultation page uses the V2 panel without the dense wrapper; Classic keeps its panel', () => {
    assert.match(
        page,
        /import DispensaryAttentionPanel from '@\/pages\/Dispensary\/V2\/Partials\/DispensaryAttentionPanel\.vue';/,
    );
    assert.match(
        page,
        /<\/section>\s*<DispensaryAttentionPanel\s+:items="clinical\.dispensaryAttention"\s*\/>/,
    );
    assert.match(
        classicPage,
        /import DispensaryAttentionPanel from '@\/pages\/Clinical\/Partials\/DispensaryAttentionPanel\.vue';/,
    );
    assert.ok(!/\/V2\//.test(classic));
});

test('same props, POST, lock versions and refusal fallback as Classic', () => {
    for (const fragment of [
        'defineProps<{ items: DoctorDispensaryAttention[] }>()',
        'item.acknowledgeUrl,',
        'case_lock_version: item.caseLockVersion,',
        'item_lock_version: item.itemLockVersion,',
        'preserveScroll: true,',
        'Object.values(errors)[0] ??',
        "'This Dispensary proposal changed. Refresh and review it again.'",
        'onFinish: () => (processing.value = null),',
        'Dispensary attention required',
        "item.status === 'awaiting_acknowledgement'",
        'Acknowledge partial fulfilment',
    ]) {
        assert.ok(classic.includes(fragment), `Classic ${fragment}`);
        assert.ok(v2.includes(fragment), `V2 ${fragment}`);
    }
});

test('nothing renders when there is nothing to show (no empty wrapper)', () => {
    assert.match(template(v2), /<section\s+v-if="items\.length"/);
});

test('rows keep a fixed order (waiting first, then Classic order); new rows go to the end (Hakim)', () => {
    assert.match(
        v2,
        /\.\.\.items\.filter\(\(i\) => i\.status === 'awaiting_acknowledgement'\),\s*\.\.\.items\.filter\(\(i\) => i\.status !== 'awaiting_acknowledgement'\),/,
    );
    assert.match(
        v2,
        /const order = ref<string\[\]>\(\s*waitingFirst\(props\.items\)/,
    );
    assert.match(v2, /\.filter\(\(id\) => !known\.has\(id\)\)/);
});

test("collapsed: the first 5 plus acted-on rows; the toggle names hidden review rows (owner's middle way)", () => {
    assert.match(
        v2,
        /index < visibleLimit \|\| pinned\.value\.has\(item\.exceptionPublicId\)/,
    );
    assert.match(
        v2,
        /showAllLabel\(ordered\.value\.length, hiddenReview\.value\)/,
    );
    assert.match(v2, /showAll\.value = true;/);
    // Mira: a real button right under the list, 44 px, aria-expanded and aria-controls, its text is its name.
    assert.match(template(v2), /<\/ul>\s*<div\s+v-if="hiddenRows\.length > 0"/);
    const toggle = template(v2).slice(
        template(v2).indexOf('data-testid="v2-dispensary-attention-show-all"') -
            400,
        template(v2).indexOf("{{ showAll ? 'Show fewer' : showAllText }}"),
    );
    assert.match(toggle, /:aria-expanded="showAll"/);
    assert.match(toggle, /aria-controls="dispensary-attention-rows"/);
    assert.match(toggle, /'lg:h-11!'/);
    assert.ok(!/aria-label/.test(toggle), 'the visible text is the name');
});

test('the toggle and heading words (Mira): 7 review rows collapsed reads "2 more need review"', async () => {
    const { showAllLabel, needReviewLabel, visibleLimit } =
        await import('../../resources/js/pages/Dispensary/V2/Partials/attention-panel.ts');

    assert.equal(visibleLimit, 5);
    assert.equal(
        showAllLabel(7, 7 - visibleLimit),
        'Show all 7 · 2 more need review',
    );
    assert.equal(showAllLabel(6, 1), 'Show all 6 · 1 more needs review');
    assert.equal(showAllLabel(7, 0), 'Show all 7');
    assert.equal(needReviewLabel(6), '6 need review');
    assert.equal(needReviewLabel(1), '1 needs review');
    assert.match(
        template(v2),
        /<V2Chip\s+v-if="waitingCount > 0"\s+tone="warning"\s+:label="needReviewLabel\(waitingCount\)"/,
    );
});

test('the acknowledge request is a verbatim copy of Classic (Mira)', () => {
    const call = (source) => {
        const start = source.indexOf('router.post(');

        return source.slice(
            start,
            source.indexOf('preserveScroll: true,', start),
        );
    };

    assert.ok(call(classic).length > 0);
    assert.equal(call(v2), call(classic));

    for (const fragment of [
        'processing.value = item.exceptionPublicId;',
        ':disabled="processing === item.exceptionPublicId"',
    ]) {
        assert.ok(classic.includes(fragment), `Classic ${fragment}`);
        assert.ok(v2.includes(fragment), `V2 ${fragment}`);
    }
});

test('the three states differ in words, quantities read as unit words', () => {
    for (const label of [
        'label="Acknowledged"',
        'label="Updated by Dispensary — review again"',
        'label="Dispensary review required"',
    ]) {
        assert.ok(template(v2).includes(label), label);
    }

    assert.match(v2, /quantityWords\(item\.quantityOrdered, item\.unit\)/);
    assert.match(v2, /quantityWords\(item\.proposedQuantity, item\.unit\)/);
});

test('no filled button; the outline button names the medicine and is full width at 44 px on phones', () => {
    assert.ok(!/bg-primary/.test(v2));
    assert.match(
        template(v2),
        /:aria-label="`Acknowledge partial fulfilment for \$\{medicine\(item\)\}`"/,
    );
    assert.match(v2, /const outlineButton =\s*'inline-flex h-11 /);
    assert.match(v2, /max-sm:w-full lg:h-9/);
    assert.match(
        template(v2),
        /:aria-busy="processing === item\.exceptionPublicId"/,
    );
});

test('success announces politely and keeps focus on the row; a refusal shows under its row and refocuses its button', () => {
    assert.match(template(v2), /role="status" aria-live="polite"/);
    assert.match(
        v2,
        /onSuccess: \(\) => \{\s*status\.value = `Acknowledged partial fulfilment for \$\{medicine\(item\)\}\.`;\s*focusRow\(item, false\);/,
    );
    assert.match(v2, /focusRow\(item, true\);/);
    assert.match(
        template(v2),
        /v-if="errorFor\?\.id === item\.exceptionPublicId"[\s\S]*?role="alert"/,
    );
    assert.ok(!/toast/i.test(v2), 'no toast');
});

test('the warning is a left accent and an icon on a neutral card, with the warning token in dark mode', () => {
    assert.match(
        template(v2),
        /border-l-4 border-l-warning bg-card dark:border-foreground\/20 dark:border-l-warning/,
    );
    assert.match(template(v2), /text-warning-strong/);
    assert.ok(!/amber|bg-warning-soft/.test(v2), 'no pale surface');
});
