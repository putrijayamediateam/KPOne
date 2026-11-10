import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-17a hiring stages (HR-PLAN 19; owner, 2026-11-10, relayed by B.E.R.N.I.S.S).
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
    // Pass is the primary button; Reject asks first and needs a reason (Daniel, HR-17a).
    assert.match(template('hr'), /@submit\.prevent="recordPass"/);
    assert.match(template('hr'), /@click="askReject"/);
    assert.match(template('hr'), /title="Reject after the phone call\?"/);
    assert.match(source.hr, /reject_reason:/);
    // The rating is five radio buttons in a fieldset, with labelled ends.
    assert.match(template('hr'), /v-for="n in 5"/);
    assert.match(template('hr'), /type="radio"\s+name="screening-rating"/);
    assert.match(template('hr'), /1 Weak<\/span><span>5 Strong/);
    // The stage action comes before the application's details.
    assert.ok(
        template('hr').indexOf('data-test="screening-form"') <
            template('hr').indexOf('id="application-details-title"'),
    );
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

test('the public form has three steps, checks each before Next and uses the shared date box', async () => {
    assert.match(
        template('apply'),
        /Step \{\{ step \}\} of \{\{ STEP_COUNT \}\}/,
    );
    assert.match(template('apply'), /Langkah \{\{ step \}\} daripada/);
    assert.match(template('apply'), /data-test="apply-next"/);
    assert.match(template('apply'), /data-test="apply-back"/);
    assert.match(template('apply'), /data-test="apply-error-summary"/);
    assert.match(template('apply'), /<DateField/);
    assert.doesNotMatch(template('apply'), /type="date"/);
    assert.match(template('apply'), /From today, within a year\./);
    assert.doesNotMatch(source.apply, /localStorage|sessionStorage|indexedDB/);

    const lib = await import('../../resources/js/lib/job-application-form.ts');
    const blank = {
        position: '',
        name: '',
        phone: '',
        email: '',
        preferred_branch: '',
        start_date: '',
        education: '',
        years_experience: '',
        languages: [],
        institution: '',
        programme: '',
        intern_from: '',
        intern_to: '',
        availability: '',
        mmc_number: '',
        apc_year: '',
        apc_expiry: '',
        message: '',
        privacy_accepted: false,
    };
    assert.deepEqual(
        Object.keys(lib.validateApplicationStep(1, blank, null, '2026-11-10')),
        ['position', 'name', 'phone', 'email'],
    );
    assert.ok(
        'mmc_number' in
            lib.validateApplicationStep(
                2,
                blank,
                'resident_doctor',
                '2026-11-10',
            ),
    );
    assert.ok(
        !(
            'mmc_number' in
            lib.validateApplicationStep(2, blank, 'ca', '2026-11-10')
        ),
    );
    assert.ok(
        'start_date' in
            lib.validateApplicationStep(
                2,
                { ...blank, start_date: '2027-11-11' },
                'ca',
                '2026-11-10',
            ),
    );
    assert.ok(
        !(
            'start_date' in
            lib.validateApplicationStep(
                2,
                { ...blank, start_date: '2027-11-10' },
                'ca',
                '2026-11-10',
            )
        ),
    );
    assert.deepEqual(
        Object.keys(lib.validateApplicationStep(3, blank, null, '2026-11-10')),
        ['privacy_accepted'],
    );
    // A number box hands over a number, not a string (found in the screenshot run).
    assert.ok(
        !(
            'years_experience' in
            lib.validateApplicationStep(
                2,
                { ...blank, years_experience: 2 },
                'ca',
                '2026-11-10',
            )
        ),
    );
    assert.ok(
        'years_experience' in
            lib.validateApplicationStep(
                2,
                { ...blank, years_experience: 51 },
                'ca',
                '2026-11-10',
            ),
    );
    assert.ok(
        !(
            'apc_year' in
            lib.validateApplicationStep(
                2,
                { ...blank, apc_year: 2026 },
                'resident_doctor',
                '2026-11-10',
            )
        ),
    );
    assert.equal(lib.stepOf('mmc_number'), 2);
    assert.equal(lib.stepOf('cv'), 3);
});
