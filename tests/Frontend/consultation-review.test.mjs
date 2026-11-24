import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Item 4 (owner decision P4): the Director's read-only consultation. Nothing may look editable, the shared banner sits
// first with Back as its only control, and the entry link is a plain outline link (no prefetch).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/Clinical/Review.vue');
const visit = read('resources/js/pages/Registration/Show.vue');
const types = read('resources/js/types/visit.ts');
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

test('the shared banner sits first and Back is its only control', () => {
    const body = template(page);
    assert.ok(body.indexOf('<ReadOnlyBanner>') < body.indexOf('<PageHeader'));
    assert.match(
        body,
        /<ReadOnlyBanner>\s*<a[\s\S]*?data-testid="review-back"[\s\S]*?Back to visit<\/a\s*>\s*<\/ReadOnlyBanner>/,
    );
});

test('the sections come in the agreed order', () => {
    const body = template(page);
    const order = [
        'review-consultation',
        'review-allergies',
        'review-vitals',
        'review-note',
        'review-diagnoses',
        'review-medicines',
        'review-services',
    ].map((id) => body.indexOf(`aria-labelledby="${id}"`));
    assert.ok(order.every((index) => index > 0));
    assert.deepEqual(
        [...order].sort((a, b) => a - b),
        order,
    );
});

test('the visit page has a Consultation card with a plain outline link, no prefetch, and the type has the field', () => {
    assert.match(visit, /v-if="visit\.consultationReview"/);
    assert.match(
        visit,
        /<a\s+:href="visit\.consultationReview\.url"[\s\S]*?data-testid="consultation-review-link"[\s\S]*?Open consultation \(read only\)<\/a\s*>/,
    );
    assert.ok(!/<Link[^>]*consultationReview/.test(visit), 'a plain link');
    assert.match(
        types,
        /consultationReview: \{ url: string; status: string \} \| null;/,
    );
});

test('the Consultation card sits above the Dispensary card (consultation first)', () => {
    assert.ok(
        visit.indexOf('data-testid="consultation-review-card"') > 0 &&
            visit.indexOf('data-testid="consultation-review-card"') <
                visit.indexOf('data-testid="dispensary-review-card"'),
    );
});

test('orders show labelled fields and withdrawn lines sit in a collapsed group with a count', () => {
    for (const label of [
        'Quantity',
        'Dosage',
        'Frequency',
        'Duration',
        'Route',
        'Instruction',
    ]) {
        assert.ok(
            template(page).includes(`>${label}</dt`) ||
                template(page).includes(`${label}\n`),
            label,
        );
    }

    assert.match(
        template(page),
        /<details[\s\S]*?Withdrawn lines \(\{\{ withdrawnCount \}\}\)/,
    );
    assert.ok(!template(page).includes('<details open'));
    assert.match(page, /No clinical note recorded\./);
    assert.match(page, /Allergy profile on record/);
});
