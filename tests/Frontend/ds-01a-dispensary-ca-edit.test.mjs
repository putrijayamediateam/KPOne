import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');
const page = squash(read('resources/js/pages/Dispensary/Show.vue'));
const routes = read('routes/web.php');

test('the CA edits every medicine field and no longer manages Pending, Partial or Not dispensed', () => {
    for (const field of [
        'quantity',
        'dosage',
        'frequency',
        'duration',
        'route',
        'instruction',
        'precaution',
    ]) {
        assert.ok(
            page.includes(`data-field="${field}"`),
            `${field} is editable`,
        );
    }

    assert.doesNotMatch(
        page,
        /<option value="partial"|<option value="not_dispensed"|<option value="pending"/,
    );
    assert.doesNotMatch(page, />State<select/);
    assert.match(page, /data-action="save-line"/);
    assert.match(page, /data-action="remove-line"/);
    assert.match(page, /data-action="restore-line"/);
    assert.match(
        page,
        /router\.put\( `\/dispensary\/\$\{props\.dispensary\.publicId\}\/items\/\$\{item\.publicId\}\/final`/,
    );
});

test('the CA can add a medicine the doctor did not order, from the catalogue with stock', () => {
    assert.match(page, /data-testid="add-medicine"/);
    assert.match(
        page,
        /\/dispensary\/\$\{props\.dispensary\.publicId\}\/medicines\/search/,
    );
    assert.match(
        page,
        /router\.post\( `\/dispensary\/\$\{props\.dispensary\.publicId\}\/items`/,
    );
    assert.match(page, /No stock/);
});

test("the doctor's original stays visible beside the CA's version", () => {
    assert.match(page, /Doctor ordered/);
    assert.match(page, /data-testid="original-order"/);
    assert.match(page, /Edited by CA/);
    assert.match(page, /Added by CA/);
});

test("completing is the CA's own verification step, and unsaved lines block it", () => {
    assert.match(page, /data-testid="dispensary-verify"/);
    assert.match(page, /Verify before completing/);
    assert.match(page, /data-testid="dispensary-complete-confirm"/);
    assert.match(page, /caseAction\('complete'\)/);
    assert.match(
        page,
        /:disabled="\s*busy \|\| unsaved\.length > 0 \|\| unsavedServices\.length > 0 \|\| \(isOtc && !otcAllergyConfirmed\)\s*"/,
    );
    // owner decision 2026-10-09: the doctor's allergy confirmation stands, so there is no CA allergy tick
    assert.doesNotMatch(page, /allergyChecked|allergy_checked/);
    assert.match(page, /data-testid="dispensary-allergies"/);
});

test('a refreshed server version is adopted but never overwrites what the CA is typing', () => {
    assert.match(page, /baseLock\[item\.publicId\] !== item\.lockVersion/);
    assert.match(
        page,
        /!isDirty\(\{ \.\.\.item, lockVersion: baseLock\[item\.publicId\] \}\)/,
    );
});

test('the edit routes are permission-gated', () => {
    for (const [method, path, permission] of [
        ['post', 'items', 'dispensary.update.branch'],
        ['put', 'items/{item}/final', 'dispensary.update.branch'],
        ['post', 'items/{item}/remove', 'dispensary.update.branch'],
        ['post', 'medicines/search', 'dispensary.update.branch'],
    ]) {
        const pattern = new RegExp(
            `Route::${method}\\('dispensary/\\{dispensaryCase\\}/${path.replace(/[{}/]/g, '\\$&')}'[\\s\\S]*?permission:${permission.replaceAll('.', '\\.')}`,
        );
        assert.match(routes, pattern, `${method} ${path}`);
    }
});

test('the CA edits, adds, removes and confirms services on the same page', () => {
    assert.match(page, /data-testid="dispensary-services"/);

    for (const field of [
        'service-quantity',
        'service-instruction',
        'service-search',
        'add-service-quantity',
    ]) {
        assert.ok(page.includes(`data-field="${field}"`), field);
    }

    for (const action of [
        'save-service',
        'remove-service',
        'restore-service',
        'choose-service',
        'add-service',
    ]) {
        assert.ok(page.includes(`data-action="${action}"`), action);
    }

    assert.match(
        page,
        /\/dispensary\/\$\{props\.dispensary\.publicId\}\/services\/\$\{line\.publicId\}\/final/,
    );
    assert.match(
        page,
        /\/dispensary\/\$\{props\.dispensary\.publicId\}\/services\/search/,
    );
    assert.match(page, /Confirm and save/);
    assert.match(page, /Enter 0 if the service was not performed\./);
    assert.match(page, /Doctor ordered/);
    // an unsaved service blocks Complete, like an unsaved medicine
    assert.match(page, /unsaved\.length > 0 \|\| unsavedServices\.length > 0/);
});

test('the service routes are permission-gated', () => {
    for (const [method, path] of [
        ['post', 'services'],
        ['put', 'services/{line}/final'],
        ['post', 'services/{line}/remove'],
        ['post', 'services/search'],
    ]) {
        const pattern = new RegExp(
            `Route::${method}\\('dispensary/\\{dispensaryCase\\}/${path.replace(/[{}/]/g, '\\$&')}'[\\s\\S]*?permission:dispensary\\.update\\.branch`,
        );
        assert.match(routes, pattern, `${method} ${path}`);
    }
});
