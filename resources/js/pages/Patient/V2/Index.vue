<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import MergedFromNote from '@/components/patient/MergedFromNote.vue';
import WhatsAppLink from '@/components/patient/WhatsAppLink.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import {
    PATIENT_SEARCH_TYPES,
    identifierLabel,
    patientHref,
    usePatientDirectory,
} from '@/composables/patient/usePatientDirectory';
import type { PatientResult } from '@/composables/patient/usePatientDirectory';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDate } from '@/lib/presentation';

/**
 * Version 2 Patient directory (shown only in V2; Classic is `pages/Patient/Index.vue`, untouched). Same props, same
 * `POST /patients/search`, same routes, permissions and masking. No backend change.
 */
defineProps<{ canCreate: boolean; canSeeMergeRequests?: boolean }>();

usePageCrumbs(() => [{ title: 'Patients' }]);

const {
    form,
    results,
    total,
    currentPage,
    lastPage,
    error,
    loading,
    searched,
    perPage,
    search,
} = usePatientDirectory();

const dobSex = (patient: PatientResult) => {
    const sex = patient.sex ?? '';

    return `${
        patient.dateOfBirth ? formatDate(patient.dateOfBirth) : 'Not recorded'
    } · ${sex.charAt(0).toUpperCase()}${sex.slice(1)}`;
};

const linkButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9';
</script>

<template>
    <Head title="Patient directory" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Patient directory"
            description="Find a patient record. Identity and phone values are masked in results."
        >
            <template #actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <Link
                        v-if="canSeeMergeRequests"
                        href="/patient-merges"
                        :class="[
                            linkButton,
                            'max-sm:order-2 max-sm:border-transparent max-sm:bg-transparent max-sm:text-primary',
                        ]"
                        >Merge requests</Link
                    >
                    <Link
                        v-if="canCreate"
                        href="/patients/create"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:order-1 lg:h-9"
                        >Create patient</Link
                    >
                </div>
            </template>

            <template #main>
                <h2 class="sr-only">Search patients</h2>
                <form class="flex flex-col gap-3" @submit.prevent="search(1)">
                    <V2Toolbar
                        v-model="form.query"
                        search-label="Search patients"
                        search-placeholder="Enter at least 3 characters"
                        @search="search(1)"
                    >
                        <template #filters>
                            <div class="w-full sm:w-44">
                                <OperationalSelect
                                    id="patient-search-type"
                                    v-model="form.search_type"
                                    label="Search type"
                                    :options="PATIENT_SEARCH_TYPES"
                                />
                            </div>
                            <input
                                v-if="form.search_type === 'passport'"
                                v-model="form.issuing_country_code"
                                aria-label="Passport issuing country (2-letter code)"
                                maxlength="2"
                                placeholder="Issuer"
                                class="h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base uppercase outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring sm:w-24 md:text-[15px] lg:h-9"
                            />
                        </template>
                        <template #primary>
                            <button
                                type="submit"
                                class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9"
                                :disabled="loading"
                            >
                                {{ loading ? 'Searching…' : 'Search' }}
                            </button>
                        </template>
                    </V2Toolbar>
                    <InputError :message="error" />
                </form>

                <p class="text-[13px] text-muted-foreground" role="status">
                    <template v-if="searched"
                        >{{ total }} matching
                        {{ total === 1 ? 'record' : 'records' }}</template
                    >
                </p>
                <p
                    v-if="!searched"
                    class="rounded-[14px] border border-dashed p-10 text-center text-sm text-muted-foreground"
                >
                    Start with a patient number, identity document, name or
                    phone.
                </p>

                <template v-else>
                    <V2Table
                        label="Patients"
                        :columns="4"
                        cards="xl"
                        :empty="!results.length"
                        empty-message="No matching patient records."
                        :loading="loading && !results.length"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Patient</th>
                                <th scope="col">DOB / sex</th>
                                <th scope="col">Identifier</th>
                                <th scope="col">Phone</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="patient in results"
                                :key="patient.patientNumber"
                            >
                                <td data-label="Patient" data-primary>
                                    <Link
                                        :href="
                                            patientHref(patient.patientNumber)
                                        "
                                        class="-my-1.5 block py-1.5 text-sm font-medium text-primary hover:underline max-xl:-my-2.5 max-xl:py-2.5"
                                        >{{ patient.fullName }}</Link
                                    >
                                    <span
                                        class="block font-mono text-xs font-normal text-muted-foreground"
                                        >{{ patient.patientNumber }}</span
                                    >
                                    <MergedFromNote
                                        :numbers="patient.mergedFrom ?? []"
                                    />
                                </td>
                                <td data-label="DOB / sex" class="text-sm">
                                    {{ dobSex(patient) }}
                                </td>
                                <td data-label="Identifier" class="text-sm">
                                    {{ identifierLabel(patient) }}
                                </td>
                                <td data-label="Phone" class="text-sm">
                                    <span
                                        class="flex items-center gap-1 max-xl:justify-end"
                                    >
                                        {{
                                            patient.maskedPhone ??
                                            'Not recorded'
                                        }}<WhatsAppLink
                                            :can-whatsapp="patient.canWhatsapp"
                                            :route-key="patient.patientNumber"
                                        />
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                    <V2Pager
                        v-if="lastPage > 1"
                        :current-page="currentPage"
                        :last-page="lastPage"
                        :total="total"
                        :per-page="perPage"
                        :disabled="loading"
                        @change="search"
                    />
                </template>
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
