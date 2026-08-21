<script setup lang="ts">
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import type { PatientFormValues } from '@/types';

const props = defineProps<{
    model: PatientFormValues;
    errors: Partial<Record<keyof PatientFormValues, string>>;
}>();
const fields = reactive(props.model);
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
                    <span class="text-sm font-medium">Full name</span>
                    <input
                        v-model="fields.full_name"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.full_name" />
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Date of birth</span>
                    <input
                        v-model="fields.date_of_birth"
                        type="date"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    />
                    <InputError :message="errors.date_of_birth" />
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Sex</span>
                    <select
                        v-model="fields.sex"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="female">Female</option>
                        <option value="male">Male</option>
                        <option value="indeterminate">Indeterminate</option>
                        <option value="unknown">Unknown</option>
                    </select>
                    <InputError :message="errors.sex" />
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-medium"
                        >Nationality country code</span
                    >
                    <input
                        v-model="fields.nationality_code"
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
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Mobile phone</span>
                    <input
                        v-model="fields.mobile_phone"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                        placeholder="+60123456789"
                    />
                    <InputError :message="errors.mobile_phone" />
                </label>
                <label class="grid gap-2">
                    <span class="text-sm font-medium">Email</span>
                    <input
                        v-model="fields.email"
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
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_1" />
                </label>
                <label class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-medium">Address line 2</span>
                    <input
                        v-model="fields.address_line_2"
                        class="h-10 rounded-md border bg-background px-3 text-sm"
                        autocomplete="off"
                    />
                    <InputError :message="errors.address_line_2" />
                </label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">Postcode</span
                    ><input
                        v-model="fields.postcode"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.postcode"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">City</span
                    ><input
                        v-model="fields.city"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.city"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">State</span
                    ><input
                        v-model="fields.state"
                        class="h-10 rounded-md border bg-background px-3 text-sm" /><InputError
                        :message="errors.state"
                /></label>
                <label class="grid gap-2"
                    ><span class="text-sm font-medium">Country code</span
                    ><input
                        v-model="fields.country_code"
                        maxlength="2"
                        placeholder="MY"
                        class="h-10 rounded-md border bg-background px-3 text-sm uppercase" /><InputError
                        :message="errors.country_code"
                /></label>
            </div>
        </section>
    </div>
</template>
