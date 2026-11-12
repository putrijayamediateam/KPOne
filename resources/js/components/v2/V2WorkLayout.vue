<script setup lang="ts">
/**
 * Version 2 work layout: a title row, then the main panel and a right rail. `railFrom` says where the rail sits beside
 * the main panel: `lg` (from 1024 px; below that the rail goes under the main panel) or `2xl` (from 1536 px, 18rem
 * wide, for a page that has a sidebar next to it). With `2xl`, below 1536 px the rail is not shown and the
 * `rail-collapsed` slot is shown above the main panel instead (for example a V2DisclosureBar, "3 need attention").
 * The sidebar and the crumb belong to the V2 shell, not to this.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        railLabel?: string;
        railWidth?: 'narrow' | 'default';
        railFrom?: 'lg' | '2xl';
    }>(),
    {
        description: undefined,
        railLabel: 'Details',
        railWidth: 'default',
        railFrom: 'lg',
    },
);
</script>

<template>
    <div data-slot="v2-work-layout" class="flex min-w-0 flex-col gap-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
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
                      : railWidth === 'narrow'
                        ? 'lg:grid-cols-[minmax(0,1fr)_17rem]'
                        : 'lg:grid-cols-[minmax(0,1fr)_21rem]'
            "
        >
            <main class="flex min-w-0 flex-col gap-4">
                <div
                    v-if="railFrom === '2xl' && $slots['rail-collapsed']"
                    class="2xl:hidden"
                >
                    <slot name="rail-collapsed" />
                </div>
                <slot name="main" />
            </main>
            <aside
                v-if="$slots.rail"
                :aria-label="railLabel"
                class="min-w-0 flex-col gap-4"
                :class="railFrom === '2xl' ? 'hidden 2xl:flex' : 'flex'"
            >
                <slot name="rail" />
            </aside>
        </div>
    </div>
</template>
