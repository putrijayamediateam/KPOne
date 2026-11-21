// Classic only, CO-8: the Classic half of v2-clinician-credentials, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ClinicianCredentials/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');

test('Classic Clinician credentials keeps the self-edit and inactive rule and posts to the person', () => {
    assert.ok(
        flat(classic).includes(
            'canManage && clinician.active && clinician.userId !== page.props.auth.user?.id',
        ),
    );
    assert.ok(tight(classic).includes('onBeforeUnmount(clear);'));
    assert.ok(tight(classic).includes('/clinician-credentials/${'));
});

test('Classic Clinician credentials keeps every wording string', () => {
    for (const text of [
        'Clinician credentials',
        'My clinician credentials',
        'Professional identity for future Medical Certificates. Issuing certificates is not available. Verification is a manual check, not an automated MMC or current APC validation.',
        'Credentials saved.',
        'Branch changed. Reload to review credentials.',
        'Review credentials',
        'Review clinician credentials',
        'Registered professional name',
        'MMC registration number',
        'Not configured',
        'Manually verified',
        'Not verified',
        'Inactive account',
        'No eligible clinicians in this organisation.',
        'Previous',
        'Next',
        'This does not grant clinical permissions or authorise MC issuance.',
        'I have checked the professional name and MMC registration number. Uncheck to mark these credentials unverified.',
        'Reload latest credentials',
        'Save credentials',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
    }
});
