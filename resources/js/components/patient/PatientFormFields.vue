<script setup lang="ts">
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import PhoneInput from '@/components/patient/PhoneInput.vue';
import { OperationalSelect } from '@/components/ui/select';
import type { PatientFormValues } from '@/types';

const props = defineProps<{
    model: PatientFormValues;
    newPatient?: boolean;
    originalPhone?: string;
    errors: Partial<Record<keyof PatientFormValues, string>>;
}>();
const fields = reactive(props.model);
const genderOptions = [
    { value: 'female', label: 'Female' },
    { value: 'male', label: 'Male' },
    { value: 'indeterminate', label: 'Indeterminate' },
    { value: 'unknown', label: 'Unknown' },
];
</script>

<template>
    <div class="space-y-6">
        <section class="rounded-lg border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Identity</h2>
                <p class="text-sm text-muted-foreground">
                    Record the stated identity. NRIC-derived values are never
                    authoritative.
                </p>
            </div>
            <div class="grid gap-4 p-5 md:grid-cols-2">
                <label class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-medium">Full name *</span>
                    <input
                        v-model="fields.full_name"
                        :aria-invalid="!!errors.full_name"
                        aria-describedby="patient-name-error"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError
                        id="patient-name-error"
                        role="alert"
                        :message="errors.full_name"
                    />
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Date of birth</span>
                    <input
                        v-model="fields.date_of_birth"
                        :aria-invalid="!!errors.date_of_birth"
                        type="date"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    />
                    <InputError :message="errors.date_of_birth" />
                </label>
                <div class="grid gap-2">
                    <span id="patient-gender-label" class="text-sm font-medium"
                        >Gender</span
                    >
                    <OperationalSelect
                        id="patient-gender"
                        v-model="fields.sex"
                        label="Gender"
                        labelledby="patient-gender-label"
                        :options="genderOptions"
                        :invalid="!!errors.sex"
                        trigger-class="h-10"
                    />
                    <InputError :message="errors.sex" />
                </div>
                <label class="grid gap-2">
                    <span class="text-sm font-medium"
                        >Nationality country code</span
                    >
                    <input
                        v-model="fields.nationality_code"
                        :aria-invalid="!!errors.nationality_code"
                        maxlength="2"
                        placeholder="MY"
                        class="h-10 rounded-md border bg-background px-3 text-sm uppercase"
                    />
                    <InputError :message="errors.nationality_code" />
                </label>
            </div>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Contact</h2>
            </div>
            <div class="grid gap-4 p-5 md:grid-cols-2">
                <PhoneInput
                    id="patient-phone"
                    v-model="fields.mobile_phone"
                    v-model:country="fields.phone_country"
                    :required="newPatient"
                    :unchanged-value="originalPhone"
                    :error="errors.mobile_phone || errors.phone_country"
                />
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Email</span>
                    <input
                        v-model="fields.email"
                        :aria-invalid="!!errors.email"
                        type="email"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.email" />
                </label>
            </div>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Address</h2>
            </div>
            <div class="grid gap-4 p-5 md:grid-cols-2">
                <label class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-medium">Address line 1</span>
                    <input
                        v-model="fields.address_line_1"
                        :aria-invalid="!!errors.address_line_1"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_1" />
                </label>
                <label class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-medium">Address line 2</span>
                    <input
                        v-model="fields.address_line_2"
                        :aria-invalid="!!errors.address_line_2"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_2" />
                </label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">Postcode</span
                    ><input
                        v-model="fields.postcode"
                        :aria-invalid="!!errors.postcode"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.postcode"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">City</span
                    ><input
                        v-model="fields.city"
                        :aria-invalid="!!errors.city"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.city"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">State</span
                    ><input
                        v-model="fields.state"
                        :aria-invalid="!!errors.state"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.state"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">Country code</span
                    ><input
                        v-model="fields.country_code"
                        :aria-invalid="!!errors.country_code"
                        maxlength="2"
                        placeholder="MY"
                        class="h-10 rounded-md border bg-background px-3 text-sm uppercase" /><InputError
                        :message="errors.country_code"
                /></label>
            </div>
        </section>
    </div>
</template>
