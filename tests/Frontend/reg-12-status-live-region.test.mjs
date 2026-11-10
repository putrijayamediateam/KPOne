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
const { statusAnnouncement } =
    await import('../../resources/js/lib/public-intake-announcement.ts');
const waiting = 'Pendaftaran disahkan. Sila tunggu nombor anda dipanggil.';

test('the card is not a live region; one narrow polite region announces the key facts', () => {
    assert.equal((template.match(/aria-live=/g) ?? []).length, 1);
    assert.match(template, /role="status"/);
    assert.match(template, /aria-live="polite"/);
    assert.match(template, /aria-atomic="true"/);
    assert.match(template, /data-test="status-announcer"/);
    assert.doesNotMatch(template, /<section[^>]*aria-live/);
});

test('the announcer stays empty until a change, so the page is not read twice on load', () => {
    assert.match(status, /const announced = ref\(''\)/);
    assert.match(status, /announced\.value = text/);
});

test('a call to the room is still announced by its own alert', () => {
    assert.match(template, /v-if="called"[\s\S]{0,200}role="alert"/);
});

test('the announcement holds the message, the queue number and the patients ahead', () => {
    assert.equal(
        statusAnnouncement(
            {
                message: 'Pendaftaran dihantar.',
                queueNumber: null,
                ahead: null,
            },
            false,
        ),
        'Pendaftaran dihantar.',
    );
    assert.equal(
        statusAnnouncement(
            { message: waiting, queueNumber: 'A-012', ahead: 4 },
            false,
        ),
        `${waiting} Nombor queue anda A-012. Pesakit di hadapan anda: 4.`,
    );
    assert.equal(
        statusAnnouncement(
            { message: waiting, queueNumber: 'A-012', ahead: 0 },
            false,
        ),
        `${waiting} Nombor queue anda A-012. Anda seterusnya dalam giliran.`,
    );
});

test('the announcement changes when the patients ahead change and not otherwise', () => {
    const at = (ahead) =>
        statusAnnouncement(
            { message: waiting, queueNumber: 'A-012', ahead },
            false,
        );

    assert.notEqual(at(5), at(4));
    assert.equal(at(4), at(4));
});

test('nothing is announced here once the patient is called, so the alert speaks alone', () => {
    assert.equal(
        statusAnnouncement(
            { message: waiting, queueNumber: 'A-012', ahead: null },
            true,
        ),
        null,
    );
    assert.match(status, /if \(text !== null\)/);
});

test('the wait estimate and the other branches are never part of the announcement', () => {
    const lib = readFileSync(
        new URL(
            '../../resources/js/lib/public-intake-announcement.ts',
            import.meta.url,
        ),
        'utf8',
    );

    const code = lib.replace(/\/\*[\s\S]*?\*\//g, '');

    assert.doesNotMatch(code, /waitRange|branches/);
});
