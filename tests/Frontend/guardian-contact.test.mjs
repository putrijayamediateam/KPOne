import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
runInNewContext(ts.transpileModule(read('resources/js/lib/guardian-contact.ts'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { module, exports: module.exports });
test('whole-year guardian boundary remains under 18 and unknown remains unknown', () => {
    assert.equal(module.exports.knownMinor('2008-10-28', '2026-10-27'), true);
    assert.equal(module.exports.knownMinor('2008-10-27', '2026-10-27'), false);

    for (const value of ['', null, '2015-02-30', '2027-01-01']) {
assert.equal(module.exports.knownMinor(value, '2026-10-27'), false);
}
});
test('guardian controls are independent, English, retained for adults only when present and never copied to own contact', () => {
    const fields = read('resources/js/components/patient/GuardianFields.vue');

    for (const label of ['Mother','Father','Legal guardian','Other family member','Guardian full name','Guardian phone','Guardian email (optional)']) {
assert.ok(fields.includes(label));
}

    assert.match(fields, /minor \|\| guardian/);
    assert.doesNotMatch(fields, /WhatsAppLink|v-html|localStorage|sessionStorage|mobile_phone\s*=/);

    for (const path of ['Patient/Create.vue','Patient/Edit.vue','Registration/Create.vue']) {
assert.match(read(`resources/js/pages/${path}`), /knownMinor/);
}

    assert.match(read('resources/js/components/patient/PatientFormFields.vue'), /<GuardianFields/);
    assert.match(read('resources/js/pages/Patient/Show.vue'), /patient.guardian.full_name/);
});
test('QR review compares both contacts and requires an explicit existing-contact choice', () => {
    const review = read('resources/js/pages/RegistrationReview/Show.vue');

    for (const text of ['Incoming:', 'Existing:', 'Keep the existing guardian', 'Use the incoming guardian', 'guardian_lock_version']) {
assert.ok(review.includes(text));
}

    assert.match(read('resources/js/pages/PublicCheckIn/Show.vue'), /Nombor telefon anak \(pilihan\)/);
});
test('guardian storage never enters WA, matching, public status, TV, billing print or report source code', () => {
    for (const path of ['app/Domain/Patient/Services/PatientWhatsAppService.php','app/Domain/Patient/Services/PatientIdentityService.php','app/Domain/Queue/Display/QueueDisplayFeedService.php','resources/js/pages/PublicCheckIn/Status.vue','resources/js/pages/Billing/Print.vue','app/Domain/Visit/Billing/Services/PanelClaimDirectoryService.php','app/Domain/Shared/Services/BranchReportService.php','app/Domain/Shared/Services/TransactionReportService.php']) {
        assert.doesNotMatch(read(path), /patient_guardian|PatientGuardian|patient\.guardian|guardian-contact/);
    }
});

test('Quick create HTML phone requirement follows the same minor rule as validation', () => {
    assert.match(read('resources/js/pages/Registration/Create.vue'), /:required="!knownMinor\(quick.date_of_birth, guardianToday\)"/);
});
