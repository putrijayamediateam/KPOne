import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('website editors disclose legacy references and use correctly capitalised SEO labels', () => {
    for (const name of ['People', 'Services', 'Editorial']) {
        const source = read(`resources/js/pages/Website/${name}.vue`);
        assert.match(source, /sharedReferencesDiverge/);
        assert.match(source, /danglingServiceLink/);
        assert.match(source, /SEO title/);
        assert.match(source, /SEO description/);
        assert.doesNotMatch(source, /Seo title|Seo description/);
    }
});

test('all reference editors use the coordinated append and explicit people archive destination', () => {
    for (const name of ['People', 'Services', 'Editorial']) {
        assert.match(
            read(`app/Domain/Organisation/Website/Website${name}Service.php`),
            /WebsiteSharedReferences::append/,
        );
    }

    const controller = read('app/Http/Controllers/WebsitePeopleController.php');
    const archive = controller.slice(
        controller.indexOf('public function archive'),
    );
    assert.match(archive, /redirect\(\)->route/);
    assert.match(archive, /website\.doctors\.edit/);
    assert.match(archive, /website\.careers\.edit/);
    assert.doesNotMatch(archive, /back\(/);
});
