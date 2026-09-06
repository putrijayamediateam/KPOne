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
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ eyebrow }}
            </p>
            <h1 class="text-2xl font-semibold tracking-tight text-foreground">
                {{ title }}
            </h1>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="kp-control-group">
            <slot name="actions" />
        </div>
    </header>
</template>
