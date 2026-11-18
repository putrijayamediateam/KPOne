import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// HR-15e (owner, via B.E.R.N.I.S.S, 18 Nov 2026): HR picks In person or Online when confirming an interview, and for
// Online types a video link. The candidate sees it on their private page and in the email. No video or calendar
// integration.
const pages = {
    classic: readFileSync('resources/js/pages/HR/Applications.vue', 'utf8'),
    v2: readFileSync('resources/js/pages/HR/V2/Applications.vue', 'utf8'),
};
const composable = readFileSync(
    'resources/js/composables/hr/useApplicationsPage.ts',
    'utf8',
);
const status = readFileSync('resources/js/pages/Careers/Status.vue', 'utf8');

test('the confirm sends the mode, and the link only for Online', () => {
    assert.match(
        composable,
        /const meeting = reactive\(\{ mode: 'in_person', videoLink: '' \}\);/,
    );
    assert.match(
        composable,
        /Object\.assign\(meeting, \{ mode: 'in_person', videoLink: '' \}\);/,
    );
    assert.match(composable, /mode: meeting\.mode,/);
    assert.match(
        composable,
        /meeting\.mode === 'online'\s+\? \{ video_link: meeting\.videoLink\.trim\(\) \}\s+: \{\}/,
    );
});

for (const [name, page] of Object.entries(pages)) {
    test(`${name}: the confirm dialog asks how it happens and shows the link field only for Online`, () => {
        assert.match(page, /@click="askConfirm\(pick\)"/);
        assert.match(page, /@confirm="confirmInterview"/);
        assert.match(page, /data-test="interview-meeting-modes"/);
        assert.match(page, /How does it happen\?/);
        assert.match(page, /applications\.options\.meetingModes/);
        assert.match(
            page,
            /v-if="meeting\.mode === 'online'"[\s\S]*?data-test="interview-video-link"/,
        );
        assert.match(page, /type="url"/);
        assert.match(page, /maxlength="500"/);
        assert.match(page, /errors\.video_link/);
        assert.match(
            page,
            /The candidate is told the date, time and video link\./,
        );
    });

    test(`${name}: a confirmed online interview shows its link, opened safely`, () => {
        assert.match(page, /data-test="interview-online"/);
        assert.match(page, /rel="noopener noreferrer"/);
    });
}

test('the candidate page shows the link instead of the branch for Online', () => {
    assert.match(status, /data-test="status-interview-online"/);
    assert.match(status, /Online\. Join with this link:/);
    assert.match(status, /rel="noopener noreferrer"/);
    assert.match(
        status,
        /<p v-else class="text-sm">\s+\{\{ clinicName \}\} \{\{ application\.interview\.branch \}\}/,
    );
});
