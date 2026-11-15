<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';

withDefaults(
    defineProps<{
        label?: string;
        mode?: 'spinner' | 'skeleton';
        rows?: number;
    }>(),
    {
        label: 'Loading…',
        mode: 'spinner',
        rows: 3,
    },
);
</script>

<template>
    <div
        v-if="mode === 'spinner'"
        data-slot="loading-state"
        class="inline-flex items-center gap-2 text-sm text-muted-foreground"
        role="status"
    >
        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
        {{ label }}
    </div>
    <div
        v-else
        data-slot="loading-state"
        class="grid gap-2"
        role="status"
        :aria-label="label"
    >
        <div
            v-for="row in rows"
            :key="row"
            class="h-10 animate-pulse rounded-md bg-muted"
            aria-hidden="true"
        />
    </div>
</template>
