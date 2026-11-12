import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// DD-38 (Daniel; Yasmin's tokens): board and consultation chips use theme tokens, so they read in dark mode.
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const board = read('resources/js/components/patient-board/PatientBoard.vue');
const show = read('resources/js/pages/Clinical/Show.vue');

test('the board status chips use tokens: neutral waiting, warning On Hold, accent serving, outline quiet states', () => {
    assert.match(board, /waiting: 'border-border bg-muted text-foreground'/);
    assert.match(
        board,
        /held: 'border-warning\/25 bg-warning\/10 text-warning-strong'/,
    );
    assert.match(
        board,
        /serving: 'border-primary\/25 bg-primary\/10 text-primary'/,
    );

    for (const tone of ['neutral', 'cancelled', 'removed']) {
        assert.match(
            board,
            new RegExp(`${tone}: 'border-border text-muted-foreground'`),
        );
    }

    assert.match(
        board,
        /class="inline-flex rounded-md border px-2 py-0\.5 text-\[11px\] font-normal"\s*:class="statusClass\(row\.statusTone\)"/,
    );
});

test('Urgent is the danger tone on the board and the consultation page; Returned is neutral', () => {
    assert.match(
        board,
        /border-destructive\/25 bg-destructive\/10 px-2 py-0\.5 text-\[11px\] font-normal text-destructive"\s*>Urgent</,
    );
    assert.match(
        board,
        /border border-border bg-muted px-2 py-0\.5 text-\[11px\] font-normal text-foreground"\s*>Returned</,
    );
    assert.match(
        show,
        /border border-destructive\/25 bg-destructive\/10 px-2 py-1 text-xs font-medium text-destructive"\s*>Urgent</,
    );
});

test('no pale palette fills are left on these chips', () => {
    assert.doesNotMatch(
        board,
        /bg-(?:amber|emerald|rose|pink)-100\/(?:65|70|80)/,
    );
    assert.doesNotMatch(show, /bg-red-50 px-2 py-1 text-xs font-medium/);
});

test('the not-ready-to-call panel uses the warning tokens, not a hard-coded orange palette', () => {
    const board = readFileSync(
        new URL(
            '../../resources/js/components/patient-board/PatientBoard.vue',
            import.meta.url,
        ),
        'utf8',
    );
    const badge = board.match(
        /class="([^"]*)"\s*data-testid="call-readiness-badge"/,
    );
    assert.ok(badge, 'call-readiness badge found');
    assert.match(badge[1], /border-warning\/25 bg-warning\/10/);
    assert.match(badge[1], /text-warning-strong/);
    assert.doesNotMatch(badge[1], /amber-|orange-|dark:/);
});
