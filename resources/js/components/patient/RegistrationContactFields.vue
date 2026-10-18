<script setup lang="ts">
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
type RegistrationContactValues = {
    email: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postcode: string | null;
    country_code: string | null;
};
const props = defineProps<{
    model: RegistrationContactValues;
    requireEmail?: boolean;
    requireAddress?: boolean;
    errors: Partial<Record<keyof RegistrationContactValues, string>>;
    publicForm?: boolean;
    idPrefix: string;
}>();
const fields = reactive(props.model);
const labels = {
    email: ['Email', 'E-mel'],
    address_line_1: ['Address line 1', 'Alamat baris 1'],
    address_line_2: ['Address line 2', 'Alamat baris 2'],
    city: ['City', 'Bandar'],
    state: ['State', 'Negeri'],
    postcode: ['Postcode', 'Poskod'],
    country_code: ['Country code', 'Kod negara'],
};
const limits = {
    email: 255,
    address_line_1: 255,
    address_line_2: 255,
    city: 100,
    state: 100,
    postcode: 20,
    country_code: 2,
};
const keys: Array<keyof RegistrationContactValues> = [
    'email',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'postcode',
    'country_code',
];
const required = (field: keyof RegistrationContactValues) =>
    field === 'email'
        ? props.requireEmail
        : field !== 'address_line_2' && props.requireAddress;
</script>
<template>
    <div class="grid gap-4 md:grid-cols-2">
        <label
            v-for="field in keys"
            :key="field"
            :for="`${idPrefix}-${field}`"
            class="grid gap-1.5"
            :class="{
                'md:col-span-2': [
                    'email',
                    'address_line_1',
                    'address_line_2',
                ].includes(field),
            }"
        >
            <span class="text-sm font-medium"
                >{{ labels[field][publicForm ? 1 : 0]
                }}{{
                    required(field)
                        ? ' *'
                        : publicForm
                          ? ' (pilihan)'
                          : ' (optional)'
                }}</span
            >
            <input
                :id="`${idPrefix}-${field}`"
                v-model="fields[field]"
                :type="field === 'email' ? 'email' : 'text'"
                :maxlength="limits[field]"
                :required="required(field)"
                :aria-invalid="!!errors[field]"
                :aria-describedby="
                    errors[field] ? `${idPrefix}-${field}-error` : undefined
                "
                :placeholder="field === 'country_code' ? 'MY' : undefined"
                autocomplete="off"
                class="min-w-0 rounded-md border bg-background px-3 text-sm"
                :class="publicForm ? 'min-h-12 rounded-xl' : 'h-10'"
            />
            <InputError
                :id="`${idPrefix}-${field}-error`"
                :message="errors[field]"
            />
        </label>
    </div>
</template>
