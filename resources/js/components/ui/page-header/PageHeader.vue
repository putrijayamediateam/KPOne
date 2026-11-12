<script setup lang="ts">
/**
 * Below 640 px the actions group is full width. Two actions sit side by side at half width each. With three or more,
 * the primary (cobalt) action goes first on its own full-width 44 px row and the others share one row beneath it.
 * Never stack several full-width buttons: if there are more secondary actions than fit one row, put them in a "…" menu.
 */
defineProps<{
    title: string;
    description?: string;
    eyebrow?: string;
}>();
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0 space-y-1">
            <p
                v-if="eyebrow"
                class="text-[13px] leading-[18px] font-medium text-muted-foreground"
            >
                {{ eyebrow }}
            </p>
            <!-- UX-15: an optional badge (for example a status) sits beside the title. -->
            <div
                v-if="$slots['title-extra']"
                class="flex flex-wrap items-center gap-2"
            >
                <h1 class="kp-page-title text-foreground">
                    {{ title }}
                </h1>
                <slot name="title-extra" />
            </div>
            <h1 v-else class="kp-page-title text-foreground">
                {{ title }}
            </h1>
            <!-- UX-15: the description slot is for a line that needs markup; plain text uses the prop. -->
            <p
                v-if="description || $slots.description"
                class="text-[15px] leading-[22px] text-muted-foreground"
            >
                <slot name="description">{{ description }}</slot>
            </p>
        </div>
        <div
            v-if="$slots.actions"
            class="kp-control-group max-sm:w-full max-sm:[&>*]:flex-1 max-sm:[&:has(>:nth-child(3))>:is(:not([data-variant]),[data-variant=default],[data-variant=primary])]:order-first max-sm:[&:has(>:nth-child(3))>:is(:not([data-variant]),[data-variant=default],[data-variant=primary])]:basis-full"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
