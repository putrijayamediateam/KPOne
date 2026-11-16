import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

const load = async () => {
    const ts = (await import('typescript')).default;
    const js = ts.transpileModule(
        read('resources/js/lib/clinic-hub-search.ts'),
        {
            compilerOptions: {
                target: ts.ScriptTarget.ES2022,
                module: ts.ModuleKind.ES2022,
            },
        },
    ).outputText;

    return import(
        `data:text/javascript;base64,${Buffer.from(js).toString('base64')}`
    );
};

// A small hub in the shape the server sends (titles and descriptions as in ClinicManagementNavigation).
const groups = [
    {
        title: 'Panel',
        links: [
            {
                title: 'Panel Claims',
                description: 'Manage manual claims and their progress.',
            },
            {
                title: 'Correct a Panel payment',
                description: 'Reverse a wrongly recorded Panel payment.',
            },
        ],
    },
    {
        title: 'Inventory',
        links: [
            {
                title: 'Stock count and write-off',
                description: 'Count stock and approve a variance.',
            },
        ],
    },
    {
        title: 'Patients',
        links: [
            {
                title: 'Daily cash closing',
                description:
                    "Count the day's cash takings; someone else approves the closing.",
            },
            {
                title: 'Billing',
                description: 'Record a refund after a reversed receipt.',
            },
        ],
    },
];

const find = async (query) => {
    const { searchWords, hubLinkMatches } = await load();
    const words = searchWords(query);

    return groups.flatMap((g) =>
        g.links
            .filter((l) => hubLinkMatches(words, g.title, l))
            .map((l) => l.title),
    );
};

test('typing "refund", "stock count" or "panel" narrows the cards; an empty box shows everything', async () => {
    assert.deepEqual(await find('refund'), ['Billing']);
    assert.deepEqual(await find('stock count'), ['Stock count and write-off']);
    assert.deepEqual(await find('panel'), [
        'Panel Claims',
        'Correct a Panel payment',
    ]);
    assert.equal((await find('')).length, 5);
    assert.equal((await find('   ')).length, 5);
    // case does not matter, every word must match, and no match gives nothing
    assert.deepEqual(await find('STOCK Count'), ['Stock count and write-off']);
    assert.deepEqual(await find('panel refund'), []);
    assert.deepEqual(await find('zzz'), []);
});

test('the search only filters the cards the server sent: it adds no link and sends or stores nothing', () => {
    const page = read('resources/js/pages/ClinicManagement/Index.vue');
    assert.match(page, /props\.groups\.reduce/);
    assert.match(page, /v-show="hasMatch\(group\)"/);
    assert.match(page, /v-show="matches\(group, link\)"/);
    // still the same list of groups and links, rendered from the server props
    assert.match(page, /v-for="\(group, index\) in groups"/);
    assert.match(page, /v-for="link in group\.links"/);
    assert.doesNotMatch(
        page,
        /router\.|fetch\(|axios|localStorage|sessionStorage/,
    );
    // a visible label, the polite result sentence and an empty state
    assert.match(page, /<label for="hub-find"[\s\S]*?Find a page/);
    // one polite status container that is always in the page (read once); the input does not also point at it
    assert.match(page, /<div role="status"/);
    assert.doesNotMatch(page, /aria-describedby/);
    assert.match(page, /page matches|pages match/);
    assert.match(page, /No page matches/);
    // the empty message replaces the card grid, so no empty bordered grid is left under it
    assert.match(
        page,
        /data-testid="clinic-management-cards"\s+v-show="!noMatch"/,
    );
    // our own clear button: 44 px below 1024, neutral icon colour, a name, and the native one is hidden
    assert.match(page, /aria-label="Clear search"/);
    assert.match(page, /size-11[^"]*lg:size-9/);
    assert.match(page, /text-muted-foreground[^"]*hover:text-foreground/);
    assert.match(page, /\[&::-webkit-search-cancel-button\]:hidden/);
});
