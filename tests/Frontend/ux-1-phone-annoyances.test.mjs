import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { baseParse } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = (path) =>
    baseParse(parse(read(path)).descriptor.template.content);
const elements = (node) =>
    node.children?.flatMap((child) =>
        child.type === 1 ? [child, ...elements(child)] : [],
    ) ?? [];
const attr = (node, name) =>
    node.props.find((prop) => prop.type === 6 && prop.name === name)?.value
        ?.content;
const directive = (node, name, arg) =>
    node.props.find(
        (prop) =>
            prop.type === 7 &&
            prop.name === name &&
            (arg === undefined || prop.arg?.content === arg),
    );
const classes = (node) => (attr(node, 'class') ?? '').split(/\s+/);

test('UX-1 S1-017: the mobile drawer is controlled and closes when one of its links is followed', () => {
    const path = 'resources/js/components/workspace/WorkspaceHeader.vue';
    const source = read(path);
    const root = template(path);
    const sheet = elements(root).find((node) => node.tag === 'Sheet');

    assert.ok(sheet, 'the header keeps its mobile Sheet');
    assert.equal(
        directive(sheet, 'model', 'open')?.exp?.content,
        'mobileNavOpen',
    );
    assert.match(source, /const mobileNavOpen = ref\(false\);/);

    const mobileNav = elements(root).find(
        (node) =>
            node.tag === 'nav' &&
            attr(node, 'aria-label') === 'Mobile primary navigation',
    );
    const links = elements(mobileNav).filter((node) => node.tag === 'Link');

    assert.ok(links.length > 0);

    for (const link of links) {
        assert.equal(
            directive(link, 'on', 'click')?.exp?.content,
            'mobileNavOpen = false',
        );
    }

    // Live boards reload themselves; closing on every Inertia navigate event would
    // snap the drawer shut while a CA is reading it.
    assert.doesNotMatch(source, /router\.on\(\s*['"]navigate/);
});

test('UX-1 S1-015: staff toasts move to the top below the lg breakpoint and keep the desktop default', () => {
    const source = read('resources/js/lib/toastPlacement.ts');

    assert.match(source, /STAFF_TOAST_DESKTOP_QUERY = '\(min-width: 1024px\)'/);
    assert.match(source, /STAFF_TOAST_TOP_OFFSET = '64px'/);
    assert.match(
        source,
        /if \(desktop\) \{\s*return \{\};\s*\}/,
        'desktop keeps the library default (bottom-right) unchanged',
    );
    assert.match(source, /position: 'top-center'/);
    assert.match(source, /offset: \{ top: STAFF_TOAST_TOP_OFFSET \}/);
    assert.match(source, /mobileOffset: \{ top: STAFF_TOAST_TOP_OFFSET \}/);
});

test('UX-1 S1-015: every staff layout passes the staff placement to its Toaster; the public QR layout is untouched', () => {
    for (const path of [
        'resources/js/layouts/ClinicLayout.vue',
        'resources/js/layouts/AdminLayout.vue',
    ]) {
        const source = read(path);
        const toaster = elements(template(path)).find(
            (node) => node.tag === 'Toaster',
        );

        assert.match(
            source,
            /import \{ useStaffToastPlacement \} from '@\/lib\/toastPlacement';/,
        );
        assert.match(
            source,
            /const toastPlacement = useStaffToastPlacement\(\);/,
        );
        assert.equal(
            directive(toaster, 'bind')?.exp?.content,
            'toastPlacement',
        );
    }

    const publicToaster = elements(
        template('resources/js/layouts/PublicCheckInLayout.vue'),
    ).find((node) => node.tag === 'Toaster');

    assert.equal(directive(publicToaster, 'bind'), undefined);
});

test('UX-1 S1-004: the Today ranking card can shrink inside its grid and its table fits a phone', () => {
    const root = template('resources/js/components/insights/RankingCard.vue');
    const section = elements(root).find((node) => node.tag === 'section');
    const table = elements(root).find((node) => node.tag === 'table');
    const nameCell = elements(root).find(
        (node) => node.tag === 'td' && classes(node).includes('truncate'),
    );

    assert.ok(classes(section).includes('min-w-0'));
    assert.ok(classes(table).includes('sm:min-w-[440px]'));
    assert.ok(!classes(table).includes('min-w-[440px]'));
    assert.ok(classes(nameCell).includes('max-w-32'));
    assert.ok(classes(nameCell).includes('sm:max-w-52'));
    assert.equal(
        directive(nameCell, 'bind', 'title')?.exp?.content,
        'item.name',
        'the full name stays available when it is cut short',
    );
});

test('UX-1 S1-004: the report ranking cards fit a phone; Panel claim outcomes keep their in-card scroll', () => {
    const root = template('resources/js/pages/Insights/Report.vue');
    const rankings = elements(root).find(
        (node) =>
            node.tag === 'section' && attr(node, 'aria-label') === 'Rankings',
    );
    const article = elements(rankings).find((node) => node.tag === 'article');
    const table = elements(rankings).find((node) => node.tag === 'table');
    const tableClass = directive(table, 'bind', 'class')?.exp?.content ?? '';
    const nameCell = elements(rankings).find(
        (node) =>
            node.tag === 'td' &&
            node.children.some(
                (child) =>
                    child.type === 5 &&
                    child.content?.content?.trim() === 'row.name',
            ),
    );

    assert.ok(classes(article).includes('min-w-0'));
    assert.ok(classes(article).includes('overflow-hidden'));
    assert.match(tableClass, /'min-w-\[760px\]'/);
    assert.match(tableClass, /'sm:min-w-\[420px\]'/);
    assert.doesNotMatch(tableClass, /: 'min-w-\[420px\]'/);
    assert.ok(classes(nameCell).includes('max-sm:[overflow-wrap:anywhere]'));
});
