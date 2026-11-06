import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// UX-11 (design debt DD-08): the Staff pages and the shared patient board drew pale palette
// backgrounds (bg-emerald-100, bg-amber-100/70 and similar) with no dark style, so they stayed
// as light panels in dark mode. They now use the theme tokens, which work in both themes.
const files = [
    'resources/js/pages/Staff/Create.vue',
    'resources/js/pages/Staff/Edit.vue',
    'resources/js/pages/Staff/Index.vue',
    'resources/js/pages/Staff/Show.vue',
    'resources/js/pages/Staff/Partials/AssignmentStateBadge.vue',
    'resources/js/components/patient-board/PatientBoard.vue',
];
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('UX-11: no pale palette background without a dark style on Staff pages and the patient board', () => {
    for (const file of files) {
        const source = read(file);

        for (const match of source.matchAll(/"([^"]*)"|'([^']*)'|`([^`]*)`/g)) {
            const classes = match[1] ?? match[2] ?? match[3] ?? '';

            if (
                /(?<![\w:-])bg-(?:[a-z]+-(?:50|100)(?:\/\d+)?|white)\b/.test(
                    classes,
                )
            ) {
                assert.match(classes, /dark:/, `${file}: ${classes}`);
            }
        }
    }
});

test('UX-11: status chips use the theme tokens', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );
    assert.match(board, /waiting: 'bg-warning\/10 text-warning-strong'/);
    assert.match(board, /serving: 'bg-success\/10 text-success'/);
    assert.match(board, /held: 'bg-warning\/15 text-warning-strong'/);

    const badge = read(
        'resources/js/pages/Staff/Partials/AssignmentStateBadge.vue',
    );
    assert.match(badge, /border-success\/25 bg-success\/10 text-success/);
    assert.match(badge, /border-brand\/25 bg-brand\/10 text-brand-strong/);
    assert.match(badge, /border-border bg-muted text-muted-foreground/);
});
