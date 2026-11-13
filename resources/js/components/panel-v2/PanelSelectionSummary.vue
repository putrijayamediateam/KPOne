<script setup lang="ts">
import { Loader2 } from '@lucide/vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2StickySummary from '@/components/v2/V2StickySummary.vue';

/**
 * Version 2 selection summary for the Panel Claims list: what is ticked, the one primary action and Clear. One
 * component renders the three places it can sit, so the page does not repeat the wiring: `rail` (the V2 rail, from
 * 1536 px), `static` (under the pager, 640 to 1535 px; `ids` and test ids live only here) and `bar` (a sticky bar below
 * 640 px). The Panel rule line, the maximum note and every error are always shown inside it. No filled button exists on
 * the page until something is ticked.
 */
const props = defineProps<{
    variant: 'rail' | 'static' | 'bar';
    title: string;
    panel: string | null;
    totalText: string;
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
        v-if="variant !== 'bar'"
        :title="title"
        :items="[
            { label: 'Panel', value: panel ?? '' },
            { label: 'Amount', value: totalText },
        ]"
        :aria-label="label"
        :data-testid="testId"
        :class="variant === 'static' ? '2xl:hidden' : ''"
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
    <V2StickyBar v-else :aria-label="label">
        <div
            class="!h-auto max-h-[40vh] !w-full space-y-1 overflow-y-auto pb-2 text-sm"
        >
            <div class="flex items-start justify-between gap-3">
                <span class="min-w-0 font-medium wrap-anywhere"
                    >{{ title }} · {{ panel }} · {{ totalText }}</span
                >
                <button
                    type="button"
                    class="min-h-11 shrink-0 px-2 text-sm font-medium text-primary underline-offset-4 hover:underline disabled:opacity-50"
                    :disabled="busy"
                    @click="$emit('clear')"
                >
                    {{ clearLabel }}
                </button>
            </div>
            <p class="text-[13px] text-muted-foreground">{{ ruleLine }}</p>
            <p
                v-for="notice in notices"
                :key="notice"
                class="text-sm text-warning-strong"
                role="status"
            >
                {{ notice }}
            </p>
            <p
                v-for="message in errors"
                :key="message"
                class="text-sm wrap-anywhere text-destructive"
                role="alert"
            >
                {{ message }}
            </p>
        </div>
        <button
            type="button"
            :class="filled"
            :disabled="busy"
            :aria-busy="busy ? 'true' : undefined"
            @click="$emit('primary')"
        >
            <Loader2
                v-if="busy"
                class="size-4 animate-spin motion-reduce:animate-none"
                aria-hidden="true"
            />
            {{ primaryLabel }}
        </button>
    </V2StickyBar>
</template>
