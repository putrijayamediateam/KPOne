import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

// TRI-1b: the live zone suggestion on the triage form uses the same rules as PHP and SQL, from one shared case file.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const load = (path) => {
    const module = { exports: {} };
    runInNewContext(
        ts.transpileModule(read(path), {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        { exports: module.exports, module },
    );

    return module.exports;
};
const zone = load('resources/js/lib/triage-zone.ts');
const fixture = JSON.parse(read('tests/Fixtures/triage-zone-cases.json'));

test('the shared zone cases give the same zone and crossed readings as PHP and SQL', () => {
    assert.ok(fixture.cases.length >= 15);

    for (const c of fixture.cases) {
        const result = zone.suggest(
            c.readings,
            c.red_flags,
            c.yellow_signs,
            c.age_band,
            fixture.limits,
        );
        assert.equal(result.zone, c.zone, c.name);
        const crossed = Object.fromEntries(
            Object.entries(result.crossed).map(([k, v]) => [k, v.zone]),
        );
        assert.deepEqual({ ...crossed }, c.crossed, c.name);
    }
});

test('hundredths are exact and never floats', () => {
    assert.equal(zone.hundredths('38'), 3800);
    assert.equal(zone.hundredths('38.5'), 3850);
    assert.equal(zone.hundredths('38.05'), 3805);
    assert.equal(zone.hundredths('37.995'), null);
    assert.equal(zone.hundredths('abc'), null);
});

const base = (overrides = {}) => ({
    suggested: 'green',
    crossed: {},
    readings: { temperature_celsius: '36.8' },
    redFlags: [],
    previousZone: 'red',
    hasEarlierTriage: true,
    hadRedFlag: false,
    readingsToRetake: ['temperature_celsius'],
    ...overrides,
});

test('owner rule 1: lowering needs the crossed reading retaken within limits and no red flag', () => {
    assert.equal(
        zone.loweringRefusal('green', base()),
        null,
        'retaken and normal: green allowed',
    );
    assert.match(
        zone.loweringRefusal('green', base({ readings: { pulse_bpm: 80 } })),
        /Temperature must be retaken/,
    );
    assert.match(
        zone.loweringRefusal('green', base({ redFlags: ['rf_01'] })),
        /while a red flag is ticked/,
    );
    assert.match(
        zone.loweringRefusal('yellow', base({ hadRedFlag: true })),
        /stays red/,
    );
    assert.equal(zone.loweringRefusal('red', base({ hadRedFlag: true })), null);
    assert.match(
        zone.loweringRefusal(
            'green',
            base({
                hasEarlierTriage: false,
                previousZone: null,
                suggested: 'red',
            }),
        ),
        /Take the readings again before/,
    );
    const yellowNow = base({
        suggested: 'yellow',
        crossed: {
            temperature_celsius: { direction: 'very_high', zone: 'yellow' },
        },
        readings: { temperature_celsius: '38.5' },
    });
    assert.equal(
        zone.loweringRefusal('yellow', yellowNow),
        null,
        'red to yellow once only the yellow limit is crossed',
    );
    assert.match(
        zone.loweringRefusal('green', yellowNow),
        /must be retaken and within limits/,
    );
    assert.deepEqual(
        { ...zone.zoneToConfirm(yellowNow) },
        { zone: 'yellow', heldBy: null },
    );
});

test('the form offers the lowest zone the server will accept and says why', () => {
    const held = base({ readings: { pulse_bpm: 80 } });
    const result = zone.zoneToConfirm(held);
    assert.equal(result.zone, 'red');
    assert.match(result.heldBy, /Temperature must be retaken/);
    assert.deepEqual(
        {
            ...zone.zoneToConfirm(
                base({
                    previousZone: null,
                    hasEarlierTriage: false,
                    readingsToRetake: [],
                }),
            ),
        },
        { zone: 'green', heldBy: null },
    );
});

test('the yellow sign override below the suggestion is allowed only with every reading within limits', () => {
    const signOnly = base({
        suggested: 'yellow',
        previousZone: 'yellow',
        readingsToRetake: [],
    });
    assert.equal(zone.loweringRefusal('green', signOnly), null);
    const pastYellow = {
        ...signOnly,
        crossed: { pulse_bpm: { direction: 'very_high', zone: 'yellow' } },
    };
    assert.match(
        zone.loweringRefusal('green', pastYellow),
        /Pulse must be within limits/,
    );
});

test('the triage form keeps the retry key on a refusal, renews it on success and follows the server versions', () => {
    const show = read('resources/js/pages/Triage/Show.vue').replace(/\s+/g, ' ');
    assert.match(show, /idempotency_key: crypto\.randomUUID\(\)/);
    assert.match(show, /onSuccess: \(\) => \{ form\.idempotency_key = crypto\.randomUUID\(\); \}/);
    assert.doesNotMatch(show, /onError: \(\) => \{ form\.idempotency_key/);
    assert.match(show, /\(\) => props\.expected, \(expected\) => \{ form\.visit_lock_version = expected\.visitLockVersion;/);
    assert.match(show, /\/visits\/\$\{props\.visit\.visitNumber\}\/triage/);
    assert.doesNotMatch(show, /dateOfBirth|date_of_birth/);
    assert.match(show, /aria-pressed/);
});

test('the guideline page uses fixed heading ids and shows the two-person rule', () => {
    const page = read('resources/js/pages/TriageGuideline/Index.vue').replace(/\s+/g, ' ');
    assert.match(page, /heading-\$\{section\.id\}/);
    assert.match(page, /someone else must check it against the signed copy and approve it/);
    assert.match(page, /'Very high · yellow'/);
    assert.match(page, /'Very high · red'/);
});

test('the Registration board shows the Triage tab only with its server count and links each row to the form', () => {
    const board = read('resources/js/pages/Registration/Index.vue').replace(/\s+/g, ' ');
    assert.match(board, /tabCounts\.value\?\.triage !== undefined \|\| props\.visits\?\.tabCounts\?\.triage !== undefined/);
    assert.match(board, /:aria-label="`Triage \$\{row\.patientName\}`"/);
});
