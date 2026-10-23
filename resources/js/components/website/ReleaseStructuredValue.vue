<script setup lang="ts">
import type { ReleaseValue } from '@/types/website-releases';
defineProps<{ value: ReleaseValue }>();
</script>
<template>
    <ul v-if="Array.isArray(value)" class="space-y-2 border-l pl-3">
        <li v-for="(entry, index) in value" :key="index">
            <ReleaseStructuredValue :value="entry" />
        </li>
    </ul>
    <dl
        v-else-if="value !== null && typeof value === 'object'"
        class="space-y-2"
    >
        <div v-for="(entry, key) in value" :key="key">
            <dt class="text-xs font-medium text-muted-foreground">
                {{ String(key).replaceAll('_', ' ') }}
            </dt>
            <dd class="whitespace-pre-wrap">
                <ReleaseStructuredValue :value="entry" />
            </dd>
        </div>
    </dl>
    <span v-else>{{
        value === null || value === ''
            ? '—'
            : typeof value === 'boolean'
              ? value
                  ? 'Yes'
                  : 'No'
              : value
    }}</span>
</template>
