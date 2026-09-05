<script setup lang="ts">
import { getCountries, getCountryCallingCode } from 'libphonenumber-js/max';
import { computed, ref } from 'vue';
import { phoneInputError } from '@/lib/patient-registration';

const props = withDefaults(
    defineProps<{
        id: string;
        modelValue: string;
        country?: string;
        error?: string;
        required?: boolean;
        unchangedValue?: string;
    }>(),
    { country: 'MY', required: false },
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
const countries = getCountries()
    .map((code) => ({
        code,
        name: names.of(code) ?? code,
        prefix: getCountryCallingCode(code),
    }))
    .sort((a, b) => a.name.localeCompare(b.name));
</script>

<template>
    <div class="grid gap-2">
        <label :for="id" class="text-sm font-medium"
            >Phone <span v-if="required">*</span></label
        >
        <div class="flex gap-2">
            <select
                :id="id + '-country'"
                :value="country"
                aria-label="Phone country"
                class="h-10 w-36 min-w-0 rounded-md border bg-background px-2 text-sm"
                @change="
                    emit(
                        'update:country',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option
                    v-for="option in countries"
                    :key="option.code"
                    :value="option.code"
                >
                    {{ option.name }} (+{{ option.prefix }})
                </option>
            </select>
            <input
                :id="id"
                :value="modelValue"
                type="tel"
                inputmode="tel"
                autocomplete="tel"
                :required="required"
                :aria-invalid="!!message"
                :aria-describedby="message ? id + '-error' : undefined"
                class="h-10 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
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
