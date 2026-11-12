<script setup lang="ts">
/**
 * Version 2 summary rail: what is about to be submitted, with the actions under it. Sticky from 1024px (it sits
 * under the main panel on narrower screens). On phones the page also shows V2StickyBar for the main action.
 */
defineProps<{
    title: string;
    items: { label: string; value: string }[];
}>();
</script>

<template>
    <aside
        data-slot="v2-sticky-summary"
        class="flex min-w-0 flex-col gap-3 rounded-[14px] border bg-card p-4 lg:sticky lg:top-4"
    >
        <h2 class="text-base leading-[22px] font-semibold">{{ title }}</h2>
        <dl class="flex flex-col gap-2 text-sm">
            <div
                v-for="item in items"
                :key="item.label"
                class="flex items-baseline justify-between gap-3"
            >
                <dt class="text-muted-foreground">{{ item.label }}</dt>
                <dd class="text-right font-medium">{{ item.value }}</dd>
            </div>
        </dl>
        <div
            v-if="$slots.default"
            class="flex flex-col gap-2 [&>*]:h-11 lg:[&>*]:h-9"
        >
            <slot />
        </div>
    </aside>
</template>
