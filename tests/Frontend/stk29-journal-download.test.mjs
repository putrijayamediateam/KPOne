import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL(
        '../../resources/js/pages/Inventory/V2/StockValue.vue',
        import.meta.url,
    ),
    'utf8',
);
const script = source
    .slice(0, source.indexOf('</script>'))
    .replace(/\s+/g, ' ');
const template = source
    .slice(source.indexOf('<template>'))
    .replace(/\s+/g, ' ');

test('STK-29: the journal download shows only for one calendar month, with what the file will hold', () => {
    assert.match(template, /<template v-if="journalMonth">/);
    assert.match(script, /if \(!fullMonth && !toDate\) \{ return null; \}/);
    assert.match(
        script,
        /\$\{fullMonth && t < today \? 'full month' : 'month to date'\}/,
    );
    assert.match(
        template,
        /data-testid="journal-scope" >\{\{ journalMonth\.label \}\}/,
    );
    assert.match(
        template,
        /The journal summary is for one calendar month\. Choose the 1st to the last day of a month\./,
    );
    assert.match(
        template,
        /A management summary only\. No real accounting system receives this file\./,
    );
});

test('STK-29: pressing it prepares once, announces the file and shows a refusal in words', () => {
    assert.match(template, /:disabled="downloading" :aria-busy="downloading"/);
    assert.match(
        template,
        /downloading \? 'Preparing…' : 'Download journal summary \(CSV\)'/,
    );
    assert.match(
        script,
        /if \(downloading\.value \|\| !journalMonth\.value\) \{ return; \}/,
    );
    assert.match(script, /journalDone\.value = `Downloaded \$\{name\}`;/);
    assert.match(
        template,
        /<p role="status" class="sr-only">\{\{ journalDone \}\}<\/p>/,
    );
    assert.match(template, /v-if="journalError" role="alert"/);
    assert.match(
        script,
        /fetch\( ?`\/inventory\/stock-value\/journal\?\$\{query\}`/,
    );
});

test('STK-29: the download is an outline button, so Show stays the only filled one', () => {
    assert.equal((template.match(/bg-primary /g) ?? []).length, 1);
    assert.match(template, /data-testid="journal-button"/);
});
