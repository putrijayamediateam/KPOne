<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PayslipRow } from '@/components/hr/payroll/types';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import {
    addressLine,
    dateLabel,
    statusTone,
} from '@/components/hr/records/types';
import V2CorrectionDialog from '@/components/hr/records/V2CorrectionDialog.vue';
import V2DocumentList from '@/components/hr/records/V2DocumentList.vue';
import V2OutsideExportCard from '@/components/hr/records/V2OutsideExportCard.vue';
import V2OwnValue from '@/components/hr/records/V2OwnValue.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useRecordsPage } from '@/composables/hr/useRecordsPage';
import type { RecordsPageProps } from '@/composables/hr/useRecordsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 My HR details and Staff files (shown only in V2; Classic is `pages/HR/Records.vue`, untouched). Same props,
 * routes, permissions, lock versions and idempotency keys: every request comes from `useRecordsPage` (and the
 * correction and export composables), which Classic uses too, so this page sends none of its own. Sensitive values stay
 * as in Classic: your own are hidden until you press Show and are never fetched; nobody else's value is on this page;
 * documents open only through their existing audited link when pressed; nothing loads ahead.
 */
const props = defineProps<RecordsPageProps>();
usePageCrumbs(() => [
    {
        title: props.records.tabs.includes('files')
            ? 'Staff files'
            : 'My HR details',
    },
]);

const {
    errors,
    tabs,
    selectTab,
    my,
    notGiven,
    ownIdentity,
    identityMask,
    recordedFor,
    pendingKinds,
    correctionKind,
    correctionOpen,
    askCorrection,
    contactOpen,
    contact,
    editContact,
    saveContact,
    withdrawing,
    withdrawBusy,
    withdrawCorrection,
    correctionTone,
    correctionStatus,
    retentionConfirm,
    retentionLine,
    askRetention,
    retentionMessage,
    sendRetention,
    retentionDays,
    newItem,
    addItem,
    archiveItem,
    files,
    filters,
    applyFilters,
    clearFilters,
    filtered,
    branchOptions,
    typeOptions,
    statusOptions,
    attentionLabel,
    openFile,
    startOpenFile,
    sendOpenFile,
    locum,
    startLocum,
    sendLocum,
} = useRecordsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';

const isManager = computed(() => props.records.tabs.includes('files'));
const title = computed(() =>
    isManager.value ? 'Staff files' : 'My HR details',
);
// The manager sentence belongs to the manager view only (Daniel).
const description = computed(() =>
    isManager.value
        ? 'Employment, personal and bank details. Sensitive values stay hidden until someone presses Show.'
        : 'Your employment, personal and bank details. Change your contact details here, or ask HR to correct the rest.',
);
const personalItems = computed(() => {
    const p = my.value?.personal;

    if (!p) {
        return [];
    }

    return [
        { kind: 'legal_name', label: 'Legal name', value: p.legalName },
        {
            kind: 'identity',
            label: 'Identity number',
            value: p.identity ? ownIdentity.value : null,
        },
        {
            kind: 'date_of_birth',
            label: 'Date of birth',
            value: p.dateOfBirth ? dateLabel(p.dateOfBirth) : null,
        },
        { kind: 'nationality', label: 'Nationality', value: p.nationality },
    ];
});
const emergencyLine = computed(() => {
    const e = my.value?.personal?.emergencyContact;

    return e
        ? [e.name, e.relationship, e.phone].filter((p) => p).join(' · ')
        : notGiven;
});
const contactParts = [
    { key: 'line1', label: 'Address line 1' },
    { key: 'line2', label: 'Address line 2 (optional)' },
    { key: 'city', label: 'City' },
    { key: 'state', label: 'State' },
    { key: 'postcode', label: 'Postcode' },
    { key: 'country', label: 'Country' },
] as const;
const listOptions = [
    { value: 'onboarding', label: 'Onboarding' },
    { value: 'offboarding', label: 'Offboarding' },
];
const withoutFileOptions = computed(() =>
    (files.value?.withoutFile ?? []).map((u) => ({
        value: String(u.id),
        label: u.name,
    })),
);
const openFileMissing = computed(() =>
    openFile.userId === '' ? 'Choose a staff member to continue.' : '',
);
const locumMissing = computed(() =>
    locum.name.trim() === '' ? 'Enter a name to continue.' : '',
);
const itemMissing = computed(() =>
    newItem.label.trim() === '' ? 'Enter the item to continue.' : '',
);
const payslipLabel = (p: PayslipRow) =>
    p.kind === 'adjustment' ? `${p.period} (adjustment)` : p.period;
</script>

<template>
    <Head :title="title" />
    <PageFrame>
        <V2WorkLayout :title="title" :description="description">
            <template
                v-if="records.tab === 'files' && records.canManage"
                #actions
            >
                <button
                    type="button"
                    :class="button"
                    data-test="add-locum"
                    @click="startLocum"
                >
                    Add a locum
                </button>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="open-file"
                    @click="startOpenFile"
                >
                    Open a staff file
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="records.tab"
                        :tabs="tabs"
                        ariaLabel="Staff file sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="errors.records && !correctionOpen && !contactOpen"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.records }}
                    </p>

                    <!-- My HR details -->
                    <section
                        v-if="records.tab === 'my' && my"
                        aria-labelledby="v2-my-hr-title"
                        class="flex flex-col gap-4"
                        data-test="my-hr"
                    >
                        <h2 id="v2-my-hr-title" class="sr-only">
                            My HR details
                        </h2>
                        <div
                            v-if="sopsToRead"
                            :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                            data-test="sops-to-read"
                        >
                            <p class="text-sm">
                                <span class="font-semibold">SOPs to read:</span>
                                {{ sopsToRead }}
                            </p>
                            <Link href="/hr/sops" :class="button"
                                >Open SOPs</Link
                            >
                        </div>
                        <div
                            v-if="memosToRead"
                            :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                            data-test="memos-to-read"
                        >
                            <p class="text-sm">
                                <span class="font-semibold"
                                    >Memos to read:</span
                                >
                                {{ memosToRead }}
                            </p>
                            <Link href="/hr/memos" :class="button"
                                >Open memos</Link
                            >
                        </div>
                        <div
                            v-if="lettersMine"
                            :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                            data-test="letters-mine"
                        >
                            <p class="text-sm">
                                <span class="font-semibold"
                                    >Letters from HR:</span
                                >
                                {{ lettersMine }}
                            </p>
                            <Link href="/hr/letters" :class="button"
                                >Open letters</Link
                            >
                        </div>

                        <div
                            v-if="!my.hasFile"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                HR has not opened your staff file yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                When they do, your employment, personal and bank
                                details appear here. Ask HR if you expected to
                                see them.
                            </p>
                        </div>
                        <template v-else-if="my.worker && my.personal">
                            <div
                                :class="`${cardClass} sm:flex-row sm:items-center sm:gap-3`"
                            >
                                <p class="text-base font-semibold">
                                    {{ my.worker.name }}
                                </p>
                                <span class="text-sm text-muted-foreground">{{
                                    my.worker.role
                                }}</span>
                                <V2Chip
                                    :tone="statusTone(my.worker.status)"
                                    :label="my.worker.statusLabel"
                                />
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <section
                                    aria-labelledby="v2-my-employment"
                                    :class="cardClass"
                                >
                                    <h3
                                        id="v2-my-employment"
                                        class="text-sm font-semibold"
                                    >
                                        Employment
                                    </h3>
                                    <dl class="grid gap-3 text-sm">
                                        <div>
                                            <dt class="text-muted-foreground">
                                                Position
                                            </dt>
                                            <dd>
                                                {{
                                                    my.position
                                                        ? [
                                                              my.position.title,
                                                              my.position
                                                                  .department,
                                                          ]
                                                              .filter((p) => p)
                                                              .join(' · ')
                                                        : notGiven
                                                }}
                                            </dd>
                                        </div>
                                        <div
                                            v-for="(p, i) in my.periods"
                                            :key="i"
                                        >
                                            <dt class="text-muted-foreground">
                                                {{ p.typeLabel }}
                                            </dt>
                                            <dd>
                                                From {{ dateLabel(p.startsOn) }}
                                                <template v-if="p.endsOn"
                                                    >to
                                                    {{
                                                        dateLabel(p.endsOn)
                                                    }}</template
                                                >
                                                <template
                                                    v-if="
                                                        p.probationEndsOn &&
                                                        !p.confirmedOn
                                                    "
                                                >
                                                    · probation to
                                                    {{
                                                        dateLabel(
                                                            p.probationEndsOn,
                                                        )
                                                    }}</template
                                                >
                                                <template v-if="p.confirmedOn">
                                                    · confirmed
                                                    {{
                                                        dateLabel(p.confirmedOn)
                                                    }}</template
                                                >
                                            </dd>
                                        </div>
                                        <p
                                            v-if="!my.periods?.length"
                                            class="text-muted-foreground"
                                        >
                                            No employment period yet.
                                        </p>
                                    </dl>
                                </section>

                                <section
                                    aria-labelledby="v2-my-bank"
                                    :class="cardClass"
                                >
                                    <h3
                                        id="v2-my-bank"
                                        class="text-sm font-semibold"
                                    >
                                        Bank
                                    </h3>
                                    <dl class="grid gap-3 text-sm">
                                        <div>
                                            <dt class="text-muted-foreground">
                                                Account
                                            </dt>
                                            <dd class="flex flex-col gap-1">
                                                <template v-if="my.bank">
                                                    <span class="break-words"
                                                        >{{ my.bank.bankName }}
                                                        ·
                                                        {{
                                                            my.bank.holder
                                                        }}</span
                                                    >
                                                    <V2OwnValue
                                                        label="Account number"
                                                        field="account number"
                                                        :mask="`Account •••• ${my.bank.account.slice(-4)}`"
                                                        :value="my.bank.account"
                                                        :last4="
                                                            my.bank.account.slice(
                                                                -4,
                                                            )
                                                        "
                                                    />
                                                </template>
                                                <span v-else>{{
                                                    notGiven
                                                }}</span>
                                            </dd>
                                        </div>
                                    </dl>
                                    <V2Chip
                                        v-if="pendingKinds.has('bank')"
                                        tone="neutral"
                                        label="Correction waiting"
                                        class="self-start"
                                    />
                                    <button
                                        v-else
                                        type="button"
                                        :class="`${button} self-start`"
                                        data-test="ask-bank"
                                        @click="askCorrection('bank')"
                                    >
                                        Ask for a correction
                                    </button>
                                </section>
                            </div>

                            <section
                                aria-labelledby="v2-my-personal"
                                :class="cardClass"
                            >
                                <h3
                                    id="v2-my-personal"
                                    class="text-sm font-semibold"
                                >
                                    Personal
                                </h3>
                                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                                    <div
                                        v-for="item in personalItems"
                                        :key="item.kind"
                                        class="flex flex-col gap-1"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ item.label }}
                                        </dt>
                                        <dd
                                            v-if="
                                                item.kind === 'identity' &&
                                                my.personal.identity
                                            "
                                        >
                                            <V2OwnValue
                                                label="Identity number"
                                                field="identity number"
                                                :mask="identityMask"
                                                :value="ownIdentity"
                                                :last4="
                                                    my.personal.identity.number.slice(
                                                        -4,
                                                    )
                                                "
                                            />
                                        </dd>
                                        <dd v-else class="break-words">
                                            {{ item.value ?? notGiven }}
                                        </dd>
                                        <dd>
                                            <V2Chip
                                                v-if="
                                                    pendingKinds.has(item.kind)
                                                "
                                                tone="neutral"
                                                label="Correction waiting"
                                                :data-test="`waiting-${item.kind}`"
                                            />
                                            <button
                                                v-else
                                                type="button"
                                                :class="button"
                                                :data-test="`ask-${item.kind}`"
                                                @click="
                                                    askCorrection(item.kind)
                                                "
                                            >
                                                Ask for a correction
                                            </button>
                                        </dd>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <dt class="text-muted-foreground">
                                            Preferred name
                                        </dt>
                                        <dd>
                                            {{
                                                my.personal.preferredName ??
                                                notGiven
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                                <div class="flex flex-col gap-3 border-t pt-4">
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <h4 class="text-sm font-semibold">
                                            Contact details you can change
                                        </h4>
                                        <button
                                            type="button"
                                            :class="button"
                                            data-test="edit-contact"
                                            @click="editContact"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                    <dl
                                        class="grid gap-4 text-sm sm:grid-cols-3"
                                    >
                                        <div>
                                            <dt class="text-muted-foreground">
                                                Address
                                            </dt>
                                            <dd class="break-words">
                                                {{
                                                    addressLine(
                                                        my.personal.address,
                                                    ) || notGiven
                                                }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-muted-foreground">
                                                Personal phone
                                            </dt>
                                            <dd>
                                                {{
                                                    my.personal.personalPhone ??
                                                    notGiven
                                                }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-muted-foreground">
                                                Emergency contact
                                            </dt>
                                            <dd class="break-words">
                                                {{ emergencyLine }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </section>

                            <section
                                v-if="my.corrections?.length"
                                aria-labelledby="v2-my-corrections"
                                :class="cardClass"
                            >
                                <h3
                                    id="v2-my-corrections"
                                    class="text-sm font-semibold"
                                >
                                    My corrections
                                </h3>
                                <ul class="flex flex-col divide-y">
                                    <li
                                        v-for="c in my.corrections"
                                        :key="c.id"
                                        class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2 text-sm"
                                        data-test="my-correction"
                                    >
                                        <span class="font-medium">{{
                                            c.kindLabel
                                        }}</span>
                                        <span class="text-muted-foreground">{{
                                            c.reasonLabel
                                        }}</span>
                                        <V2Chip
                                            :tone="correctionTone(c.status)"
                                            :label="correctionStatus(c)"
                                        />
                                        <span
                                            v-if="c.decisionLabel"
                                            class="text-muted-foreground"
                                            >{{ c.decisionLabel }}</span
                                        >
                                        <button
                                            v-if="c.status === 'pending'"
                                            type="button"
                                            :class="dangerButton"
                                            @click="withdrawing = c"
                                        >
                                            Withdraw
                                        </button>
                                    </li>
                                </ul>
                            </section>

                            <section
                                aria-labelledby="v2-my-documents"
                                class="flex flex-col gap-3"
                                data-test="my-documents"
                            >
                                <h3
                                    id="v2-my-documents"
                                    class="text-sm font-semibold"
                                >
                                    My documents
                                </h3>
                                <V2DocumentList
                                    :documents="my.documents ?? []"
                                    :worker-id="my.workerId ?? ''"
                                    :can-open="true"
                                    empty-title="No documents yet."
                                    empty-description="HR adds your contract and other documents here. Ask HR if one is missing."
                                />
                            </section>

                            <section
                                aria-labelledby="v2-my-payslips"
                                class="flex flex-col gap-3"
                                data-test="my-payslips"
                            >
                                <h3
                                    id="v2-my-payslips"
                                    class="text-sm font-semibold"
                                >
                                    My payslips
                                </h3>
                                <ul
                                    v-if="records.payslips.length"
                                    class="flex flex-col gap-2"
                                >
                                    <li
                                        v-for="p in records.payslips"
                                        :key="p.id"
                                        :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                        data-test="my-payslip"
                                    >
                                        <span class="min-w-0 text-sm">
                                            <span class="font-medium">{{
                                                payslipLabel(p)
                                            }}</span>
                                            <span class="text-muted-foreground">
                                                · {{ p.group }} · issued
                                                {{ p.issuedOn }}</span
                                            >
                                        </span>
                                        <Link
                                            :href="`/hr/payslips/${p.id}`"
                                            :class="button"
                                            :aria-label="`Open payslip for ${p.period}`"
                                            >Open</Link
                                        >
                                    </li>
                                </ul>
                                <p v-else class="text-sm text-muted-foreground">
                                    No payslips yet. Each one appears here once
                                    HR locks that month's payroll run.
                                </p>
                            </section>
                        </template>
                    </section>

                    <!-- Staff files -->
                    <section
                        v-if="records.tab === 'files' && files"
                        aria-labelledby="v2-files-title"
                        class="flex flex-col gap-4"
                        data-test="staff-files"
                    >
                        <h2 id="v2-files-title" class="sr-only">Staff files</h2>
                        <form
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 lg:flex-row lg:flex-wrap lg:items-end"
                            @submit.prevent="applyFilters"
                        >
                            <div class="grid min-w-0 gap-1.5 lg:w-64">
                                <label
                                    for="v2-files-search"
                                    class="text-sm font-medium"
                                    >Search by name</label
                                >
                                <input
                                    id="v2-files-search"
                                    v-model="filters.q"
                                    type="search"
                                    autocomplete="off"
                                    :class="inputClass"
                                />
                            </div>
                            <div class="grid gap-1.5 lg:w-48">
                                <label
                                    id="v2-files-branch-label"
                                    class="text-sm font-medium"
                                    >Branch</label
                                >
                                <OperationalSelect
                                    v-model="filters.branch"
                                    trigger-class="max-lg:h-11"
                                    label="Branch"
                                    labelledby="v2-files-branch-label"
                                    :options="branchOptions"
                                />
                            </div>
                            <div class="grid gap-1.5 lg:w-44">
                                <label
                                    id="v2-files-type-label"
                                    class="text-sm font-medium"
                                    >Type</label
                                >
                                <OperationalSelect
                                    v-model="filters.type"
                                    trigger-class="max-lg:h-11"
                                    label="Type"
                                    labelledby="v2-files-type-label"
                                    :options="typeOptions"
                                />
                            </div>
                            <div class="grid gap-1.5 lg:w-48">
                                <label
                                    id="v2-files-status-label"
                                    class="text-sm font-medium"
                                    >Status</label
                                >
                                <OperationalSelect
                                    v-model="filters.status"
                                    trigger-class="max-lg:h-11"
                                    label="Status"
                                    labelledby="v2-files-status-label"
                                    :options="statusOptions"
                                />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="submit" :class="button">
                                    Search
                                </button>
                                <button
                                    v-if="filtered"
                                    type="button"
                                    :class="button"
                                    @click="clearFilters"
                                >
                                    Clear
                                </button>
                            </div>
                        </form>

                        <div
                            v-if="files.total === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No staff files yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Open a staff file for someone with a KPOne
                                account, or add a locum. Files are made one at a
                                time, never in bulk.
                            </p>
                        </div>
                        <div
                            v-else-if="files.rows.length === 0"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No staff files match.</p>
                            <p class="text-sm text-muted-foreground">
                                Try another name, or clear the filters.
                            </p>
                        </div>
                        <template v-else>
                            <div
                                class="hidden overflow-x-auto rounded-[14px] border bg-card lg:block"
                            >
                                <table class="w-full min-w-[60rem] text-sm">
                                    <caption class="sr-only">
                                        Staff files
                                    </caption>
                                    <thead
                                        class="text-left text-muted-foreground"
                                    >
                                        <tr class="border-b">
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Name
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Branch
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Role
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Type
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                class="px-4 py-2 font-medium"
                                            >
                                                Needs attention
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in files.rows"
                                            :key="row.id"
                                            class="border-b align-top last:border-0"
                                            data-test="file-row"
                                        >
                                            <th
                                                scope="row"
                                                class="px-4 py-3 text-left font-medium"
                                            >
                                                <Link
                                                    :href="`/hr/records/${row.id}`"
                                                    class="underline-offset-4 hover:underline"
                                                    >{{ row.name }}</Link
                                                >
                                            </th>
                                            <td class="px-4 py-3">
                                                {{ row.branch ?? '—' }}
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ row.role }}
                                                <V2Chip
                                                    v-if="row.kind === 'locum'"
                                                    tone="neutral"
                                                    label="No KPOne sign-in"
                                                    class="ml-1"
                                                />
                                            </td>
                                            <td class="px-4 py-3">
                                                {{ row.typeLabel ?? '—' }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <V2Chip
                                                    :tone="
                                                        statusTone(row.status)
                                                    "
                                                    :label="row.statusLabel"
                                                />
                                            </td>
                                            <td class="px-4 py-3">
                                                <template
                                                    v-if="row.attention.length"
                                                >
                                                    <V2Chip
                                                        tone="warning"
                                                        :label="
                                                            attentionLabel(
                                                                row.attention,
                                                            )
                                                        "
                                                    />
                                                    <ul
                                                        v-if="
                                                            row.attention
                                                                .length > 1
                                                        "
                                                        class="mt-1 list-disc pl-5 text-xs text-muted-foreground"
                                                    >
                                                        <li
                                                            v-for="reason in row.attention"
                                                            :key="reason"
                                                        >
                                                            {{ reason }}
                                                        </li>
                                                    </ul>
                                                </template>
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >—</span
                                                >
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <ul
                                class="flex flex-col gap-3 lg:hidden"
                                aria-label="Staff files"
                            >
                                <li
                                    v-for="row in files.rows"
                                    :key="row.id"
                                    :class="cardClass"
                                    data-test="file-card"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Link
                                            :href="`/hr/records/${row.id}`"
                                            class="inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                                            >{{ row.name }}</Link
                                        >
                                        <V2Chip
                                            :tone="statusTone(row.status)"
                                            :label="row.statusLabel"
                                        />
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            [
                                                row.role,
                                                row.branch,
                                                row.typeLabel !== row.role
                                                    ? row.typeLabel
                                                    : null,
                                            ]
                                                .filter((p) => p)
                                                .join(' · ')
                                        }}
                                    </p>
                                    <V2Chip
                                        v-if="row.kind === 'locum'"
                                        tone="neutral"
                                        label="No KPOne sign-in"
                                        class="self-start"
                                    />
                                    <div
                                        v-if="row.attention.length"
                                        class="flex flex-col gap-1"
                                    >
                                        <V2Chip
                                            tone="warning"
                                            :label="
                                                attentionLabel(row.attention)
                                            "
                                            class="self-start"
                                        />
                                        <ul
                                            v-if="row.attention.length > 1"
                                            class="list-disc pl-5 text-xs text-muted-foreground"
                                        >
                                            <li
                                                v-for="reason in row.attention"
                                                :key="reason"
                                            >
                                                {{ reason }}
                                            </li>
                                        </ul>
                                    </div>
                                </li>
                            </ul>
                        </template>
                    </section>

                    <V2OutsideExportCard
                        v-if="records.tab === 'files' && records.canManage"
                    />

                    <!-- Settings: retention per document type and checklist items (managers, under Staff files) -->
                    <details
                        v-if="records.tab === 'files' && records.settings"
                        class="rounded-[14px] border bg-card p-4"
                        data-test="records-settings"
                    >
                        <summary
                            class="min-h-11 cursor-pointer content-center text-sm font-semibold lg:min-h-0"
                        >
                            Documents and checklist settings
                        </summary>
                        <div class="mt-4 grid gap-6 lg:grid-cols-2">
                            <section
                                aria-labelledby="v2-retention-title"
                                class="flex flex-col gap-3"
                            >
                                <h3
                                    id="v2-retention-title"
                                    class="text-sm font-semibold"
                                >
                                    How long documents are kept
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Days after the person's last employment
                                    period ends. KPOne ships no number; until
                                    you enter one, documents of that type are
                                    kept. The files of anyone with no ended
                                    employment period are always kept. A first
                                    or shorter retention starts after
                                    {{ records.settings.graceDays }} days, so a
                                    mistake can be undone.
                                </p>
                                <p
                                    v-if="
                                        errors.records && !retentionConfirm.row
                                    "
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.records }}
                                </p>
                                <form
                                    v-for="r in records.settings.retentions"
                                    :key="r.category"
                                    class="flex flex-col gap-1.5"
                                    data-test="retention-form"
                                    @submit.prevent="askRetention(r)"
                                >
                                    <div class="flex flex-wrap items-end gap-2">
                                        <div class="grid min-w-0 gap-1.5">
                                            <label
                                                :for="`v2-retention-${r.category}`"
                                                class="text-sm font-medium"
                                                >{{ r.label }} (days)</label
                                            >
                                            <input
                                                :id="`v2-retention-${r.category}`"
                                                v-model="
                                                    retentionDays[r.category]
                                                "
                                                type="number"
                                                min="1"
                                                max="36500"
                                                inputmode="numeric"
                                                placeholder="Not set"
                                                :class="`${inputClass} w-40`"
                                            />
                                        </div>
                                        <button type="submit" :class="button">
                                            Save
                                        </button>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ retentionLine(r) }}
                                    </p>
                                </form>
                                <p
                                    v-if="errors.retention_days"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.retention_days }}
                                </p>
                            </section>
                            <section
                                aria-labelledby="v2-items-title"
                                class="flex flex-col gap-3"
                            >
                                <h3
                                    id="v2-items-title"
                                    class="text-sm font-semibold"
                                >
                                    Checklist items
                                </h3>
                                <div
                                    v-for="list in [
                                        'onboarding',
                                        'offboarding',
                                    ] as const"
                                    :key="list"
                                    class="flex flex-col gap-1"
                                >
                                    <p class="text-sm font-medium">
                                        {{
                                            list === 'onboarding'
                                                ? 'Onboarding'
                                                : 'Offboarding'
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            !records.settings.items.some(
                                                (i) => i.list === list,
                                            )
                                        "
                                        class="text-sm text-muted-foreground"
                                    >
                                        No items yet.
                                    </p>
                                    <ul class="flex flex-col divide-y">
                                        <li
                                            v-for="item in records.settings.items.filter(
                                                (i) => i.list === list,
                                            )"
                                            :key="item.id"
                                            class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                                        >
                                            <span
                                                >{{ item.label
                                                }}<span
                                                    v-if="item.aboutAccount"
                                                    class="text-muted-foreground"
                                                >
                                                    · asks the account
                                                    administrator</span
                                                ></span
                                            >
                                            <button
                                                type="button"
                                                :class="button"
                                                @click="archiveItem(item)"
                                            >
                                                Archive
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                                <form
                                    class="flex flex-col gap-3 border-t pt-3"
                                    @submit.prevent="addItem"
                                >
                                    <V2OptionGroup
                                        v-model="newItem.list"
                                        :options="listOptions"
                                        label="List"
                                        required
                                    />
                                    <V2Field
                                        v-slot="{ id, describedBy }"
                                        label="Item"
                                        required
                                        :error="errors.label"
                                    >
                                        <input
                                            :id="id"
                                            v-model="newItem.label"
                                            maxlength="160"
                                            :class="inputClass"
                                            :aria-describedby="describedBy"
                                        />
                                    </V2Field>
                                    <label
                                        class="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <input
                                            v-model="newItem.aboutAccount"
                                            type="checkbox"
                                            class="size-5"
                                        />
                                        About the KPOne account (HR asks the
                                        account administrator)
                                    </label>
                                    <div
                                        class="flex flex-wrap items-center gap-3"
                                    >
                                        <button
                                            type="submit"
                                            :class="button"
                                            :disabled="
                                                newItem.processing ||
                                                itemMissing !== ''
                                            "
                                        >
                                            Add item
                                        </button>
                                        <span
                                            v-if="itemMissing"
                                            class="text-sm text-muted-foreground"
                                            >{{ itemMissing }}</span
                                        >
                                    </div>
                                </form>
                            </section>
                        </div>
                    </details>
                </div>
            </template>
        </V2WorkLayout>

        <V2CorrectionDialog
            v-model:open="correctionOpen"
            :kind="correctionKind"
            :kinds="records.options.correctionKinds"
            :reasons="records.options.correctionReasons"
            :recorded="recordedFor(correctionKind)"
        />

        <!-- Your contact details -->
        <V2Dialog
            :open="contactOpen"
            title="Your contact details"
            description="You can change these yourself. HR sees the change."
            size="tall"
            :confirm-label="contact.processing ? 'Saving…' : 'Save'"
            :busy="contact.processing"
            data-test="contact-sheet"
            @update:open="(open: boolean) => (contactOpen = open)"
            @confirm="saveContact"
        >
            <p
                v-if="errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.records }}
            </p>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-semibold">Address</legend>
                <V2Field
                    v-for="part in contactParts"
                    :key="part.key"
                    v-slot="{ id, describedBy }"
                    :label="part.label"
                    :error="errors[`address.${part.key}`]"
                >
                    <input
                        :id="id"
                        v-model="contact.address[part.key]"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </fieldset>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Personal phone"
                :error="errors.personal_phone"
            >
                <input
                    :id="id"
                    v-model="contact.phone"
                    type="tel"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-1 text-sm font-semibold">
                    Emergency contact
                </legend>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Name"
                    :error="errors['emergency_contact.name']"
                >
                    <input
                        :id="id"
                        v-model="contact.emergency.name"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Relationship"
                    :error="errors['emergency_contact.relationship']"
                >
                    <input
                        :id="id"
                        v-model="contact.emergency.relationship"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Phone"
                    :error="errors['emergency_contact.phone']"
                >
                    <input
                        :id="id"
                        v-model="contact.emergency.phone"
                        type="tel"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
            </fieldset>
        </V2Dialog>

        <!-- Open a staff file -->
        <V2Dialog
            :open="openFile.open"
            title="Open a staff file"
            description="For someone who already has a KPOne account. HR never changes the account."
            :confirm-label="
                openFile.processing ? 'Opening…' : 'Open staff file'
            "
            :busy="openFile.processing"
            :confirm-disabled="
                !files?.withoutFile.length || openFileMissing !== ''
            "
            :confirm-disabled-reason="
                !files?.withoutFile.length
                    ? 'Everyone with a KPOne account already has a staff file.'
                    : openFileMissing
            "
            @update:open="(open: boolean) => (openFile.open = open)"
            @confirm="sendOpenFile"
        >
            <p
                v-if="!files?.withoutFile.length"
                class="text-sm text-muted-foreground"
            >
                Everyone with a KPOne account already has a staff file.
            </p>
            <div v-else class="grid gap-1.5">
                <label id="v2-open-file-label" class="text-sm font-medium"
                    >Staff member</label
                >
                <OperationalSelect
                    v-model="openFile.userId"
                    trigger-class="max-lg:h-11"
                    label="Staff member"
                    labelledby="v2-open-file-label"
                    placeholder="Choose a staff member"
                    :options="withoutFileOptions"
                    :invalid="!!errors.user_id"
                />
                <p
                    v-if="errors.user_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.user_id }}
                </p>
            </div>
        </V2Dialog>

        <!-- Add a locum -->
        <V2Dialog
            :open="locum.open"
            title="Add a locum"
            description="A locum has a staff file but no KPOne sign-in."
            :confirm-label="locum.processing ? 'Adding…' : 'Add locum'"
            :busy="locum.processing"
            :confirm-disabled="locumMissing !== ''"
            :confirm-disabled-reason="locumMissing"
            data-test="locum-sheet"
            @update:open="(open: boolean) => (locum.open = open)"
            @confirm="sendLocum"
        >
            <V2Field
                v-slot="{ id, describedBy }"
                label="Name"
                required
                :error="errors.display_name"
            >
                <input
                    :id="id"
                    v-model="locum.name"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Contact phone (optional)"
                :error="errors.phone"
            >
                <input
                    :id="id"
                    v-model="locum.phone"
                    type="tel"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
        </V2Dialog>

        <!-- Save a retention (the daily purge deletes files, so it is confirmed in words) -->
        <V2Dialog
            :open="retentionConfirm.row !== null"
            title="Save this retention?"
            :description="retentionMessage"
            confirm-label="Save"
            :busy="retentionConfirm.processing"
            @update:open="
                (open: boolean) => !open && (retentionConfirm.row = null)
            "
            @confirm="sendRetention"
        >
            <p
                v-if="errors.retention_days ?? errors.records"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.retention_days ?? errors.records }}
            </p>
        </V2Dialog>

        <!-- Withdraw a correction -->
        <V2Dialog
            :open="withdrawing !== null"
            title="Withdraw this correction?"
            :description="
                withdrawing
                    ? `${withdrawing.kindLabel}. HR will no longer see it.`
                    : ''
            "
            confirm-label="Withdraw"
            tone="danger"
            :busy="withdrawBusy"
            @update:open="(open: boolean) => !open && (withdrawing = null)"
            @confirm="withdrawCorrection"
        >
            <p
                v-if="errors.correction"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.correction }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
