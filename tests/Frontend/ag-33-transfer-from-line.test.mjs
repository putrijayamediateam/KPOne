import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

for (const [name, path] of [
    ['V2', 'resources/js/pages/Agents/V2/DailySummary.vue'],
    ['Classic', 'resources/js/pages/Agents/DailySummary.vue'],
]) {
    test(`${name} Daily summary: the receiving branch sees a read-only line for a transfer decided at the sending branch`, () => {
        const page = read(path);

        assert.match(page, /transferFrom/);
        assert.match(page, /Transfer suggested from \{\{ sender\.branch \}\}/);
        assert.match(page, /decided\s+there/);
        assert.match(page, /data-test="summary-transfer-from"/);
        // Read only: the line carries no link, button or form.
        const line =
            /<p[^>]*data-test="summary-transfer-from"[\s\S]*?<\/p>/.exec(
                page,
            )?.[0] ?? '';

        assert.ok(line.length > 0);
        assert.doesNotMatch(line, /<Link|<Button|<form|@click/);
    });
}
