// Classic only, CO-8: the Classic half of v2-clinical-service, kept when its V2 page was removed.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (p) =>
    readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const classic = read('resources/js/pages/ClinicalService/Index.vue');
const normal = (s) => s.replace(/\s+/g, ' ');

test('Classic Clinical Service Catalogue uses its list, setup, update and activation routes', () => {
    for (const route of [
        "'/clinical-services'",
        '`/clinical-services/${row.publicId}/setup`',
        '`/clinical-services/${editing.value.publicId}`',
        "`/clinical-services/${row.publicId}/${row.isActive ? 'deactivate' : 'activate'}`",
    ]) {
        assert.ok(classic.includes(route), `classic ${route}`);
    }
});

test('Classic Clinical Service Catalogue keeps its toggle wording and the Consultation tariff panel', () => {
    for (const text of [
        'will no longer be available to order on a Treatment Plan.',
        'will become available to order on a Treatment Plan again.',
    ]) {
        assert.ok(normal(classic).includes(text), text);
    }

    assert.ok(classic.includes('<ConsultationTariffPanel'));
});
