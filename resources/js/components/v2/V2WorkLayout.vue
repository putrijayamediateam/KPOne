<script setup lang="ts">
import { computed, provide, useSlots } from 'vue';
import { V2_RAIL_FROM } from './railKey';
import type { RailFrom } from './railKey';
/**
 * Version 2 work layout: a title row, then the main panel and a right rail. `railFrom` says where the rail sits beside
 * the main panel: `lg` (from 1024 px; below that the rail goes under the main panel), `xl` (from 1280 px) or `2xl` (from 1536 px,
 * 18rem wide; for a page that has a sidebar next to it). With `xl` or `2xl`, below that width the rail is not shown and the
 * `rail-collapsed` slot is shown above the main panel instead (for example a V2DisclosureBar, "3 need attention").
 * `railBelow` (only with `xl` or `2xl`) says what happens to the rail below that width: `hide` (the default, as above),
 * `stack-top` (the rail stays, ABOVE the main panel) or `stack-bottom` (the rail stays, BELOW it). With a stack option the
 * rail is rendered ONCE and never remounts when the window crosses the breakpoint (browser zoom, resizing, rotating a
 * tablet), so an open dialog or typed text inside it is not lost; `rail-collapsed` is not shown. Blocks that hide when
 * the rail is beside the main panel (V2SelectionBar, V2StickyBar) still appear below the breakpoint, because a stacked
 * rail is not in view at the bottom of a long list.
 * `eyebrow` is a small muted line above the title (for example "Visit" above "KPV-00000008"), so an identifier does not
 * read as a person. It is not part of the heading. The sidebar and the crumb belong to the V2 shell, not to this.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        eyebrow?: string;
        railLabel?: string;
        railWidth?: 'narrow' | 'default';
        railFrom?: RailFrom;
        railBelow?: 'hide' | 'stack-top' | 'stack-bottom';
    }>(),
    {
        description: undefined,
        eyebrow: undefined,
        railLabel: 'Details',
        railWidth: 'default',
        railFrom: 'lg',
        railBelow: 'hide',
    },
);

const slots = useSlots();

// Blocks that must hide when the rail shows (V2SelectionBar) read this, so a page never repeats the breakpoint.
provide(
    V2_RAIL_FROM,
    computed(() => (slots.rail ? props.railFrom : null)),
);
</script>

<template>
    <div data-slot="v2-work-layout" class="flex min-w-0 flex-col gap-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p
                    v-if="eyebrow"
                    class="mb-0.5 text-[13px] leading-[18px] font-medium text-muted-foreground"
                >
                    {{ eyebrow }}
                </p>
                <h1 class="v2-page-title">{{ title }}</h1>
                <p
                    v-if="description"
                    class="mt-1 text-[15px] leading-[22px] text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2 max-sm:w-full [&>*]:max-sm:flex-1"
            >
                <slot name="actions" />
            </div>
        </header>
        <div
            class="grid min-w-0 gap-5"
            :class="
                !$slots.rail
                    ? ''
                    : railFrom === '2xl'
                      ? '2xl:grid-cols-[minmax(0,1fr)_18rem]'
                      : railFrom === 'xl'
                        ? 'xl:grid-cols-[minmax(0,1fr)_18rem]'
                        : railWidth === 'narrow'
                          ? 'lg:grid-cols-[minmax(0,1fr)_17rem]'
                          : 'lg:grid-cols-[minmax(0,1fr)_21rem]'
            "
        >
            <main class="flex min-w-0 flex-col gap-4">
                <div
                    v-if="
                        railFrom !== 'lg' &&
                        railBelow === 'hide' &&
                        $slots['rail-collapsed']
                    "
                    :class="railFrom === 'xl' ? 'xl:hidden' : '2xl:hidden'"
                >
                    <slot name="rail-collapsed" />
                </div>
                <slot name="main" />
            </main>
            <aside
                v-if="$slots.rail"
                :aria-label="railLabel"
                class="min-w-0 flex-col gap-4"
                :class="
                    railFrom === 'lg'
                        ? 'flex'
                        : railBelow === 'hide'
                          ? railFrom === '2xl'
                              ? 'hidden 2xl:flex'
                              : 'hidden xl:flex'
                          : [
                                'flex',
                                railBelow === 'stack-top'
                                    ? railFrom === '2xl'
                                        ? 'order-first 2xl:order-none'
                                        : 'order-first xl:order-none'
                                    : '',
                            ]
                "
            >
                <slot name="rail" />
            </aside>
        </div>
    </div>
</template>
