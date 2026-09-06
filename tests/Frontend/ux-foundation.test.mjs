import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const { createRenderer, defineComponent, h, nextTick, ref } = Vue;

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const button = read('resources/js/components/ui/button/index.ts');
const input = read('resources/js/components/ui/input/Input.vue');
const select = read('resources/js/components/ui/select/SelectTrigger.vue');
const actionLink = read(
    'resources/js/components/ui/action-link/ActionLink.vue',
);
const tabs = read('resources/js/components/ui/tabs/OperationalTabs.vue');
const pagination = read(
    'resources/js/components/ui/pagination/CompactPagination.vue',
);
const presentationSource = read('resources/js/lib/presentation.ts');
const presentationJavascript = ts.transpileModule(presentationSource, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const presentation = runInNewContext(
    `${presentationJavascript.replaceAll('export ', '')}\n({ formatStatusLabel, statusTone, formatDate, formatDateTime, formatElapsedMinutes, formatRelativeMinutes, formatWaitingMinutes })`,
);

const compileComponent = (path, requireMap = {}) => {
    const source = read(path);
    const descriptor = parse(source, { filename: path }).descriptor;
    const compiled = compileScript(descriptor, {
        id: `test-${path.replaceAll(/\W/g, '-')}`,
        inlineTemplate: true,
    }).content;
    const javascript = ts.transpileModule(compiled, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const componentModule = { exports: {} };
    const localRequire = (specifier) => {
        if (specifier === 'vue') {
            return { ...Vue, ...requireMap.vue };
        }

        if (specifier in requireMap) {
            return requireMap[specifier];
        }

        throw new Error(`Unexpected component import: ${specifier}`);
    };

    Function(
        'require',
        'module',
        'exports',
        javascript,
    )(localRequire, componentModule, componentModule.exports);

    return componentModule.exports.default;
};

const createMountedRenderer = () => {
    let activeElement = null;
    const createElement = (type) => {
        const element = {
            type,
            props: {},
            children: [],
            parent: null,
            focus: () => {
                activeElement = element;
            },
        };

        return element;
    };
    const renderer = createRenderer({
        patchProp(element, key, _previous, next) {
            element.props[key] = next;
        },
        insert(child, parent, anchor = null) {
            child.parent = parent;
            const position = anchor ? parent.children.indexOf(anchor) : -1;

            if (position >= 0) {
                parent.children.splice(position, 0, child);
            } else {
                parent.children.push(child);
            }
        },
        remove(child) {
            const position = child.parent?.children.indexOf(child) ?? -1;

            if (position >= 0) {
                child.parent.children.splice(position, 1);
            }
        },
        createElement,
        createText: (text) => ({ type: '#text', text, parent: null }),
        createComment: (text) => ({ type: '#comment', text, parent: null }),
        setText(node, text) {
            node.text = text;
        },
        setElementText(element, text) {
            element.text = text;
            element.children = [];
        },
        parentNode: (node) => node.parent,
        nextSibling(node) {
            const siblings = node.parent?.children ?? [];

            return siblings[siblings.indexOf(node) + 1] ?? null;
        },
        querySelector: () => null,
        setScopeId: () => {},
        cloneNode: (node) => ({ ...node, props: { ...node.props } }),
        insertStaticContent: () => [null, null],
    });

    return {
        renderer,
        root: createElement('root'),
        activeElement: () => activeElement,
    };
};

const descendants = (node, type) => {
    const matches = node.type === type ? [node] : [];

    return [
        ...matches,
        ...(node.children ?? []).flatMap((child) => descendants(child, type)),
    ];
};

const css = read('resources/css/app.css');

const cssTokens = (selector) => {
    const body = new RegExp(
        `${selector.replace('.', '\\.')}\\s*\\{([\\s\\S]*?)\\n\\}`,
    ).exec(css)?.[1];

    assert.ok(body, `Missing CSS token scope: ${selector}`);

    return Object.fromEntries(
        [...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map((match) => [
            match[1],
            match[2].trim(),
        ]),
    );
};

const hslToRgb = (hue, saturation, lightness) => {
    const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
    const section = (((hue % 360) + 360) % 360) / 60;
    const intermediate = chroma * (1 - Math.abs((section % 2) - 1));
    const [red, green, blue] =
        section < 1
            ? [chroma, intermediate, 0]
            : section < 2
              ? [intermediate, chroma, 0]
              : section < 3
                ? [0, chroma, intermediate]
                : section < 4
                  ? [0, intermediate, chroma]
                  : section < 5
                    ? [intermediate, 0, chroma]
                    : [chroma, 0, intermediate];
    const adjustment = lightness - chroma / 2;

    return [red + adjustment, green + adjustment, blue + adjustment];
};

const parseColor = (value) => {
    if (value.startsWith('#')) {
        return [1, 3, 5].map(
            (position) =>
                Number.parseInt(value.slice(position, position + 2), 16) / 255,
        );
    }

    const match = /^hsl\(([-\d.]+)\s+([-\d.]+)%\s+([-\d.]+)%\)$/.exec(value);
    assert.ok(match, `Unsupported test colour: ${value}`);

    return hslToRgb(
        Number(match[1]),
        Number(match[2]) / 100,
        Number(match[3]) / 100,
    );
};

const blend = (foreground, background, alpha) =>
    foreground.map(
        (channel, index) => channel * alpha + background[index] * (1 - alpha),
    );

const luminance = (color) => {
    const [red, green, blue] = color.map((channel) =>
        channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4,
    );

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
};

const contrast = (foreground, background) => {
    const first = luminance(foreground);
    const second = luminance(background);

    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
};

test('buttons expose the approved hierarchy and interactive states', () => {
    for (const variant of [
        'primary',
        'secondary',
        'ghost',
        'accent',
        'destructive',
    ]) {
        assert.match(button, new RegExp(`${variant}:`));
    }

    assert.match(button, /cursor-pointer/);
    assert.match(button, /disabled:cursor-not-allowed/);
    assert.match(button, /focus-visible:ring-\[3px\]/);
});

test('input and select controls remain visibly editable and accessible', () => {
    assert.match(input, /bg-card/);
    assert.match(input, /read-only:bg-muted/);
    assert.match(input, /disabled:cursor-not-allowed/);
    assert.match(select, /cursor-pointer/);
    assert.match(select, /focus-visible:ring-\[3px\]/);
});

test('operational action links use Button-as-Link semantics', () => {
    assert.match(actionLink, /<Button as-child/);
    assert.match(actionLink, /<Link :href="href">/);
    assert.doesNotMatch(actionLink, /underline/);
});

test('tabs and pagination provide active, focus and disabled states', () => {
    assert.match(tabs, /after:bg-brand/);
    assert.match(tabs, /focus-visible:ring-2/);
    assert.match(tabs, /disabled:cursor-not-allowed/);
    assert.match(pagination, /aria-current="page"/);
    assert.match(pagination, /:disabled=/);
});

test('operational tabs mount with roving focus and automatic keyboard activation', async () => {
    const OperationalTabs = compileComponent(
        'resources/js/components/ui/tabs/OperationalTabs.vue',
    );
    const selected = ref('waiting');
    const emitted = [];
    const tabsData = [
        { value: 'waiting', label: 'Waiting' },
        { value: 'blocked', label: 'Blocked', disabled: true },
        { value: 'serving', label: 'Serving', count: 2 },
        { value: 'completed', label: 'Completed' },
    ];
    const harness = defineComponent({
        setup: () => () =>
            h(OperationalTabs, {
                modelValue: selected.value,
                tabs: tabsData,
                label: 'Visit states',
                'onUpdate:modelValue': (value) => {
                    emitted.push(value);
                    selected.value = value;
                },
            }),
    });
    const mounted = createMountedRenderer();
    mounted.renderer.createApp(harness).mount(mounted.root);
    await nextTick();

    const buttons = () => descendants(mounted.root, 'button');
    const keydown = async (index, key) => {
        let prevented = false;
        buttons()[index].props.onKeydown({
            key,
            preventDefault: () => {
                prevented = true;
            },
        });
        await nextTick();
        await nextTick();
        assert.equal(prevented, true);
    };

    assert.deepEqual(
        buttons().map((button) => button.props.tabindex),
        [0, -1, -1, -1],
    );
    assert.deepEqual(
        buttons().map((button) => button.props['aria-selected']),
        [true, false, false, false],
    );

    await keydown(0, 'ArrowRight');
    assert.equal(emitted.at(-1), 'serving');
    assert.equal(mounted.activeElement(), buttons()[2]);
    assert.deepEqual(
        buttons().map((button) => button.props.tabindex),
        [-1, -1, 0, -1],
    );
    assert.deepEqual(
        buttons().map((button) => button.props['aria-selected']),
        [false, false, true, false],
    );

    await keydown(2, 'ArrowLeft');
    assert.equal(emitted.at(-1), 'waiting');
    await keydown(0, 'ArrowLeft');
    assert.equal(emitted.at(-1), 'completed');
    await keydown(3, 'ArrowRight');
    assert.equal(emitted.at(-1), 'waiting');
    await keydown(0, 'End');
    assert.equal(emitted.at(-1), 'completed');
    await keydown(3, 'Home');
    assert.equal(emitted.at(-1), 'waiting');

    buttons()[2].props.onClick();
    await nextTick();
    assert.equal(emitted.at(-1), 'serving');
    assert.equal(buttons()[1].props.disabled, true);
});

test('status and date presentation are deterministic without changing values', () => {
    assert.equal(
        presentation.formatStatusLabel('not_dispensed'),
        'Not Dispensed',
    );
    assert.equal(presentation.formatStatusLabel('finalized'), 'Finalized');
    assert.equal(presentation.statusTone('approved'), 'success');
    assert.equal(presentation.statusTone('unknown_state'), 'neutral');
    assert.equal(presentation.formatDate('2026-09-11'), '11 Sep 2026');
    assert.equal(
        presentation.formatDateTime(
            '2026-09-11T06:35:00.000Z',
            'Asia/Kuala_Lumpur',
        ),
        '11 Sep 2026, 2:35 PM',
    );
    assert.equal(presentation.formatElapsedMinutes(18), '18 min');
    assert.equal(presentation.formatRelativeMinutes(18), '18 min ago');
    assert.equal(presentation.formatWaitingMinutes(18), 'Waiting 18 min');
});

test('date and duration helpers reject ambiguous or invalid values deterministically', () => {
    const fallback = '—';

    assert.equal(presentation.formatDate('2026-09-11'), '11 Sep 2026');
    assert.equal(presentation.formatDate('2028-02-29'), '29 Feb 2028');
    assert.equal(presentation.formatDate('2026-02-31'), fallback);
    assert.equal(presentation.formatDate('2026-13-01'), fallback);
    assert.equal(presentation.formatDate(null), fallback);
    assert.equal(presentation.formatDate(undefined), fallback);
    assert.equal(presentation.formatDate(''), fallback);
    assert.equal(presentation.formatDate('not-a-date'), fallback);
    assert.equal(presentation.formatDate(new Date(Number.NaN)), fallback);
    assert.equal(presentation.formatDateTime(Number.NaN), fallback);
    assert.equal(
        presentation.formatDateTime(
            '2026-09-11T06:35:00Z',
            'Asia/Kuala_Lumpur',
        ),
        '11 Sep 2026, 2:35 PM',
    );
    assert.equal(
        presentation.formatDateTime(
            '2026-09-11T14:35:00+08:00',
            'Asia/Kuala_Lumpur',
        ),
        '11 Sep 2026, 2:35 PM',
    );

    const originalTimezone = process.env.TZ;

    for (const timezone of ['UTC', 'America/Los_Angeles']) {
        process.env.TZ = timezone;
        assert.equal(
            presentation.formatDateTime('2026-09-11T14:35:00'),
            fallback,
        );
    }

    process.env.TZ = originalTimezone;

    assert.equal(presentation.formatElapsedMinutes(Number.NaN), fallback);
    assert.equal(
        presentation.formatElapsedMinutes(Number.POSITIVE_INFINITY),
        fallback,
    );
    assert.equal(
        presentation.formatElapsedMinutes(Number.NEGATIVE_INFINITY),
        fallback,
    );
    assert.equal(presentation.formatElapsedMinutes(-18), '<1 min');
    assert.equal(presentation.formatElapsedMinutes(0), '<1 min');
    assert.equal(presentation.formatElapsedMinutes(18), '18 min');
    assert.equal(presentation.formatElapsedMinutes(135), '2 hr 15 min');
    assert.equal(presentation.formatRelativeMinutes(Number.NaN), fallback);
    assert.equal(presentation.formatWaitingMinutes(Number.NaN), fallback);
});

test('mounted status badges use tone classes whose text contrast exceeds 4.5 to 1', async () => {
    const StatusBadge = compileComponent(
        'resources/js/components/ui/status/StatusBadge.vue',
        { '@/lib/presentation': presentation },
    );
    const statuses = {
        neutral: 'unknown_state',
        accent: 'serving',
        success: 'completed',
        warning: 'waiting',
        danger: 'cancelled',
    };
    const expectedClasses = {
        neutral: ['text-foreground', 'bg-muted'],
        accent: ['text-brand-strong', 'bg-brand/10'],
        success: ['text-success', 'bg-success/10'],
        warning: ['text-warning-strong', 'bg-warning/10'],
        danger: ['text-destructive', 'bg-destructive/10'],
    };

    for (const [tone, status] of Object.entries(statuses)) {
        const mounted = createMountedRenderer();
        mounted.renderer.createApp(StatusBadge, { status }).mount(mounted.root);
        await nextTick();
        const badgeClass = descendants(mounted.root, 'span')[0].props.class;

        for (const expectedClass of expectedClasses[tone]) {
            assert.match(
                badgeClass,
                new RegExp(
                    `(?:^|\\s)${expectedClass.replace('/', '\\/')}(?:\\s|$)`,
                ),
            );
        }
    }

    const toneTokens = {
        neutral: ['foreground', 'muted', 1],
        accent: ['brand-strong', 'brand', 0.1],
        success: ['success', 'success', 0.1],
        warning: ['warning-strong', 'warning', 0.1],
        danger: ['destructive', 'destructive', 0.1],
    };

    for (const selector of [':root', '.dark']) {
        const tokens = cssTokens(selector);
        const surface = parseColor(tokens.card);

        for (const [
            tone,
            [foregroundName, backgroundName, alpha],
        ] of Object.entries(toneTokens)) {
            const foreground = parseColor(tokens[foregroundName]);
            const backgroundColor = parseColor(tokens[backgroundName]);
            const background =
                alpha === 1
                    ? backgroundColor
                    : blend(backgroundColor, surface, alpha);
            const ratio = contrast(foreground, background);

            assert.ok(
                ratio >= 4.5,
                `${selector} ${tone} badge contrast was ${ratio.toFixed(2)}:1`,
            );
        }
    }
});
