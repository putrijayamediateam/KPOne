<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search, UserRoundSearch } from '@lucide/vue';
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { OperationalSelect } from '@/components/ui/select';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Patients', href: '/patients' }] },
});
defineProps<{ canCreate: boolean }>();

type Result = {
    patientNumber: string;
    fullName: string;
    dateOfBirth: string | null;
    sex: string;
    identifier: { type: string; maskedValue: string } | null;
    maskedPhone: string | null;
};
const form = reactive({
    query: '',
    search_type: 'name',
    issuing_country_code: '',
});
const searchTypeOptions = [
    { value: 'name', label: 'Name' },
    { value: 'patient_number', label: 'Patient number' },
    { value: 'nric', label: 'NRIC' },
    { value: 'passport', label: 'Passport' },
    { value: 'phone', label: 'Phone' },
];
const results = ref<Result[]>([]);
const total = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const error = ref('');
const loading = ref(false);
const searched = ref(false);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const search = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch('/patients/search', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ ...form, page }),
        });
        const payload = await response.json();

        if (!response.ok) {
            const validationErrors = payload.errors as
                Record<string, string[]> | undefined;
            error.value =
                payload.message ??
                (validationErrors
                    ? Object.values(validationErrors)[0]?.[0]
                    : undefined) ??
                'Search could not be completed.';

            return;
        }

        results.value = payload.data;
        total.value = payload.total;
        currentPage.value = payload.currentPage;
        lastPage.value = payload.lastPage;
        searched.value = true;
    } catch {
        error.value = 'Search could not be completed.';
    } finally {
        loading.value = false;
    }
};
const patientHref = (number: string) =>
    '/patients/' + encodeURIComponent(number);
const identifierLabel = (result: Result) =>
    result.identifier
        ? result.identifier.type.toUpperCase() +
          ' · ' +
          result.identifier.maskedValue
        : 'None recorded';
</script>

<template>
    <Head title="Patient directory" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div
            class="flex flex-col justify-between gap-4 md:flex-row md:items-start"
        >
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                    <UserRoundSearch class="size-5" />
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Patient directory
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Find an organisation-level Patient Master record.
                    </p>
                </div>
            </div>
            <Button v-if="canCreate" as-child
                ><Link href="/patients/create"
                    ><Plus class="size-4" /> Create patient</Link
                ></Button
            >
        </div>

        <section class="rounded-lg border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Search Patient Master</h2>
                <p class="text-sm text-muted-foreground">
                    Search is server-side. Identity and phone values are masked
                    in results.
                </p>
            </div>
            <form
                class="grid gap-3 p-5 md:grid-cols-[170px_1fr] lg:grid-cols-[170px_1fr_110px_auto]"
                @submit.prevent="search(1)"
            >
                <OperationalSelect
                    id="patient-search-type"
                    v-model="form.search_type"
                    label="Search type"
                    :options="searchTypeOptions"
                    trigger-class="h-10"
                />
                <label class="relative"
                    ><Search
                        class="absolute top-3 left-3 size-4 text-muted-foreground" /><input
                        v-model="form.query"
                        class="h-10 w-full rounded-md border bg-background pr-3 pl-9 text-sm"
                        autocomplete="off"
                        placeholder="Enter at least 3 characters"
                /></label>
                <input
                    v-if="form.search_type === 'passport'"
                    v-model="form.issuing_country_code"
                    maxlength="2"
                    class="h-10 rounded-md border bg-background px-3 text-sm uppercase"
                    placeholder="Issuer"
                />
                <span v-else />
                <Button type="submit" :disabled="loading">{{
                    loading ? 'Searching…' : 'Search'
                }}</Button>
                <InputError
                    v-if="error"
                    class="md:col-span-2 lg:col-span-4"
                    :message="error"
                />
            </form>
        </section>

        <section
            v-if="searched"
            class="overflow-hidden rounded-lg border bg-card"
        >
            <div class="border-b px-5 py-3 text-sm text-muted-foreground">
                {{ total }} matching record{{ total === 1 ? '' : 's' }}
            </div>
            <div v-if="results.length" class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b bg-muted/40 text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-5 py-3">Patient</th>
                            <th class="px-5 py-3">DOB / sex</th>
                            <th class="px-5 py-3">Identifier</th>
                            <th class="px-5 py-3">Phone</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="patient in results"
                            :key="patient.patientNumber"
                        >
                            <td class="px-5 py-4">
                                <Link
                                    :href="patientHref(patient.patientNumber)"
                                    class="font-medium text-emerald-800 hover:underline"
                                    >{{ patient.fullName }}</Link
                                >
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ patient.patientNumber }}
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                {{ patient.dateOfBirth ?? 'Not recorded' }} ·
                                {{ patient.sex }}
                            </td>
                            <td class="px-5 py-4">
                                {{ identifierLabel(patient) }}
                            </td>
                            <td class="px-5 py-4">
                                {{ patient.maskedPhone ?? 'Not recorded' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="p-10 text-center text-sm text-muted-foreground">
                No matching Patient Master records.
            </div>
            <div
                v-if="lastPage > 1"
                class="flex justify-end gap-2 border-t p-4"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage === 1"
                    @click="search(currentPage - 1)"
                    >Previous</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage === lastPage"
                    @click="search(currentPage + 1)"
                    >Next</Button
                >
            </div>
        </section>
        <section
            v-else
            class="rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground"
        >
            Start with a patient number, identity document, name, or phone.
        </section>
    </main>
</template>
