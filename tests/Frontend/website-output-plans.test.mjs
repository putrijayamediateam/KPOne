import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
const read = (file) =>
    readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const source = read('resources/js/pages/Website/OutputPlans.vue');
test('Output plans are private bounded site inputs, never a website renderer', () => {
    const descriptor = parse(source).descriptor;
    const script = compileScript(descriptor, { id: 'output-plan' });
    assert.deepEqual(
        compileTemplate({
            id: 'output-plan',
            filename: 'OutputPlans.vue',
            source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings },
        }).errors,
        [],
    );

    for (const value of [
        'Nothing is public',
        'This does not build a website',
        'noindex,nofollow',
        'maxlength="100"',
        'maxlength="60"',
        'maxlength="200"',
        'maxlength="300"',
        'page.availablePages.includes(key)',
        'page.canApprove',
        'form.confirmation !== page.detail.number',
        '/branch-context',
        'page.value = null',
        'form.reset()',
        'onUnmounted(remove)',
        'wrap-anywhere',
        'md:grid-cols-2',
    ]) {
assert.ok(source.includes(value), value);
}

    assert.doesNotMatch(
        source,
        /v-html|iframe|window.open|fetch\(|type="file"|deploy\(/,
    );
});
test('Output plan destinations stay inside the existing authorised release workspace', () => {
    const routes = read('routes/web.php');
    assert.match(
        routes,
        /website\/releases.*WebsiteOutputPlanController.*sensitive.no-store:no-referrer/,
    );
    assert.match(routes, /website\.output-plans\.decide/);
    const release = read('resources/js/pages/Website/Releases.vue');
    assert.match(release, /page.detail.state === 'approved'/);
    assert.match(release, /Prepare output plan/);
    assert.match(release, /props.outputPlans/);
    const service = read(
        'app/Domain/Organisation/Website/WebsiteOutputPlanService.php',
    );
    assert.doesNotMatch(
        service,
        /website_content_revisions|business_details|User::query|Patient::|Http::|dispatch\(|schedule\(/,
    );
    assert.match(service, /WebsitePermissions::PREPARE/);
    assert.match(service, /WebsitePermissions::APPROVE/);
});
