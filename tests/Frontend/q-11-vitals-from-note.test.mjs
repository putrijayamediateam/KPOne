import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// Q-11 (owner decision 2026-11-21): vitals typed in the clinical note are suggested for the vitals boxes; the doctor presses Fill.
// The helpers are executed from the real source file, not copied.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const javascript = ts.transpileModule(
    read('resources/js/lib/vitals-from-note.ts').replaceAll('export ', ''),
    {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    },
).outputText;
const {
    parseVitalsFromNote: rawParse,
    planFill: rawPlan,
    noteVitalsKey,
    isUnusual,
} = runInNewContext(
    `${javascript}\n({ parseVitalsFromNote, planFill, noteVitalsKey, isUnusual })`,
);
const show = read('resources/js/pages/Clinical/Show.vue');
// results come from another VM context, so compare plain copies made in this one
const plain = (value) => JSON.parse(JSON.stringify(value));
const parseVitalsFromNote = (note) => plain(rawParse(note));
const planFill = (vitals, boxes) => plain(rawPlan(vitals, boxes));
const labels = (note) =>
    plain(parseVitalsFromNote(note).readings.map((r) => r.label));

test('English and Malay ways of writing the four vitals are read', () => {
    assert.deepEqual(labels('BP 120/80, temp 37.2, pulse 80, SpO2 98%'), [
        'BP 120/80',
        'Temp 37.2 °C',
        'Pulse 80',
        'SpO2 98%',
    ]);
    assert.deepEqual(labels('TD: 130/85 mmHg  suhu 38,1  nadi 92  sat 97'), [
        'BP 130/85',
        'Temp 38.1 °C',
        'Pulse 92',
        'SpO2 97%',
    ]);
    assert.deepEqual(
        labels('Febrile 38.5°C. HR 110 bpm. 150/95 mmHg. O2 sat 95%'),
        ['BP 150/95', 'Temp 38.5 °C', 'Pulse 110', 'SpO2 95%'],
    );
    assert.deepEqual(
        labels(
            'Blood pressure 110 / 70, Temperature: 36.8, Heart rate: 72, SpO₂ 99 %',
        ),
        ['BP 110/70', 'Temp 36.8 °C', 'Pulse 72', 'SpO2 99%'],
    );
});

test('weight and height are read only when named and written with their unit', () => {
    assert.deepEqual(labels('wt 62.5kg, ht 165 cm'), [
        'Weight 62.5 kg',
        'Height 165 cm',
    ]);
    assert.deepEqual(labels('BB: 70 kg  TB: 172cm'), [
        'Weight 70 kg',
        'Height 172 cm',
    ]);

    // a stray number, a bare unit or a symptom mention is never taken
    for (const note of [
        'lost 2 kg this month',
        'weight 62',
        'height 165',
        'ate 70 kg of rice',
        'walked 150 cm',
    ]) {
        assert.deepEqual(labels(note), [], note);
    }
});

test('values that are not plausible, dates and doses are never suggested', () => {
    for (const note of [
        'BP 12/10',
        'BP 80/120',
        'BP 400/200',
        'seen on 12/10/2026',
        'dose 500/125 mg',
        'temp 99',
        'temp 12',
        'pulse 400',
        'pulse 5',
        'SpO2 130',
        'SpO2 20',
        'T2DM, HTN',
        'HR 2 hours',
        'in 2 hr 80 patients',
    ]) {
        assert.deepEqual(labels(note), [], note);
    }
});

test('a reading written twice with different values is ambiguous and left out; the same value twice is fine', () => {
    const twice = parseVitalsFromNote(
        'BP 140/90 on arrival. Repeat BP 130/80. pulse 80',
    );
    assert.deepEqual(
        twice.readings.map((r) => r.label),
        ['Pulse 80'],
    );
    assert.deepEqual(twice.ambiguous, ['blood pressure']);
    assert.deepEqual(labels('BP 120/80 ... later BP 120/80'), ['BP 120/80']);
});

test('Fill writes only into empty boxes and never overwrites; blood pressure is both boxes or neither', () => {
    const vitals = parseVitalsFromNote('BP 120/80, temp 37.2, pulse 80');
    const plan = planFill(vitals, {
        systolic_bp: '',
        diastolic_bp: null,
        pulse_bpm: '',
        temperature_celsius: '36.5',
    });
    assert.deepEqual(plan.fill, {
        systolic_bp: '120',
        diastolic_bp: '80',
        pulse_bpm: '80',
    });
    assert.deepEqual(plan.filled, ['BP 120/80', 'Pulse 80']);
    assert.deepEqual(
        plan.skipped,
        ['Temp 37.2 °C'],
        'a box that holds a different value is listed, not overwritten',
    );
    // only one blood pressure box has a value: neither is filled
    const half = planFill(parseVitalsFromNote('BP 120/80'), {
        systolic_bp: '118',
        diastolic_bp: '',
    });
    assert.deepEqual(half.fill, {});
    assert.deepEqual(half.skipped, ['BP 120/80']);
    // boxes that already hold the same numbers are left alone and not reported
    const same = planFill(parseVitalsFromNote('BP 120/80 pulse 80'), {
        systolic_bp: 120,
        diastolic_bp: '80',
        pulse_bpm: '',
    });
    assert.deepEqual(same.filled, ['Pulse 80']);
    assert.deepEqual(same.skipped, []);
});

test('the suggestion key changes only when the readings change', () => {
    assert.equal(
        noteVitalsKey(parseVitalsFromNote('BP 120/80 well')),
        noteVitalsKey(parseVitalsFromNote('well BP 120/80 today')),
    );
    assert.notEqual(
        noteVitalsKey(parseVitalsFromNote('BP 120/80')),
        noteVitalsKey(parseVitalsFromNote('BP 130/80')),
    );
});

test('the owner\'s own example "bp:180/45" is read (colon, no space)', () => {
    const found = parseVitalsFromNote('pt c/o headache. bp:180/45 noted');
    assert.deepEqual(
        found.readings.map((reading) => ({ ...reading.values })),
        [{ systolic_bp: '180', diastolic_bp: '45' }],
    );
});

test('the consultation page auto-fills empty boxes after a typing pause, announces it, offers Undo and never edits the note or saves', () => {
    assert.match(show, /parseVitalsFromNote\(/);
    assert.match(
        show,
        /data-testid="note-vitals-suggestion"[\s\S]{0,200}role="status"|role="status"[\s\S]{0,200}data-testid="note-vitals-suggestion"/,
    );
    assert.doesNotMatch(
        show,
        /Fill vitals boxes/,
        'no button: fills by itself',
    );
    const fill = show.slice(
        show.indexOf('const autoFillVitalsFromNote'),
        show.indexOf('const vitalsUnsaved'),
    );
    assert.match(show, /planFill\(\s*noteVitals\.value,\s*boxesForPlan\(\)/);
    assert.match(fill, /noteVitalsPlan\.value/);
    assert.match(fill, /appliedFromNote/);
    assert.match(fill, /vitalsExpanded\.value = true/);
    assert.match(
        show,
        /watch\(noteForVitals,[\s\S]{0,80}autoFillVitalsFromNote/,
        'fires on the debounced note, not on load (no immediate)',
    );
    assert.doesNotMatch(
        fill,
        /form\.clinical_note\s*=|form\.post|form\.put|form\.patch|router\./,
        'filling never edits the note or saves',
    );
    assert.match(show, /data-testid="note-vitals-undo"/);
    assert.match(show, /const undoNoteVitals/);
    assert.match(show, /Filled from your note:/);
});

test('a unit KPOne does not take is never read or converted, only reported as not read (Daniel)', () => {
    for (const [note, reported] of [
        ['temp 98.6 F', 'temperature in °F'],
        ['Temperature: 101°F', 'temperature in °F'],
        ['suhu 99.5 fahrenheit', 'temperature in °F'],
        ['wt 140 lbs', 'weight in lb'],
        ['weight: 154 pounds', 'weight in lb'],
        ['ht 5 ft', 'height in inches or feet'],
        ['height 66 inches', 'height in inches or feet'],
    ]) {
        const parsed = parseVitalsFromNote(note);
        assert.deepEqual(parsed.readings, [], note);
        assert.deepEqual(parsed.notRead, [reported], note);
    }

    // a Celsius temperature next to a Fahrenheit one is still read, and the other is reported
    const both = parseVitalsFromNote('temp 38.2 C (100.8 F)');
    assert.deepEqual(
        both.readings.map((r) => r.label),
        ['Temp 38.2 °C'],
    );
    assert.equal(
        noteVitalsKey(parseVitalsFromNote('temp 98.6 F')) !==
            noteVitalsKey(parseVitalsFromNote('temp 99.1 F')),
        false,
    );
    assert.notEqual(
        noteVitalsKey(parseVitalsFromNote('temp 98.6 F')),
        '',
        'the not-read text is part of the key',
    );

    // "temp 3 days" and similar are simply not readings
    for (const note of ['temp 3 days', 'fever for 3 days, temp x', 'T 3']) {
        const parsed = parseVitalsFromNote(note);
        assert.deepEqual([parsed.readings, parsed.notRead], [[], []], note);
    }
});

test('a skipped reading says what the box already holds, before and after Fill (Daniel)', () => {
    const plan = planFill(parseVitalsFromNote('temp 38.1, BP 120/80'), {
        temperature_celsius: '37.0',
        systolic_bp: '118',
        diastolic_bp: '',
    });
    assert.deepEqual(plan.skippedDetails, [
        { label: 'BP 120/80', current: '118' },
        { label: 'Temp 38.1 °C', current: '37.0' },
    ]);
});

test('the page labels unsaved vitals, announces politely, never moves focus, orders the lines and flags unusual values (Daniel)', () => {
    assert.match(show, /aria-live="polite"/);
    assert.match(show, /data-testid="vitals-unsaved"[\s\S]{0,80}Not saved yet/);
    assert.match(
        show,
        /const vitalsUnsaved = computed\(\s*\(\) => JSON\.stringify\(form\.vitals\) !== JSON\.stringify\(vitalsFromProps\(\)\)/,
    );

    for (const field of [
        'systolic_bp',
        'diastolic_bp',
        'pulse_bpm',
        'temperature_celsius',
        'spo2_percent',
        'weight_kg',
        'height_cm',
    ]) {
        assert.match(
            show,
            new RegExp(
                `v-model="form\\.vitals\\.${field}"\\s+data-vitals-field="${field}"`,
            ),
        );
    }

    const fill = show.slice(
        show.indexOf('const autoFillVitalsFromNote'),
        show.indexOf('const vitalsUnsaved'),
    );
    assert.doesNotMatch(
        fill,
        /\.focus\(|nextTick/,
        'fill never moves focus: the doctor is still typing',
    );
    // line order: Filled (with Undo), Check, Not filled, Not read
    const order = [
        'Filled from your note:',
        'note-vitals-check',
        'Not filled:',
        'Not read:',
    ].map((text) =>
        show.indexOf(
            text,
            show.indexOf('data-testid="note-vitals-suggestion"'),
        ),
    );
    assert.deepEqual(
        [...order].sort((a, b) => a - b),
        order,
    );
    assert.ok(order.every((index) => index > 0));
    // a box KPOne filled may be updated by a later note change until the doctor edits it or presses Undo
    assert.match(
        show,
        /boxes\[field\] === value[\s\S]{0,40}boxes\[field\] = ''/,
    );
    assert.match(
        show,
        /Everything KPOne has put in the boxes and the doctor has not changed/,
    );
});

test('unusual readings are still filled but flagged "Check", with ranges that live in one table (Daniel)', () => {
    // The ranges approved by Dr Fatin Adilah (21 Nov 2026): the ends are inside (no Check), one step past an end is outside.
    const edges = [
        ['systolic_bp', 50, 250, '49', '50', '250', '251'],
        ['diastolic_bp', 30, 150, '29', '30', '150', '151'],
        ['pulse_bpm', 35, 220, '34', '35', '220', '221'],
        ['temperature_celsius', 34, 42, '33.9', '34.0', '42.0', '42.1'],
        ['spo2_percent', 70, 100, '69', '70', '100', '101'],
    ];

    for (const [field, , , lowOut, lowIn, highIn, highOut] of edges) {
        assert.equal(
            isUnusual({ [field]: lowOut }),
            true,
            `${field} ${lowOut}`,
        );
        assert.equal(isUnusual({ [field]: lowIn }), false, `${field} ${lowIn}`);
        assert.equal(
            isUnusual({ [field]: highIn }),
            false,
            `${field} ${highIn}`,
        );
        assert.equal(
            isUnusual({ [field]: highOut }),
            true,
            `${field} ${highOut}`,
        );
    }

    assert.equal(isUnusual({ systolic_bp: '120', diastolic_bp: '80' }), false);
    // one odd half of a blood pressure flags the whole reading
    assert.equal(isUnusual({ systolic_bp: '120', diastolic_bp: '25' }), true);
    // the owner's example "bp:180/45" is inside both ranges now, so it is read and not flagged
    assert.equal(isUnusual({ systolic_bp: '180', diastolic_bp: '45' }), false);
    assert.equal(isUnusual({ temperature_celsius: '37.2' }), false);
    assert.equal(isUnusual({ weight_kg: '62.5' }), false);
    // the owner's own example is read and flagged, not refused
    const found = parseVitalsFromNote('bp:180/45');
    assert.equal(found.readings.length, 1);
    assert.match(show, /Check:\s*\{\{\s*filledChecks\.join/);
    assert.match(show, /isUnusual\(reading\.values\)/);
});

test('Undo clears only the boxes auto-fill filled, whatever else the doctor typed (Daniel)', () => {
    const undo = show.slice(
        show.indexOf('const undoNoteVitals'),
        show.indexOf('const vitalsUnsaved'),
    );
    assert.match(
        undo,
        /form\.vitals\[field as keyof typeof form\.vitals\] === value/,
    );
    assert.match(undo, /= ''/);
    assert.doesNotMatch(
        undo,
        /form\.clinical_note|form\.reset|form\.vitals = /,
    );
    assert.match(undo, /delete autoValues/);
});
