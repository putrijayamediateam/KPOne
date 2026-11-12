<script setup lang="ts">
/**
 * Version 2 form card: a numbered section (title, one description line, then the fields).
 * The step number is decoration; the title is the heading.
 */
defineProps<{
    title: string;
    description?: string;
    step?: number;
}>();

const id = `v2-card-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <section
        data-slot="v2-form-card"
        :aria-labelledby="id"
        class="flex min-w-0 flex-col gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
    >
        <header class="flex items-start gap-3">
            <span
                v-if="step"
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                aria-hidden="true"
                >{{ step }}</span
            >
            <div class="min-w-0">
                <h2 :id="id" class="text-base leading-[22px] font-semibold">
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="text-sm leading-5 text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
        </header>
        <slot />
    </section>
</template>
