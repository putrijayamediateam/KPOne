import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-18 labour cost (Classic only): branch rows and a clinic total, hidden rows in words, no person anywhere.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/HR/LabourCost.vue');
const template = parse(page).descriptor.template.content;

test('the page compiles and has no Version 2 twin', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-18',
            source: template,
            filename: 'LabourCost.vue',
        }).errors,
        [],
    );
    assert.doesNotMatch(read('config/v2-pages.php'), /'HR\/LabourCost'/);
});

test('hidden branches say why, in words', () => {
    assert.match(page, /few: 'Fewer than 3 people: not shown'/);
    assert.match(page, /const NOT_SHOWN = 'Not shown'/);
    assert.match(page, /value \?\? NOT_SHOWN/);
    assert.match(template, /data-test="labour-cost-some-hidden"/);
    assert.match(template, /class="sr-only">\s*\(withheld to protect privacy\)/);
    assert.match(template, /no one person's pay can be worked\s+out/);
    assert.match(template, /data-test="labour-cost-hidden"/);
    assert.match(template, /The clinic total covers fewer than 3 people/);
});

test('the columns are aggregates only and the estimate label stays', () => {
    for (const heading of [
        'People paid',
        'Pay',
        'Employer contributions',
        'Locum pay',
        'Total',
    ]) {
        assert.match(template, new RegExp(`>\\s*${heading}\\s*<`));
    }

    assert.match(template, /label="Estimate"/);
    assert.match(template, /checked against current law/);
    assert.doesNotMatch(template, /worker|staff member|\bname\b/i);
});

test('the month picker reloads the page with the chosen month', () => {
    assert.match(page, /'\/hr\/labour-cost',\s*\{ month: String\(value\) \}/);
});
