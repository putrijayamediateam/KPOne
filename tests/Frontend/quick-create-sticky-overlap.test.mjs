import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/pages/Registration/Create.vue', import.meta.url), 'utf8');
const template = parse(source).descriptor.template.content;

test('small-screen sticky action occupies its own row before visit fields; desktop keeps its bottom bar', () => {
    const form = template.match(/<form\b[^>]*v-if="visitReady"[^>]*>/)?.[0];
    assert.ok(form, 'visit fields and submit action retain their existing form');

    for (const token of ['max-lg:flex', 'max-lg:flex-col', 'max-lg:gap-3', 'max-lg:space-y-0']) {
        assert.ok(form.includes(token), `small-screen normal-flow row: ${token}`);
    }

    const card = template.match(/<div\s+class="sticky bottom-3 z-10[^"]*"/);
    assert.ok(card, 'desktop retains the existing sticky bottom card');

    for (const token of ['max-lg:order-first', 'max-lg:top-16', 'max-lg:bottom-auto']) {
        assert.ok(card[0].includes(token), `small-screen action row: ${token}`);
    }

    assert.match(form, /@submit\.prevent="submit"/);
    assert.match(template, /class="sticky bottom-3 z-10[^"\n]*p-3[^"\n]*"/);
    assert.match(template, /type="submit"\s+size="lg"/);
});
