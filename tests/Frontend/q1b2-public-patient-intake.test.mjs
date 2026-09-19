import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const publicForm = read('resources/js/pages/PublicCheckIn/Show.vue');
const status = read('resources/js/pages/PublicCheckIn/Status.vue');
const review = read('resources/js/pages/RegistrationReview/Show.vue');
const links = read('resources/js/pages/PublicCheckInLinks/Index.vue');
const appBootstrap = read('resources/js/app.ts');

test('frontend bootstrap accepts both supported Inertia initial-page markup formats', () => {
    assert.ok(appBootstrap.includes('initialPageElement?.dataset.page'));
    assert.ok(appBootstrap.includes('script[data-page="app"]'));
    assert.ok(appBootstrap.includes('initialPageScript?.textContent'));
});

test('mobile intake has the approved patient and guardian journey', () => {
    for (const expected of [
        'Mula Daftar',
        'Saya pesakit',
        'Saya penjaga',
        'Maklumat pesakit',
        'consent_confirmed',
        'guardian_attestation',
        'min-h-svh',
        'validationSummary',
        'aria-live="assertive"',
    ]) {
        assert.ok(publicForm.includes(expected), expected);
    }

    assert.ok(publicForm.includes('type="date"'));
    assert.ok(publicForm.includes('type="tel"'));
    assert.ok(!publicForm.includes('localStorage'));
    assert.ok(!publicForm.includes('sessionStorage'));
});

test('mobile intake is single-flight and retains safe back, refresh, and polling behaviour', () => {
    assert.ok(publicForm.includes('if (!session.value || submitting.value)'));
    assert.ok(publicForm.includes(':disabled="submitting"'));
    assert.ok(publicForm.includes("submitting ? 'Menghantar…'"));
    assert.ok(publicForm.includes('const back = () =>'));
    assert.ok(status.includes('router.reload'));
    assert.ok(status.includes("only: ['status']"));
    assert.ok(status.includes('window.setInterval(refresh, 10_000)'));
    assert.ok(status.includes('window.clearInterval(timer)'));
});

test('secure status surface exposes queue state without internal identifiers', () => {
    assert.ok(status.includes('status.queueNumber'));
    assert.ok(status.includes("only: ['status']"));
    assert.ok(status.includes('name="referrer" content="no-referrer"'));
    assert.ok(!status.includes('patientId'));
    assert.ok(!status.includes('visitId'));
    assert.ok(!status.includes('localStorage'));
});

test('staff review makes conversion explicit and keeps duplicate candidates internal', () => {
    assert.ok(review.includes('Duplicate resolution'));
    assert.ok(review.includes('duplicateCandidates'));
    assert.match(review, /Sahkan &amp;\s+Masukkan Queue/);
    assert.match(review, /Patient, creates Visit\s+and Queue Entry/);
    assert.ok(review.includes('Correction required'));
});

test('branch link management renders QR locally and reports expiry', () => {
    assert.ok(links.includes('issuedLink.qrDataUri'));
    assert.ok(links.includes('Generated locally'));
    assert.ok(links.includes('expiresAt'));
    assert.ok(
        !links.includes(
            'external QR service.</p>\n                    QR generation',
        ),
    );
});
