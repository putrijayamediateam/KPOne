<script setup lang="ts">
import { StatusBadge } from '@/components/ui/status';

defineProps<{ state: 'current' | 'future' | 'ended' }>();
</script>

<template>
    <StatusBadge
        :status="state"
        :tone="
            state === 'current'
                ? 'success'
                : state === 'future'
                  ? 'accent'
                  : 'neutral'
        "
        :label="
            state === 'ended'
                ? 'Ended'
                : state === 'future'
                  ? 'Future'
                  : 'Current'
        "
    />
</template>
