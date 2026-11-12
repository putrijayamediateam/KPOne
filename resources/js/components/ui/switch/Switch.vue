<script setup lang="ts">
/**
 * A two-state switch. `label` names it for screen readers (what it controls); a visible word beside it
 * (`onText` / `offText`, for example Active / Archived) means the state is never shown by colour alone.
 * The button is a 44 px tap target at every width; the track inside is 44 x 24 px.
 */
withDefaults(
    defineProps<{
        modelValue: boolean;
        label: string;
        onText?: string;
        offText?: string;
        disabled?: boolean;
    }>(),
    { onText: undefined, offText: undefined, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <button
            type="button"
            role="switch"
            data-slot="switch"
            :aria-checked="modelValue"
            :aria-label="label"
            :disabled="disabled"
            class="group inline-flex size-11 shrink-0 items-center justify-center rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
            @click="emit('update:modelValue', !modelValue)"
        >
            <span
                class="relative inline-flex h-6 w-11 items-center rounded-full border transition-colors"
                :class="
                    modelValue
                        ? 'border-success bg-success'
                        : 'border-foreground/55 bg-muted'
                "
            >
                <span
                    class="inline-block size-[18px] rounded-full transition-transform"
                    :class="
                        modelValue
                            ? 'translate-x-[22px] bg-success-foreground'
                            : 'translate-x-[2px] bg-foreground/60'
                    "
                />
            </span>
        </button>
        <span
            v-if="onText || offText"
            class="text-sm text-foreground"
            :class="disabled ? 'opacity-50' : ''"
        >
            {{ modelValue ? (onText ?? '') : (offText ?? '') }}
        </span>
    </span>
</template>
