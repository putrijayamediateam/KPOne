<script setup lang="ts">
import { computed } from 'vue';

/**
 * Version 2 date range: a From and To pair for filters (native date inputs, 44px). When both are set and From is after
 * To, "From must be on or before To." is announced. What the dates filter is the page's rule.
 */
const props = withDefaults(
    defineProps<{
        from: string;
        to: string;
        label: string;
        fromLabel?: string;
        toLabel?: string;
        error?: string;
    }>(),
    { fromLabel: 'From', toLabel: 'To', error: undefined },
);

const emit = defineEmits<{
    'update:from': [value: string];
    'update:to': [value: string];
}>();

const base = `v2-range-${Math.random().toString(36).slice(2, 8)}`;
const message = computed(
    () =>
        props.error ??
        (props.from && props.to && props.from > props.to
            ? 'From must be on or before To.'
            : undefined),
);

const input =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none selection:bg-primary/25 hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <fieldset data-slot="v2-date-range" class="flex min-w-0 flex-col gap-1.5">
        <legend class="mb-1.5 text-sm font-medium text-foreground">
            {{ label }}
        </legend>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label
                class="flex min-w-0 flex-col gap-1 text-[13px] text-muted-foreground"
            >
                {{ fromLabel }}
                <input
                    type="date"
                    :value="from"
                    :max="to || undefined"
                    :aria-invalid="!!message"
                    :aria-describedby="message ? `${base}-error` : undefined"
                    :class="[
                        input,
                        message ? 'border-destructive' : 'border-input',
                        'text-foreground',
                    ]"
                    @input="
                        emit(
                            'update:from',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
            <label
                class="flex min-w-0 flex-col gap-1 text-[13px] text-muted-foreground"
            >
                {{ toLabel }}
                <input
                    type="date"
                    :value="to"
                    :min="from || undefined"
                    :aria-invalid="!!message"
                    :aria-describedby="message ? `${base}-error` : undefined"
                    :class="[
                        input,
                        message ? 'border-destructive' : 'border-input',
                        'text-foreground',
                    ]"
                    @input="
                        emit(
                            'update:to',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
        </div>
        <p
            v-if="message"
            :id="`${base}-error`"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ message }}
        </p>
    </fieldset>
</template>
