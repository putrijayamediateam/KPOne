import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Attendance: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Attendance.vue');
const classic = read('resources/js/pages/HR/Attendance.vue');
const composable = read('resources/js/composables/hr/useAttendancePage.ts');
const lib = read('resources/js/lib/hr-attendance-v2.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;

const loadLib = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(lib, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ES2022,
        },
    }).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-attendance',
            source: template,
            filename: 'Attendance.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Attendance',/);
    assert.match(script, /useAttendancePage\(props\)/);
    assert.match(classic, /useAttendancePage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios/);
    assert.match(composable, /'\/hr\/attendance\/corrections'/);
    assert.match(composable, /'\/hr\/attendance\/settings'/);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(
        classic,
        /<PageHeader\s+eyebrow="KPOne HR"\s+title="Attendance"/,
    );
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
});

test('My attendance: one filled Clock in or Clock out, 56 px, with the server time never in a live region', () => {
    assert.equal((template.match(/:class="clockButton"/g) ?? []).length, 2);
    assert.match(script, /const clockButton =\s*'inline-flex min-h-14 w-full/);
    assert.match(
        template,
        /data-test="clock-in"[\s\S]*?@click="press\('in'\)"/,
    );
    assert.match(
        template,
        /data-test="clock-out"[\s\S]*?@click="clock\.confirmOut = true"/,
    );
    assert.match(template, /data-test="server-time"/);
    const live = template.match(/aria-live="polite"[\s\S]*?<\/p>/)?.[0] ?? '';
    assert.match(live, /announcement/);
    assert.doesNotMatch(live, /serverTime/);
});

test('privacy and location wording carry over word for word', () => {
    for (const words of [
        'KPOne records the time and a photo of you at',
        'This branch checks your location when you',
        'Only inside, outside or not available',
        "Draft wording until the clinic's PDPA review. Synthetic data",
        'I understand, check my location',
        'Do not write medical details here.',
        'Someone else decides this',
        'Showing a photo is recorded.',
    ]) {
        assert.ok(
            template.replace(/\s+/g, ' ').includes(words),
            `missing: ${words}`,
        );
    }

    // Location results are words from the server; the page never shows or reads a position.
    assert.match(template, /r\.location\.in/);
    assert.doesNotMatch(
        template + script,
        /latitude|longitude|getCurrentPosition/,
    );
});

test('consent: two equal outline choices, neither preselected; closing records nothing', () => {
    assert.match(template, /single-action\s+single-action-style="outline"/);
    assert.match(
        template,
        /@confirm="withoutLocation\(clock\.consentFor \?\? 'in'\)"/,
    );
    assert.match(
        template,
        /data-test="location-agree"\s+@click="locate\(clock\.consentFor \?\? 'in', true\)"/,
    );
    assert.match(template, /!open && \(clock\.consentFor = null\)/);
});

test('Review: a table from 1280 px and cards below; photos and location per press; outline Approve and Reject', () => {
    assert.match(
        template,
        /<V2Table\s+label="Roster against attendance"[\s\S]*?cards="xl"/,
    );
    assert.match(template, /`Clock-in photo of \$\{r\.person\}`/);
    assert.match(template, /`Clock-out photo of \$\{r\.person\}`/);
    assert.match(template, /data-test="review-location-in"/);
    assert.match(template, /data-test="review-location-out"/);
    assert.match(
        template,
        /:class="button"\s+@click="\s*openDecision\(c, true\)\s*"/,
    );
    assert.match(template, /:tone="decision\.approve \? 'default' : 'danger'"/);
});

test('one filled action per tab: Clock in or out on My attendance, Save on Settings, none on Review', () => {
    const review = template.slice(
        template.indexOf('<!-- Review -->'),
        template.indexOf('<!-- Overtime and Summary'),
    );
    assert.doesNotMatch(review, /primaryButton|clockButton/);
    // The Settings Save is filled before the first save and while its card has changes (Daniel, Suresh m1).
    assert.doesNotMatch(template, /:class="primaryButton"/);
    assert.match(
        template,
        /:class="\s*!attendance\.settings\?\.current \|\|\s*rulesDirty\s*\? primaryButton\s*: button\s*"/,
    );
    assert.match(template, /'Save attendance rules'/);
});

test('the correction form shows a date and a time and sends the same local value as Classic', async () => {
    const { splitLocal, joinLocal } = await loadLib();
    assert.deepEqual(splitLocal('2026-11-13T08:30'), {
        date: '2026-11-13',
        time: '08:30',
    });
    assert.deepEqual(splitLocal(''), { date: '', time: '' });
    assert.deepEqual(splitLocal(null), { date: '', time: '' });
    assert.equal(joinLocal('2026-11-13', '17:05'), '2026-11-13T17:05');
    // A half-filled pair is empty, so the server's own rule speaks; no time is guessed.
    assert.equal(joinLocal('2026-11-13', ''), '');
    assert.equal(joinLocal('', '17:05'), '');
    assert.match(
        template,
        /<V2TimeField[\s\S]*?:error="correctionErrors\.in_time"/,
    );
    assert.match(
        template,
        /<V2TimeField[\s\S]*?:error="correctionErrors\.out_time"/,
    );
});

test('Overtime, Summary and the two settings cards show their current components inside the V2 frame', () => {
    for (const name of [
        '<OvertimeTab',
        '<SummaryTab',
        '<OvertimeSettingsForm',
        '<LocationCheckSettings',
        '<MyOvertimeSection',
        '<OvertimeAskSheet',
    ]) {
        assert.ok(template.includes(name), name);
    }
});

test("Daniel's build notes: muted refusal, red Reject that needs a reason, guarded settings, no native select", () => {
    assert.match(
        template,
        /class="text-sm text-muted-foreground"\s+data-test="clock-refusal"/,
    );
    assert.match(template, /:confirm-disabled="decisionBlocked"/);
    assert.match(
        template,
        /confirm-disabled-reason="Pick a reason to continue\."/,
    );
    assert.match(script, /!decision\.approve && decision\.reason === ''/);
    assert.match(
        template,
        /<V2LeaveGuard ref="guard" :dirty="rulesDirty" @discard="resetRules" \/>/,
    );
    assert.match(script, /guard\.value\.ask\(\(\) => selectTab\(tab\)\)/);
    assert.doesNotMatch(template, /<select\b/);
});

test('an empty pair keeps a date picked first, then sends the full value once the time follows (Suresh S1)', async () => {
    const { updatePair } = await loadLib();
    const pair = { date: '', time: '' };
    assert.equal(updatePair(pair, { date: '2026-11-13' }), '');
    assert.deepEqual(pair, { date: '2026-11-13', time: '' });
    assert.equal(updatePair(pair, { time: '17:30' }), '2026-11-13T17:30');

    const timeFirst = { date: '', time: '' };
    assert.equal(updatePair(timeFirst, { time: '08:05' }), '');
    assert.equal(
        updatePair(timeFirst, { date: '2026-11-12' }),
        '2026-11-12T08:05',
    );

    // The fields read the page's own pair, never parts recomputed from the request value.
    assert.match(template, /:model-value="parts\.out\.date"/);
    assert.match(
        template,
        /@update:model-value="setOut\(\{ time: \$event \}\)"/,
    );
    assert.match(
        script,
        /correction\.outTime = updatePair\(parts\.out, change\)/,
    );
    assert.doesNotMatch(script, /computed\(\(\) => splitLocal\(correction/);
});

test('Settings: Save is filled before the first save, and the guard baseline comes from the server (Suresh m1, m2)', () => {
    assert.match(
        template,
        /!attendance\.settings\?\.current \|\|\s*rulesDirty\s*\? primaryButton\s*: button/,
    );
    assert.match(script, /const rulesSaved = computed\(savedRules\)/);
});

test('Daniel: times never split, chips stay on one line, and the Branch and Reason boxes are OperationalSelect', async () => {
    const { glueTimes, shiftParts } = await loadLib();
    assert.equal(glueTimes('8:00 AM to 2:00 PM'), '8:00 AM to 2:00 PM');
    assert.equal(glueTimes('6 h 7 min'), '6 h 7 min');
    assert.equal(glueTimes(null), '');
    assert.deepEqual(shiftParts('Morning, 8:00 AM to 2:00 PM'), {
        name: 'Morning,',
        times: '8:00 AM to 2:00 PM',
    });
    assert.deepEqual(shiftParts('8:00 AM to 2:00 PM'), {
        name: '',
        times: '8:00 AM to 2:00 PM',
    });
    // Review: the shift times, In, Out and Worked are nowrap; the Flags cell keeps each chip on one line.
    assert.match(
        template,
        /data-label="Worked"\s+class="whitespace-nowrap tabular-nums"/,
    );
    assert.match(template, /data-label="Flags"\s+class="whitespace-nowrap"/);
    assert.match(
        template,
        /<span\s+class=\"whitespace-nowrap\"\s*>\{\{\s*glueTimes\(\s*r\.session\?\.in\s*\?\?\s*'–',?\s*\)/,
    );
    assert.match(
        template,
        /<span\s+class=\"whitespace-nowrap\"\s*>\{\{\s*glueTimes\(\s*r\.session\?\.out\s*\?\?\s*'–',?\s*\)/,
    );
    // The chip's own text-balance resets wrapping, so each Flags chip forces nowrap; the photo buttons do not wrap either.
    assert.equal((template.match(/class="text-nowrap!"/g) ?? []).length, 2);
    assert.match(
        template,
        /<td\s+data-label="In"\s+class="\[&_button\]:whitespace-nowrap"/,
    );
    assert.match(
        template,
        /<td\s+data-label="Out"\s+class="\[&_button\]:whitespace-nowrap"/,
    );
    // The Branch box on Review and the Reason box on the correction are the shared OperationalSelect.
    assert.match(
        template,
        /<OperationalSelect\s+:model-value="\s*attendance\.review\.branch\.id\s*"/,
    );
    assert.match(
        template,
        /<OperationalSelect\s+:id="id"\s+v-model="correction\.reason"/,
    );
});
