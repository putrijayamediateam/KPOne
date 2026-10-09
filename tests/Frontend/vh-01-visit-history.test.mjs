import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const squash = (value) => value.replace(/\s+/g, ' ');

test('a completed Registration row opens the visit history, without hijacking its own controls', () => {
    const board = squash(
        read('resources/js/components/patient-board/PatientBoard.vue'),
    );
    const registration = read('resources/js/pages/Registration/Index.vue');

    assert.match(registration, /historyUrl: visit\.historyUrl/);
    assert.match(board, /@click="openHistory\(\$event, row\)"/);
    assert.match(board, /row\.historyUrl \? 'cursor-pointer' : ''/);
    // links, buttons and the actions menu keep their own behaviour
    assert.match(
        board,
        /target\?\.closest\('a, button, \[role="menuitem"\], \[role="menu"\]'\)/,
    );
    assert.match(board, /router\.visit\(row\.historyUrl\)/);
    // keyboard users get the same destination from the actions menu
    assert.match(
        board,
        /v-if="row\.historyUrl"[^>]*as-child[\s\S]*?Visit History/,
    );
});

test('the history page shows only what exists today and is read only', () => {
    const page = squash(read('resources/js/pages/Visits/History.vue'));

    for (const label of [
        'All',
        'Consultation',
        'Items',
        'Services',
        'Documents',
    ]) {
        assert.ok(
            page.includes(`label: '${label}'`) ||
                page.includes(`label: \`${label} `),
            `${label} tab`,
        );
    }

    for (const testId of [
        'history-patient',
        'history-consultation',
        'history-items',
        'history-services',
        'history-documents',
        'history-financial',
    ]) {
        assert.ok(page.includes(`data-testid="${testId}"`), testId);
    }

    // not built in KPOne, so not present
    assert.doesNotMatch(
        page,
        /Reservations|Pre-orders|Packages|e-Invoice|Create claim|Add note/,
    );
    // no mutation affordance
    assert.doesNotMatch(page, /<form|router\.(post|put|patch|delete)|useForm/);
    assert.match(page, /Billing details are not shown for your role\./);
});

test('the history route is permission-gated and not cacheable', () => {
    const routes = read('routes/web.php');

    assert.match(
        routes,
        /visits\/\{visit\}\/history', VisitHistoryController::class\)\s*->middleware\(\['permission:visits\.history\.view\.branch', 'sensitive\.no-store'\]\)\s*->name\('visits\.history'\)/,
    );
});
