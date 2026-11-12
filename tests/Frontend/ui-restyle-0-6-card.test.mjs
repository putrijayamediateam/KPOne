import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('0.6: Card is a 14px hairline with no shadow', () => {
    const card = read('resources/js/components/ui/card/Card.vue');
    assert.match(card, /rounded-\[14px\]/);
    assert.doesNotMatch(card, /shadow/);
});
test('0.6: StatCard and ListRow follow the sizes', () => {
    assert.match(
        read('resources/js/components/ui/card/StatCard.vue'),
        /text-3xl/,
    );
    assert.match(
        read('resources/js/components/ui/card/ListRow.vue'),
        /min-h-14/,
    );
});
