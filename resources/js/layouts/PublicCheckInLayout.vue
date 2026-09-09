<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
</script>

<template>
    <div
        class="min-h-svh bg-zinc-50 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-50"
    >
        <slot />
        <Toaster />
    </div>
</template>
