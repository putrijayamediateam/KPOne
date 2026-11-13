<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';

/**
 * Version 2 selection summary for the Panel Claims list rail (from 1536 px): what is ticked, the one primary action and
 * Clear. Below 1536 px the same facts show in PanelSelectionBar at the foot of the page. The Panel rule line, the
 * maximum note and every error are always shown. No filled button exists on the page until something is ticked.
 */
const props = defineProps<{
    title: string;
    panel: string;
    money: string;
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
    'inline-flex items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50';
const outline =
    'inline-flex items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50';

void props;
</script>

<template>
    <V2StickySummary
        :title="title"
        :items="[
            { label: 'Panel', value: panel },
            { label: 'Amount', value: money },
        ]"
        :aria-label="label"
        :data-testid="testId"
    >
        <p
            :id="ruleId"
            class="!h-auto text-[13px] text-muted-foreground lg:!h-auto"
        >
            {{ ruleLine }}
        </p>
        <p
            v-for="notice in notices"
            :key="notice"
            class="!h-auto text-sm text-warning-strong lg:!h-auto"
            role="status"
        >
            {{ notice }}
        </p>
        <p
            v-for="message in errors"
            :key="message"
            class="!h-auto text-sm wrap-anywhere text-destructive lg:!h-auto"
            role="alert"
        >
            {{ message }}
        </p>
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
    </V2StickySummary>
</template>
