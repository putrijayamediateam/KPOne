<script setup lang="ts">
import { Loader2 } from '@lucide/vue';

/**
 * Interim Version 2 selection bar for the Panel Claims list (Kavitha builds the shared V2SelectionBar; this keeps its
 * expected props so the swap is one import). It sits at the foot of the main column whenever something is ticked and
 * the summary rail is hidden (below 1536 px): "3 claims selected · Panel X · RM 1,240.00", one filled action and an
 * outline Clear, 44 px targets, clear of the home indicator. The Panel rule line, the maximum note and every error stay
 * visible. A polite live region announces the selection.
 */
defineProps<{
    title: string;
    detail: string;
    primaryLabel: string;
    clearLabel: string;
    busy?: boolean;
    ruleLine: string;
    ruleId?: string;
    notices: string[];
    errors: string[];
    testId?: string;
    primaryTestId?: string;
    label: string;
}>();

defineEmits<{ primary: []; clear: [] }>();

const filled =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50';
const outline =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50';
</script>

<template>
    <div
        data-slot="v2-selection-bar"
        role="region"
        :aria-label="label"
        :data-testid="testId"
        class="sticky bottom-0 z-10 -mx-4 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] 2xl:hidden"
    >
        <div class="grid gap-2 text-sm">
            <p class="font-medium wrap-anywhere" aria-live="polite">
                {{ title }} · {{ detail }}
            </p>
            <p :id="ruleId" class="text-[13px] text-muted-foreground">
                {{ ruleLine }}
            </p>
            <p
                v-for="notice in notices"
                :key="notice"
                class="text-warning-strong"
                role="status"
            >
                {{ notice }}
            </p>
            <p
                v-for="message in errors"
                :key="message"
                class="wrap-anywhere text-destructive"
                role="alert"
            >
                {{ message }}
            </p>
            <div class="flex flex-wrap gap-2 max-sm:[&>*]:flex-1">
                <button
                    type="button"
                    :class="filled"
                    :disabled="busy"
                    :aria-busy="busy ? 'true' : undefined"
                    :data-testid="primaryTestId"
                    @click="$emit('primary')"
                >
                    <Loader2
                        v-if="busy"
                        class="size-4 animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    {{ primaryLabel }}
                </button>
                <button
                    type="button"
                    :class="outline"
                    :disabled="busy"
                    @click="$emit('clear')"
                >
                    {{ clearLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
