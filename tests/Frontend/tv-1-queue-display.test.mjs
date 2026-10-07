import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const javascript = ts.transpileModule(
    read('resources/js/lib/queue-display.ts'),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    },
).outputText;
const module = { exports: {} };
runInNewContext(javascript, {
    exports: module.exports,
    module,
    Date,
    Intl,
    URLSearchParams,
});
const {
    nextPosterIndex,
    roomLabel,
    ROOM_FALLBACK,
    unannouncedCalls,
    youtubeEmbedUrl,
    formatDisplayDate,
    formatDisplayTime,
    announcementText,
    pickAnnouncementVoice,
    spokenQueueNumber,
} = module.exports;
const plain = (value) => JSON.parse(JSON.stringify(value));

const screen = read('resources/js/pages/QueueDisplay/Screen.vue');
const settings = read('resources/js/pages/QueueDisplay/Settings.vue');
const queue = read('resources/js/pages/Queue/Index.vue');
const app = read('resources/js/app.ts');
const navigation = read('resources/js/lib/workspace-navigation.ts');

test('only calls the screen has not seen are announced, oldest first', () => {
    const calls = [
        { id: 7, number: '007', room: 'Bilik 2', calledAt: '' },
        { id: 5, number: '005', room: null, calledAt: '' },
        { id: 6, number: '006', room: 'Bilik 1', calledAt: '' },
    ];

    assert.deepEqual(
        plain(unannouncedCalls(calls, new Set([5]))).map((call) => call.id),
        [6, 7],
    );
    assert.deepEqual(plain(unannouncedCalls(calls, new Set([5, 6, 7]))), []);
});

test('the screen marks the first feed as seen so a reload never replays old calls', () => {
    assert.match(
        screen,
        /const seen = new Set<number>\(props\.feed\.calls\.map\(\(call\) => call\.id\)\);/,
    );
});

test('a call without a room tells the patient to go to the counter', () => {
    assert.equal(roomLabel('Bilik Rawatan 3'), 'Bilik Rawatan 3');
    assert.equal(roomLabel(null), ROOM_FALLBACK);
    assert.equal(roomLabel('  '), ROOM_FALLBACK);
    assert.equal(roomLabel(null, 'dispensary'), 'Pharmacy');
    assert.equal(roomLabel(null, 'treatment'), 'Treatment Room');
});

test('YouTube plays from the privacy-enhanced domain, muted and looping, and rejects anything but a video id', () => {
    const url = new URL(youtubeEmbedUrl('dQw4w9WgXcQ'));
    assert.equal(url.origin, 'https://www.youtube-nocookie.com');
    assert.equal(url.pathname, '/embed/dQw4w9WgXcQ');
    assert.equal(url.searchParams.get('mute'), '1');
    assert.equal(url.searchParams.get('autoplay'), '1');
    assert.equal(url.searchParams.get('loop'), '1');
    assert.equal(url.searchParams.get('playlist'), 'dQw4w9WgXcQ');
    assert.equal(youtubeEmbedUrl(null), null);
    assert.equal(youtubeEmbedUrl('"><script>'), null);
    assert.equal(youtubeEmbedUrl('short'), null);
});

test('posters rotate and wrap around', () => {
    assert.equal(nextPosterIndex(0, 3), 1);
    assert.equal(nextPosterIndex(2, 3), 0);
    assert.equal(nextPosterIndex(4, 0), 0);
});

test('the clock and date are shown in English in the branch time zone', () => {
    const time = formatDisplayTime(
        new Date(Date.UTC(2026, 9, 5, 13, 4, 9)),
        'Asia/Kuala_Lumpur',
    );
    assert.equal(time, '21:04:09');
    assert.equal(
        formatDisplayDate(
            new Date(Date.UTC(2026, 9, 5, 13, 4, 9)),
            'Asia/Kuala_Lumpur',
        ),
        'Monday, 5 October 2026',
    );
});

test('the TV screen shows no patient data, needs a tap for sound, keeps the screen awake and stops on a lost session', () => {
    for (const forbidden of [
        'patientName',
        'full_name',
        'doctorName',
        'visitNumber',
        'localStorage',
        'sessionStorage',
        'v-html',
    ]) {
        assert.ok(!screen.includes(forbidden), forbidden);
    }

    assert.ok(screen.includes('Click to enable sound'));
    assert.ok(screen.includes("wakeLock?.request('screen')"));
    assert.ok(screen.includes('[401, 403, 419].includes(error.status)'));
    assert.ok(screen.includes('Display session expired'));
    assert.ok(screen.includes('Now calling'));
    assert.ok(screen.includes('window.clearInterval(pollTimer)'));
    assert.match(
        screen,
        /sandbox="allow-scripts allow-same-origin allow-presentation"/,
    );
    assert.match(screen, /prefers-reduced-motion: reduce/);
    assert.match(app, /case name === 'QueueDisplay\/Screen':\s*return null;/);
});

test('settings expose rooms, content and posters, and the doctor chooses a room in the queue console', () => {
    for (const expected of [
        "'/queue-display-settings'",
        'Add room',
        'Save screen content',
        'Upload poster',
        'forceFormData: true',
        'lock_version',
        'Do\n                not upload anything that identifies a patient.',
    ]) {
        assert.ok(settings.includes(expected), expected);
    }

    assert.ok(!settings.includes('v-html'));
    assert.ok(queue.includes('My room today'));
    assert.ok(queue.includes("'/queue/room'"));
    assert.ok(queue.includes('expected_branch_id: live.value.branch.id'));
    assert.ok(navigation.includes("href: '/queue-display-settings'"));
});

test('calls are read aloud in Malay when the TV has a Malay voice, otherwise in English', () => {
    const malay = { lang: 'ms-MY', name: 'Malay' };
    const english = { lang: 'en-GB', name: 'English' };
    const american = { lang: 'en-US', name: 'US' };

    assert.equal(pickAnnouncementVoice([english, malay]).language, 'ms');
    assert.equal(pickAnnouncementVoice([english, malay]).voice.name, 'Malay');
    assert.equal(
        pickAnnouncementVoice([american, english]).voice.name,
        'English',
    );
    assert.equal(pickAnnouncementVoice([american]).language, 'en');
    assert.equal(pickAnnouncementVoice([]).voice, null);

    assert.equal(spokenQueueNumber('P082', 'en'), 'P, zero, eight, two');
    assert.equal(spokenQueueNumber('021', 'en'), 'zero, two, one');
    assert.equal(spokenQueueNumber('012', 'ms'), 'kosong, satu, dua');
    assert.equal(
        announcementText({ number: 'P082', room: 'Consultation Room 2' }, 'en'),
        'P, zero, eight, two. Consultation Room 2.',
    );
    assert.equal(
        announcementText({ number: '007', room: 'Bilik Rawatan 1' }, 'ms'),
        'kosong, kosong, tujuh. Bilik Rawatan 1.',
    );
    assert.equal(
        announcementText({ number: '003', room: null }, 'en'),
        'zero, zero, three. Counter.',
    );
    assert.equal(
        announcementText({ number: '003', room: ' ' }, 'ms'),
        'kosong, kosong, tiga. Kaunter.',
    );
});

test('the TV keeps running unattended: sound prompt does not cover the calls, lost sessions are retried, sign-out is confirmed', () => {
    assert.ok(screen.includes('SpeechSynthesisUtterance'));
    assert.ok(screen.includes("addEventListener('voiceschanged', loadVoices)"));
    assert.ok(screen.includes('AUTH_RETRY_MILLISECONDS'));
    assert.ok(screen.includes('sessionEnded.value = false;'));
    assert.ok(
        screen.includes("window.confirm('Sign out of this TV display?')"),
    );
    assert.doesNotMatch(
        screen,
        /v-if="!soundOn && !sessionEnded"\s+class="fixed inset-0/,
    );
    assert.ok(screen.includes('Click to enable sound'));
});

test('the display has re-enterable fullscreen, a light/dark theme control and clinic branding', () => {
    assert.ok(
        screen.includes(
            "document.addEventListener('fullscreenchange', syncFullscreen)",
        ),
    );
    assert.ok(
        screen.includes(
            "document.removeEventListener('fullscreenchange', syncFullscreen)",
        ),
    );
    assert.ok(screen.includes('document.exitFullscreen()'));
    assert.ok(screen.includes('@click="toggleFullscreen"'));
    assert.match(screen, /:aria-label="\s*isFullscreen\s*\?/);
    assert.ok(screen.includes('@click="toggleTheme"'));
    assert.ok(screen.includes(':aria-pressed="theme === \'dark\'"'));
    assert.ok(screen.includes('src="/kp-mark.png"'));
    assert.ok(screen.includes('bg-pink-700'));
    assert.ok(screen.includes('bg-zinc-950'));

    for (const visibleMalayCopy of [
        'Paparan Giliran',
        'Cawangan',
        'Panggilan semula',
        'Sedang dipanggil',
        'Belum ada panggilan hari ini',
        'Nombor',
        'Bilik',
        'Maklumat klinik',
        'Video klinik',
        'Poster klinik',
        'Ketik untuk aktifkan bunyi',
        'Sesi paparan tamat',
        'Sambungan terputus',
        'Log keluar',
    ]) {
        assert.ok(!screen.includes(visibleMalayCopy), visibleMalayCopy);
    }
});

test('a patient being served can be called again from the queue board, and the TV labels it', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );
    assert.ok(board.includes('>Call Again</DropdownMenuItem'));
    assert.ok(board.includes('@select="$emit(\'recall\', row)"'));
    assert.ok(
        queue.includes(
            "recall: row.canCall && row.status === 'serving' && !row.isHeld",
        ),
    );
    assert.ok(queue.includes('/queue/recall'));
    assert.ok(screen.includes("'Recall'"));
});

test('the consultation page offers Panggil semula and returns to the consultation', () => {
    const consultation = read('resources/js/pages/Clinical/Show.vue');
    assert.ok(consultation.includes('v-if="clinical.queue.canRecall"'));
    assert.ok(consultation.includes('Panggil semula'));
    assert.ok(consultation.includes("from: 'consultation'"));
    assert.ok(consultation.includes('/queue/recall'));
});

test('the current call room label wraps long room names instead of clipping them', () => {
    const match = screen.match(
        /<p\s+class="([^"]*)"\s+data-testid="current-room"/,
    );
    assert.ok(match, 'current room label not found');
    assert.ok(match[1].includes('text-balance'));
    assert.ok(match[1].includes('break-words'));
    assert.ok(!match[1].includes('truncate'));
});

test('the theme button is labelled with the theme it switches to', () => {
    assert.ok(
        screen.includes("theme === 'light' ? 'Dark mode' : 'Light mode'"),
    );
    assert.ok(!screen.includes("theme === 'light' ? 'Light' : 'Dark'"));
    assert.ok(screen.includes("? 'Switch to dark theme'"));
});
