// Classic only, CO-7: the Classic half of hr-photo-storage.test.mjs, kept when the V2 card (PhotoStorageCardV2) and its
// V2 page were removed. The Classic placement line and the Classic card test are carried over unchanged.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/HR/Attendance.vue');
const classicFile =
    'resources/js/components/hr/attendance/PhotoStorageCard.vue';
const classicSource = read(classicFile);
const classicTemplate = parse(classicSource).descriptor.template.content;

test('the Classic card sits on the Classic Settings tab (owner, 16 Nov 2026: "photo card in classic too")', () => {
    assert.match(
        classic,
        /<PhotoStorageCard\s+v-if="attendance\.settings\.photoStorage"\s+:card="attendance\.settings\.photoStorage"/,
    );
});

test('Classic card: same figures and words, read only, totals only, in Classic style', () => {
    const { errors } = compileTemplate({
        source: classicTemplate,
        filename: classicFile,
        id: 'photo-storage-classic',
    });
    assert.deepEqual(errors, []);
    assert.doesNotMatch(classicTemplate, /<button|<form|<input|@click/);
    assert.doesNotMatch(classicSource, /router\.|useForm|fetch\(/);
    assert.match(classicTemplate, /<StatusBadge/);
    assert.doesNotMatch(classicTemplate, /V2Chip|V2FormCard/);

    for (const words of [
        'Clock-in photo storage',
        'The earliest delete is',
        'No clock-in photos yet.',
        'Totals only. No names.',
        'A forecast appears after',
    ]) {
        assert.ok(classicTemplate.includes(words), words);
    }

    assert.match(classicTemplate, /` · \$\{card\.basis\}`/);
    assert.doesNotMatch(classicSource, / /);
});
