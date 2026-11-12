<script setup lang="ts">
/**
 * A small icon, one title, an optional one-sentence description and at most one action. The page passes the action
 * Button: it is cobalt only when the page has no other main button, otherwise outline, so there is one cobalt per screen.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        data-slot="empty-state"
        class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-[14px] border bg-card px-4 py-6 text-center"
    >
        <!-- UI-RESTYLE 0.3b: a small decorative icon; a page can pass its own through the icon slot. -->
        <div
            class="text-muted-foreground [&_svg]:size-6"
            aria-hidden="true"
        >
            <slot name="icon">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M22 12h-6l-2 3h-4l-2-3H2" />
                    <path
                        d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"
                    />
                </svg>
            </slot>
        </div>
        <p class="text-base leading-[22px] font-semibold text-foreground">
            {{ title }}
        </p>
        <p
            v-if="description"
            class="max-w-lg text-sm leading-5 text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-1">
            <slot name="action" />
        </div>
    </div>
</template>
