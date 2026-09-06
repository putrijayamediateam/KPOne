import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const billingPath = 'resources/js/pages/Billing/Show.vue';
const workPath = 'resources/js/pages/FinancialWork/Index.vue';
const billing = read(billingPath);
const work = read(workPath);

const assertTemplateCompiles = (path, source) => {
    const descriptor = parse(source, { filename: path }).descriptor;
    const result = compileTemplate({
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        filename: path,
        source: descriptor.template?.content ?? '',
    });

    assert.deepEqual(result.errors, []);
};

test('Panel and Finance work queues compile with operational table and action semantics', () => {
    assertTemplateCompiles(workPath, work);
    assert.match(work, /<OperationalTable/);
    assert.match(work, /<StatusBadge :status="row\.status"/);
    assert.match(work, /<ActionLink[\s\S]*?>\s*Review\s*<\/ActionLink>/);
    assert.match(work, /variant="secondary"/);
    assert.doesNotMatch(work, /<a[\s>]/);
    assert.doesNotMatch(work, /underline/);
    assert.match(work, /panel\.value \? 7 : 11/);
    assert.match(work, /text-right[^\n]*tabular-nums/);
});

test('Billing and Completed Visit compile with distinct read-only and governed settlement presentation', () => {
    assertTemplateCompiles(billingPath, billing);
    assert.match(billing, /Read-only Visit record/);
    assert.match(billing, /Outstanding Receivable/);
    assert.match(billing, /Settle Outstanding Balance/);
    assert.match(
        billing,
        /This governed settlement is separate from the\s+completed Visit record\./,
    );
    assert.match(billing, /isCompleted\s*\?\s*'Settle Outstanding Balance'/);
    assert.match(billing, /v-if="billing\.can\.complete"/);
    assert.doesNotMatch(billing, /<select[\s>]/);
});

test('Billing hierarchy uses shared controls without raw underlined operational navigation', () => {
    assert.match(
        billing,
        /<ActionLink href="\/registration" variant="secondary">/,
    );
    assert.match(billing, /<OperationalTabs/);
    assert.match(billing, /<OperationalTable/);
    assert.match(billing, /<OperationalSelect[\s\S]*label="Payment method"/);
    assert.match(billing, /<OperationalSelect[\s\S]*label="Verified Panel"/);
    assert.match(billing, /<StatusBadge :status="billing\.invoice\.status"/);
    assert.match(billing, /variant="destructive"/);
    assert.doesNotMatch(billing, /underline/);
});
