import assert from 'node:assert/strict';
import test from 'node:test';
import {
    greetingFor,
    hourFromAsOf,
    hourInZone,
} from '../../resources/js/lib/greeting.ts';

const KL = 'Asia/Kuala_Lumpur'; // UTC+8, no daylight saving

test('the hour follows the branch time zone, not the device clock', () => {
    // 16:07 UTC is 00:07 the next day in Kuala Lumpur.
    assert.equal(hourInZone(KL, new Date('2026-11-27T16:07:00Z')), 0);
    assert.equal(hourInZone('UTC', new Date('2026-11-27T16:07:00Z')), 16);
});

test('greeting edges in Kuala Lumpur: midnight, noon, 18:00', () => {
    const at = (utc) =>
        greetingFor('Aina Binti Ali', hourInZone(KL, new Date(utc)));

    assert.equal(at('2026-11-27T16:00:00Z'), 'Good morning, Aina'); // 00:00 KL
    assert.equal(at('2026-11-27T16:07:00Z'), 'Good morning, Aina'); // just after KL midnight
    assert.equal(at('2026-11-27T03:59:00Z'), 'Good morning, Aina'); // 11:59 KL
    assert.equal(at('2026-11-27T04:00:00Z'), 'Good afternoon, Aina'); // 12:00 KL
    assert.equal(at('2026-11-27T09:59:00Z'), 'Good afternoon, Aina'); // 17:59 KL
    assert.equal(at('2026-11-27T10:00:00Z'), 'Good evening, Aina'); // 18:00 KL
    assert.equal(at('2026-11-27T15:59:00Z'), 'Good evening, Aina'); // 23:59 KL
});

test('as-of text from the server gives the branch hour', () => {
    assert.equal(hourFromAsOf('12:00 AM'), 0);
    assert.equal(hourFromAsOf('11:47 PM'), 23);
    assert.equal(hourFromAsOf('12:05 PM'), 12);
    assert.equal(hourFromAsOf('9:30 AM'), 9);
    assert.equal(hourFromAsOf('6:00 PM'), 18);
});

test('missing or unusable input falls back instead of failing', () => {
    assert.equal(hourFromAsOf(null), null);
    assert.equal(hourFromAsOf('later'), null);
    assert.equal(hourInZone(null), null);
    assert.equal(hourInZone('Not/AZone'), null);
    assert.match(greetingFor('', null), /^Good (morning|afternoon|evening)$/);
    assert.match(
        greetingFor('Aina', null),
        /^Good (morning|afternoon|evening), Aina$/,
    );
});
