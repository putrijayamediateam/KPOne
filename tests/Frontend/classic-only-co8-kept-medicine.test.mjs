// Classic only, CO-8: the Classic half of v2-medicine, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/Medicine/Index.vue');
const normal = (s) => s.replace(/\s+/g, ' ');

test('Classic Medicine Catalogue uses its list, setup, update, activation, controlled and allergy routes', () => {
    for (const route of [
        "'/medicines'",
        '`/medicines/${row.publicId}/setup`',
        '`/medicines/${editing.value.publicId}`',
        "`/medicines/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`",
        '`/medicines/${row.publicId}/controlled`',
        '`/medicines/${row.publicId}/allergy-groups`',
        '`/medicines/${row.publicId}/allergy-aliases`',
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
    }
});

test('Classic Medicine Catalogue row actions send the expected values and handle a stale list', () => {
    for (const text of [
        'is_controlled: !row.isControlled',
        'expected_is_controlled: row.isControlled',
        'expected_allergy_groups: row.allergyGroups',
        'expected_allergy_aliases: row.allergyAliases',
        "message.startsWith('This medicine changed.')",
        'Could not change the controlled medicine mark. Reload and try again.',
    ]) {
        assert.ok(classic.includes(text), text);
    }
});

test('Classic Medicine Catalogue keeps the wording of its confirms and allergy dialogs', () => {
    for (const text of [
        'will no longer be available to order on a Treatment Plan.',
        'Ubat Terkawal/Controlled Medicine',
        'The Dispensary allergy warning checks a patient',
        'They are never printed on drug labels and do not change the medicine name.',
        'Prints on drug labels: up to 72 characters.',
    ]) {
        assert.ok(normal(classic).includes(text), `classic ${text}`);
    }
});
