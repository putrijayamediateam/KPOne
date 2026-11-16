import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';

// HR V2 Leave, PR 2 (Daniel): Settings in four sub-tabs, Types · Entitlements · Calendar · Opening balances. Every
// request still comes from useLeavePage, which Classic uses too; the Classic page is untouched.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/HR/V2/Leave.vue');
const { template, scriptSetup } = parse(source).descriptor;
const html = template.content;
const script = scriptSetup.content;
const settings = html.slice(
    html.indexOf('<!-- Settings (PR 2'),
    html.indexOf('</section>', html.indexOf('data-test="entries"')),
);

test('Settings has four sub-tabs, kept per viewer in this browser only', () => {
    assert.match(
        script,
        /\{ value: 'types', label: 'Types' \},\s*\{ value: 'entitlements', label: 'Entitlements' \},\s*\{ value: 'calendar', label: 'Calendar' \},\s*\{ value: 'balances', label: 'Opening balances' \},/,
    );
    assert.match(
        settings,
        /<V2Tabs[\s\S]*?:model-value="settingsTab"[\s\S]*?id-prefix="leave-settings"/,
    );

    for (const tab of ['types', 'entitlements', 'calendar', 'balances']) {
        assert.match(
            settings,
            new RegExp(
                `v-if="settingsTab === '${tab}'"\\s*id="leave-settings-panel-${tab}"\\s*role="tabpanel"\\s*aria-labelledby="leave-settings-tab-${tab}"`,
            ),
        );
    }

    // Storage can be blocked: every read and write is wrapped, and Types is the fallback.
    assert.match(
        script,
        /try \{\s*const kept = window\.localStorage\.getItem\(SETTINGS_KEY\)/,
    );
    assert.match(script, /catch \{\s*return 'types';/);
    assert.match(
        script,
        /try \{\s*window\.localStorage\.setItem\(SETTINGS_KEY, value\);\s*\} catch/,
    );
    assert.doesNotMatch(script, /visit\(\{[^}]*settings/);
});

test('each sub-tab keeps its content and its data-test hooks', () => {
    for (const hook of [
        'types-card',
        'use-defaults',
        'add-type',
        'defaults-label',
        'entitlements-card',
        'entitlement-cards',
        'save-entitlements',
        'grant',
        'year-actions',
        'working-days-card',
        'save-working-days',
        'holidays-card',
        'entries-card',
        'entries',
    ]) {
        assert.match(settings, new RegExp(`data-test="${hook}"`), hook);
    }

    // Types and holidays are V2 tables that become cards below 1024 px; row actions sit in the row menu.
    assert.match(settings, /<V2Table\s+label="Leave types"[\s\S]*?cards="lg"/);
    assert.match(settings, /<V2RowMenu\s+:label="`Actions for \$\{t\.name\}`"/);
    assert.match(
        settings,
        /<V2Table\s+label="Public holidays"[\s\S]*?cards="lg"/,
    );
    assert.match(
        settings,
        /:label="`Opening balances and adjustments \$\{leave\.year\}`"[\s\S]*?cards="lg"/,
    );

    // One filled action per card: Add leave type, Save entitlements, Save working days, Send for a check.
    assert.match(settings, /data-test="use-defaults"/);
    assert.match(
        settings,
        /variant="outline"[\s\S]{0,120}data-test="use-defaults"/,
    );
    assert.match(settings, /data-test="add-type"/);

    // The weekday chips are real checkboxes for screen readers.
    assert.match(
        settings,
        /role="checkbox"\s*:aria-checked="\s*workingDays\.includes\(d\.value\)\s*"/,
    );

    // Checking someone else's entry stays a row action, never by the entrant (the server's canDecide).
    assert.match(settings, /v-if="e\.canDecide"/);
});

test('the type sheet and the year confirm are V2 dialogs with the same requests', () => {
    assert.doesNotMatch(html, /<Sheet\b|<OperationalConfirmDialog\b/);
    assert.match(
        html,
        /<V2Dialog\s+:open="typeOpen"[\s\S]*?typeForm\.processing \? 'Saving…' : 'Save leave type'[\s\S]*?@confirm="saveType"/,
    );
    assert.match(
        html,
        /<V2Dialog\s+:open="yearAction\.open"[\s\S]*?:title="yearText\.title"[\s\S]*?@confirm="confirmYear"/,
    );
    assert.match(html, /data-test="defaults-details"/);
    assert.match(html, /v-if="yearAction\.error"\s*role="alert"/);
    assert.match(html, /data-test="type-evidence"/);
    assert.match(html, /data-test="type-retention-over-cap"/);

    // Still no request of its own: everything goes through the composable.
    assert.doesNotMatch(
        script,
        /router\.(get|post|put|patch|delete)\(|fetch\(|axios|useForm/,
    );
});
