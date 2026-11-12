<script setup lang="ts">
withDefaults(
    defineProps<{
        message: string;
        title?: string;
    }>(),
    { title: 'Something needs attention' },
);
</script>

<template>
    <div
        data-slot="error-state"
        class="flex gap-2.5 rounded-[10px] border border-destructive/30 bg-destructive-soft px-3 py-2.5 text-destructive"
        role="alert"
    >
        <!-- A decorative alert icon, inline so this component needs no icon import. -->
        <svg
            class="mt-0.5 size-4 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path
                d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"
            />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
        <div class="min-w-0">
            <p class="text-[15px] leading-[22px] font-semibold">{{ title }}</p>
            <p class="text-sm leading-5">{{ message }}</p>
            <div v-if="$slots.action" class="mt-2">
                <slot name="action" />
            </div>
        </div>
    </div>
</template>
