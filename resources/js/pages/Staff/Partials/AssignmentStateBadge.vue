<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{ state: 'current' | 'future' | 'ended' }>();
</script>

<template>
    <Badge
        variant="outline"
        :class="{
            'border-success/25 bg-success/10 text-success': state === 'current',
            'border-brand/25 bg-brand/10 text-brand-strong': state === 'future',
            'border-border bg-muted text-muted-foreground': state === 'ended',
        }"
    >
        {{
            state === 'ended'
                ? 'Ended'
                : state === 'future'
                  ? 'Future'
                  : 'Current'
        }}
    </Badge>
</template>
