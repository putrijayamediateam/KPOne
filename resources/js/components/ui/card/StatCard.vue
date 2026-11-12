<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    note?: string;
}>();
</script>

<template>
    <div
        data-slot="stat-card"
        class="flex min-w-0 flex-col gap-1 rounded-[14px] border bg-card p-4"
    >
        <p class="text-[13px] text-muted-foreground">{{ label }}</p>
        <p class="text-3xl leading-9 font-bold tabular-nums">{{ value }}</p>
        <p v-if="note" class="text-[13px] text-muted-foreground">{{ note }}</p>
    </div>
</template>
