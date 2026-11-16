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
    // Types: a table from 1024 px, compact cards below (name, chips, a two-column list; no empty "Carry up to").
    assert.match(
        settings,
        /<V2Table\s+class="hidden lg:block"\s+label="Leave types"/,
    );
    assert.match(
        settings,
        /data-test="type-cards"[\s\S]*?<dl\s+class="grid grid-cols-2/,
    );
    assert.match(settings, /v-if="t\.carryMaxDays"\s+class="col-span-2/);
    assert.match(settings, /:label="`More actions for \$\{t\.name\}`"/);
    assert.match(settings, /label="Archived"/);
    // A single action is a named outline button, not a menu (Daniel): Remove <holiday>.
    assert.match(
        settings,
        /data-test="remove-holiday"\s+@click="removeHoliday\(h\.id\)"\s*>Remove<span\s+class="sr-only"/,
    );
    assert.doesNotMatch(settings, /Actions for \$\{h\.name\}/);
    // Entitlements below 768 px: one column, and the save is a bar at the bottom of the screen.
    assert.match(settings, /class="mt-2 grid gap-3"/);
    assert.match(
        settings,
        /max-md:fixed max-md:inset-x-0 max-md:bottom-0[\s\S]*?data-test="save-entitlements-bar"/,
    );
    // "Send for a check" stays off until the person, type and days (and a reason) are chosen, and says what is missing.
    assert.match(
        settings,
        /:disabled="entryForm\.processing"\s*:aria-disabled="\s*entryMissing !== '' \|\|\s*undefined\s*"/,
    );
    // The reason stays in the page as a live region, so each change is read out (Suresh).
    assert.match(settings, /id="entry-missing"\s*aria-live="polite"/);
    assert.match(
        settings,
        /@submit\.prevent="\s*entryMissing === '' && saveEntry\(\)\s*"/,
    );
    assert.match(script, /'Choose a person to continue\.'/);
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

    // Branch filters only the calendar, so it sits inside the Calendar sub-tab (Daniel); Year stays above the sub-tabs.
    const filters = settings.slice(
        settings.indexOf('data-test="settings-filters"'),
        settings.indexOf('<V2Tabs'),
    );
    assert.match(filters, /label="Year"/);
    assert.doesNotMatch(filters, /label="Branch"/);
    const calendar = settings.slice(
        settings.indexOf('v-if="settingsTab === \'calendar\'"'),
        settings.indexOf('v-if="settingsTab === \'balances\'"'),
    );
    assert.match(calendar, /label="Branch"[\s\S]*?visit\(\{ branch: v \}\)/);

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
        /<V2Dialog\s+:open="yearAction\.open"[\s\S]*?:title="yearText\.title"[\s\S]*?defaultsNothing \? \(yearAction\.open = false\) : confirmYear\(\)/,
    );
    // Nothing to add from the KPOne defaults: a Close button only, sized to its content (Daniel).
    assert.match(
        html,
        /:single-action="defaultsNothing"\s+single-action-style="outline"/,
    );
    assert.match(html, /data-test="defaults-details"/);
    // Nothing to add from the KPOne defaults: a Close button only, sized to its content (Daniel).
    assert.match(
        html,
        /:single-action="defaultsNothing"\s+single-action-style="outline"/,
    );
    // Expire and Archive confirm first, in red (Daniel); Archive still calls the same archiveType.
    assert.match(
        html,
        /:tone="yearAction\.kind === 'expire' \? 'danger' : 'default'"/,
    );
    assert.match(html, /: askArchive\(t\)/);
    assert.match(
        html,
        /data-test="archive-dialog"[\s\S]*?@confirm="confirmArchive"/,
    );
    assert.match(
        script,
        /const confirmArchive = \(\) => \{\s*if \(archiving\.type\) \{\s*archiveType\(archiving\.type\);/,
    );
    assert.match(html, /v-if="yearAction\.error"\s*role="alert"/);
    assert.match(html, /data-test="type-evidence"/);
    assert.match(html, /data-test="type-retention-over-cap"/);

    // Suresh: "Discard changes?" only when something changed since the dialog opened (a snapshot, not isDirty), and
    // Enter in a field saves once, never while a save is running.
    assert.match(html, /:dirty="typeDirty"/);
    assert.doesNotMatch(html, /typeForm\.isDirty/);
    assert.match(
        script,
        /watch\(typeOpen, \(open\) => \{\s*if \(open\) \{\s*void nextTick\(\(\) => \(typeSnapshot\.value = typeFields\(\)\)\);/,
    );
    assert.match(
        html,
        /@submit\.prevent="typeForm\.processing \|\| saveType\(\)"/,
    );
    assert.match(
        html,
        /<button\s+type="submit"\s+class="sr-only"\s+tabindex="-1"\s+aria-hidden="true"/,
    );

    // Still no request of its own: everything goes through the composable.
    assert.doesNotMatch(
        script,
        /router\.(get|post|put|patch|delete)\(|fetch\(|axios|useForm/,
    );
});
