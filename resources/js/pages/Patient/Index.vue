<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { nextTick, onBeforeUnmount, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import MergedFromNote from '@/components/patient/MergedFromNote.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import RegistrationWorkspaceNav from '@/components/registration/RegistrationWorkspaceNav.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Patients', href: '/patients' }] },
});
defineProps<{ canCreate: boolean; canSeeMergeRequests?: boolean }>();

type Result = {
    canWhatsapp: boolean;
    patientNumber: string;
    fullName: string;
    dateOfBirth: string | null;
    sex: string;
    identifier: { type: string; maskedValue: string } | null;
    maskedPhone: string | null;
    mergedFrom?: string[];
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
const searchInput = ref<HTMLInputElement | null>(null);
let searchGeneration = 0;
let searchController: AbortController | undefined;
const clearSearch = async () => {
    searchGeneration++;
    searchController?.abort();
    searchController = undefined;
    Object.assign(form, {
        query: '',
        search_type: 'name',
        issuing_country_code: '',
    });
    results.value = [];
    total.value = 0;
    currentPage.value = 1;
    lastPage.value = 1;
    error.value = '';
    searched.value = false;
    loading.value = false;
    await nextTick();
    searchInput.value?.focus();
};
onBeforeUnmount(() => {
    searchGeneration++;
    searchController?.abort();
});
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const search = async (page = 1) => {
    const generation = ++searchGeneration;
    searchController?.abort();
    const controller = new AbortController();
    searchController = controller;
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch('/patients/search', {
            method: 'POST',
            signal: controller.signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ ...form, page }),
        });
        const payload = await response.json();

        if (generation !== searchGeneration) {
            return;
        }

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
        if (generation !== searchGeneration || controller.signal.aborted) {
            return;
        }

        error.value = 'Search could not be completed.';
    } finally {
        if (generation === searchGeneration) {
            loading.value = false;
            searchController = undefined;
        }
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
    <main
        class="mx-auto flex w-full max-w-[1600px] flex-1 flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8 lg:py-6"
    >
        <PageHeader
            title="Registration"
            description="Patient directory · organisation records"
        >
            <template #actions>
                <Button v-if="canSeeMergeRequests" variant="outline" as-child
                    ><Link href="/patient-merges">Merge requests</Link></Button
                >
                <Button v-if="canCreate" as-child
                    ><Link href="/patients/create"
                        ><Plus class="size-4" /> Create patient</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <RegistrationWorkspaceNav active="patients" />

        <div
            class="grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_280px]"
        >
            <div class="min-w-0 space-y-4">
                <section class="rounded-lg border bg-card shadow-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="font-semibold">Search patients</h2>
                        <p class="text-sm text-muted-foreground">
                            Choose how to search. Identity and phone values stay
                            masked in results.
                        </p>
                    </div>
                    <form class="grid gap-3 p-4" @submit.prevent="search(1)">
                        <fieldset class="min-w-0">
                            <legend
                                class="mb-2 text-xs font-medium text-muted-foreground"
                            >
                                Search type
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="option in searchTypeOptions"
                                    :key="option.value"
                                    class="cursor-pointer"
                                >
                                    <input
                                        v-model="form.search_type"
                                        class="peer sr-only"
                                        type="radio"
                                        name="patient-search-type"
                                        :value="option.value"
                                    />
                                    <span
                                        class="flex min-h-11 items-center rounded-full border px-3 text-sm peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring lg:min-h-9"
                                        >{{ option.label }}</span
                                    >
                                </label>
                            </div>
                        </fieldset>
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="relative min-w-0 flex-[1_1_240px]"
                                ><span class="sr-only">Search patients</span
                                ><Search
                                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><input
                                    ref="searchInput"
                                    v-model="form.query"
                                    class="h-9 w-full rounded-md border bg-background pr-3 pl-9 text-sm max-lg:min-h-11"
                                    autocomplete="off"
                                    placeholder="Enter at least 3 characters"
                            /></label>
                            <label
                                v-if="form.search_type === 'passport'"
                                class="grid gap-1 text-xs"
                            >
                                Passport issuing country (2-letter code)
                                <input
                                    v-model="form.issuing_country_code"
                                    aria-label="Passport issuing country (2-letter code)"
                                    maxlength="2"
                                    class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm uppercase max-lg:min-h-11"
                                    placeholder="Issuer"
                                />
                            </label>
                            <Button type="submit" :disabled="loading">{{
                                loading ? 'Searching…' : 'Search'
                            }}</Button>
                            <Button
                                type="button"
                                variant="outline"
                                @click="clearSearch"
                                >Clear</Button
                            >
                        </div>
                        <InputError
                            v-if="error"
                            class="w-full"
                            :message="error"
                        />
                    </form>
                </section>

                <section
                    v-if="searched"
                    class="overflow-hidden rounded-lg border bg-card"
                >
                    <div
                        class="border-b px-5 py-3 text-sm text-muted-foreground"
                    >
                        {{ total }} matching record{{ total === 1 ? '' : 's' }}
                        <p
                            v-if="results.length"
                            id="patient-results-scroll-hint"
                            class="mt-1 hidden text-xs md:block"
                        >
                            If columns are off-screen, scroll the results
                            horizontally.
                        </p>
                    </div>
                    <div
                        v-if="results.length"
                        class="overflow-x-auto focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        tabindex="0"
                        role="region"
                        aria-label="Patient search results"
                        aria-describedby="patient-results-scroll-hint"
                    >
                        <table class="block w-full text-left text-sm md:table">
                            <caption class="sr-only">
                                Patient search results with masked identity and
                                phone
                            </caption>
                            <thead
                                class="hidden border-b bg-muted/40 text-xs text-muted-foreground uppercase md:table-header-group"
                            >
                                <tr>
                                    <th class="px-5 py-3">Patient</th>
                                    <th class="px-5 py-3">DOB / sex</th>
                                    <th class="px-5 py-3">Identifier</th>
                                    <th class="px-5 py-3">Phone</th>
                                </tr>
                            </thead>
                            <tbody class="block divide-y md:table-row-group">
                                <tr
                                    v-for="patient in results"
                                    :key="patient.patientNumber"
                                    class="grid grid-cols-[minmax(0,1fr)_auto] md:table-row"
                                >
                                    <td
                                        class="order-1 min-w-0 px-3 py-3 md:px-5 md:py-4"
                                    >
                                        <Link
                                            :href="
                                                patientHref(
                                                    patient.patientNumber,
                                                )
                                            "
                                            class="inline-flex min-h-11 items-center font-medium text-primary hover:underline lg:min-h-9"
                                            >{{ patient.fullName }}</Link
                                        >
                                        <div
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{ patient.patientNumber }}
                                        </div>
                                        <MergedFromNote
                                            :numbers="patient.mergedFrom ?? []"
                                        />
                                    </td>
                                    <td
                                        class="order-3 px-3 pb-3 md:px-5 md:py-4"
                                    >
                                        <span
                                            class="block text-xs text-muted-foreground md:hidden"
                                            >DOB / sex</span
                                        >
                                        {{
                                            patient.dateOfBirth ??
                                            'Not recorded'
                                        }}
                                        ·
                                        {{ patient.sex }}
                                    </td>
                                    <td
                                        class="order-4 px-3 pb-3 md:px-5 md:py-4"
                                    >
                                        <span
                                            class="block text-xs text-muted-foreground md:hidden"
                                            >Identifier</span
                                        >
                                        {{ identifierLabel(patient) }}
                                    </td>
                                    <td
                                        class="order-2 px-3 py-3 md:px-5 md:py-4"
                                    >
                                        <span class="flex items-center gap-1">
                                            {{
                                                patient.maskedPhone ??
                                                'Not recorded'
                                            }}<WhatsAppLink
                                                :can-whatsapp="
                                                    patient.canWhatsapp
                                                "
                                                :route-key="
                                                    patient.patientNumber
                                                "
                                            />
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-else
                        class="p-10 text-center text-sm text-muted-foreground"
                    >
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
                    Start with a patient number, identity document, name, or
                    phone.
                </section>
            </div>
            <aside
                aria-label="Patient directory guidance"
                class="space-y-3 rounded-lg border bg-card p-4 text-sm shadow-card"
            >
                <h2 class="font-semibold">Find the right patient</h2>
                <p class="text-muted-foreground">
                    Search before creating a new record. Use a patient number or
                    identity document when available.
                </p>
                <p class="text-muted-foreground">
                    Patient records are shared across the organisation. Opening
                    a record still follows your access permissions.
                </p>
                <details class="border-t pt-2">
                    <summary
                        class="min-h-11 cursor-pointer py-2 font-medium lg:min-h-9"
                    >
                        Search tips
                    </summary>
                    <p class="py-2 text-muted-foreground">
                        Choose a search type, enter the saved details, then
                        select Search. Passport searches also use the issuing
                        country. Changing the search type does not submit a
                        search.
                    </p>
                </details>
            </aside>
        </div>
    </main>
</template>
