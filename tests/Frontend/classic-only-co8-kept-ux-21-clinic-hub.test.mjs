// Classic only, CO-8: the Classic half of ux-21-v2-clinic-hub, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ClinicManagement/Index.vue');

test('Classic clinic hub takes the server groups of titled links as its props', () => {
    const squash = (x) => x.replace(/\s+/g, ' ');

    assert.ok(
        squash(classic).includes(
            'groups: { title: string; icon: string; links: { title: string; description: string; href: string }[]; }[];',
        ),
    );
});
