import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');
const board = squash(read('resources/js/components/patient-board/PatientBoard.vue'));
const registration = squash(read('resources/js/pages/Registration/Index.vue'));
const show = squash(read('resources/js/pages/Dispensary/Show.vue'));
const routes = read('routes/web.php');

test('an OTC row on the Registration board offers Dispense and posts to the dispense route', () => {
    assert.match(board, /v-if="row\.can\.dispense"/);
    assert.match(board, /data-action="dispense"/);
    assert.match(board, /\$emit\('dispense', row\)/);
    assert.match(board, />Dispense</);
    assert.match(registration, /@dispense="dispenseOtc"/);
    assert.match(registration, /\/visits\/\$\{encodeURIComponent\(row\.visitNumber\)\}\/dispense/);
});

test('the OTC Dispensary page records the patient allergy statement before Complete', () => {
    assert.match(show, /data-testid="otc-allergy"/);
    assert.match(show, /data-field="otc-allergy-none"/);
    assert.match(show, /data-field="otc-allergy-has"/);
    assert.match(show, /data-action="confirm-otc-allergy"/);
    assert.match(show, /caseAction\(\s*'otc-allergy'/);
    assert.match(show, /isOtc && !otcAllergyConfirmed/);
    assert.match(show, /OTC · no doctor/);
});

test('the OTC page hides the doctor-only services panel', () => {
    assert.match(show, /v-if="!isOtc"\s+class="mt-6 rounded-lg border bg-background"\s+data-testid="dispensary-services"/);
});

test('the OTC routes are permission-gated', () => {
    assert.match(routes, /Route::post\('visits\/\{visit\}\/dispense'[\s\S]*?permission:dispensary\.otc\.create\.branch/);
    assert.match(routes, /Route::post\('dispensary\/\{dispensaryCase\}\/otc-allergy'[\s\S]*?permission:dispensary\.update\.branch/);
});
