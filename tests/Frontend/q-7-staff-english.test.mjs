import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Q-7 (owner decision 2026-11-10): staff screens and the messages staff see are English. The public QR form and
// status pages, the careers form, the MC, referral and attendance wording, the website drafts, the drug label,
// the TV's optional Malay spoken digits and docs/guides/ms are deliberately NOT in this list.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const malay =
    /\b(panggil|dipanggil|bilik|rawatan|pesakit|tahun|penjaga|tujuan|aduan|duplikasi|semakan|semak|pendaftaran|cawangan|sila|waktu operasi|pagi|setiap hari|doktor sudah|doktor sedang|selesaikan|konsultasi|kaunter|farmasi|dahulu|dinyatakan|tiada)\b/i;

const staffFiles = [
    'resources/js/pages/Clinical/Show.vue',
    'resources/js/pages/Dispensary/Show.vue',
    'resources/js/pages/RegistrationReview/Index.vue',
    'resources/js/pages/PublicCheckInLinks/Index.vue',
    'resources/js/pages/QueueDisplay/Settings.vue',
    'resources/js/components/AppSidebar.vue',
    'resources/js/lib/workspace-navigation.ts',
    'app/Domain/Access/ClinicManagementNavigation.php',
    'app/Domain/Clinical/Services/ConsultationHoldService.php',
    'app/Domain/Queue/Services/QueueEntryService.php',
];

test('staff screens and staff messages contain no Malay words', () => {
    for (const file of staffFiles) {
        assert.doesNotMatch(read(file), malay, file);
    }
});

test('the TV fallback room names are English, and the Malay spoken digits stay', () => {
    const display = read('resources/js/lib/queue-display.ts');
    assert.doesNotMatch(display, /Kaunter|Farmasi|Bilik rawatan/);
    assert.match(
        display,
        /consultation: \{ display: ROOM_FALLBACK, ms: 'Counter', en: 'Counter' \}/,
    );
    assert.match(display, /'kosong',\s*'satu',\s*'dua',\s*'tiga'/);
});

test('the consultation page and Dispensary use the English call labels', () => {
    const consultation = read('resources/js/pages/Clinical/Show.vue');
    assert.match(consultation, /<Megaphone class="size-4" \/> Call again/);
    assert.match(
        consultation,
        /<Megaphone class="size-4" \/> Call to treatment room/,
    );
    assert.match(
        read('resources/js/pages/Dispensary/Show.vue'),
        /<Megaphone class="size-4" \/>Call<\/Button/,
    );
});
