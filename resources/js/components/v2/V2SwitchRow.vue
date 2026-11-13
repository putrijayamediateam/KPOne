<script setup lang="ts">
import { computed } from 'vue';

/**
 * Version 2 switch row: a label, a help line, and a switch with the state in words ("On" or "Off") beside it, so the
 * state is never colour alone. The switch is a real `role="switch"` button, a 44px target below 1024px (36px from
 * 1024px), toggled by click, Space or Enter, and by clicking the label. The accessible name is the label and the help
 * line is the description (`aria-describedby`); the visible On/Off word is `aria-hidden`, because `aria-checked` already
 * says it ("Require date of birth, switch, on", not "On, switch, on"). A disabled switch needs a reason: pass
 * `disabledReason` and it replaces the help line, in words ("Only the Director can change this"); a disabled switch
 * with no reason warns in development.
 *
 * The block only changes the form value (`update:modelValue`). The page decides whether it saves at once or with its own
 * Save button. A page that saves at once must give the person a visible result ("Saved"), and a switch with a privacy
 * or money effect (for example "Show full name on the waiting-room TV") needs the page's own confirm step. None of that
 * is the block's job. V2 views only.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        label: string;
        help?: string;
        disabled?: boolean;
        disabledReason?: string;
    }>(),
    { help: undefined, disabled: false, disabledReason: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

const id = `v2-switch-${Math.random().toString(36).slice(2, 8)}`;
const note = computed(() =>
    props.disabled && props.disabledReason ? props.disabledReason : props.help,
);

if (import.meta.env?.DEV && props.disabled && !props.disabledReason) {
    console.warn('V2SwitchRow: a disabled switch needs a `disabledReason`.');
}

const toggle = () => {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue);
    }
};
</script>

<template>
    <div
        data-slot="v2-switch-row"
        class="flex min-w-0 items-start justify-between gap-4 py-2"
    >
        <div class="min-w-0">
            <label
                :for="id"
                class="block text-[15px] leading-[22px] font-medium text-foreground"
                >{{ label }}</label
            >
            <p
                v-if="note"
                :id="`${id}-note`"
                class="text-sm leading-5 text-muted-foreground"
            >
                {{ note }}
            </p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span
                class="w-7 text-right text-sm font-medium text-foreground tabular-nums"
                aria-hidden="true"
                >{{ modelValue ? 'On' : 'Off' }}</span
            >
            <button
                :id="id"
                type="button"
                role="switch"
                :aria-checked="modelValue"
                :aria-describedby="note ? `${id}-note` : undefined"
                :aria-disabled="disabled ? 'true' : undefined"
                class="group inline-flex h-11 w-14 items-center justify-center rounded-[10px] outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-disabled:cursor-not-allowed aria-disabled:opacity-50 lg:h-9"
                @click="toggle"
            >
                <span
                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full border transition-colors motion-reduce:transition-none"
                    :class="
                        modelValue
                            ? 'border-primary bg-primary'
                            : 'border-foreground/50 bg-muted'
                    "
                    aria-hidden="true"
                >
                    <span
                        class="absolute top-0.5 left-0.5 size-[18px] rounded-full shadow-sm transition-transform motion-reduce:transition-none"
                        :class="
                            modelValue
                                ? 'translate-x-5 bg-primary-foreground'
                                : 'translate-x-0 bg-foreground/70'
                        "
                    />
                </span>
            </button>
        </div>
    </div>
</template>
