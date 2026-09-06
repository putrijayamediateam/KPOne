<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        data-slot="empty-state"
        class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border border-dashed bg-card px-4 py-6 text-center"
    >
        <p class="text-sm font-medium text-foreground">{{ title }}</p>
        <p v-if="description" class="max-w-lg text-xs text-muted-foreground">
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
