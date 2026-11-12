import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileTemplate, parse } from '@vue/compiler-sfc';

// UI-RESTYLE 1a (Queue & TV, owner-authorised 2026-11-12): the TV room settings show one card per room below 1024 px and the
// table from 1024 px. Layout only: the same Edit, Save, Cancel and Activate or Deactivate actions, in the same words.
const path = 'resources/js/pages/QueueDisplay/Settings.vue';
const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const template = parse(source).descriptor.template.content;

test('the page compiles', () => {
    assert.deepEqual(
        compileTemplate({ id: 'room-cards', source: template, filename: path })
            .errors,
        [],
    );
});

test('the rooms table is for 1024 px and wider, and each room is a card below it', () => {
    assert.match(template, /class="mt-4 hidden overflow-x-auto lg:block"/);
    assert.match(template, /class="mt-4 grid gap-3 lg:hidden"/);
    assert.match(template, /data-test="room-card"/);
    assert.match(template, /aria-label="Rooms"/);
});

test('a room card keeps the table actions in the same words, with 44 px targets and a name for each room', () => {
    const start = template.indexOf('data-test="room-cards"');
    const cards = template.slice(start, template.indexOf('</ul>', start));
    const table = template.slice(
        template.indexOf('<table'),
        template.indexOf('</table>'),
    );

    // The table row and the card run the same handlers.
    for (const handler of ['beginEdit(room)', 'toggleRoom(room)', 'saveEdit']) {
        assert.ok(cards.includes(handler) && table.includes(handler), handler);
    }

    for (const word of ['Edit', 'Save', 'Cancel']) {
        assert.match(cards, new RegExp(`>${word}</Button`), word);
    }

    assert.match(
        cards,
        /\$\{room\.isActive \? 'Deactivate' : 'Activate'\} \$\{room\.name\}/,
    );
    assert.match(cards, /:aria-label="`Edit \$\{room\.name\}`"/);
    assert.match(cards, /@click="beginEdit\(room\)"/);
    assert.match(cards, /@click="toggleRoom\(room\)"/);
    assert.match(cards, /@click="saveEdit"/);
    // Every button and field in the card is at least 44 px tall.
    assert.equal((cards.match(/min-h-11/g) ?? []).length >= 4, true);
    assert.match(cards, /class="h-11"/);
    // Status stays a word plus chip, never colour alone.
    assert.match(cards, /<StatusBadge/);
});

test('the add-room form fields and button are 44 px tall below 1024 px and the labels can shrink', () => {
    const form = template.slice(
        template.indexOf('@submit.prevent="addRoom"'),
        template.indexOf('Add room'),
    );
    assert.match(form, /h-11 w-full min-w-0 [^"]*lg:h-9/);
    assert.equal((form.match(/max-lg:h-11/g) ?? []).length, 2);
    assert.match(form, /class="max-lg:min-h-11"/);
    assert.equal(
        (form.match(/<label class="grid min-w-0 gap-1 text-xs">/g) ?? [])
            .length,
        3,
    );
});
