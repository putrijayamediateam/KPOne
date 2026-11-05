import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const page = read('resources/js/pages/Agents/Office.vue');
const scene = read('resources/js/components/agents/office/OfficeScene.vue');
const figure = read('resources/js/components/agents/office/AgentFigure.vue');
const layout = read('resources/js/components/agents/office/office-layout.ts');
const routes = read('routes/web.php');
const pkg = JSON.parse(read('package.json'));

test('the office is CSS and SVG only, with no 3D or new dependency', () => {
    const deps = { ...pkg.dependencies, ...pkg.devDependencies };

    for (const name of [
        'three',
        '@react-three/fiber',
        'babylonjs',
        'pixi.js',
    ]) {
        assert.equal(deps[name], undefined, `${name} must not be added`);
    }

    for (const source of [page, scene, figure, layout]) {
        assert.doesNotMatch(source, /from 'three'|WebGL|getContext\('webgl/);
    }

    assert.match(scene, /<svg/);
});

test('every agent carries a visible AI agent label and is never presented as staff', () => {
    assert.match(figure, /'AI agent'/);
    assert.match(figure, /'AI agent · Off'/);
    assert.match(page, /label="AI agent"/);
    assert.match(
        page,
        /Everyone in this office is an AI agent, not a member of\s+staff\./,
    );
});

test('lights follow the contract colours and labels', () => {
    for (const [light, label] of [
        ['green', 'Working'],
        ['yellow', 'Waiting for approval'],
        ['red', 'Last run failed'],
        ['grey', 'Idle'],
    ]) {
        assert.match(layout, new RegExp(`${light}: '${label}'`));
    }
});

test('the page polls the office feed every 10 seconds and pauses when hidden', () => {
    assert.match(page, /const POLL_MS = 10_000;/);
    assert.match(page, /requestJson<OfficeFeed>\('\/agents\/office-feed'/);
    assert.match(page, /document\.addEventListener\('visibilitychange'/);
    assert.match(page, /if \(document\.hidden\) \{\s*clearTimeout\(timer\);/);
    assert.match(page, /Live updates paused\. Showing the last view\./);
});

test('the Director desk shows the pending count and opens the inbox', () => {
    assert.match(scene, /Approval inbox/);
    assert.match(
        scene,
        /inboxCount = computed\(\(\) => props\.feed\.pendingProposals\)/,
    );
    assert.match(page, /router\.visit\('\/agents\/inbox'\)/);
    assert.match(page, /if \(props\.canOpenInbox\)/);
});

test('the click panel shows title, current task, last steps and pending proposals', () => {
    assert.match(
        page,
        /\/agents\/office\/agents\/\$\{encodeURIComponent\(code\)\}/,
    );
    assert.match(page, /Last steps/);
    assert.match(
        page,
        /Waiting for a decision \(\{\{ detail\.pendingTotal \}\}\)/,
    );
    assert.match(page, /selectedAgent\.jobTitle/);
    assert.match(page, /selectedAgent\.currentTask/);
});

test('agents are keyboard reachable and motion respects reduced-motion', () => {
    assert.match(figure, /role="button"/);
    assert.match(figure, /tabindex="0"/);
    assert.match(figure, /@keydown\.enter\.prevent="select"/);
    assert.match(figure, /prefers-reduced-motion: reduce/);
    assert.match(scene, /prefers-reduced-motion: reduce/);
});

test('dark mode rules are scoped to the office drawing, never the whole page', () => {
    assert.doesNotMatch(scene, /:global\(/);
    assert.doesNotMatch(figure, /:global\(/);
    assert.match(scene, /^\.dark \.office-scene\.office-scene \{/m);
});

test('office routes sit under the registry view permission', () => {
    assert.match(
        routes,
        /Route::get\('office', \[AgentOfficeController::class, 'index'\]\)->name\('agents\.office'\)/,
    );
    assert.match(
        routes,
        /Route::get\('office\/agents\/\{code\}', \[AgentOfficeController::class, 'agent'\]\)/,
    );
    const group = routes.slice(
        routes.indexOf(
            "permission:ai_agents.view.organisation', 'sensitive.no-store'])->prefix('agents')",
        ),
    );
    assert.ok(group.indexOf("'office'") < group.indexOf('});'));
});
