import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/ClinicManagement/Index.vue');
const navigation = read('resources/js/lib/workspace-navigation.ts');
const js = ts.transpileModule(navigation, { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText;
const { mainMenuGroups, headerDestinations } = runInNewContext(`${js.replaceAll('export ', '')}\n({ mainMenuGroups, headerDestinations })`);

test('Clinic management is permission-filtered in Main Menu and account menu, keeping personal Settings', () => {
    const links = (caps) => Array.from(mainMenuGroups(caps)).flatMap((g) => Array.from(g.destinations));
    assert.equal(links({}).some((l) => l.href === '/clinic-management'), false);
    assert.equal(links({ clinicManagement: true }).filter((l) => l.href === '/clinic-management').length, 1);
    assert.equal(Array.from(headerDestinations({ clinicManagement: true }, 'clinic')).length, 0);
    const account = read('resources/js/components/UserMenuContent.vue');
    assert.match(account, /v-if="page.props.workspace\?\.navigation.clinicManagement"/);
    assert.match(account, /href="\/clinic-management"/);
    assert.match(account, /:href="edit\(\)"/);
});

test('Hub compiles with semantic keyboard links, card headings, responsive columns and no sidebar or mutations', () => {
    assert.deepEqual(compileTemplate({ id: 'clinic-hub', source: parse(source).descriptor.template.content, filename: 'Index.vue' }).errors, []);
    assert.match(source, /<Link\s+:href="link.href"/);
    assert.match(source, /focus-visible:outline-2/);
    assert.match(source, /:aria-labelledby=/);
    assert.match(source, /md:grid-cols-2 xl:grid-cols-3/);
    assert.doesNotMatch(source, /router\.(post|put|patch|delete)|useForm|<aside|aria-disabled/);
    assert.doesNotMatch(read('app/Domain/Access/ClinicManagementNavigation.php'), /Reviews|Blasting|Packages|Scheduling|e-Invoice|Tax settings|API key|Price tier|registration-settings|\.download/);
});

test('Clinic management and Business details coexist in Main Menu with independent capability filters', () => {
    const links = (caps) => Array.from(mainMenuGroups(caps)).flatMap((g) => Array.from(g.destinations)).map((l) => l.href);
    assert.deepEqual(links({ clinicManagement: true, businessDetails: true }), ['/clinic-management', '/business-details']);
    assert.deepEqual(links({ clinicManagement: true, businessDetails: false }), ['/clinic-management']);
    assert.deepEqual(links({ clinicManagement: false, businessDetails: true }), ['/business-details']);
    assert.deepEqual(links({ clinicManagement: false, businessDetails: false }), []);
});

test('Unoccupied grid cells use the card surface with visible card borders and no placeholder destinations', () => {
    const template = parse(source).descriptor.template.content;
    const grid = template.match(/<div\s+class="([^"]+)"\s+data-testid="clinic-management-cards"/);
    assert.ok(grid);
    assert.match(grid[1], /\bbg-card\b/);
    assert.doesNotMatch(grid[1], /\bbg-border\b|\bbg-muted\b/);
    assert.match(template, /v-for="\(group, index\) in groups"/);
    assert.match(template, /ring-1 ring-border/);
    assert.doesNotMatch(template, /placeholder|empty-cell|aria-disabled/);
});
