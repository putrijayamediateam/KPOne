<script setup lang="ts">
defineProps<{
    number: string;
    title: string;
    description: string;
}>();
</script>

<template>
    <div class="flex items-start gap-3 border-b pb-3">
        <span
            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
            aria-hidden="true"
        >
            {{ number }}
        </span>
        <div class="grid gap-1">
            <h3 class="font-semibold">{{ title }}</h3>
            <p class="text-sm leading-relaxed text-muted-foreground">
                {{ description }}
            </p>
        </div>
    </div>
</template>
