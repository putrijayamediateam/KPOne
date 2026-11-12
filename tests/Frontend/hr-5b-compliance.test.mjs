import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// HR-5b roster compliance warnings (owner 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.3 rule 7
// and 12.6, Daniel's early look folded in).
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const files = {
    panel: 'resources/js/components/hr/compliance/CompliancePanel.vue',
    rules: 'resources/js/components/hr/compliance/ComplianceRules.vue',
    roster: 'resources/js/pages/HR/Roster.vue',
};
const source = Object.fromEntries(
    Object.entries(files).map(([k, p]) => [k, read(p)]),
);
const template = (key) => parse(source[key]).descriptor.template.content;

test('the new components and the roster page compile', () => {
    for (const [key, path] of Object.entries(files)) {
        assert.deepEqual(
            compileTemplate({ id: key, source: template(key), filename: path })
                .errors,
            [],
            path,
        );
    }
});

test('the planner shows a worded chip in warning tone and a side panel that says warnings never stop anything', () => {
    const panel = template('panel');
    assert.match(source.panel, /`\$\{count\.value\} compliance warnings`/);
    assert.match(panel, /:label="chip"\s+tone="warning"/);
    assert.doesNotMatch(source.panel, /danger/);
    assert.match(
        panel,
        /Warnings only\. They don't stop you saving or sending the\s+roster\./,
    );

    for (const title of [
        'Doctor cover',
        'Days in a row',
        'Rest between shifts',
        'Planned hours',
    ]) {
        assert.match(source.panel, new RegExp(`title: '${title}'`));
    }

    assert.match(
        panel,
        /Fair rotation · \{\{ compliance\.rotation\.month \}\}/,
    );
    assert.match(panel, /Counts only\./);
    assert.match(
        template('roster'),
        /<CompliancePanel[\s\S]{0,120}:compliance="planner\.compliance"/,
    );
});

test('rules: KPOne ships no number, the form only suggests, each limit can be left empty, and the outline saves have visible labels', () => {
    const rules = template('rules');
    assert.match(
        rules,
        /KPOne ships no number: the form suggests\s+values, and nothing counts until you save\./,
    );
    assert.match(rules, /A gap of 15 minutes\s+or more is shown\./);
    assert.match(rules, /approved leave counts as a day\s+off\./);

    for (const id of ['coverage-min', 'work-days', 'work-rest', 'work-week']) {
        assert.match(rules, new RegExp(`<Label for="${id}"`));
    }

    for (const [flag, t] of [
        ['coverUnsaved', 'coverage-save'],
        ['workUnsaved', 'work-hours-save'],
    ]) {
        assert.match(
            rules,
            new RegExp(
                `:variant="${flag} \\? 'default' : 'outline'"[\\s\\S]{0,200}data-test="${t}"`,
            ),
        );
    }

    assert.match(rules, /:aria-label="`\$\{label\} opens`"/);
    assert.match(
        template('roster'),
        /<ComplianceRules[\s\S]{0,80}v-if="roster\.minimums\.compliance"/,
    );
});
