// Classic only, CO-8: the Classic half of v2-branches, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Branches/Index.vue');
const tight = (s) => s.replace(/\s+/g, '');

test('Classic Branches keeps its props and the link to each branch', () => {
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
    }
});

test('Classic Branches keeps its wording', () => {
    for (const text of [
        'Branches',
        'Locations available within your current permission scope.',
        'Active',
        'Inactive',
        'Klinik Putrijaya branch',
    ]) {
        assert.ok(
            tight(classic).includes(tight(text)),
            `Classic lacks: ${text}`,
        );
    }
});
