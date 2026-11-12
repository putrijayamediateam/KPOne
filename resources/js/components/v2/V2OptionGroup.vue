<script setup lang="ts">
/**
 * Version 2 option group: a row of pressed-button choices (Visit type, Coverage, Urgency). One can be pressed.
 * Each button is 44px; the pressed one has a cobalt tint and a cobalt outline, and `aria-pressed` for screen readers.
 */
export type Option = {
    value: string;
    label: string;
    disabled?: boolean;
};

withDefaults(
    defineProps<{
        modelValue: string | null;
        options: Option[];
        label: string;
        required?: boolean;
        error?: string;
    }>(),
    { required: false, error: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div
        data-slot="v2-option-group"
        role="group"
        :aria-label="label"
        class="flex min-w-0 flex-col gap-1.5"
    >
        <p class="text-sm font-medium text-foreground">
            {{ label
            }}<span v-if="required" class="text-destructive" aria-hidden="true">
                *</span
            >
        </p>
        <div class="flex flex-wrap gap-2">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                :aria-pressed="option.value === modelValue"
                :disabled="option.disabled"
                class="inline-flex h-11 items-center justify-center rounded-[10px] border px-4 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                :class="
                    option.value === modelValue
                        ? 'border-primary bg-primary/10 text-primary'
                        : 'border-border bg-card text-foreground hover:bg-muted'
                "
                @click="emit('update:modelValue', option.value)"
            >
                {{ option.label }}
            </button>
        </div>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
    </div>
</template>
