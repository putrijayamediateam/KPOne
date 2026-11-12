<script setup lang="ts">
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
                class="text-[13px] leading-[18px] font-medium tracking-wide text-muted-foreground uppercase"
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
            class="kp-control-group max-sm:w-full max-sm:[&>*]:flex-1"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
