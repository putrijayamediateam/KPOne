import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Branches/Index.vue');
const page = read('resources/js/pages/Branches/V2/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');
const flat = (s) => s.replace(/\s+/g, ' ');

test('V2 keeps the Classic props and the link to each branch', () => {
    for (const piece of [
        'id: number;',
        'code: string;',
        'name: string;',
        'timezone: string;',
        'isActive: boolean;',
        'selectedBranchId: number | null;',
        ':href="`/branches/${branch.id}`"',
        'View authorised branch →',
    ]) {
        assert.ok(
            tight(classic).includes(tight(piece)),
            `Classic lacks ${piece}`,
        );
        assert.ok(tight(page).includes(tight(piece)), `V2 lacks ${piece}`);
    }
});

test('V2 keeps the Classic wording and says "Selected" in words, not only a coloured border', () => {
    for (const text of [
        'Branches',
        'Locations available within your current permission scope.',
        'Active',
        'Inactive',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
        assert.ok(tight(page).includes(tight(text)), `V2 lacks: ${text}`);
    }

    assert.ok(flat(page).includes('label="Selected"'));
    assert.ok(!page.includes('Current branch'));
    assert.ok(flat(page).includes('selectedBranchId === branch.id'));
});

test('V2 is read only, names each branch for screen readers and has 44 px actions that fill the width on phones', () => {
    assert.ok(!/fetch\(|router\.|useForm|<form|<button/.test(page));
    assert.ok(
        flat(page).includes(
            ':aria-label="`View authorised branch ${branch.name}`"',
        ),
    );
    assert.ok(tight(page).includes('h-11'));
    assert.ok(tight(page).includes('max-sm:w-full'));
    assert.ok(
        flat(page).includes(
            'No branches are available within your permission scope.',
        ),
    );
});

test('V2 branch header wraps, so a long name keeps its words whole beside the status chips', () => {
    const f = flat(page);

    assert.ok(
        f.includes(
            'flex flex-wrap items-start justify-between gap-x-3 gap-y-2',
        ),
    );
    assert.ok(f.includes('min-w-[11rem] flex-1'));
    assert.ok(!f.includes('flex shrink-0 flex-wrap justify-end'));
});

test('V2 shows no placeholder location line (the server sends no address), and Inactive is a neutral state, not an error', () => {
    // Classic repeats the fixed words "Klinik Putrijaya branch" with a map pin on every card; V2 leaves the line out.
    assert.ok(classic.includes('Klinik Putrijaya branch'));
    assert.ok(!page.includes('Klinik Putrijaya branch'));
    assert.ok(!page.includes('MapPin'));
    assert.ok(flat(page).includes("branch.isActive ? 'success' : 'neutral'"));
    assert.ok(!page.includes("'danger'"));
    // Props carry no address, so none may be invented.
    assert.ok(!/address/i.test(page.replace(/\/\*[\s\S]*?\*\//, '')));
});

test('the branch link opens /branches/{id}, which renders this same list with that branch marked Selected', () => {
    assert.ok(tight(page).includes(':href="`/branches/${branch.id}`"'));
    assert.ok(flat(page).includes('selectedBranchId === branch.id'));
});
