import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const registry = read('config/v2-pages.php');
const v2 = read('resources/js/pages/Inventory/V2/StockValue.vue');
const classic = read('resources/js/pages/Inventory/StockValue.vue');
const script = v2.slice(0, v2.indexOf('<template>'));
const template = v2.slice(v2.indexOf('<template>'));
const flat = template.replace(/\s+/g, ' ');

test('STK-28: Classic and the new layout show the same report from the same props (owner decision 2026-11-19)', () => {
    // The page swap stays registered, so a person with the new layout on still gets the V2 page.
    assert.match(registry, /'Inventory\/StockValue',/);
    // Classic no longer asks the Director to turn on the new layout.
    assert.doesNotMatch(classic, /\/spaces\/preference|Turn on the new layout/);
    // The same props type, the same report route and the same journal download.
    const propsType = (source) =>
        source.slice(
            source.indexOf('type StockRow'),
            source.indexOf('const props'),
        );
    assert.equal(propsType(classic), propsType(v2));
    assert.match(classic, /defineProps<\{ report: Report \}>\(\)/);
    assert.match(classic, /router\.get\(\s*'\/inventory\/stock-value'/);
    assert.equal(
        (classic.match(/\/inventory\/stock-value\/journal/g) ?? []).length,
        1,
    );
    // The banner shows above the summary and on every tab, with an icon, as in V2.
    const classicTemplate = classic.slice(classic.indexOf('<template>'));
    assert.ok(
        (classicTemplate.match(/\{\{ report\.banner \}\}/g) ?? []).length >= 3,
    );
    assert.ok(
        classicTemplate.indexOf('valuation-banner') <
            classicTemplate.indexOf('valuation-summary'),
    );

    // The same four tabs and 25 rows a page; nothing exported beyond the CSV.
    for (const label of [
        'Stock value',
        'Cost and margin',
        'Losses',
        'How this is counted',
    ]) {
        assert.match(classic, new RegExp(`label: '${label}'`));
    }

    assert.match(classic, /const PAGE_SIZE = 25;/);
    assert.doesNotMatch(classic, /\.xlsx|\.pdf/i);
});

test('STK-28: the disclaimer banner shows above the summary and on every tab, with an icon and words', () => {
    assert.ok((template.match(/\{\{ report\.banner \}\}/g) ?? []).length >= 3);
    assert.match(flat, /data-testid="valuation-banner"/);
    assert.ok((template.match(/<TriangleAlert/g) ?? []).length >= 3);
    assert.ok(
        flat.indexOf('valuation-banner') <
            flat.indexOf('valuation-summary-list'),
    );
});

test('STK-28: a plain summary list below 640 px and tiles from 640 px, each figure named with its date', () => {
    assert.match(
        flat,
        /class="flex flex-col divide-y rounded-\[14px\] border sm:hidden" data-testid="valuation-summary-list"/,
    );
    assert.match(
        flat,
        /class="hidden gap-3 sm:grid sm:grid-cols-2 xl:grid-cols-5" data-testid="valuation-summary-tiles"/,
    );
    assert.match(
        script,
        /label: `Stock value on \$\{formatDate\(props\.report\.periodEnd\)\}`/,
    );
});

test('STK-28: money, percent and margin words never rely on colour or a hyphen for a negative figure', () => {
    assert.match(script, /\$\{sen < 0 \? '\\u2212' : ''\}RM\\u00a0/);
    assert.match(
        script,
        /\$\{value < 0 \? '\\u2212' : ''\}\$\{Math\.abs\(value\)\.toFixed\(1\)\}%/,
    );
    assert.match(script, /sen < 0 \? 'Loss' : sen === 0 \? 'No margin' : null/);
    assert.match(script, /status: s\.lossesSen < 0 \? 'Net gain' : undefined/);
    assert.match(
        flat,
        /<V2Chip v-if="row\.includesEstimate" tone="neutral" label="Includes an estimate" \/>/,
    );
    assert.match(
        flat,
        /stock with no cost recorded\. They are marked below and left out of the value\./,
    );
    assert.match(flat, /row\.sen > 0 \? 'Loss' : 'Gain'/);
    assert.match(flat, /lossesTotal > 0 \? 'Net loss' : 'Net gain'/);
    assert.match(
        flat,
        /Unit costs show up to 4 decimals; values are rounded to sen\./,
    );
    assert.match(flat, />No cost recorded<\/span/);
});

test('STK-28: filters need an explicit Show, day-first dates and at most 92 days; Show is the only filled button', () => {
    assert.equal((template.match(/<V2DateField/g) ?? []).length, 2);
    assert.match(
        script,
        /`Choose \$\{props\.report\.filters\.maxDays\} days or fewer\.`/,
    );
    assert.match(flat, /:disabled="!ready \|\| busy"/);
    assert.equal((template.match(/bg-primary /g) ?? []).length, 1);
    assert.match(script, /\{ value: 'all', label: 'All branches' \}/);
    assert.match(flat, /data-testid="valuation-refused"/);
});

test('STK-28: four tabs, "How this is counted" on every tab, 25 rows a page with a total row, and only the STK-29 journal download', () => {
    for (const tab of [
        "value: 'value'",
        "value: 'margin'",
        "value: 'losses'",
        "value: 'counted'",
    ]) {
        assert.ok(script.includes(tab), tab);
    }

    assert.match(
        flat,
        /<V2DisclosureBar v-if="tab !== 'counted'" title="How this is counted"/,
    );
    assert.match(script, /const PAGE_SIZE = 25;/);
    assert.ok((template.match(/<tr data-total>/g) ?? []).length === 3);
    assert.ok((template.match(/cards="xl"/g) ?? []).length === 3);
    assert.equal(
        (v2.match(/\/inventory\/stock-value\/journal/g) ?? []).length,
        1,
    );
    assert.doesNotMatch(v2, /export|\.xlsx|\.pdf/i);
    assert.match(
        script,
        /credit: 'Cost of the returned stock minus the supplier credit note amount\./,
    );
});

test('STK-28: both Inventory homes link the report under Reports and checks, for the Director only', () => {
    const home = read('resources/js/pages/Inventory/V2/Index.vue');
    assert.match(
        home,
        /group\.id === 'reports-and-checks' &&\s*props\.referenceData\.canViewStockValue/,
    );
    assert.match(
        home,
        /href: '\/inventory\/stock-value',\s*label: 'Stock value and cost'/,
    );
    // Classic links it the same way, behind the same server flag (owner decision 2026-11-19).
    assert.match(
        read('resources/js/pages/Inventory/Index.vue'),
        /href="\/inventory\/stock-value"\s+variant="outline"\s+v-if="referenceData\.canViewStockValue"/,
    );
});
