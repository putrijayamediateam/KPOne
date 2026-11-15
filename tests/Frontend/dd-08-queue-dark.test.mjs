import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('the unused QueueRows component is gone and nothing imports it', () => {
    assert.equal(
        existsSync(
            new URL(
                '../../resources/js/components/queue/QueueRows.vue',
                import.meta.url,
            ),
        ),
        false,
    );
    assert.doesNotMatch(
        read('resources/js/pages/Queue/Index.vue'),
        /QueueRows/,
    );
});

test('Cancel Visit on the board uses the menu destructive variant, not a fixed red', () => {
    const board = read(
        'resources/js/components/patient-board/PatientBoard.vue',
    );
    const item = board.match(
        /<DropdownMenuItem\s+v-if="row\.can\.cancel"([\s\S]*?)>Cancel Visit/,
    );
    assert.ok(item, 'Cancel Visit menu item found');
    assert.match(item[1], /variant="destructive"/);
    assert.doesNotMatch(item[1], /red-|rose-/);
});

test('the "My room today" notice uses the warning tokens when no room is chosen', () => {
    const page = read('resources/js/pages/Queue/Index.vue');
    const block = page.match(
        /roomChoice\.currentRoomId === null\s*\?\s*'([^']*)'/,
    );
    assert.ok(block, 'room choice notice found');
    assert.equal(
        block[1],
        'border-warning/25 bg-warning/10 text-warning-strong',
    );
});
