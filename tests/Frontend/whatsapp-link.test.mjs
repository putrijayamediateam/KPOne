import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, defineComponent, h } from 'vue';

const require = createRequire(import.meta.url);
const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const helper = { exports: {} };
runInNewContext(transpile(read('resources/js/lib/whatsapp-link.ts')), {
    exports: helper.exports,
});
const { whatsAppNumber } = helper.exports;

const valid = [
    ['012345678', '6012345678'],
    ['0123456789', '60123456789'],
    ['6012345678', '6012345678'],
    ['60123456789', '60123456789'],
    ['+60123456789', '60123456789'],
    [' (012) 345-6789 ', '60123456789'],
    ['+60 (12) 345.6789', '60123456789'],
    ['012\u00a0345\u20096789', '60123456789'],
    ['+12025550123', '12025550123'],
    ['+44 (20) 7946-0958', '442079460958'],
    ['+12345678', '12345678'],
    ['+123456789012345', '123456789012345'],
];

for (const [input, expected] of valid) {
    test(`normalises a complete displayed number: ${input}`, () => {
        assert.equal(whatsAppNumber(input), expected);
        assert.match(whatsAppNumber(input), /^[0-9]+$/);
    });
}

const invalid = [
    null,
    undefined,
    '',
    ' ',
    123456789,
    {},
    '01234567',
    '01234567890',
    '601234567890',
    '+601234567890',
    '+60312345678',
    '0312345678',
    '041234567',
    '051234567',
    '061234567',
    '071234567',
    '081234567',
    '091234567',
    '012***6789',
    '012xxx6789',
    '012XXX6789',
    '012•••6789',
    '012●●●6789',
    '012…6789',
    '012...6789',
    '012___6789',
    '012#456789',
    '012ABC6789',
    '０１２３４５６７８９',
    '+1234567',
    '+1234567890123456',
    '442079460958',
    '00442079460958',
    '+0123456789',
    '++60123456789',
    '0123456789/0198765432',
    '0123456789,0198765432',
    '0123456789;0198765432',
    '+60123456789 +60198765432',
    '0123456789\n0198765432',
    '012\t3456789',
    '0123456789?text=test',
    'https://wa.me/60123456789',
    '0'.repeat(129),
];
test('rejects masks, partial values, landlines, ambiguous countries, controls and multiple numbers', () => {
    for (const input of invalid) {
        assert.equal(whatsAppNumber(input), null, String(input));
    }
});

// Render the actual link component; only presentation wrappers/icons are replaced.
const passthrough = defineComponent({
    inheritAttrs: false,
    setup:
        (_, { slots }) =>
        () =>
            slots.default?.(),
});
const { descriptor } = parse(
    read('resources/js/components/patient/WhatsAppLink.vue'),
);
const compiled = compileScript(descriptor, {
    id: 'whatsapp-link',
    inlineTemplate: true,
});
const component = { exports: {} };
runInNewContext(transpile(compiled.content), {
    exports: component.exports,
    require: (name) => {
        if (name === '@/lib/whatsapp-link') {
            return helper.exports;
        }

        if (name === '@/components/ui/button') {
            return { Button: passthrough };
        }

        if (name === '@/components/ui/tooltip') {
            return Object.fromEntries(
                [
                    'Tooltip',
                    'TooltipContent',
                    'TooltipProvider',
                    'TooltipTrigger',
                ].map((key) => [key, passthrough]),
            );
        }

        if (name === '@lucide/vue') {
            return {
                MessageCircle: defineComponent({ render: () => h('svg') }),
            };
        }

        return require(name);
    },
});
const render = (phone) =>
    renderToString(
        createSSRApp({ render: () => h(component.exports.default, { phone }) }),
    );
test('actual component renders the exact plain link, privacy attributes, BM name and disclosure', async () => {
    const html = await render('+60 (12) 345-6789');
    assert.match(html, /href="https:\/\/wa\.me\/60123456789"/);
    assert.match(html, /target="_blank"/);
    assert.match(html, /rel="noopener noreferrer"/);
    assert.match(html, /referrerpolicy="no-referrer"/);
    assert.match(html, /aria-label="Hubungi melalui WhatsApp"/);
    assert.match(
        html,
        /title="Nombor ini akan dikongsi dengan WhatsApp apabila dibuka"/,
    );
    assert.match(html, />WhatsApp/);
    assert.doesNotMatch(html, /\?text=|onclick|<button/);
    assert.match(descriptor.template.content, /min-h-12 min-w-12/);
    assert.doesNotMatch(
        descriptor.scriptSetup.content,
        /fetch\(|axios|router\.|@click|window\.open|localStorage/,
    );
});
test('actual component exposes no anchor for masked, invalid or absent values', async () => {
    for (const phone of invalid.filter(
        (value) => typeof value === 'string' || value == null,
    )) {
        assert.doesNotMatch(await render(phone), /<a\b|wa\.me|WhatsApp/);
    }
});
test('only already displayed saved values are bound; correction drafts and new patient inputs are excluded', () => {
    const show = read('resources/js/pages/Patient/Show.vue');
    assert.match(show, /:phone="patient\.contact\.mobilePhone"/);
    const fields = read(
        'resources/js/components/patient/PatientFormFields.vue',
    );
    assert.match(
        fields,
        /!newPatient[\s\S]*originalPhone !== undefined[\s\S]*fields\.mobile_phone === originalPhone/,
    );
    assert.match(fields, /:phone="originalPhone"/);
    const intake = read('resources/js/pages/RegistrationReview/Show.vue');

    for (const field of ['mobile_phone', 'guardian_contact_number']) {
        assert.match(
            intake,
            new RegExp(
                `correction\\.${field} ===\\s*intake\\.fields\\.${field}`,
            ),
        );
        assert.match(intake, new RegExp(`:phone="intake\\.fields\\.${field}"`));
    }

    const claim = read('resources/js/pages/PanelClaims/Show.vue');
    assert.match(claim, /v-if="claim\.patientPhone"/);
    assert.match(claim, /:phone="claim\.patientPhone"/);

    for (const path of [
        'pages/Registration/Show.vue',
        'pages/Registration/Create.vue',
        'pages/Patient/Index.vue',
        'pages/PublicCheckIn/Show.vue',
        'components/patient/PhoneInput.vue',
    ]) {
        assert.doesNotMatch(
            read(`resources/js/${path}`),
            /WhatsAppLink|wa\.me/,
        );
    }
});
