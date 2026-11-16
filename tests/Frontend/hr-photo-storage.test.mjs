import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-PHOTO-STORAGE: the read-only "Clock-in photo storage" card, Version 2 only (B.E.R.N.I.S.S: Classic stays as it is).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const file = 'resources/js/components/hr/attendance/v2/PhotoStorageCardV2.vue';
const source = read(file);
const template = parse(source).descriptor.template.content;
const v2 = read('resources/js/pages/HR/V2/Attendance.vue');
const classic = read('resources/js/pages/HR/Attendance.vue');

test('the card compiles and sits on the V2 and Classic Settings tabs (owner, 16 Nov 2026: "photo card in classic too")', () => {
    const { errors } = compileTemplate({
        source: template,
        filename: file,
        id: 'photo-storage',
    });
    assert.deepEqual(errors, []);
    assert.match(
        v2,
        /<PhotoStorageCardV2\s+v-if="attendance\.settings\.photoStorage"\s+:card="attendance\.settings\.photoStorage"/,
    );
    assert.match(
        classic,
        /<PhotoStorageCard\s+v-if="attendance\.settings\.photoStorage"\s+:card="attendance\.settings\.photoStorage"/,
    );
});

test('it is read only: no Save, no form, no request, and totals only', () => {
    assert.doesNotMatch(template, /<button|<form|<input|@click/);
    assert.doesNotMatch(source, /router\.|useForm|fetch\(/);
    assert.match(template, /Totals only\. No names\./);
    assert.doesNotMatch(template, /\bname\b|public_id|publicId/);
});

test('the retention chip names the owner decision (neutral) and keeps the earliest delete in the sentence', () => {
    assert.match(
        template,
        /<V2Chip\s+:tone="card\.draft \? 'warning' : 'neutral'"\s+:label="`Retention: \$\{card\.retentionYears\} years\$\{card\.draft \? ' · draft, to confirm' : ` · \$\{card\.basis\}`\}`"/,
    );
    assert.match(template, /The earliest delete is/);
    assert.match(template, /No clock-in photos yet\./);
});

test('Daniel: a forecast only after three full months, labelled as rough, with the encrypted-size note', () => {
    assert.match(
        template,
        /v-if="card\.forecastOneYear && card\.forecastRetention"/,
    );
    assert.match(
        template,
        /Rough forecast, from the last\s+\{\{ word\(card\.forecastMonths\) \}\} full months:/,
    );
    assert.match(
        template,
        /A forecast appears after\s+\{\{ word\(card\.forecastMonths\) \}\} full months\./,
    );
    assert.match(source, /'three'/);
    assert.match(
        template,
        /the encrypted files on disk take about a third\s+more/,
    );
});

test('Daniel: quantities carry their unit, in tabular figures, and never split from it', () => {
    assert.match(
        source,
        /const plural = \(n: number, one: string, many: string\) =>\s+`\$\{count\(n\)\}\\u00A0\$\{n === 1 \? one : many\}`;/,
    );
    assert.match(
        source,
        /const glue = \(size: string\) => size\.replace\(' ', '\\u00A0'\);/,
    );
    assert.doesNotMatch(source, /\u00A0/); // escapes, never a hidden character in the source

    for (const dd of template.match(/<dd[^>]*>/g) ?? []) {
        assert.match(dd, /whitespace-nowrap tabular-nums/);
    }
});

const classicFile =
    'resources/js/components/hr/attendance/PhotoStorageCard.vue';
const classicSource = read(classicFile);
const classicTemplate = parse(classicSource).descriptor.template.content;

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
    assert.doesNotMatch(classicSource, /\u00A0/);
});
