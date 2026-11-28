import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const day = readFileSync('resources/js/pages/MyDay/Index.vue', 'utf8');

test('My day shows count tiles under the greeting, from props the page already has', () => {
    assert.match(day, /data-test="space-tiles"/);
    assert.ok(
        day.indexOf('data-test="space-greeting"') <
            day.indexOf('data-test="space-tiles"'),
        'tiles come after the greeting',
    );
    assert.match(day, /props\.waiting\.total/);
    assert.match(day, /props\.next\.thingsTotal/);
    assert.match(day, /props\.today\.credentials\.rows\.length/);
});

test('the tiles add no request and no new prop', () => {
    const script = day.slice(0, day.indexOf('</script>'));

    assert.doesNotMatch(script, /requestJson|fetch\(|router\./);
});

test('a tile that reads 0 is hidden, the row fills the width, and the tiles are not links', () => {
    assert.match(day, /tile\.value > 0/);
    assert.match(day, /class="space-tiles is-fill"/);
    assert.doesNotMatch(
        day.slice(
            day.indexOf('data-test="space-tiles"'),
            day.indexOf('</ul>', day.indexOf('data-test="space-tiles"')),
        ),
        /<Link|href=/,
    );
});
