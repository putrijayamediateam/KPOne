<script setup lang="ts">
import { computed } from 'vue';

/**
 * Version 2 chip choice (the reason picker): chips where several can be chosen and one is the primary. A chosen chip
 * shows its label, a "Primary" word or a "Make primary" button, and a × button named "Remove <label>"; each of those
 * buttons is its own 44px target and tab stop, so nothing toggles by accident. An unchosen chip adds itself when
 * pressed. The first chosen becomes primary, and if the primary is removed the next chosen one takes over.
 *
 * `max` shows the limit in words ("Up to 5 reasons. 5 chosen.") and disables the unchosen chips at the limit, with
 * the reason shown. `inactiveMessage` shows a note about a chosen item that is no longer in use. `addLabel` shows an
 * "Add new" entry that emits `add`. The page supplies the rules and the endpoint.
 */
export type ChipOption = { value: string; label: string; disabled?: boolean };

const props = withDefaults(
    defineProps<{
        label: string;
        options: ChipOption[];
        modelValue: string[];
        primary: string | null;
        max?: number;
        noun?: string;
        inactiveMessage?: string;
        addLabel?: string;
    }>(),
    {
        max: undefined,
        noun: 'reasons',
        inactiveMessage: undefined,
        addLabel: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
    'update:primary': [value: string | null];
    add: [];
}>();

const atLimit = computed(
    () => props.max !== undefined && props.modelValue.length >= props.max,
);

const chosen = (value: string) => props.modelValue.includes(value);

const change = (next: string[]) => {
    emit('update:modelValue', next);

    if (!next.includes(props.primary ?? '')) {
        emit('update:primary', next[0] ?? null);
    }
};

const add = (value: string) => {
    if (!atLimit.value) {
        change([...props.modelValue, value]);
    }
};
const remove = (value: string) =>
    change(props.modelValue.filter((v) => v !== value));

const target =
    'inline-flex h-11 min-w-11 items-center justify-center outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
</script>

<template>
    <div
        data-slot="v2-chip-choice"
        role="group"
        :aria-label="label"
        class="flex min-w-0 flex-col gap-1.5"
    >
        <p class="text-sm font-medium text-foreground">{{ label }}</p>
        <p v-if="max !== undefined" class="text-[13px] text-muted-foreground">
            Up to {{ max }} {{ noun }}. {{ modelValue.length }} chosen.
            <span v-if="atLimit">
                Limit reached. Remove one to add another.</span
            >
        </p>
        <p
            v-if="inactiveMessage"
            class="rounded-[10px] border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-foreground"
        >
            {{ inactiveMessage }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <template v-for="option in options" :key="option.value">
                <span
                    v-if="chosen(option.value)"
                    class="inline-flex items-center rounded-full border border-primary bg-primary/10 pl-4 text-sm font-medium text-primary"
                >
                    {{ option.label }}
                    <span
                        v-if="option.value === primary"
                        class="ml-1.5 rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground"
                        >Primary</span
                    >
                    <button
                        v-else
                        type="button"
                        :class="`${target} px-2 text-primary underline-offset-2 hover:underline`"
                        @click="emit('update:primary', option.value)"
                    >
                        Make primary
                    </button>
                    <button
                        type="button"
                        :aria-label="`Remove ${option.label}`"
                        :class="`${target} rounded-full`"
                        @click="remove(option.value)"
                    >
                        <svg
                            class="size-4"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.25"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </span>
                <button
                    v-else
                    type="button"
                    :disabled="option.disabled || atLimit"
                    :class="`${target} rounded-full border border-border bg-card px-4 text-sm font-medium text-foreground hover:bg-muted disabled:cursor-not-allowed disabled:border-dashed disabled:bg-transparent disabled:text-muted-foreground disabled:hover:bg-transparent`"
                    @click="add(option.value)"
                >
                    {{ option.label }}
                </button>
            </template>
            <button
                v-if="addLabel"
                type="button"
                :class="`${target} rounded-full border border-dashed border-primary px-4 text-sm font-medium text-primary hover:bg-primary/10`"
                @click="emit('add')"
            >
                + {{ addLabel }}
            </button>
        </div>
    </div>
</template>
