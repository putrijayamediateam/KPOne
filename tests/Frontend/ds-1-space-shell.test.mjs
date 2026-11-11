import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('the space shell page uses the server list and has no menu link or mutation', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /defineProps<\{\s*space: Space;\s*spaces: Space\[\];/);
    assert.match(page, /aria-current/);
    assert.match(page, /id="main-content"/);
    assert.doesNotMatch(page, /router\.(put|patch|delete)|fetch\(/);
    assert.equal((page.match(/router\.post\(/g) ?? []).length, 1);
    assert.match(page, /router\.post\(\s*'\/branch-context'/);
    assert.match(page, /defineOptions\(\{ layout:/);
});

test('the shell touch targets are 44 px and the layout collapses below 768 px', () => {
    const css = read('resources/css/space-shell-layout.css');
    assert.match(css, /\.space-item \{[^}]*min-height: 44px/);
    assert.match(css, /@media \(max-width: 767px\)/);
});

test('spaces are not linked from the menu until the DS-2 switch', () => {
    for (const f of ['resources/js/components/AppShell.vue']) {
        assert.doesNotMatch(read(f), /\/spaces/);
    }
});

test('the shell layout css is imported and every selector is scoped to the spaces shell', () => {
    assert.match(read('resources/css/app.css'), /space-shell-layout\.css/);
    const css = read('resources/css/space-shell-layout.css').replace(
        /\/\*[\s\S]*?\*\//g,
        '',
    );

    for (const [, selector] of css
        .replace(/@media[^{]*\{/g, '')
        .matchAll(/([^{}]+)\{/g)) {
        for (const part of selector.split(',')) {
            assert.match(part.trim(), /^\.space-shell\b/, part);
        }
    }
});

test('DS-2: the switch lives in the user menu, defaults off and sends the lock version', () => {
    const menu = read('resources/js/components/UserMenuContent.vue');
    assert.match(menu, /router\.put\('\/spaces\/preference'/);
    assert.match(
        menu,
        /lock_version: page\.props\.workspace\?\.spaces\?\.lockVersion/,
    );
    assert.match(menu, /Use the old layout/);
    assert.match(menu, /page\.url\.startsWith\('\/spaces'\)/);
    assert.match(menu, /switchSpaces\(!inNewLayout\)/);
    assert.match(menu, /v-if="page\.props\.workspace\?\.spaces\?\.offered"/);
});

test('DS-1: the shell has a named nav, a branch switcher, an account menu, a way back to the existing screens and a right-edge fade', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    const css = read('resources/css/space-shell-layout.css');
    assert.match(page, /<nav aria-label="Spaces"/);
    assert.match(page, /id="space-branch"/);
    assert.match(page, /UserMenuContent/);
    assert.match(page, /Open the existing \{\{ existing\.title \}\} screens/);
    assert.match(page, /scrollIntoView/);
    assert.match(css, /\.has-more/);
    assert.match(css, /\.space-select,[\s\S]*min-height: 44px/);
});

test('DS-3: the Pharmacy widgets render only what the server sent and mutate nothing', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /v-if="pharmacy\.toDispense"/);
    assert.match(page, /v-if="pharmacy\.returnsToCheck"/);
});

test('DS-2: below 640 px the branch select and an initials-only account button share one row', () => {
    const css = read('resources/css/space-shell-layout.css');
    assert.match(
        css,
        /@media \(max-width: 639px\)[\s\S]*\.space-top[\s\S]*flex-wrap: nowrap/,
    );
    assert.match(css, /\.space-account > div \{\s*display: none;/);
});

test('DS-2: account menu rows are 44 px below 640 px', () => {
    assert.match(
        read('resources/js/pages/Spaces/Show.vue'),
        /max-sm:\[&_\[role=menuitem\]\]:min-h-11/,
    );
});

test('DS-3: a Pharmacy space with no widgets still links to the existing screens', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.equal(
        (
            page.match(
                /Open the existing\s+\{\{ existing\.title \}\} screens/g,
            ) ?? []
        ).length,
        5,
    );
});

test('DS-2: the account button names the person', () => {
    assert.match(
        read('resources/js/pages/Spaces/Show.vue'),
        /:aria-label="`Account menu, \$\{user\.name\}`"/,
    );
});

test('DS-3: the Pharmacy list shows a queue number, waiting words, a cap line, empty lines and chevrons', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /row\.queueNumber/);
    assert.match(page, /Long wait/);
    assert.match(page, /Very long wait/);
    assert.match(
        page,
        /Showing \{\{ pharmacy\.toDispense\.rows\.length \}\} of/,
    );
    assert.match(page, /Nothing is waiting for a check\./);
    assert.match(page, /Nothing is waiting at Dispensary\./);
    assert.match(page, /Stock watch/);
    assert.match(page, /ChevronRight/);
    assert.doesNotMatch(page, /patientName|fullName/);
});

test('DS-3: See all links to the Dispensary list, the chevron sits outside the row body and the visit number is not shown', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    const css = read('resources/css/space-shell-layout.css');
    assert.match(page, /pharmacy\.toDispense\.seeAllHref/);
    assert.match(page, />See all</);
    assert.match(css, /\.space-row-text \{[^}]*flex-wrap: wrap/);
    assert.doesNotMatch(page, /\{\{\s*row\.visitNumber\s*\}\}/);
});

test('DS-3a: the Ask panel posts a preset question key only, with no free-text box', () => {
    const panel = read('resources/js/components/spaces/SpaceAskPanel.vue');
    assert.match(panel, /\/spaces\/\$\{props\.space\}\/ask/);
    assert.match(panel, /JSON\.stringify\(\{ question: question\.key \}\)/);
    assert.match(panel, /aria-live="polite"/);
    assert.doesNotMatch(panel, /<input|<textarea|v-model/);
    assert.match(read('resources/js/pages/Spaces/Show.vue'), /<SpaceAskPanel/);
    assert.match(
        read('resources/css/space-shell-layout.css'),
        /\.space-ask-btn \{[^}]*min-height: 44px/,
    );
});

test('DS-3a: Quick questions start unpicked and have loading, failed and answer states with an as-of stamp and a full-list link', () => {
    const panel = read('resources/js/components/spaces/SpaceAskPanel.vue');
    assert.match(panel, />Quick questions</);
    assert.match(panel, /Fixed questions\. Nothing is typed\./);
    assert.match(panel, /Pick a question/);
    assert.match(panel, /space-ask-skeleton/);
    assert.match(panel, /Could not load\. Try again\./);
    assert.match(panel, /Retry/);
    assert.match(panel, /as of \{\{ answer\.asOf \}\}/);
    assert.match(panel, /Open the full list/);
    assert.doesNotMatch(panel, /onMounted|immediate/);
});

test('DS-3a: short names never wrap in the phone table and Retry shares the row with the message from 640 px', () => {
    const css = read('resources/css/space-shell-layout.css');
    assert.match(
        css,
        /\.space-ask-table td:nth-child\(2\) \{\s*white-space: nowrap;/,
    );
    assert.match(
        css,
        /@media \(min-width: 640px\) \{\s*\.space-shell \.space-ask-failed \{\s*flex-direction: row;/,
    );
    assert.match(
        read('resources/js/components/spaces/SpaceAskPanel.vue'),
        /class="space-ask-failed"/,
    );
});

test('DS-4: the Front desk shows waiting, a glance, QR count only, open links and an empty fallback', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /v-else-if="frontDesk"/);
    assert.match(page, /v-if="frontDesk\.waiting"/);
    assert.match(page, /Today at a glance/);
    assert.match(page, /QR check-ins to review/);
    assert.match(page, /No QR check-ins are waiting for review\./);
    assert.doesNotMatch(
        page,
        /frontDesk\.[a-zA-Z.]*patientName|qrPending\.(summary|items)/,
    );
});

test('DS-4: Refresh reloads only the Front desk data, with no polling', () => {
    const page = read('resources/js/components/spaces/SpaceAsOf.vue');
    assert.match(page, /router\.reload\(\{\s*only: \[props\.only\]/);
    assert.match(page, /Refreshing…/);
    assert.doesNotMatch(page, /setInterval|setTimeout/);
    const show = read('resources/js/pages/Spaces/Show.vue');
    assert.match(show, /only="frontDesk"/);
    assert.match(show, /only="pharmacy"/);
    assert.match(
        read('resources/css/space-shell-layout.css'),
        /\.space-refresh \{[^}]*min-height: 44px/,
    );
});

test('DS-4: the primary action sits in the header, rows and buttons stack on phones, the glance is 2x2 and a failed refresh says so', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    const css = read('resources/css/space-shell-layout.css');
    assert.match(page, /class="space-primary"/);
    assert.match(
        read('resources/js/components/spaces/SpaceAsOf.vue'),
        /Could not refresh\. Still showing the update from/,
    );
    assert.match(page, /space-stats is-glance/);
    assert.match(
        css,
        /@media \(max-width: 479px\)[\s\S]*\.space-primary \{\s*width: 100%/,
    );
    assert.match(
        css,
        /\.space-stats\.is-glance \{\s*grid-template-columns: repeat\(2/,
    );
    assert.match(css, /\.space-ask-list \{\s*flex-direction: column/);
});

test('DS-5: the Billing space shows pay later, cash closing and awaiting billing with words, a primary action and the empty fallback', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(page, /v-else-if="billing"/);
    assert.match(page, /only="billing"/);
    assert.match(page, /Pay later to collect/);
    assert.match(page, /Cash closing today/);
    assert.match(page, /Visits awaiting billing/);
    assert.match(page, /Changed after approval\./);
    assert.match(page, /props\.billing\?\.primaryAction/);
    assert.doesNotMatch(page, /billing\.[a-zA-Z.]*patientName/);
});
