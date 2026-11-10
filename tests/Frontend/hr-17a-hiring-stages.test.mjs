import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17a hiring stages (HR-PLAN 20; owner, 2026-11-10, relayed by B.E.R.N.I.S.S).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    apply: 'resources/js/pages/Careers/Apply.vue',
    hr: 'resources/js/pages/HR/Applications.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('both pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the form asks each position type its own questions, in both languages', () => {
    for (const key of [
        'preferred_branch',
        'start_date',
        'education',
        'years_experience',
        'languages',
        'institution',
        'programme',
        'intern_from',
        'intern_to',
        'availability',
        'mmc_number',
        'apc_year',
        'apc_expiry',
    ]) {
        assert.match(source.apply, new RegExp(`\\b${key}:`), key);
    }

    assert.match(source.apply, /positionType\.value === 'intern'/);
    assert.match(source.apply, /positionType\.value === 'resident_doctor'/);
    assert.match(template('apply'), /positionType === 'locum'/);
    assert.match(template('apply'), /Tentang anda/);
    assert.match(template('apply'), /Mana-mana cawangan/);
    // Nothing about pay or identity is asked: neither the fields sent nor the page mention them.
    const fields = source.apply.slice(
        source.apply.indexOf('useForm({'),
        source.apply.indexOf('});', source.apply.indexOf('useForm({')),
    );

    for (const text of [fields, template('apply')]) {
        assert.doesNotMatch(
            text,
            /salary|wage|gaji|ic_number|date_of_birth|photo/i,
        );
    }

    // No talent pool until HR-17e: the form neither asks nor promises one (Suresh, HR-17a).
    assert.doesNotMatch(template('apply'), /talent pool|simpanan\s+bakat/i);
});

test('HR records the phone call with the checklist, a 1 to 5 rating and pass or reject', () => {
    assert.match(
        source.hr,
        /`\/hr\/applications\/\$\{application\.id\}\/screening`/,
    );
    assert.match(source.hr, /expected_lock_version: application\.lockVersion/);
    assert.match(template('hr'), /viewing\.status === 'phone_screening'/);
    assert.match(template('hr'), /value="pass"/);
    assert.match(template('hr'), /value="reject"/);
    assert.match(source.hr, /'5 · Excellent'/);
    assert.match(template('hr'), /KPOne\s+ships\s+none\./);
    assert.match(source.hr, /'\/hr\/applications\/checklist'/);
    assert.match(
        source.hr,
        /expected_version: props\.applications\.checklist\.version/,
    );
    // A position has a type, which decides the form's extra questions.
    assert.match(source.hr, /position_type: positionForm\.type/);
    // No offer is made in KPOne.
    assert.match(template('hr'), /Any offer is made outside KPOne\./);
});
