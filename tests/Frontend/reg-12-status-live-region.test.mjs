import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// REG-12: the patient QR status page announces only the key changes, not the whole card on every poll.
const status = readFileSync(
    new URL(
        '../../resources/js/pages/PublicCheckIn/Status.vue',
        import.meta.url,
    ),
    'utf8',
);
const template = status.slice(status.indexOf('<template>'));
const announcement = status.slice(
    status.indexOf('const announcement = computed'),
    status.indexOf('const announced = ref'),
);

test('the card is not a live region; one narrow polite region announces the key facts', () => {
    assert.equal((template.match(/aria-live=/g) ?? []).length, 1);
    assert.match(
        template,
        /<p\s+class="sr-only"\s+role="status"\s+aria-live="polite"\s+aria-atomic="true"\s+data-test="status-announcer"/,
    );
    assert.doesNotMatch(template, /<section[^>]*aria-live/);
});

test('the announcement holds only the message, the queue number and the patients ahead', () => {
    assert.match(announcement, /props\.status\.message/);
    assert.match(announcement, /props\.status\.queueNumber/);
    assert.match(announcement, /props\.status\.ahead/);
    assert.doesNotMatch(announcement, /waitRange/);
    assert.doesNotMatch(announcement, /branches/);
});

test('the announcer stays empty until a change, so the page is not read twice on load', () => {
    assert.match(status, /const announced = ref\(''\)/);
    assert.match(
        status,
        /watch\(announcement, \(text\) => \{\s*announced\.value = text;\s*\}\)/,
    );
});

test('a call to the room is still announced by its own alert', () => {
    assert.match(template, /v-if="called"[\s\S]{0,200}role="alert"/);
});
