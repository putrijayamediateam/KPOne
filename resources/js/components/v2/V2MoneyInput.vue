<script setup lang="ts">
import { computed, ref } from 'vue';
import { MONEY_ERROR, tidyMoney } from '@/lib/v2-money';
import V2Field from './V2Field.vue';

/**
 * Version 2 money input: a field with an "RM" prefix, a decimal keypad on a phone, the text right-aligned in tabular
 * figures, and two decimals once the person leaves the box ("12" becomes "12.00").
 *
 * What the block emits: `modelValue` is the typed text, tidied on blur ("12.50", or '' when empty). The block does no maths
 * and does not validate the amount: the page (and the server) own validation, ranges and what an amount may be. An empty
 * box stays empty and is never shown as "0.00"; **empty is a legal value that the page must treat as "no price", not as
 * zero**, and a typed "0" or "0.00" is a valid amount (CAT-1b: free is an answer, blank is not).
 *
 * Paste: "RM 12.50" and "1,250.50" are accepted ("1,250.50" becomes "1250.50"; commas only as thousands groups of three
 * before one decimal point). Anything else is refused with "Enter an amount such as 12.50." and keeps what was typed:
 * "1,25", "1.250,50", "1.234", "-5", "abc". Nothing is guessed. The page's own `error` (for example from the server) wins.
 *
 * Editing vs reading: after a blur the box shows "1250.00" with no thousands separator, on purpose, because separators in
 * an editable box make editing harder. A read-only display of a price anywhere else in V2 must use grouping ("RM 1,250.00");
 * keep the two apart.
 *
 * It is a text box, so the mouse wheel and the arrow keys never change the amount. The prefix is read as part of the
 * description ("Amount in ringgit (RM)"). V2 views only.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        hint?: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
    }>(),
    { hint: undefined, error: undefined, required: false, disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const typedInvalid = ref(false);
const shownError = computed(
    () => props.error ?? (typedInvalid.value ? MONEY_ERROR : undefined),
);
const unitId = `v2-money-unit-${Math.random().toString(36).slice(2, 8)}`;

const onInput = (event: Event) => {
    typedInvalid.value = false;
    emit('update:modelValue', (event.target as HTMLInputElement).value);
};

const onBlur = () => {
    const tidy = tidyMoney(props.modelValue);
    typedInvalid.value = !tidy.valid;

    if (tidy.value !== props.modelValue) {
        emit('update:modelValue', tidy.value);
    }
};
</script>

<template>
    <V2Field
        v-slot="{ id, describedBy }"
        :label="label"
        :hint="hint"
        :error="shownError"
        :required="required"
    >
        <div
            class="flex h-11 w-full items-center rounded-[10px] border bg-card pl-3 focus-within:border-ring focus-within:ring-2 focus-within:ring-ring lg:h-9"
            :class="[
                shownError ? 'border-destructive' : 'border-input',
                disabled ? 'opacity-50' : 'hover:border-foreground/60',
            ]"
        >
            <span
                class="shrink-0 text-sm font-medium text-muted-foreground"
                aria-hidden="true"
                >RM</span
            >
            <input
                :id="id"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                enterkeyhint="done"
                :value="modelValue"
                :disabled="disabled"
                :required="required"
                :aria-invalid="!!shownError"
                :aria-describedby="
                    [unitId, describedBy].filter(Boolean).join(' ')
                "
                class="h-full min-w-0 flex-1 bg-transparent px-3 text-right text-base tabular-nums outline-none md:text-[15px]"
                @input="onInput"
                @blur="onBlur"
            />
        </div>
        <span :id="unitId" class="sr-only">Amount in ringgit (RM)</span>
    </V2Field>
</template>
