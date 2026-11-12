<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

/**
 * UI-RESTYLE 0.3b: a token-based notice (allergy match, unsaved lines, a saved confirmation, a hint). A soft fill, a
 * tone border and icon, a title, a body and an optional action. Status meaning always comes with words; the icon is
 * decorative. Danger is announced as an alert, the other tones as a status.
 */
const props = withDefaults(
    defineProps<{
        tone?: 'info' | 'success' | 'warning' | 'danger';
        title?: string;
    }>(),
    { tone: 'info' },
);

const style = computed(
    () =>
        ({
            info: 'border-primary/30 bg-tint-1 [&>svg]:text-primary',
            success: 'border-success/30 bg-success-soft [&>svg]:text-success',
            warning: 'border-warning/30 bg-warning-soft [&>svg]:text-warning',
            danger: 'border-destructive/30 bg-destructive-soft [&>svg]:text-destructive',
        })[props.tone],
);
const icon = computed(
    () =>
        ({
            info: Info,
            success: CircleCheck,
            warning: TriangleAlert,
            danger: CircleAlert,
        })[props.tone],
);
</script>

<template>
    <div
        data-slot="notice-panel"
        :data-tone="tone"
        class="flex gap-2.5 rounded-[14px] border px-3.5 py-3 text-foreground"
        :class="style"
        :role="tone === 'danger' ? 'alert' : 'status'"
    >
        <component
            :is="icon"
            class="mt-0.5 size-4 shrink-0"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <p v-if="title" class="text-[15px] leading-[22px] font-semibold">
                {{ title }}
            </p>
            <div class="text-sm leading-5">
                <slot />
            </div>
            <div v-if="$slots.action" class="mt-2">
                <slot name="action" />
            </div>
        </div>
    </div>
</template>
