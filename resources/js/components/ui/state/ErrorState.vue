<script setup lang="ts">
withDefaults(
    defineProps<{
        message: string;
        title?: string;
    }>(),
    { title: 'Something needs attention' },
);
</script>

<template>
    <div
        data-slot="error-state"
        class="rounded-lg border border-destructive/25 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        role="alert"
    >
        <p class="font-medium">{{ title }}</p>
        <p class="mt-0.5 text-xs">{{ message }}</p>
        <div v-if="$slots.action" class="mt-2">
            <slot name="action" />
        </div>
    </div>
</template>
