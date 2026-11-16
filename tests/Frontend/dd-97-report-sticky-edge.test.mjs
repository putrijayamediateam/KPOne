import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-97: the sticky first column of the branch reports has a visible edge,
// drawn with ::after because box-shadow is not painted on collapsed cells.
const page = readFileSync(
    new URL(
        '../../resources/js/pages/Reports/BranchReport.vue',
        import.meta.url,
    ),
    'utf8',
);

test('DD-97: the sticky first column draws its edge with ::after, not box-shadow', () => {
    const base = page.match(/const stickyBase =\s*"([^"]+)"/);
    assert.ok(base, 'stickyBase');
    const classes = base[1];

    for (const token of [
        'sticky',
        'left-0',
        'after:absolute',
        'after:inset-y-0',
        'after:translate-x-full',
        'after:border-l',
        'after:bg-gradient-to-r',
        'after:pointer-events-none',
        "after:content-['']",
    ]) {
        assert.ok(classes.split(' ').includes(token), token);
    }

    assert.doesNotMatch(classes, /shadow-\[/);
});
