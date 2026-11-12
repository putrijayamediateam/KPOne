<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
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
        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="relative w-full sm:w-36">
                <select
                    :id="id + '-country'"
                    :value="country"
                    aria-label="Phone country"
                    class="h-9 w-full min-w-0 appearance-none rounded-[10px] border bg-background pr-8 pl-2 text-sm font-normal max-lg:h-11 sm:w-36"
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
                <ChevronDown
                    class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
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
                class="h-9 w-full min-w-0 rounded-[10px] border bg-background px-3 text-sm max-lg:h-11 sm:flex-1"
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
