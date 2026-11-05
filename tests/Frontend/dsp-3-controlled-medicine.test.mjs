import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const catalogue = read('resources/js/pages/Medicine/Index.vue');

test('catalogue editors see a Controlled badge and a confirmed mark or unmark action', () => {
    assert.ok(catalogue.includes('isControlled: boolean;'));
    assert.ok(catalogue.includes('data-testid="controlled-badge"'));
    assert.ok(catalogue.includes('data-action="toggle-controlled"'));
    assert.ok(catalogue.includes("'Mark as controlled medicine'"));
    assert.ok(catalogue.includes('Ubat Terkawal / Controlled Medicine'));
    // Owner-approved change (CAT-1, 2026-11-05): the Controlled badge is a
    // neutral word badge built from theme tokens (Daniel's design review), so
    // it stays readable in dark mode without a palette colour.
    assert.match(
        catalogue,
        /<Badge\s+v-if="row\.isControlled"\s+variant="outline"\s+class="ml-1 border-border bg-card text-foreground"\s+data-testid="controlled-badge"/,
        'readable in dark mode',
    );
    assert.ok(
        !catalogue.includes('bg-amber-'),
        'no amber: Controlled is a property, not a status',
    );
});

test('the request states the value the editor saw, so a concurrent change is refused', () => {
    assert.ok(catalogue.includes('`/medicines/${row.publicId}/controlled`'));
    assert.ok(catalogue.includes('expected_is_controlled: row.isControlled,'));
    assert.ok(catalogue.includes('is_controlled: !row.isControlled,'));
});

test('a refused change shows an error, and unmarking is styled as the safety-reducing direction', () => {
    assert.ok(catalogue.includes('toast.error('));
    assert.ok(catalogue.includes('errors.is_controlled ??'));
    assert.ok(
        catalogue.includes(
            ':destructive="pendingControlled.row?.isControlled ?? false"',
        ),
    );
    assert.ok(catalogue.includes('as a controlled medicine`"'));
});
