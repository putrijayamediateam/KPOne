<script setup lang="ts">
/**
 * Version 2 chip choice (the reason picker): chips where several can be chosen and one is the primary. The first one
 * chosen becomes primary; a chosen chip that is not primary offers "Make primary"; if the primary is unchosen the
 * next chosen one takes over. The block only keeps the primary inside the chosen set. Which reasons exist and which
 * may be primary is the page's rule.
 */
export type ChipOption = { value: string; label: string; disabled?: boolean };

const props = defineProps<{
    label: string;
    options: ChipOption[];
    modelValue: string[];
    primary: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
    'update:primary': [value: string | null];
}>();

const toggle = (value: string) => {
    const chosen = props.modelValue.includes(value)
        ? props.modelValue.filter((v) => v !== value)
        : [...props.modelValue, value];

    emit('update:modelValue', chosen);

    if (!chosen.includes(props.primary ?? '')) {
        emit('update:primary', chosen[0] ?? null);
    }
};
</script>

<template>
    <div
        data-slot="v2-chip-choice"
        role="group"
        :aria-label="label"
        class="flex min-w-0 flex-col gap-1.5"
    >
        <p class="text-sm font-medium text-foreground">{{ label }}</p>
        <div class="flex flex-wrap gap-2">
            <span
                v-for="option in options"
                :key="option.value"
                class="inline-flex items-center"
            >
                <button
                    type="button"
                    :aria-pressed="modelValue.includes(option.value)"
                    :disabled="option.disabled"
                    class="inline-flex h-11 items-center gap-1.5 border px-4 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                    :class="[
                        modelValue.includes(option.value)
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-border bg-card text-foreground hover:bg-muted',
                        modelValue.includes(option.value) &&
                        option.value !== primary
                            ? 'rounded-l-full rounded-r-none border-r-0'
                            : 'rounded-full',
                    ]"
                    @click="toggle(option.value)"
                >
                    {{ option.label }}
                    <span
                        v-if="option.value === primary"
                        class="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground"
                        >Primary</span
                    >
                </button>
                <button
                    v-if="
                        modelValue.includes(option.value) &&
                        option.value !== primary
                    "
                    type="button"
                    class="inline-flex h-11 items-center rounded-l-none rounded-r-full border border-l-0 border-primary bg-primary/10 pr-4 pl-2 text-sm text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                    @click="emit('update:primary', option.value)"
                >
                    Make primary
                </button>
            </span>
        </div>
    </div>
</template>
