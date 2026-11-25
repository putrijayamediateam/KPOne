import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const phrases = readFileSync(
    'resources/js/pages/ConsultationSettings/Phrases.vue',
    'utf8',
).replace(/\s+/g, ' ');
const settings = readFileSync(
    'resources/js/pages/ConsultationSettings/Index.vue',
    'utf8',
).replace(/\s+/g, ' ');
const review = readFileSync(
    'resources/js/pages/SuggestionReview/Index.vue',
    'utf8',
).replace(/\s+/g, ' ');

test('The phrases page asks for both languages, limits each to 60 characters and sends versions and retry keys', () => {
    assert.match(phrases, /const MAX = 60/);
    assert.match(phrases, /:maxlength="MAX"/);
    assert.match(phrases, /Bahasa Melayu/);
    assert.match(phrases, /label_en/);
    assert.match(phrases, /label_ms/);
    assert.match(phrases, /idempotency_key: key/);
    assert.match(phrases, /lock_version: phrase\.lockVersion/);
    assert.match(phrases, /expected_branch_id: props\.branchId/);
    assert.match(phrases, /Waiting for doctor review/);
    assert.match(phrases, /A changed wording goes back to a doctor for review/);
    assert.match(phrases, /Saved orders keep their text/);
    assert.doesNotMatch(phrases, /localStorage|sessionStorage|Delete|v-html/);
});

test('The phrases page names every action and keeps one label size', () => {
    assert.match(phrases, /:aria-label="`Edit \$\{phrase\.labelEn\}`"/);
    assert.match(
        phrases,
        /:aria-label="`\$\{phrase\.active \? 'Archive' : 'Restore'\} \$\{phrase\.labelEn\}`"/,
    );
    assert.match(phrases, /Add phrase/);
    assert.match(phrases, /aria-current="page"/);
});

test('Consultation settings links to the phrases page, and the review page approves phrases too', () => {
    assert.match(settings, /href="\/consultation-settings\/phrases"/);
    assert.match(review, /approve\(phrase, 'phrases'\)/);
    assert.match(review, /approve\(draft, 'diagnoses'\)/);
    assert.match(review, /phrase\.enteredLabel/);
    assert.match(review, /\{\{ waiting \}\} waiting/);
});
