<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ module: string }>();
</script>

<template>
    <Head :title="module" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 py-8 md:px-6"
    >
        <section class="border-t pt-6">
            <p
                class="text-xs font-semibold tracking-wide text-emerald-800 uppercase"
            >
                KPOne clinic workspace
            </p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">
                {{ module }}
            </h1>
            <p class="mt-2 max-w-xl text-sm text-muted-foreground">
                Planned module — not available yet.
            </p>
        </section>
    </main>
</template>
