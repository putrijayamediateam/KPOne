import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const settings = readFileSync(
    'resources/js/pages/ConsultationSettings/Index.vue',
    'utf8',
).replace(/\s+/g, ' ');
const review = readFileSync(
    'resources/js/pages/SuggestionReview/Index.vue',
    'utf8',
).replace(/\s+/g, ' ');
const service = readFileSync(
    'app/Domain/Clinical/Services/DiagnosisPresetSettingsService.php',
    'utf8',
);

test('The settings page takes an optional ICD-10 code and says a change goes back for review', () => {
    assert.match(settings, /ICD-10 code[^<]*<span[^>]*>\s*\(optional\)/);
    assert.match(settings, /addForm\.codes\.push\(''\)/);
    assert.match(settings, /icd10_code: ''/);
    assert.match(settings, /Waiting for doctor review/);
    assert.match(
        settings,
        /A changed name or code goes back to a doctor for review/,
    );
});

test('The review page approves with a retry key, the branch and the lock version, and never lets a person approve their own entry', () => {
    assert.match(review, /Review suggestions/);
    assert.match(review, /decision: 'approve'/);
    assert.match(review, /expected_branch_id: props\.branchId/);
    assert.match(review, /lock_version: draft\.lockVersion/);
    assert.match(review, /idempotency_key: key/);
    assert.match(review, /draft\.ownEntry/);
    assert.match(review, /\{\{ waiting \}\} waiting/);
    assert.match(review, /draft\.enteredLabel/);
    assert.match(review, /You entered this, so someone else approves it/);
    assert.match(review, /variant="outline"/);
    assert.match(review, /role="alert"/);
    assert.doesNotMatch(review, /localStorage|sessionStorage|fetch\(/);
});

test('The service checks the reviewer, the version and the retry key under lock', () => {
    assert.match(service, /SuggestionPermissions::APPROVE/);
    assert.match(service, /edited_by_user_id/);
    assert.match(service, /lockForUpdate\(\)->firstOrFail\(\)/);
    assert.match(service, /diagnosis_preset\.approved/);
});
