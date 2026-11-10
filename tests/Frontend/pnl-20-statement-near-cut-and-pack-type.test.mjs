import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// PNL-20: the statement helper says when near matches looked at only part of the Panel's open claims, and the claim
// pack uses the shared letterhead type (CAT-9 added phone_secondary to it) instead of widening it locally.

const read = (path) =>
    readFileSync(
        new URL(`../../resources/js/${path}`, import.meta.url),
        'utf8',
    );

const helper = read('components/panel/PanelStatementHelper.vue');
const pack = read('pages/PanelClaims/Pack.vue');
const letterhead = read('types/letterhead.ts');

test('The cut note shows only when the server says near matches were cut and a row is Not found', () => {
    assert.match(helper, /nearCut\?: boolean;/);
    assert.match(helper, /nearLimit\?: number;/);
    assert.match(
        helper,
        /v-if="statement\.nearCut && notFoundCount > 0"[\s\S]*?data-testid="statement-near-cut"/,
    );
    assert.match(helper, /oldest open claims/);
    assert.match(helper, /\{\{ statement\.nearLimit \?\? 500 \}\}/);
});

test('The cut note is quiet text, not an alert', () => {
    const note = helper.match(
        /<p\s+v-if="statement\.nearCut[\s\S]*?<\/p>/,
    )?.[0];
    assert.ok(note, 'note found');
    assert.doesNotMatch(note, /role="alert"/);
    assert.match(note, /text-muted-foreground/);
    assert.match(note, /role="note"/);
});

test('The claim pack takes the shared letterhead type with the second phone', () => {
    assert.match(letterhead, /phone_secondary\?: string \| null;/);
    assert.match(pack, /letterhead: PrintLetterhead;/);
    assert.doesNotMatch(pack, /PrintLetterhead &/);
    assert.match(pack, /props\.letterhead\.details\.phone_secondary/);
});
