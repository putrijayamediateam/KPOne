import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

// CO-1 (owner decision 19 Nov 2026): Classic only. The look is always Classic, the spaces render inside the Classic clinic
// layout, the account menu has no layout switch and gets a Spaces row, and no V2 chrome is drawn on a space page.
const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');

test('CO-1: the look is always Classic on first paint and after every visit', () => {
    const blade = read('resources/views/app.blade.php');
    assert.doesNotMatch(blade, /data-ui=|data-look=|\$uiVersion/);
    assert.ok(
        !existsSync(
            new URL('../../resources/js/lib/ui-version.ts', import.meta.url),
        ),
    );
});

test('CO-1: the spaces sit in the Classic clinic layout with the Classic page frame and header', () => {
    const app = read('resources/js/app.ts');
    assert.match(
        app,
        /case name\.startsWith\('Spaces\/'\):\s*return ClinicLayout;/,
    );
    assert.ok(
        !existsSync(
            new URL(
                '../../resources/js/layouts/SpacePassThroughLayout.vue',
                import.meta.url,
            ),
        ),
    );
    assert.ok(
        !existsSync(
            new URL('../../resources/js/lib/v2-spaces.ts', import.meta.url),
        ),
    );

    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.doesNotMatch(page, /defineOptions\(\{ layout:/);
    assert.doesNotMatch(
        page,
        /V2Chip|v2-spaces|SpacePassThroughLayout|v2-page-title/,
    );

    assert.match(page, /<PageHeader :title="space\.title"/);
    assert.match(page, /<PageFrame class="space-shell" data-classic-frame>/);
    // The layout owns the page landmark and the account menu; the page draws neither again.
    assert.doesNotMatch(
        page,
        /id="main-content"|UserMenuContent|id="space-branch"/,
    );
    // The Classic status badge replaces the V2 chip, with the same tones.
    assert.match(
        page,
        /<StatusBadge\s+:status="billing\.cashClosing\.status"\s+:tone="cashTone/,
    );
    assert.match(
        page,
        /<StatusBadge\s+:status="hr\.roster\.status"\s+:tone="rosterTone/,
    );
});

test('CO-1: the other spaces are a named tab strip with the current one marked', () => {
    const page = read('resources/js/pages/Spaces/Show.vue');
    assert.match(
        page,
        /<nav[\s\S]*?aria-label="Spaces"[\s\S]*?class="space-tabs"/,
    );
    assert.match(
        page,
        /:aria-current="s\.key === space\.key \? 'page' : undefined"/,
    );
    const css = read('resources/css/space-shell-layout.css');
    assert.match(
        css,
        /\.space-shell\[data-classic-frame\] \.space-tab \{[^}]*min-height: 44px/,
    );
    // The space tokens follow the Classic theme, light and dark.
    assert.match(
        css,
        /\.space-shell\[data-classic-frame\] \{[^}]*--space-surface: var\(--card\);/,
    );
});

test('CO-1: the account menu has no layout switch and a Spaces row from the server', () => {
    const menu = read('resources/js/components/UserMenuContent.vue');
    assert.doesNotMatch(
        menu,
        /spaces\/preference|Try the new layout|Back to Classic layout|New look \(preview\)|inNewLayout|clock-link-row/,
    );
    assert.match(menu, /v-if="accountMenu\?\.spaces"/);
    assert.match(menu, /href="\/spaces"/);
    assert.match(menu, /data-test="spaces-row"/);
    // The Classic Clock in row stays on every page except Me home, which has its own big Clock in button.
    assert.match(
        menu,
        /v-if="accountMenu\?\.clock && clockVisible && !onMeHome"/,
    );
});
