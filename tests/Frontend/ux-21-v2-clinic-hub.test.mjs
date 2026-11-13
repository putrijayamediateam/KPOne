import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const page = read('resources/js/pages/ClinicManagement/V2/Index.vue');
const classic = read('resources/js/pages/ClinicManagement/Index.vue');

test('UX-21: the V2 hub is registered and uses the same props as Classic', () => {
    const squash = (x) => x.replace(/\s+/g, ' ');

    assert.match(read('config/v2-pages.php'), /'ClinicManagement\/Index',/);
    assert.ok(
        squash(classic).includes(
            'groups: { title: string; icon: string; links: { title: string; description: string; href: string }[]; }[];',
        ),
    );
    assert.ok(
        squash(page).includes(
            'groups: { title: string; icon: string; links: HubLink[] }[];',
        ),
    );
    assert.ok(
        squash(page).includes(
            'type HubLink = { title: string; description: string; href: string };',
        ),
    );
});

test('UX-21: PageFrame and the V2 work layout, so the crumb and gutters match the shell', () => {
    assert.match(page, /<PageFrame>/);
    assert.match(page, /<V2WorkLayout\s+title="Clinic management"/);
    assert.match(
        page,
        /description="Settings, reports and records you can open\."/,
    );
});

test('UX-21: "Find a page" filters the server links in the browser only, with a spoken result and an empty state', () => {
    assert.doesNotMatch(page, /fetch\(|axios|router\.(get|post|visit|reload)/);
    assert.match(
        page,
        /<label for="hub-find" class="sr-only">Find a page<\/label>/,
    );
    assert.match(page, /aria-describedby="hub-find-result"/);
    assert.match(page, /id="hub-find-result" class="sr-only" role="status"/);
    assert.match(page, /No page matches “\{\{ query\.trim\(\) \}\}”\./);
    assert.match(page, /props\.groups/);
});

test('UX-21: cards with a heading per group, 44 px rows with a 3:1 focus ring, grid 1/2/3 columns', () => {
    assert.match(page, /:aria-label="group\.title"/);
    assert.match(
        page,
        /<h2\s+class="min-w-0 flex-1 text-\[15px\] font-semibold"\s*>/,
    );
    assert.match(
        page,
        /class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3"/,
    );
    assert.match(
        page,
        /min-h-11[^"]*focus-visible:ring-2 focus-visible:ring-ring/,
    );
    assert.match(page, /rounded-\[14px\] border bg-card/);
    assert.doesNotMatch(page, /dataset\.ui|data-ui|applyUiVersion/);
});

test('UX-21: the controller relabels only for the V2 page, with the sidebar label helper', () => {
    const controller = read(
        'app/Http/Controllers/ClinicManagementController.php',
    );

    assert.match(
        controller,
        /if \(\$ui->pageFor\('ClinicManagement\/Index'\) !== 'ClinicManagement\/Index'\) \{/,
    );
    assert.match(controller, /V2ShellNavigation::label\(\$link\['title'\]\)/);
});

test('UX-21 Daniel: KPOne HR gets its own card with short names; the full name stays as the title', () => {
    assert.match(page, /const HR_PREFIX = \/\^KPOne HR\\s\+\/i;/);
    assert.match(page, /key: 'kpone-hr',\s+title: 'KPOne HR',/);
    // Sofia: one HR card from every group, empty groups dropped, ids safe for aria-controls.
    assert.match(page, /out\.splice\(hrAt, 0, \{/);
    assert.match(page, /if \(rest\.length > 0\) \{/);
    assert.match(page, /key: slug\(group\.title\)/);
    assert.match(page, /\.replace\(\/\[\^a-z0-9\]\+\/g, '-'\)/);
    assert.match(page, /link\.fullTitle !== link\.title\s+\? link\.fullTitle/);
    // Search still matches the full name.
    assert.match(
        page,
        /`\$\{link\.fullTitle\} \$\{link\.description\} \$\{group\.title\}`/,
    );
});

test('UX-21 Daniel: below 640 px each card is a closed disclosure with its count; a search opens the matches', () => {
    assert.match(page, /<h2 class="sm:hidden">/);
    assert.match(page, /:aria-expanded="isOpen\(group\.key\)"/);
    assert.match(page, /:aria-controls="`hub-list-\$\{group\.key\}`"/);
    assert.match(page, /'max-sm:hidden': !isOpen\(group\.key\)/);
    assert.match(
        page,
        /words\.value\.length > 0 \|\| opened\.value\.has\(key\)/,
    );
    assert.match(page, /· \{\{ group\.links\.length \}\}/);
});

test('UX-21 Daniel: search results are announced politely ("2 pages found")', () => {
    assert.match(page, /`\$\{pageCount\(matchCount\.value\)\} found`/);
    assert.match(page, /'No pages found'/);
    assert.match(page, /role="status"/);
});
