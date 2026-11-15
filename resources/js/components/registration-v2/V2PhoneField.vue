<script setup lang="ts">
import { getCountries, getCountryCallingCode } from 'libphonenumber-js/max';
import { computed, ref } from 'vue';
import { OperationalSelect } from '@/components/ui/select';
import { phoneInputError } from '@/lib/patient-registration';

/**
 * Version 2 phone field: the same country list, checks and messages as the Classic
 * `components/patient/PhoneInput.vue` (untouched), with the country as the same select the Doctor field uses.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        modelValue: string;
        country?: string;
        error?: string;
        required?: boolean;
        label?: string;
        emptyLabel?: string;
        unchangedValue?: string;
    }>(),
    { country: 'MY', required: false, label: 'Phone', emptyLabel: '' },
);
const emit = defineEmits<{
    'update:modelValue': [value: string];
    'update:country': [value: string];
}>();
const touched = ref(false);
const message = computed(
    () =>
        props.error ||
        (touched.value
            ? phoneInputError(
                  props.modelValue,
                  props.country,
                  props.required,
                  props.unchangedValue,
              )
            : ''),
);
const names = new Intl.DisplayNames(['en'], { type: 'region' });
const countryOptions = getCountries()
    .map((code) => ({
        value: code,
        label: `${names.of(code) ?? code} (+${getCountryCallingCode(code)})`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label));
const options = computed(() =>
    props.country === ''
        ? [
              { value: '', label: props.emptyLabel || 'Choose a country' },
              ...countryOptions,
          ]
        : countryOptions,
);
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1.5">
        <label :for="id" class="text-sm font-medium"
            >{{ label }}
            <span v-if="required" class="text-destructive" aria-hidden="true"
                >*</span
            ></label
        >
        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="w-full sm:w-52 sm:shrink-0">
                <OperationalSelect
                    :model-value="country"
                    label="Phone country"
                    :options="options"
                    trigger-class="h-11 lg:h-9"
                    @update:model-value="
                        (value) => emit('update:country', String(value))
                    "
                />
            </div>
            <input
                :id="id"
                :value="modelValue"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                :required="required"
                :aria-invalid="!!message"
                :aria-describedby="message ? id + '-error' : undefined"
                class="h-11 w-full min-w-0 rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring sm:flex-1 md:text-[15px] lg:h-9"
                @input="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    )
                "
                @blur="touched = true"
            />
        </div>
        <p
            v-if="message"
            :id="id + '-error'"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ message }}
        </p>
    </div>
</template>
