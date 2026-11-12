import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/StockHealth.vue',
        import.meta.url,
    ),
    'utf8',
);
const index = readFileSync(
    new URL('../../resources/js/pages/Inventory/Index.vue', import.meta.url),
    'utf8',
);

test('STK-33: the page explains each measure and says there are no money figures', () => {
    assert.match(
        page,
        /<strong>Days on hand<\/strong> is\s+the usable stock now ÷ the average use a day\./,
    );
    assert.match(
        page,
        /<strong>Turnover<\/strong> is used ÷ the average stock held\./,
    );
    assert.match(
        page,
        /No money figures: stock value waits for the clinic's cost\s+policy\./,
    );
    assert.doesNotMatch(page, /formatRinggit|RM /);
});

test('STK-33: it only reads, with a period choice and no form that writes', () => {
    assert.match(page, /router\.get\(\s+'\/inventory\/stock-health'/);
    assert.doesNotMatch(page, /router\.(post|put|patch|delete)|useForm/);
    assert.match(page, /v-for="period in health\.periods"/);
    assert.match(page, /No use in this period/);
});

test('STK-33: the Inventory page links to Stock health', () => {
    assert.match(
        index,
        /href="\/inventory\/stock-health"[\s\S]*?>Stock health<\/ActionLink/,
    );
});
