import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UX-15 (design debt DD-17): Staff Experience's own pages drew their own title in their own size
// (Clinic management at text-lg). They now start with the shared PageHeader, which renders the one h1.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = (path) => {
    const source = read(path);

    return source.slice(source.indexOf('<template>'));
};
const pages = [
    'resources/js/pages/Staff/Index.vue',
    'resources/js/pages/Staff/Show.vue',
    'resources/js/pages/Staff/Create.vue',
    'resources/js/pages/Staff/Edit.vue',
    'resources/js/pages/ClinicManagement/Index.vue',
];

test('UX-15: each Staff Experience page has exactly one title, from PageHeader', () => {
    for (const page of pages) {
        const source = read(page);
        const body = template(page);

        assert.match(
            source,
            /import \{ PageHeader \} from '@\/components\/ui\/page-header';/,
            page,
        );
        assert.equal((body.match(/<PageHeader\b/g) ?? []).length, 1, page);
        assert.doesNotMatch(body, /<h1\b/, page);
    }
});

test('UX-15: the staff page keeps its status badge, wrapping email line and actions in the header', () => {
    const body = template('resources/js/pages/Staff/Show.vue');
    const header = body.slice(
        body.indexOf('<PageHeader'),
        body.indexOf('</PageHeader>'),
    );

    assert.match(header, /<template #title-extra>[\s\S]*staff\.isActive/);
    assert.match(
        header,
        /<template #description>\s*<span class="\[overflow-wrap:anywhere\]"/,
    );

    for (const action of [
        'Directory',
        'Edit profile',
        '<RoleManagementDialog',
        '<StatusConfirmationDialog',
    ]) {
        assert.ok(header.includes(action), action);
    }
});

test('UX-15: Create staff stays an action of the directory header, shown only when allowed', () => {
    const body = template('resources/js/pages/Staff/Index.vue');
    const header = body.slice(
        body.indexOf('<PageHeader'),
        body.indexOf('</PageHeader>'),
    );

    assert.match(header, /<template v-if="canCreate" #actions>/);
    assert.ok(header.includes('/staff/create'));
});

test('UX-15: PageHeader slots are optional and keep one h1 either way', () => {
    const source = read(
        'resources/js/components/ui/page-header/PageHeader.vue',
    );

    assert.match(source, /v-if="\$slots\['title-extra'\]"/);
    assert.match(source, /<slot name="title-extra" \/>/);
    assert.match(
        source,
        /v-if="description \|\| \$slots\.description"[\s\S]*<slot name="description">\{\{ description \}\}<\/slot>/,
    );
    // The two h1 branches are v-if / v-else, so only one renders.
    assert.equal((source.match(/<h1\b/g) ?? []).length, 2);
    assert.match(source, /<h1\s+v-else/);
});
