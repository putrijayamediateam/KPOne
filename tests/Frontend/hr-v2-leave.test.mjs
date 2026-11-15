import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR V2 Leave, PR 1: the Version 2 page uses the same composable as Classic, so both send the same requests.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Leave.vue');
const classic = read('resources/js/pages/HR/Leave.vue');
const composable = read('resources/js/composables/hr/useLeavePage.ts');
const template = parse(source).descriptor.template.content;
const script = parse(source).descriptor.scriptSetup.content;

test('the V2 page compiles, is registered, and shares the composable with Classic', () => {
    assert.deepEqual(
        compileTemplate({
            id: 'hr-v2-leave',
            source: template,
            filename: 'Leave.vue',
        }).errors,
        [],
    );
    assert.match(read('config/v2-pages.php'), /'HR\/Leave',/);
    assert.match(script, /useLeavePage\(props\)/);
    assert.match(classic, /useLeavePage\(props\)/);
    // Every request comes from the composable: the V2 page sends none of its own.
    assert.doesNotMatch(script, /router\.(get|post|put|patch|delete)\(/);
    assert.doesNotMatch(script, /fetch\(|axios|useForm/);
    assert.match(composable, /'\/hr\/leave\/requests'/);
    assert.match(composable, /'\/hr\/leave\/preview'/);
});

test('the Classic template did not change: Classic still renders its own markup', () => {
    assert.match(classic, /<PageHeader\s+eyebrow="KPOne HR"\s+title="Leave"/);
    assert.doesNotMatch(classic, /V2WorkLayout|V2Dialog/);
    // Classic never reads the V2-only "Counting…" state.
    assert.doesNotMatch(classic, /preview\.counting/);
});

test('Daniel: one filled action per width: Apply for leave at the top, Send request in the sheet', () => {
    assert.equal((template.match(/:class="primaryButton"/g) ?? []).length, 1);
    assert.match(
        template,
        /#actions[\s\S]*?:class="primaryButton"[\s\S]*?data-test="apply-leave"[\s\S]*?@click="openApply"/,
    );
    assert.match(template, /title="Apply for leave"/);
    assert.match(template, /: 'Send request'/);
    assert.match(
        template,
        /description="Apply for leave and see your balance\. Someone else approves each request\."/,
    );
});

test('Daniel (a): the big figure is what is left; a type with no yearly balance says so', () => {
    assert.match(
        template,
        /\{\{ b\.left \}\}\s+\{\{ dayWord\(b\.left\) \}\} left/,
    );
    assert.match(
        script,
        /`of \$\{b\.entitled\} · \$\{b\.taken\} taken · \$\{b\.booked\} booked`/,
    );
    assert.match(
        template,
        /v-if="!b\.counted"[\s\S]*?data-test="no-balance"\s*>\s*No yearly balance/,
    );
});

test('Daniel: the working-days line says Counting… while the server works, never an old number', () => {
    assert.match(
        script,
        /if \(preview\.counting\) \{\s*return \{ text: 'Counting…', error: false \};/,
    );
    assert.match(
        composable,
        /reactive\(\{ text: '', error: '', counting: false \}\)/,
    );
    assert.match(
        composable,
        /preview\.counting = Boolean\(\s*applyForm\.leave_type_id &&\s*applyForm\.start_date &&\s*applyForm\.end_date,?\s*\);/,
    );
    assert.match(
        template,
        /role="status"\s+aria-live="polite"\s+data-test="apply-summary"/,
    );
});

test('Daniel: approvals show the balance after approval; Approve and Reject are outline, the filled action is in the dialog', () => {
    assert.match(
        template,
        /data-test="left-after"\s*>\s*Left after approval: \{\{ r\.leftAfter \}\}/,
    );
    const card = template.slice(
        template.indexOf('data-test="pending-card"'),
        template.indexOf('Approved leave</h2>'),
    );
    assert.match(card, /:class="button"\s+@click="openAction\('approve', r\)"/);
    assert.match(card, /:class="button"\s+@click="openAction\('reject', r\)"/);
    assert.doesNotMatch(card, /primaryButton/);
    // Reject and every cancel are red; reasons start empty and the filled action waits for one.
    assert.match(
        template,
        /:tone="action\.kind === 'approve' \? 'default' : 'danger'"/,
    );
    assert.match(template, /:confirm-disabled="actionDisabled"/);
    assert.match(template, /:model-value="action\.reason \|\| null"/);
    assert.match(composable, /action\.reason = '';/);
});

test('Daniel: files use the file chooser with a spoken state line, never the bare file control', () => {
    assert.equal((template.match(/<FileChooseButton/g) ?? []).length, 2);
    assert.match(template, /:status="applyFileStatus"/);
    assert.match(template, /:status="evidenceFileStatus"/);
    assert.doesNotMatch(template, /type="file"/);
    assert.doesNotMatch(template, /capture=/);
});

test('Daniel (b): weekends and public holidays are shaded with words and a visible mark, never the type or reason', () => {
    assert.match(
        script,
        /day\.holiday \? 'Hol' : day\.working \? '' : day\.weekday/,
    );
    assert.match(script, /`Public holiday: \$\{day\.holiday\}`/);
    assert.match(script, /Sat: 'Saturday'/);
    assert.match(template, /data-test="day-mark"/);
    // A working day reads "M / 2"; a shaded day drops the letter and reads "28 / Sat".
    assert.match(
        template,
        /<span\s+v-if="!dayMark\(day\)"\s+class="block"\s+aria-hidden="true"\s*>\{\{ day\.weekday\[0\] \}\}<\/span/,
    );
    assert.match(template, /<span\s+v-if="dayWords\(day\)"\s+class="sr-only"/);
    // Each mark has a spoken name per person and day, "On leave" or "Half day leave" only.
    assert.match(
        script,
        /`\$\{name\}, \$\{label\}: \$\{cell === 'half' \? 'Half day leave' : 'On leave'\}`/,
    );
    assert.match(
        template,
        /class="sr-only"\s+data-test="cell-name"\s*>\{\{\s*spokenCell\(\s*row\.name,\s*day\.label,\s*row\.cells\[day\.date\],?\s*\)/,
    );
    assert.match(template, /:key="`\$\{i\}-\$\{row\.name\}`"/);
    const calendarSection =
        template.match(/<!-- Team calendar -->[\s\S]*?<\/section>/)?.[0] ?? '';
    assert.doesNotMatch(calendarSection, /\.type\b|\.reason\b/);
});

test('Daniel: the leave type is option chips, and Send request is faded until the choices are made', () => {
    assert.match(
        template,
        /<V2OptionGroup[\s\S]*?:options="typeOptions"\s+label="Leave type"\s+required/,
    );
    const applySheet = template.slice(
        template.indexOf('title="Apply for leave"'),
        template.indexOf('title="Evidence"'),
    );
    assert.doesNotMatch(applySheet, /OperationalSelect[^>]*label="Leave type"/);
    assert.match(script, /'Choose a leave type to continue\.'/);
    assert.match(script, /'Choose the start date to continue\.'/);
    assert.match(script, /'Choose the end date to continue\.'/);
    assert.match(template, /:confirm-disabled="applyMissing !== ''"/);
    assert.match(template, /:confirm-disabled-reason="applyMissing"/);
});

test('Daniel: "Booked" is explained once in words under the balance heading', () => {
    assert.match(
        template,
        /data-test="booked-note"\s*>\s*Booked: waiting for approval, or approved\s+and not yet started\./,
    );
});

test('Suresh M1: each day-count check has a number, so an older answer never shows for new dates', () => {
    assert.match(composable, /let previewSeq = 0;/);
    assert.match(composable, /const seq = \+\+previewSeq;/);
    assert.match(composable, /if \(seq !== previewSeq\) \{\s*return;\s*\}/);
    assert.match(
        composable,
        /if \(seq === previewSeq\) \{\s*preview\.counting = false;\s*\}/,
    );
    // A changed field makes the running check stale.
    assert.match(composable, /clearTimeout\(previewTimer\);\s*previewSeq\+\+;/);
});
