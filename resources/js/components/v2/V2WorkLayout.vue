<script setup lang="ts">
/**
 * Version 2 work layout: a title row, then the main panel and a right rail. From 1024 px they sit side by side;
 * below that the rail goes under the main panel. The sidebar and the crumb belong to the V2 shell, not to this.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        railLabel?: string;
        railWidth?: 'narrow' | 'default';
    }>(),
    { description: undefined, railLabel: 'Details', railWidth: 'default' },
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
                $slots.rail
                    ? railWidth === 'narrow'
                        ? 'lg:grid-cols-[minmax(0,1fr)_17rem]'
                        : 'lg:grid-cols-[minmax(0,1fr)_21rem]'
                    : ''
            "
        >
            <main class="flex min-w-0 flex-col gap-4">
                <slot name="main" />
            </main>
            <aside
                v-if="$slots.rail"
                :aria-label="railLabel"
                class="flex min-w-0 flex-col gap-4"
            >
                <slot name="rail" />
            </aside>
        </div>
    </div>
</template>
