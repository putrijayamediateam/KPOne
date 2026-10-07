import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Whitespace is collapsed so Prettier's line wrapping cannot break an assertion.
const review = readFileSync(
    new URL(
        '../../resources/js/pages/RegistrationReview/Show.vue',
        import.meta.url,
    ),
    'utf8',
).replace(/\s+/g, ' ');

test('the review page keeps both forms on the intake current lock version', () => {
    const watcher = review.match(
        /watch\( \(\) => props\.intake\.lockVersion, \(lockVersion\) => \{(.*?)\}, \);/,
    );

    assert.ok(watcher, 'a watcher on props.intake.lockVersion is missing');
    assert.ok(watcher[1].includes('correction.lock_version = lockVersion;'));
    assert.ok(watcher[1].includes('acceptance.lock_version = lockVersion;'));
});

test('the forms still start from the intake lock version and the sync touches nothing else', () => {
    assert.ok(review.includes('lock_version: props.intake.lockVersion'));
    const watcher = review.match(
        /watch\( \(\) => props\.intake\.lockVersion, \(lockVersion\) => \{(.*?)\}, \);/,
    );
    assert.ok(watcher);
    assert.ok(!watcher[1].includes('reset('));
    assert.ok(!watcher[1].includes('acceptance.assigned_doctor_user_id'));
});
