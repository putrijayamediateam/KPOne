import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-4a staff files (owner 2026-11-06; HR-PLAN section 11, Daniel's early look in 11.6).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dir = 'resources/js/components/hr/records/';
const files = {
    records: 'resources/js/pages/HR/Records.vue',
    file: 'resources/js/pages/HR/StaffFile.vue',
    masked: `${dir}MaskedValue.vue`,
    correction: `${dir}CorrectionSheet.vue`,
    synthetic: `${dir}SyntheticNote.vue`,
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;
const buttons = (key) =>
    template(key).match(/<Button[\s\S]*?<\/Button>/g) ?? [];

test('every page and component compiles', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the synthetic-data line is one shared muted component on both pages, never a banner', () => {
    assert.match(template('synthetic'), /text-xs text-muted-foreground/);
    assert.match(
        template('synthetic'),
        /Synthetic data only until the PDPA review\./,
    );
    assert.doesNotMatch(template('synthetic'), /bg-|border/);
    assert.match(template('records'), /<SyntheticNote \/>/);
    assert.match(template('file'), /<SyntheticNote \/>/);
});

test('Show is audited by the server first, names the field, becomes Hide, and nothing is copied or kept', () => {
    const masked = source.masked;
    assert.match(masked, /\/hr\/records\/\$\{props\.workerId\}\/show-value/);
    assert.match(masked, /cache: 'no-store'/);
    assert.match(template('masked'), /`Show \$\{field\}`/);
    assert.match(template('masked'), /Hide \{\{ field \}\}/);
    assert.match(
        template('masked'),
        /Shown at \{\{ shownAt \}\}\. This view is recorded\./,
    );
    assert.match(masked, /visibilitychange/);
    assert.match(masked, /watch\(\(\) => props\.resetKey, hide\)/);
    assert.match(masked, /onBeforeUnmount/);
    assert.doesNotMatch(masked, /clipboard|localStorage|sessionStorage/);
    // Screen readers hear words, e.g. "Identity number, ending 1 2 3 4, hidden".
    assert.match(
        masked,
        /ending \$\{props\.last4\.split\(''\)\.join\(' '\)\}, hidden/,
    );
    for (const b of buttons('masked')) {
        assert.match(b, /variant="outline"/);
    }
});

test('masked values never arrive in the staff file props; only masks and flags', () => {
    const props = source.file.slice(0, source.file.indexOf('const props'));
    assert.doesNotMatch(
        source.file,
        /identity\.number|accountNumber|account:\s*string/,
    );
    assert.match(source.file, /legalName: boolean;/);
    assert.match(source.file, /address: boolean;/);
    assert.ok(props.length > 0);
});

test('the bank tab: the person who entered a change sees only Withdraw; other managers see Confirm and Reject as outline buttons', () => {
    const file = template('file');
    assert.match(file, /Waiting for confirmation/);
    assert.match(file, /Someone else confirms this\./);
    assert.match(
        file,
        /v-if="file\.bank\.waiting\.enteredByMe"[\s\S]*data-test="bank-withdraw"[\s\S]*v-else-if="file\.canManage"[\s\S]*data-test="bank-confirm"/,
    );
    assert.match(file, /Recorded:[\s\S]{0,200}· New:/);
    for (const b of buttons('file').filter((x) =>
        /bank-(confirm|reject|withdraw)/.test(x),
    )) {
        assert.match(b, /variant="outline"/);
    }
});

test('My HR details: self-service fields have Edit, the rest Ask for a correction, and empty fields read "Not given"', () => {
    const records = template('records');
    assert.match(records, /data-test="edit-contact"/);
    assert.match(records, /Ask for a correction/);
    assert.match(source.records, /const notGiven = 'Not given';/);
    assert.match(records, /HR has not opened your staff file yet\./);
    for (const b of buttons('records').filter((x) =>
        /Ask for a correction|edit-contact/.test(x),
    )) {
        assert.match(b, /variant="outline"/);
    }
});

test('the correction sheet reuses the HR-3a pattern: Recorded, reason, note warning, errors under fields', () => {
    const sheet = template('correction');
    assert.match(sheet, /Recorded: \{\{ recorded \}\}/);
    assert.match(sheet, /Do not write medical details here\./);
    assert.match(sheet, /<InputError :message="errors\.reason" \/>/);
    assert.match(sheet, /<InputError :message="errors\.note" \/>/);
    assert.match(sheet, /<InputError :message="errors\.account" \/>/);
});

test('Staff files: a table from 1024 px and cards below, attention in words with every reason listed', () => {
    const records = template('records');
    assert.match(records, /class="hidden lg:block"/);
    assert.match(records, /class="flex flex-col gap-3 lg:hidden"/);
    assert.match(
        source.records,
        /`\$\{reasons\.length\} things need attention`/,
    );
    assert.match(records, /v-for="reason in row\.attention"/);
    assert.doesNotMatch(records, /<Tooltip/);
    assert.match(records, /tone="warning"/);
    assert.doesNotMatch(records, /tone="danger"/);
    assert.match(records, /No KPOne sign-in/);
    assert.match(records, /No staff files match\./);
});

test('the staff file header has a back link and the status chip; nothing writes to the account', () => {
    const file = template('file');
    assert.match(file, /← Staff files/);
    assert.match(file, /:label="w\.statusLabel"/);
    assert.match(
        file,
        /Ending a period changes nothing on the KPOne\s+account/,
    );
    assert.doesNotMatch(
        source.file + source.records,
        /\/staff\/|\/access-control/,
    );
});
