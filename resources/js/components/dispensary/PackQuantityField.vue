<script setup lang="ts">
// DSP-24: the quantity box on a Dispensary line. With a checked STK-52 pack conversion for the line's own unit, the
// CA may choose "Bottles" (whole packs only, 1 to 99) instead of ml; the page then sends the packs and the ml, and
// the server checks both. The choice starts on the ordered unit (ml). Without a usable conversion it is the plain
// quantity box it always was.
import { computed, useAttrs, useId } from 'vue';
import type { PackConversion } from '@/lib/dispensary-packs';
import {
    basesToPacks,
    conversionLine,
    displayQuantity,
    MAX_PACKS,
    packChoiceLabel,
    packsToBase,
    parsePacks,
    sameQuantity,
    usablePackConversion,
} from '@/lib/dispensary-packs';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    unit: string;
    conversion: PackConversion | null | undefined;
    inputClass: string;
    // The doctor's quantity in the base unit; null for a medicine the doctor did not order.
    ordered?: string | null;
}>();
const quantity = defineModel<string>('quantity', { required: true });
const packs = defineModel<string>('packs', { default: '' });
const mode = defineModel<'base' | 'packs'>('mode', { default: 'base' });

const attrs = useAttrs();
const id = useId();
const usable = computed(() =>
    usablePackConversion(props.conversion, props.unit),
);
const inPacks = computed(() => usable.value !== null && mode.value === 'packs');
const parsed = computed(() => parsePacks(packs.value));
const packsError = computed(() =>
    inPacks.value && packs.value.trim() !== '' && parsed.value === null
        ? `Whole ${packChoiceLabel(usable.value!.packUnit).toLowerCase()} only, 1 to ${MAX_PACKS}.`
        : '',
);
const line = computed(() =>
    inPacks.value && parsed.value !== null
        ? conversionLine(parsed.value, usable.value!)
        : '',
);
const differs = computed(
    () =>
        line.value !== '' &&
        props.ordered != null &&
        !sameQuantity(props.ordered, quantity.value),
);

const choose = (next: 'base' | 'packs') => {
    if (next === mode.value || usable.value === null) {
        return;
    }

    if (next === 'packs') {
        // Convert when the ml divide evenly into whole bottles; otherwise start empty, never round.
        const whole = basesToPacks(quantity.value, usable.value);
        packs.value = whole === null ? '' : String(whole);
        quantity.value = whole === null ? '' : packsToBase(whole, usable.value);
    } else {
        packs.value = '';
    }

    mode.value = next;
};
const typePacks = (text: string) => {
    packs.value = text;
    const whole = parsePacks(text);
    quantity.value =
        whole === null || usable.value === null
            ? ''
            : packsToBase(whole, usable.value);
};
</script>

<template>
    <div class="grid content-start gap-1" data-testid="pack-quantity">
        <fieldset v-if="usable" class="flex flex-wrap items-center gap-x-3">
            <legend class="sr-only">Quantity unit</legend>
            <label
                class="flex min-h-11 items-center gap-1.5 lg:min-h-0"
                data-testid="pack-unit-base"
            >
                <input
                    type="radio"
                    :name="`pack-unit-${id}`"
                    :checked="!inPacks"
                    @change="choose('base')"
                />{{ unit }}
            </label>
            <label
                class="flex min-h-11 items-center gap-1.5 lg:min-h-0"
                data-testid="pack-unit-packs"
            >
                <input
                    type="radio"
                    :name="`pack-unit-${id}`"
                    :checked="inPacks"
                    @change="choose('packs')"
                />{{ packChoiceLabel(usable.packUnit) }}
            </label>
        </fieldset>
        <label v-if="inPacks"
            >Quantity ({{
                packChoiceLabel(usable!.packUnit).toLowerCase()
            }})<input
                v-bind="attrs"
                :value="packs"
                :class="inputClass"
                inputmode="numeric"
                :aria-invalid="packsError !== '' || undefined"
                :aria-describedby="`pack-line-${id}`"
                @input="typePacks(($event.target as HTMLInputElement).value)"
        /></label>
        <label v-else
            >Quantity ({{ unit }})<input
                v-bind="attrs"
                v-model="quantity"
                :class="inputClass"
                inputmode="decimal"
        /></label>
        <p
            v-if="usable"
            :id="`pack-line-${id}`"
            aria-live="polite"
            class="text-muted-foreground"
            data-testid="pack-line"
        >
            <span v-if="packsError" class="text-destructive">{{
                packsError
            }}</span>
            <template v-else>{{ line }}</template>
        </p>
        <p
            v-if="differs"
            class="text-warning-strong"
            data-testid="pack-differs"
        >
            Doctor ordered {{ displayQuantity(ordered!) }} {{ unit }}. This is
            {{ displayQuantity(quantity) }} {{ unit }}.
        </p>
    </div>
</template>
