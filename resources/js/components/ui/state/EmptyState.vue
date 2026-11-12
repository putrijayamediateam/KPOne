<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        data-slot="empty-state"
        class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-[14px] border bg-card px-4 py-6 text-center"
    >
        <!-- UI-RESTYLE 0.3b: an optional decorative icon; a page that passes none looks as before. -->
        <div
            v-if="$slots.icon"
            class="text-muted-foreground [&_svg]:size-6"
            aria-hidden="true"
        >
            <slot name="icon" />
        </div>
        <p class="text-base leading-[22px] font-semibold text-foreground">
            {{ title }}
        </p>
        <p
            v-if="description"
            class="max-w-lg text-sm leading-5 text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
