// PRANK: presentation joke only. Delete with the prank branch.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the Express Dispense button only shows when the server says the prank is on', () => {
    const show = read('resources/js/pages/Dispensary/Show.vue');
    assert.ok(show.includes('v-if="prank"'));
    assert.ok(show.includes('/prank/${dispensary.publicId}/pharmamax'));
    assert.ok(show.includes('Express Dispense'));
    assert.ok(show.includes('Recommended'));
    assert.ok(read('config/prank.php').includes("env('PRANK_ENABLED', false)"));
});

test('the fake PharmaMax page stalls at 99% for 20 seconds and offers a way back', () => {
    const page = read('resources/js/pages/Prank/PharmaMax.vue');
    assert.ok(page.includes('PharmaMax 2003 Ultra Edition'));
    assert.ok(page.includes('Math.min(99,'));
    assert.ok(page.includes('26_000'));
    assert.ok(page.includes('CLICK HERE TO SPEED UP YOUR DOWNLOAD'));
    assert.ok(page.includes('Back to real dispensary'));
    assert.ok(page.includes('Comic Sans'));
});

test('the fake error counts down, then the reveal has a big way back', () => {
    const error = read('resources/js/pages/Prank/DelightfulError.vue');
    assert.ok(error.includes('KPOne has experienced a delightful error'));
    assert.ok(error.includes('ref(10)'));
    assert.ok(error.includes('Back to real dispensary'));
    const reveal = read('resources/js/pages/Prank/Reveal.vue');
    assert.ok(reveal.includes('Its a PRANKKKKK!!!!!'));
    assert.ok(reveal.includes('Back to real dispensary'));
});
