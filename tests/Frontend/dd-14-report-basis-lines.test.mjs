import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const flat = (src) => src.replace(/\s+/g, ' ');

const services = [
    'DailySummary',
    'PanelSummary',
    'StockMovement',
    'Transaction',
].map((name) => [
    name,
    read(`app/Domain/Shared/Services/${name}ReportService.php`),
]);

test('DD-14: every branch report explains itself in short labelled lines with a one-sentence summary', () => {
    for (const [name, source] of services) {
        assert.match(source, /public function basisLines\(\): array/, name);
        assert.match(source, /public function basisSummary\(\): string/, name);
        assert.doesNotMatch(source, /public function basis\(\): string/, name);
    }
    const boundary = read('app/Domain/Shared/Services/BranchReportService.php');
    assert.match(boundary, /abstract public function basisLines\(\): array;/);
    assert.match(boundary, /'basisSummary' => \$this->basisSummary\(\)/);
    assert.match(boundary, /'basisLines' => \$this->basisLines\(\)/);
});

test('DD-14: the five pinned phrases stay word for word', () => {
    const text = Object.fromEntries(services);
    assert.match(text.DailySummary, /Total is net receipts only/);
    assert.match(
        text.DailySummary,
        /Panel and Due are not included in Total received/,
    );
    assert.match(
        text.DailySummary,
        /Payment-method filters apply to receipt columns only/,
    );
    assert.match(text.PanelSummary, /first submitted date/);
    assert.match(
        text.StockMovement,
        /Opening \+ received - dispensed \+ adjusted \+ transferred = closing/,
    );
});

test('DD-14: no line repeats its own label, and the Cash or Non-cash setting says older days change', () => {
    const daily = services[0][1];
    assert.match(
        daily,
        /\['label' => null, 'text' => 'Total is net receipts only:/,
    );
    assert.match(daily, /\['label' => null, 'text' => 'Cash closing = /);
    assert.match(daily, /if the setting changes, older days change too/);
    assert.match(services[3][1], /if a setting changes, older days change too/);
});

test('DD-14: each line is its own paragraph with a bold lead word, and the summary sits in the closed row', () => {
    const page = flat(read('resources/js/pages/Reports/BranchReport.vue'));
    assert.match(page, /<p v-for="\(line, i\) in basisLines" :key="i">/);
    assert.match(
        page,
        /<strong class="font-semibold text-foreground" >\{\{ line\.label \}\}:<\/strong >/,
    );
    assert.match(page, /How this report counts<template v-if="basisSummary"/);
    assert.doesNotMatch(page, /whitespace-pre-line/);
});
