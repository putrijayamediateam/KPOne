import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-8: printable claim pack for one claim or up to 50 claims of one Panel.

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const pack = read('resources/js/pages/PanelClaims/Pack.vue');
const index = read('resources/js/pages/PanelClaims/Index.vue');
const show = read('resources/js/pages/PanelClaims/Show.vue');
const css = read('resources/css/app.css');

test('The pack prints white A4 sheets with the letterhead, a cover for several claims and no photos', () => {
    assert.match(pack, /size: A4;/);
    assert.match(
        pack,
        /\.pack-sheet \{[^}]*background: #fff;[^}]*color: #000;/s,
    );
    assert.match(pack, /class="pack-sheet document-print"/);
    assert.match(css, /\.document-print,/);
    assert.match(pack, /\.pack-sheet \+ \.pack-sheet \{\s*break-before: page;/);
    assert.match(pack, /v-if="!single"[\s\S]*PANEL CLAIM PACK/);
    assert.match(pack, /Total claimed: \{\{ myr\(pack\.totalClaimSen\) \}\}/);
    assert.match(pack, /Photos attached:/);
    assert.doesNotMatch(pack, /<img[^>]*photo/i);
    assert.doesNotMatch(pack, /diagnos/i);
    assert.match(pack, /IC \/ Passport:/);
    assert.match(
        pack,
        /Photos are not printed\. Use A4 paper\. Each print is recorded\./,
    );
    assert.match(
        pack,
        /class="[^"]*print:hidden[^"]*"[\s\S]*Print or save as PDF/,
    );
});

test('The claims list selects claims of one Panel, at most 50, and opens the pack', () => {
    assert.match(index, /const packMax = 50;/);
    assert.match(
        index,
        /const canPack = \(row: ClaimRow\) =>\s*packPanelId\.value === null \|\| row\.panelId === packPanelId\.value;/,
    );
    assert.match(
        index,
        /Only claims of \$\{packPanel\.value\} can be added to this pack/,
    );
    assert.match(
        index,
        /router\.get\('\/panel-claims\/pack', \{\s*claims: packRows\.value\.map\(\(row\) => row\.publicId\),/,
    );
    assert.match(
        index,
        /watch\(\s*\(\) => props\.work\.claims,\s*\(\) => \{\s*packSelected\.value = \{\};/,
    );
    assert.match(index, /min-h-11 min-w-11/);
    assert.match(index, /Print pack/);
});

test('The claim page keeps Download PDF and adds Print claim pack', () => {
    assert.match(show, /@click="printPack"\s*>\s*Download PDF/);
    assert.match(show, /<Link :href="packUrl">Print claim pack<\/Link>/);
    assert.match(show, /\/panel-claims\/pack\?claims\[\]=/);
});
