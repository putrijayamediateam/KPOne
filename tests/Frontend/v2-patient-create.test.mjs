import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Patient/Create.vue');
const page = read('resources/js/pages/Patient/V2/Create.vue');
const config = read('config/v2-pages.php');

test('the V2 page sends the same request as Classic', () => {
    for (const piece of [
        'adult_confirmed: false',
        'adult_confirmation_key: crypto.randomUUID()',
        'expected_branch_id',
        "phone_country: 'MY'",
        "country_code: 'MY'",
        'duplicate_override: false',
        "identifier_type: 'nric', issuing_country_code: 'MY', value: ''",
        "form.post('/patients', { preserveState: true, onError: focusInvalidField })",
        'phoneError(',
        '!knownMinor(form.date_of_birth, props.guardianToday)',
        'identityError(',
        'identifierIssuer(',
        "form.setError('full_name', 'Please enter the Patient name.')",
        'const { adult_confirmation_key, adult_confirmed, ...rest } = data;',
        '!data.date_of_birth',
    ]) {
        const loose = (s) => s.replace(/\s+/g, '');
        assert.ok(
            loose(classic).includes(loose(piece)),
            `Classic lacks ${piece}`,
        );
        assert.ok(loose(page).includes(loose(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 reuses the shared patient form with newPatient and is registered', () => {
    assert.match(page, /<PatientFormV2[\s\S]*new-patient/);
    assert.match(config, /'Patient\/Create'/);
});

test('V2 asks before discarding entries and names fields only', () => {
    assert.match(page, /Discard what you entered\?/);
    assert.match(page, /Keep editing/);
    assert.ok(!/console\./.test(page));
});
