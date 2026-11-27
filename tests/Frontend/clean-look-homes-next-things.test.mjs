import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const day = readFileSync('resources/js/pages/MyDay/Index.vue', 'utf8');
const next = readFileSync('resources/js/components/spaces/NextThings.vue', 'utf8');

test('My day shows Your next things first, from the page prop, polled from my-day/next', () => {
    assert.match(day, /<NextThings :next="next" \/>/);
    assert.ok(day.indexOf('<NextThings') < day.indexOf('data-test="my-day-waiting"'));
    assert.match(next, /requestJson<NextBlock>\('\/my-day\/next'/);
    assert.match(next, /data-test="my-day-next"/);
});

test('the home reuses the shared red-zone alert and builds none of its own', () => {
    assert.match(day, /RedZoneAlertBanner/);
    assert.match(day, /queue\.call\.own/);
});

test('the next things card uses tokens only, with no hard-coded colours', () => {
    assert.doesNotMatch(next, /#[0-9a-fA-F]{3,6}\b|bg-pink|text-pink|rose-|brand/);
});
