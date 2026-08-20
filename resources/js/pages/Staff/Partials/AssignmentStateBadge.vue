<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

defineProps<{ state: 'current' | 'future' | 'ended' }>();
</script>

<template>
    <Badge
        variant="outline"
        :class="{
            'border-emerald-200 bg-emerald-50 text-emerald-800':
                state === 'current',
            'border-blue-200 bg-blue-50 text-blue-800': state === 'future',
            'border-slate-200 bg-slate-50 text-slate-600': state === 'ended',
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
