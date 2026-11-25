import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const home = readFileSync('resources/js/components/spaces/MeHome.vue', 'utf8');
const page = readFileSync('resources/js/pages/MyDay/Index.vue', 'utf8');
const link = readFileSync('app/Domain/DepartmentSpace/StationLink.php', 'utf8');

test('the compact Today card shows the station and links only when the server gave a link', () => {
    assert.match(home, /data-test="me-station"/);
    assert.match(home, /v-if="stationHref"/);
    assert.match(home, /station\.now \? 'Station now' : 'Station today'/);
});
test('My day passes the station to both Today cards', () => {
    assert.equal(
        (page.match(/:station-href="today\.stationHref"/g) ?? []).length,
        2,
    );
});
test('station links are resolved on the server behind a permission', () => {
    assert.match(link, /\$actor->can\(/);

    for (const k of ['registration', 'ma', 'dispensary', 'treatment']) {
        assert.ok(link.includes(`'${k}'`));
    }
});
