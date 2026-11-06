import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-3b overtime, weekly hours and summaries (owner 2026-11-06; HR-PLAN section 10).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dir = 'resources/js/components/hr/attendance/';
const files = {
    page: 'resources/js/pages/HR/Attendance.vue',
    ask: `${dir}OvertimeAskSheet.vue`,
    tab: `${dir}OvertimeTab.vue`,
    summary: `${dir}SummaryTab.vue`,
    settings: `${dir}OvertimeSettingsForm.vue`,
    my: `${dir}MyOvertime.vue`,
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('every new component and the page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the Clock button stays the only primary on My attendance; overtime actions are outline buttons', () => {
    const page = template('page');
    assert.match(page, /data-test="ask-overtime"[\s\S]{0,80}/);

    for (const block of page.match(
        /<Button[^>]*data-test="(?:ask-overtime|review-ask-overtime)"[^>]*>/g,
    ) ?? []) {
        assert.match(block, /variant="outline"/);
    }

    const askButtons = page
        .match(/<Button[\s\S]*?<\/Button>/g)
        .filter((b) => b.includes('Ask for overtime'));
    assert.ok(askButtons.length >= 3);

    for (const b of askButtons) {
        assert.match(b, /variant="outline"/);
    }
});

test('the weekly hours line shows a warning chip, never red and never colour alone', () => {
    const page = template('page');
    assert.match(page, /data-test="week-hours"/);
    assert.match(page, /weekHours\.label \}\} this\s+week/);
    assert.match(page, /v-if="attendance\.my\.overtime\.weekHours\.chip"/);
    assert.match(
        source.page,
        /weekTone\(attendance\.my\.overtime\.weekHours\.level\)/,
    );
    assert.match(
        read(`${dir}types.ts`),
        /level === 'none' \? 'neutral' : 'warning'/,
    );
    assert.doesNotMatch(source.page + source.tab + source.my, /tone="danger"/);
});

test('the ask sheet shows worked beside rostered, the day type, the most allowed minutes and the note warning', () => {
    const ask = template('ask');
    assert.match(ask, /data-test="overtime-compare"/);
    assert.match(ask, /Not on the roster/);
    assert.match(source.ask, /Most allowed: \$\{max\} min/);
    assert.match(ask, /Do not write medical details here\./);
    assert.match(ask, /maxlength="200"/);
    assert.match(source.ask, /'\/hr\/attendance\/overtime'/);
    assert.match(source.ask, /idempotency_key: form\.key/);
    assert.match(
        source.ask,
        /user_id: props\.fromReview \? props\.userId : undefined/,
    );
});

test('each card has two outline buttons; the Approve dialog starts at the minutes asked and lowering them needs a reason', () => {
    const tab = template('tab');
    assert.doesNotMatch(tab, /open\(r, 'fewer'\)/);
    assert.match(tab, /open\(r, 'approve'\)/);
    assert.match(tab, /open\(r, 'reject'\)/);
    assert.match(source.tab, /minutes: String\(row\.minutesRequested\)/);
    assert.match(source.tab, /'Approve fewer minutes\?'/);
    assert.match(source.tab, /`Approve \$\{minutesValue\.value/);
    assert.match(source.tab, /dialog\.mode !== 'approve' \|\| fewer\.value/);
    assert.match(source.tab, /props\.options\.lowerReasons/);
    assert.match(tab, /Hours changed after approval/);
    assert.match(tab, /r\.changedLabel/);
    assert.match(tab, /data-test="overtime-summary"/);
    assert.match(tab, /No overtime is waiting\./);

    for (const b of tab
        .match(/<Button[\s\S]*?<\/Button>/g)
        .filter((x) => /Approve|Reject|Reverse/.test(x))) {
        assert.match(b, /variant="outline"/);
    }

    assert.match(source.tab, /CalendarX/);
});

test('the summary table right-aligns its figures and ends with a total row; empty states say what to do next', () => {
    const summary = template('summary');
    assert.match(summary, /class="text-right tabular-nums"/);
    assert.match(summary, /data-test="summary-total"/);
    assert.match(summary, /Choose another branch or period\./);
    assert.match(template('my'), /No overtime requests yet\./);
});

test('the summary is on screen only, with a table from 1024 px and cards below', () => {
    const summary = template('summary');
    assert.match(summary, /class="hidden lg:block"/);
    assert.match(summary, /class="flex flex-col gap-2 lg:hidden"/);
    assert.match(summary, /Includes time at another branch/);
    assert.doesNotMatch(source.summary, /csv|download|export/i);
});

test('overtime settings tick no role by default and say no money is worked out', () => {
    assert.match(
        source.settings,
        /roles: \[\.\.\.\(props\.card\.current\?\.roles \?\? \[\]\)\]/,
    );
    assert.match(template('settings'), /no money is worked out/);
    assert.match(
        template('settings'),
        /A warning only\. It never stops a clock-in or a/,
    );
    assert.match(source.settings, /'\/hr\/attendance\/overtime-settings'/);
});

test('phone targets are 44 px and the tabs keep their labels', () => {
    for (const key of ['ask', 'tab', 'summary', 'settings', 'my']) {
        assert.match(source[key], /min-h-11/, key);
    }

    assert.match(source.page, /overtime: 'Overtime',/);
    assert.match(source.page, /summary: 'Summary',/);
    assert.match(
        source.page,
        /`Overtime \(\$\{props\.attendance\.overtimePendingCount\}\)`/,
    );
});
