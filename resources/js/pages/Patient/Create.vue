<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PatientFormFields from '@/components/patient/PatientFormFields.vue';
import { Button } from '@/components/ui/button';
import type { PatientFormValues } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Create', href: '/patients/create' },
        ],
    },
});
type IdentifierInput = {
    identifier_type: 'nric' | 'passport';
    issuing_country_code: string;
    value: string;
};
const form = useForm<
    PatientFormValues & {
        identifiers: IdentifierInput[];
        duplicate_override: boolean;
    }
>({
    full_name: '',
    date_of_birth: '',
    sex: 'unknown',
    nationality_code: '',
    mobile_phone: '',
    email: '',
    address_line_1: '',
    address_line_2: '',
    postcode: '',
    city: '',
    state: '',
    country_code: 'MY',
    identifiers: [],
    duplicate_override: false,
});
const addIdentifier = () =>
    form.identifiers.push({
        identifier_type: 'nric',
        issuing_country_code: 'MY',
        value: '',
    });
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const submit = () => form.post('/patients', { preserveState: true });
</script>

<template>
    <Head title="Create patient" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <UserPlus class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Create Patient Master record
                </h1>
                <p class="text-sm text-muted-foreground">
                    Organisation-level canonical identity. This is not a
                    registration or encounter.
                </p>
            </div>
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <PatientFormFields :model="form" :errors="form.errors" />
            <section class="rounded-lg border bg-card">
                <div
                    class="flex items-start justify-between gap-4 border-b px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold">Identifiers</h2>
                        <p class="text-sm text-muted-foreground">
                            Optional initial NRIC or passport. Values remain
                            reserved if later retired.
                        </p>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="addIdentifier"
                        ><Plus class="size-4" /> Add</Button
                    >
                </div>
                <div class="space-y-3 p-5">
                    <div
                        v-if="!form.identifiers.length"
                        class="text-sm text-muted-foreground"
                    >
                        No identifier supplied.
                    </div>
                    <div
                        v-for="(identifier, index) in form.identifiers"
                        :key="index"
                        class="grid gap-3 rounded-md border p-4 md:grid-cols-[150px_110px_1fr_auto]"
                    >
                        <select
                            v-model="identifier.identifier_type"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="nric">NRIC</option>
                            <option value="passport">Passport</option>
                        </select>
                        <input
                            v-model="identifier.issuing_country_code"
                            maxlength="2"
                            :disabled="identifier.identifier_type === 'nric'"
                            class="h-10 rounded-md border bg-background px-3 text-sm uppercase"
                        />
                        <input
                            v-model="identifier.value"
                            autocomplete="off"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                            placeholder="Identifier value"
                        />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            @click="form.identifiers.splice(index, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                        <InputError
                            class="md:col-span-4"
                            :message="
                                errorFor('identifiers.' + index + '.value') ||
                                errorFor('identifiers')
                            "
                        />
                    </div>
                </div>
            </section>
            <label
                v-if="form.errors.duplicate_override"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm"
            >
                <input
                    v-model="form.duplicate_override"
                    type="checkbox"
                    class="mt-1"
                />
                <span
                    ><strong>Possible duplicate:</strong>
                    {{ form.errors.duplicate_override }} Confirm only after
                    reviewing the Patient Directory.</span
                >
            </label>
            <div class="flex justify-end gap-3">
                <Button variant="outline" as-child
                    ><Link href="/patients"
                        ><ArrowLeft class="size-4" /> Cancel</Link
                    ></Button
                >
                <Button type="submit" :disabled="form.processing"
                    >Create patient</Button
                >
            </div>
        </form>
    </main>
</template>
