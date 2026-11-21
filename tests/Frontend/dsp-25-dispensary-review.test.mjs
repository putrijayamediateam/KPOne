import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DSP-25 (owner decision 4; answers P5, 15 and 16): the Director's read-only dispensary case. Daniel: nothing may look
// editable, the banner is neutral and sits on top, Back is the only control, and the entry link is a plain outline link.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Dispensary/Review.vue');
const banner = read('resources/js/components/ReadOnlyBanner.vue');
const visit = read('resources/js/pages/Registration/Show.vue');
const template = (source) => source.slice(source.indexOf('<template>'));

test('the page renders nothing editable: no button, input, select, textarea, form or Link', () => {
    for (const tag of [
        '<button',
        '<input',
        '<select',
        '<textarea',
        '<form',
        '<Link',
        'router.',
        'useForm',
        'fetch(',
    ]) {
        assert.ok(
            !page.includes(tag),
            `${tag} must not appear on the review page`,
        );
    }

    assert.ok(!/disabled/.test(template(page)), 'nothing is rendered disabled');
});

test('the shared banner is neutral, sits first, and Back is its only control', () => {
    assert.match(banner, /Read only\. Every view is recorded\./);
    assert.match(banner, /bg-muted\/60/);
    assert.ok(!/warning|destructive|amber|red-/.test(banner));
    const body = template(page);
    assert.ok(body.indexOf('<ReadOnlyBanner>') < body.indexOf('<PageHeader'));
    assert.match(
        body,
        /<ReadOnlyBanner>\s*<a[\s\S]*?data-testid="review-back"[\s\S]*?Back to visit<\/a\s*>\s*<\/ReadOnlyBanner>/,
    );
});

test('statuses are a dot and a word; CA changes are chips with a dot and a word', () => {
    assert.match(page, /awaiting_doctor_check: "Waiting for doctor's check"/);
    assert.match(
        template(page),
        /class="size-2 rounded-full bg-foreground\/60"\s+aria-hidden="true"/,
    );

    for (const word of ['Added by CA', 'Edited by CA', 'Removed by CA']) {
        assert.ok(page.includes(word), word);
    }
});

test('the visit page has a Dispensary card under Visit Reason with the status and a plain outline link (no prefetch)', () => {
    const reason = visit.indexOf('<h2 class="font-semibold">Visit Reason</h2>');
    const card = visit.indexOf('data-testid="dispensary-review-card"');

    assert.ok(reason > 0 && card > reason);
    assert.match(
        visit,
        /<a\s+:href="visit\.dispensaryReview\.url"[\s\S]*?>Open dispensary case \(read only\)<\/a/,
    );
    assert.match(
        visit,
        /dispensaryStatusLabel\[visit\.dispensaryReview\.status\]/,
    );
    assert.match(
        read('resources/js/types/visit.ts'),
        /dispensaryReview: \{ url: string; status: string \} \| null;/,
    );
});

test('returns show the unit, the outcome as a dot and a word, and a latest-10 line', () => {
    assert.match(
        page,
        /\{\{ qty\(item\.quantity\) \}\}\s+\{\{ item\.unit \?\? '' \}\}/,
    );
    assert.match(page, /data-testid="review-return-outcome"/);
    assert.match(
        page,
        /Showing the latest \{\{ review\.returns\.length \}\} of/,
    );

    for (const heading of [
        '>Case</h2>',
        '>Allergies</h2>',
        '>Medicines</h2>',
        '>Services</h2>',
        '>Medicine returns</h2>',
    ]) {
        assert.ok(page.includes(heading), heading);
    }
});
