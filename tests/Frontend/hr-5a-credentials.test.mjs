import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-5a credential register (owner 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.2 and 12.6,
// Daniel's early look folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    page: 'resources/js/pages/HR/Credentials.vue',
    sheet: 'resources/js/components/hr/credentials/RecordSheet.vue',
    chip: 'resources/js/components/hr/credentials/StatusChip.vue',
    roster: 'resources/js/pages/HR/Roster.vue',
    attendance: 'resources/js/pages/HR/Attendance.vue',
    masked: 'resources/js/components/hr/records/MaskedValue.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const types = read('resources/js/components/hr/credentials/types.ts');
const template = (key) => parse(source[key]).descriptor.template.content;

test('the new page, components and changed pages compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('status words carry the time and the date, Expired has an icon and stronger text, and nothing is red', () => {
    assert.match(
        types,
        /`\$\{status\.words\} · \$\{dateLabel\(record\.expiresOn\)\}`/,
    );
    assert.match(types, /`Done · \$\{dateLabel\(record\.issuedOn\)\}`/);
    assert.match(
        types,
        /code === 'expiring' \|\| code === 'expired'\s*\? 'warning'/,
    );

    for (const key of ['page', 'sheet', 'chip']) {
        assert.doesNotMatch(source[key], /tone="danger"|'danger'/, files[key]);
    }

    const chip = template('chip');
    assert.match(chip, /<TriangleAlert\s+v-if="status\.code === 'expired'"/);
    assert.match(chip, /status\.code === 'expired' \? 'font-semibold'/);
    assert.match(chip, /class="sr-only">\{\{ spoken \}\}/);
});

test('My credentials: one outline Add or Renew per card, the reference masked with an audited Show, and the MMC read-only', () => {
    const page = template('page');
    assert.match(
        page,
        /variant="outline"[\s\S]{0,300}@click="openOwn\(card\)"/,
    );
    assert.match(page, /\{\{ card\.inForce \? 'Renew' : 'Add' \}\}/);
    assert.match(
        page,
        /:url="`\/hr\/credentials\/records\/\$\{card\.inForce\.id\}\/show-reference`"/,
    );
    assert.match(
        source.masked,
        /props\.url \?\? `\/hr\/records\/\$\{props\.workerId\}\/show-value`/,
    );
    assert.match(
        page,
        /From Clinician credentials\. The Director updates this\./,
    );
    assert.match(page, /Someone\s+else checks this\./);
    assert.match(page, /Your role needs no credentials\./);
    assert.match(page, /Credentials are not set up yet\./);
    assert.match(page, /Opening a document is recorded\./);
});

test('the register: summary line, sticky header and person column, "—" read as not required, outline Accept and Reject', () => {
    const page = template('page');
    assert.match(source.page, /waiting for check` : ''/);
    assert.match(page, /class="sticky top-0 left-0 z-20/);
    assert.match(page, /class="sticky left-0 z-10/);
    assert.match(
        page,
        /aria-hidden="true"[\s\S]{0,200}>—<\/span[\s\S]{0,300}not\s+required/,
    );

    for (const label of ['Accept', 'Reject']) {
        assert.match(
            page,
            new RegExp(`variant="outline"[\\s\\S]{0,300}>${label}</Button`),
        );
    }

    assert.match(page, /All others valid/);
    assert.match(page, /'Accept this record\?'\s*: 'Reject this record\?'/);
    assert.doesNotMatch(page, /legend[^<]*colour/i);
});

test('the sheet asks only Done or Not done and a date for a health item, with visible labels, and the file goes to the vault', () => {
    const sheet = template('sheet');
    assert.match(
        sheet,
        /A health item records only Done or Not done and the\s+date\./,
    );
    assert.match(
        sheet,
        /<Label for="credential-health"[\s\S]{0,40}<span id="credential-health-label"\s*>Done or not done \*<\/span/,
    );
    assert.match(
        sheet,
        /<Label for="credential-issued">Issue date \*<\/Label>/,
    );
    assert.match(sheet, /accept="application\/pdf,image\/jpeg,image\/png"/);
    assert.match(sheet, /kept in the\s+staff file as a Certificate\./);
    assert.match(source.sheet, /forceFormData: true/);
    assert.match(template('page'), /seen only by the person and by managers/);
});

test('My roster and My attendance show the reminder line in words, linking to the page; nothing is sent', () => {
    for (const key of ['roster', 'attendance']) {
        assert.match(source[key], /credentialReminders\?: string\[\];/);
        assert.match(
            template(key),
            /data-test="credential-reminders"[\s\S]{0,200}href="\/hr\/credentials"/,
        );
    }
});
