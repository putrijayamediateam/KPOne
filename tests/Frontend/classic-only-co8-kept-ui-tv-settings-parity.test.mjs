// Classic only, CO-8: the Classic half of ui-v2-tv-settings-parity, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const classic = readFileSync(
    new URL(
        '../../resources/js/pages/QueueDisplay/Settings.vue',
        import.meta.url,
    ),
    'utf8',
);
const script = (s) => s.slice(s.indexOf('<script'), s.indexOf('</script>'));
const norm = (s) => s.replace(/\s+/g, '');

test('Classic TV settings calls exactly the queue display settings endpoints', () => {
    const urls = [
        ...script(classic).matchAll(
            /`(\/queue-display-settings[^`]*)`|'(\/queue-display-settings[^']*)'/g,
        ),
    ]
        .map((m) => m[1] ?? m[2])
        .sort();

    assert.deepEqual(
        urls,
        [
            '/queue-display-settings',
            '/queue-display-settings',
            '/queue-display-settings/branches/${props.branch.id}',
            '/queue-display-settings/branches/${props.branch.id}/posters',
            '/queue-display-settings/branches/${props.branch.id}/posters/${posterId}/remove',
            '/queue-display-settings/branches/${props.branch.id}/rooms',
            '/queue-display-settings/rooms/${editingId.value}',
            "/queue-display-settings/rooms/${room.id}/${room.isActive ? 'deactivate' : 'activate'}",
        ].sort(),
    );
});

test('Classic TV settings sends the versioned payload keys, empty text as null and the lock versions', () => {
    for (const key of [
        'ticker_text',
        'youtube_url',
        'poster_seconds',
        'call_display_mode',
        'lock_version',
        'sort_order',
        'kind',
        'poster',
    ]) {
        assert.ok(script(classic).includes(key), key);
    }

    for (const piece of [
        'ticker_text: data.ticker_text.trim() || null',
        'youtube_url: data.youtube_url.trim() || null',
        '{ lock_version: room.lockVersion }',
        '{ lock_version: props.settings.lockVersion }',
    ]) {
        assert.ok(norm(script(classic)).includes(norm(piece)), piece);
    }
});

test('Classic TV settings states the privacy level of each call mode', () => {
    for (const text of [
        'No patient name is shown or spoken',
        'Everyone in the waiting room sees and hears part of the name',
        'least private choice',
    ]) {
        assert.ok(classic.includes(text), text);
    }
});
