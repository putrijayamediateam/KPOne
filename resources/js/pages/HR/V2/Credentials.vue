<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import { statusLine, statusTone } from '@/components/hr/credentials/types';
import type { CredentialStatus } from '@/components/hr/credentials/types';
import type { RecordView } from '@/components/hr/credentials/types';
import V2StatusChip from '@/components/hr/credentials/V2StatusChip.vue';
import WorkPermitWarnings from '@/components/hr/credentials/WorkPermitWarnings.vue';
import MaskedValue from '@/components/hr/records/MaskedValue.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { dateLabel } from '@/components/hr/records/types';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useCredentialRecordSheet } from '@/composables/hr/useCredentialRecordSheet';
import { useCredentialsPage } from '@/composables/hr/useCredentialsPage';
import type { CredentialsPageProps } from '@/composables/hr/useCredentialsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Credentials (shown only in V2; Classic is `pages/HR/Credentials.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useCredentialsPage` and
 * `useCredentialRecordSheet`, which Classic uses too, so this page sends none of its own. A credential reference stays
 * masked: it is shown only through the same audited Show component and URL as Classic (a Show records the view first),
 * and a certificate opens only through its audited link. Nothing is prefetched, cached or put in props. Warnings only:
 * Expiring and Expired are never red.
 */
const props = defineProps<CredentialsPageProps>();
usePageCrumbs(() => [{ title: 'Credentials' }]);

const {
    errors,
    tabs,
    selectTab,
    roleList,
    sheet,
    openOwn,
    register,
    filters,
    applyFilters,
    clearFilters,
    filtered,
    branchOptions,
    roleOptions,
    typeOptions,
    statusOptions,
    summaryLine,
    cellRecord,
    attention,
    enter,
    startEnter,
    continueEnter,
    enterTypeOptions,
    decision,
    askDecision,
    sendDecision,
    recordSummary,
    newType,
    yesNo,
    addType,
    edit,
    startEdit,
    saveEdit,
    archiving,
    archiveBusy,
    archiveType,
} = useCredentialsPage(props);

// The add or renew dialog: the same request and key as Classic's sheet.
const {
    errors: sheetErrors,
    form: sheetForm,
    healthOptions,
    title: sheetTitle,
    onFile,
    send: sendRecord,
} = useCredentialRecordSheet(sheet, () => (sheet.open = false));

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';

// The manager sentence belongs to the manager tabs only (Daniel).
const description = computed(() =>
    props.credentials.tab === 'my' || !props.credentials.canManage
        ? 'Your certificates and renewals. Someone else checks each one before it counts.'
        : 'Certificates and renewals for everyone, checked by someone other than the person. Warnings only: nothing here stops clinical work.',
);
const title = computed(() =>
    props.credentials.canManage ? 'Credentials' : 'My credentials',
);
// Expiring and Expired are warnings, never red; Expired also carries a small mark so it never reads like Expiring.
const chipTone = (code: CredentialStatus['code']) => statusTone(code);
const chipLabel = (status: CredentialStatus, record: RecordView | null) =>
    statusLine(status, record);
const spoken = (
    name: string,
    status: CredentialStatus,
    record: RecordView | null,
) => `${name}, ${statusLine(status, record).replace(' · ', ', ')}`;
const sheetMissing = computed(() => {
    if (!sheet.type) {
        return '';
    }

    if (sheet.type.healthItem) {
        if (sheetForm.health === '') {
            return 'Choose Done or Not done to continue.';
        }

        return sheetForm.health === 'done' && sheetForm.issuedOn === ''
            ? 'Choose the date done to continue.'
            : '';
    }

    if (sheetForm.issuedOn === '') {
        return 'Choose the issue date to continue.';
    }

    return sheet.type.expires && sheetForm.expiresOn === ''
        ? 'Choose the expiry date to continue.'
        : '';
});
const decisionMissing = computed(() =>
    decision.kind === 'reject' && decision.reason === ''
        ? 'Pick a reason to continue.'
        : '',
);
const enterMissing = computed(() =>
    enter.typeId === '' ? 'Choose the credential to continue.' : '',
);
const addTypeMissing = computed(() =>
    newType.name.trim() === '' || newType.code.trim() === ''
        ? 'Enter a name and a short code to continue.'
        : '',
);
</script>

<template>
    <Head :title="title" />
    <PageFrame>
        <V2WorkLayout :title="title" :description="description">
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="credentials.tab"
                        :tabs="tabs"
                        ariaLabel="Credential sections"
                        @update:model-value="selectTab"
                    />

                    <p
                        v-if="
                            errors.credentials &&
                            !sheet.open &&
                            !decision.item &&
                            !edit.open &&
                            !archiving
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ errors.credentials }}
                    </p>

                    <!-- My credentials -->
                    <section
                        v-if="credentials.tab === 'my' && credentials.my"
                        aria-labelledby="v2-my-credentials-title"
                        class="flex flex-col gap-4"
                        data-test="my-credentials"
                    >
                        <h2 id="v2-my-credentials-title" class="sr-only">
                            My credentials
                        </h2>
                        <div
                            v-if="credentials.my.mmc"
                            class="rounded-[14px] border bg-card p-4 text-sm"
                            data-test="mmc"
                        >
                            <p class="font-medium">
                                MMC:
                                {{
                                    credentials.my.mmc.verified
                                        ? 'verified by the Director'
                                        : credentials.my.mmc.number
                                          ? 'not verified yet'
                                          : 'not entered yet'
                                }}<template v-if="credentials.my.mmc.number">
                                    · {{ credentials.my.mmc.number }}</template
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                From Clinician credentials. The Director updates
                                this.
                            </p>
                        </div>
                        <div
                            v-if="!credentials.my.hasFile"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                HR has not opened your staff file yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                When they do, your certificates appear here. Ask
                                HR if you expected to see them.
                            </p>
                        </div>
                        <div
                            v-else-if="!credentials.setUp"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                Credentials are not set up yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                HR enters which certificates each role needs.
                                Nothing is needed from you until then.
                            </p>
                        </div>
                        <div
                            v-else-if="!credentials.my.cards?.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                Your role needs no credentials.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                If HR adds one your role needs, it appears here.
                            </p>
                        </div>
                        <ul
                            v-else
                            class="grid gap-4 md:grid-cols-2"
                            aria-label="My credentials"
                        >
                            <li
                                v-for="card in credentials.my.cards"
                                :key="card.typeId"
                                class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                                data-test="credential-card"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div class="min-w-0">
                                        <h3
                                            class="text-sm font-semibold [overflow-wrap:anywhere]"
                                        >
                                            {{ card.name }}
                                        </h3>
                                        <p
                                            v-if="!card.required"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Not required for your role
                                        </p>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1"
                                        data-test="credential-status"
                                    >
                                        <V2StatusChip
                                            :expired="
                                                card.status.code === 'expired'
                                            "
                                            :tone="chipTone(card.status.code)"
                                            :label="
                                                chipLabel(
                                                    card.status,
                                                    card.inForce,
                                                )
                                            "
                                        />
                                    </span>
                                </div>
                                <dl
                                    v-if="card.inForce && !card.healthItem"
                                    class="grid gap-2 text-sm sm:grid-cols-2"
                                >
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Issued
                                        </dt>
                                        <dd>
                                            {{
                                                dateLabel(card.inForce.issuedOn)
                                            }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Expires
                                        </dt>
                                        <dd>
                                            {{
                                                card.inForce.expiresOn
                                                    ? dateLabel(
                                                          card.inForce
                                                              .expiresOn,
                                                      )
                                                    : 'No expiry'
                                            }}
                                        </dd>
                                    </div>
                                    <div v-if="card.inForce.issuingBody">
                                        <dt class="text-muted-foreground">
                                            Issuing body
                                        </dt>
                                        <dd class="[overflow-wrap:anywhere]">
                                            {{ card.inForce.issuingBody }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="card.inForce.referenceLast4"
                                        class="sm:col-span-2"
                                    >
                                        <dt class="text-muted-foreground">
                                            Reference
                                        </dt>
                                        <dd>
                                            <MaskedValue
                                                label="Reference"
                                                field="reference"
                                                :mask="`•••• ${card.inForce.referenceLast4}`"
                                                :last4="
                                                    card.inForce.referenceLast4
                                                "
                                                worker-id=""
                                                show-field="reference"
                                                :url="`/hr/credentials/records/${card.inForce.id}/show-reference`"
                                            />
                                        </dd>
                                    </div>
                                    <div
                                        v-if="
                                            card.inForce.documentId &&
                                            credentials.my.workerId
                                        "
                                    >
                                        <dt class="text-muted-foreground">
                                            Certificate
                                        </dt>
                                        <dd>
                                            <a
                                                :href="`/hr/records/${credentials.my.workerId}/documents/${card.inForce.documentId}`"
                                                class="inline-flex min-h-11 items-center text-primary underline underline-offset-4 lg:min-h-0"
                                                >Open certificate</a
                                            >
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >Opening a document is
                                                recorded.</span
                                            >
                                        </dd>
                                    </div>
                                </dl>
                                <div
                                    v-if="card.waiting"
                                    class="flex flex-col gap-1 rounded-[10px] border border-dashed p-3 text-sm"
                                    data-test="credential-waiting"
                                >
                                    <V2Chip
                                        tone="neutral"
                                        label="Waiting for check"
                                        class="self-start"
                                    />
                                    <p>
                                        {{ recordSummary(card.waiting, card) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Entered by
                                        {{ card.waiting.enteredBy }} on
                                        {{ dateLabel(card.waiting.enteredAt) }}.
                                        Someone else checks this.
                                    </p>
                                </div>
                                <p
                                    v-if="card.rejected && !card.waiting"
                                    class="text-sm text-muted-foreground"
                                >
                                    Your last one was not accepted:
                                    {{ card.rejected.reason }}.
                                </p>
                                <div class="mt-auto">
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="!!card.waiting"
                                        :data-test="
                                            card.inForce ? 'renew' : 'add'
                                        "
                                        @click="openOwn(card)"
                                    >
                                        {{
                                            card.inForce
                                                ? card.healthItem
                                                    ? 'Update'
                                                    : 'Renew'
                                                : 'Add'
                                        }}
                                    </button>
                                    <p
                                        v-if="card.waiting"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        You can send another once this one is
                                        checked.
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Register -->
                    <section
                        v-if="credentials.tab === 'register' && register"
                        aria-labelledby="v2-register-title"
                        class="flex flex-col gap-4"
                        data-test="credentials-register"
                    >
                        <h2 id="v2-register-title" class="sr-only">
                            Credentials register
                        </h2>
                        <div
                            v-if="!credentials.setUp"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                Credentials are not set up yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Add the credential types and the roles that need
                                them under Credential types.
                            </p>
                        </div>
                        <template v-else>
                            <p
                                class="text-sm font-medium"
                                data-test="register-summary"
                            >
                                {{ summaryLine }}
                            </p>
                            <WorkPermitWarnings
                                :items="register.permits"
                                heading-id="v2-permits-title"
                                v2
                            />
                            <form
                                class="flex flex-col gap-3 rounded-[14px] border bg-card p-4 lg:flex-row lg:flex-wrap lg:items-end"
                                @submit.prevent="applyFilters"
                            >
                                <div class="grid gap-1.5 lg:w-48">
                                    <label
                                        id="v2-register-branch-label"
                                        class="text-sm font-medium"
                                        >Branch</label
                                    >
                                    <OperationalSelect
                                        v-model="filters.branch"
                                        trigger-class="max-lg:h-11"
                                        label="Branch"
                                        labelledby="v2-register-branch-label"
                                        :options="branchOptions"
                                    />
                                </div>
                                <div class="grid gap-1.5 lg:w-48">
                                    <label
                                        id="v2-register-role-label"
                                        class="text-sm font-medium"
                                        >Role</label
                                    >
                                    <OperationalSelect
                                        v-model="filters.role"
                                        trigger-class="max-lg:h-11"
                                        label="Role"
                                        labelledby="v2-register-role-label"
                                        :options="roleOptions"
                                    />
                                </div>
                                <div class="grid gap-1.5 lg:w-56">
                                    <label
                                        id="v2-register-type-label"
                                        class="text-sm font-medium"
                                        >Credential</label
                                    >
                                    <OperationalSelect
                                        v-model="filters.type"
                                        trigger-class="max-lg:h-11"
                                        label="Credential"
                                        labelledby="v2-register-type-label"
                                        :options="typeOptions"
                                    />
                                </div>
                                <div class="grid gap-1.5 lg:w-48">
                                    <label
                                        id="v2-register-status-label"
                                        class="text-sm font-medium"
                                        >Status</label
                                    >
                                    <OperationalSelect
                                        v-model="filters.status"
                                        trigger-class="max-lg:h-11"
                                        label="Status"
                                        labelledby="v2-register-status-label"
                                        :options="statusOptions"
                                    />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" :class="button">
                                        Show
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

                            <section
                                v-if="register.waiting.length"
                                aria-labelledby="v2-waiting-title"
                                class="flex flex-col gap-3"
                                data-test="register-waiting"
                            >
                                <h3
                                    id="v2-waiting-title"
                                    class="text-sm font-semibold"
                                >
                                    Waiting for check
                                </h3>
                                <ul class="flex flex-col gap-3">
                                    <li
                                        v-for="w in register.waiting"
                                        :key="w.new.id"
                                        class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                                        data-test="waiting-record"
                                    >
                                        <p class="text-sm">
                                            <span class="font-semibold">{{
                                                w.person
                                            }}</span>
                                            · {{ w.name }}
                                        </p>
                                        <div
                                            class="grid gap-3 text-sm md:grid-cols-2"
                                        >
                                            <div>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    Recorded now
                                                </p>
                                                <p
                                                    class="[overflow-wrap:anywhere]"
                                                >
                                                    {{
                                                        recordSummary(
                                                            w.recorded,
                                                            w,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                            <div>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    New
                                                </p>
                                                <p
                                                    class="[overflow-wrap:anywhere]"
                                                >
                                                    {{
                                                        recordSummary(w.new, w)
                                                    }}
                                                </p>
                                                <a
                                                    v-if="w.new.documentId"
                                                    :href="`/hr/records/${w.workerId}/documents/${w.new.documentId}`"
                                                    class="inline-flex min-h-11 items-center text-sm text-primary underline underline-offset-4 lg:min-h-0"
                                                    >Open certificate</a
                                                >
                                            </div>
                                        </div>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Entered by {{ w.enteredBy }} on
                                            {{ dateLabel(w.enteredAt) }}.
                                        </p>
                                        <p
                                            v-if="w.decideRefusal"
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ w.decideRefusal }}
                                        </p>
                                        <div
                                            v-else
                                            class="flex flex-wrap gap-2"
                                        >
                                            <button
                                                type="button"
                                                :class="button"
                                                data-test="accept"
                                                @click="
                                                    askDecision(w, 'accept')
                                                "
                                            >
                                                Accept
                                            </button>
                                            <button
                                                type="button"
                                                :class="dangerButton"
                                                data-test="reject"
                                                @click="
                                                    askDecision(w, 'reject')
                                                "
                                            >
                                                Reject
                                            </button>
                                        </div>
                                    </li>
                                </ul>
                            </section>

                            <div
                                v-if="register.rows.length === 0"
                                class="rounded-[14px] border bg-card p-6 text-center"
                            >
                                <p class="font-medium">
                                    {{
                                        register.total === 0
                                            ? 'No staff files yet.'
                                            : 'Nobody matches.'
                                    }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        register.total === 0
                                            ? 'The register lists everyone with a staff file. Open staff files under KPOne HR staff files.'
                                            : 'Try another filter, or clear the filters.'
                                    }}
                                </p>
                            </div>
                            <template v-else>
                                <!-- From 1024 px: person × credential, header row and person column sticky. -->
                                <div
                                    class="hidden max-h-[70vh] overflow-auto rounded-[14px] border bg-card lg:block"
                                    data-test="register-matrix"
                                >
                                    <table
                                        class="w-full border-separate border-spacing-0 text-sm"
                                    >
                                        <caption class="sr-only">
                                            Credentials register
                                        </caption>
                                        <thead>
                                            <tr>
                                                <th
                                                    scope="col"
                                                    class="sticky top-0 left-0 z-20 border-b bg-card px-3 py-2 text-left font-medium"
                                                >
                                                    Person
                                                </th>
                                                <th
                                                    v-for="t in register.types"
                                                    :key="t.typeId"
                                                    scope="col"
                                                    class="sticky top-0 z-10 border-b bg-card px-3 py-2 text-left font-medium"
                                                >
                                                    {{ t.name }}
                                                </th>
                                                <th
                                                    scope="col"
                                                    class="sticky top-0 z-10 border-b bg-card px-3 py-2"
                                                >
                                                    <span class="sr-only"
                                                        >Actions</span
                                                    >
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="row in register.rows"
                                                :key="row.id"
                                                data-test="register-row"
                                            >
                                                <th
                                                    scope="row"
                                                    class="sticky left-0 z-10 border-b bg-card px-3 py-2 text-left align-top font-medium"
                                                >
                                                    {{ row.name }}
                                                    <span
                                                        class="block text-xs font-normal text-muted-foreground"
                                                    >
                                                        {{
                                                            [
                                                                row.role,
                                                                row.branch,
                                                            ]
                                                                .filter(
                                                                    (p) => p,
                                                                )
                                                                .join(' · ')
                                                        }}
                                                    </span>
                                                    <span
                                                        v-if="row.mmc"
                                                        class="block text-xs font-normal text-muted-foreground"
                                                        >MMC:
                                                        {{
                                                            row.mmc ===
                                                            'verified'
                                                                ? 'verified'
                                                                : row.mmc ===
                                                                    'not_verified'
                                                                  ? 'not verified'
                                                                  : 'not entered'
                                                        }}</span
                                                    >
                                                </th>
                                                <td
                                                    v-for="t in register.types"
                                                    :key="t.typeId"
                                                    class="border-b px-3 py-2 align-top"
                                                >
                                                    <template
                                                        v-if="
                                                            row.cells[t.typeId]
                                                        "
                                                    >
                                                        <V2StatusChip
                                                            :expired="
                                                                row.cells[
                                                                    t.typeId
                                                                ]!.status
                                                                    .code ===
                                                                'expired'
                                                            "
                                                            :tone="
                                                                chipTone(
                                                                    row.cells[
                                                                        t.typeId
                                                                    ]!.status
                                                                        .code,
                                                                )
                                                            "
                                                            :label="
                                                                row.cells[
                                                                    t.typeId
                                                                ]!.status.short
                                                            "
                                                            aria-hidden="true"
                                                        />
                                                        <span class="sr-only">{{
                                                            spoken(
                                                                t.name,
                                                                row.cells[
                                                                    t.typeId
                                                                ]!.status,
                                                                cellRecord(
                                                                    row.cells[
                                                                        t.typeId
                                                                    ]!,
                                                                ),
                                                            )
                                                        }}</span>
                                                        <V2Chip
                                                            v-if="
                                                                row.cells[
                                                                    t.typeId
                                                                ]!.waiting
                                                            "
                                                            tone="neutral"
                                                            label="Waiting"
                                                            class="mt-1"
                                                        />
                                                    </template>
                                                    <template v-else>
                                                        <span
                                                            aria-hidden="true"
                                                            class="text-muted-foreground"
                                                            >—</span
                                                        >
                                                        <span class="sr-only"
                                                            >{{ t.name }}, not
                                                            required</span
                                                        >
                                                    </template>
                                                </td>
                                                <td
                                                    class="border-b px-3 py-2 text-right align-top"
                                                >
                                                    <button
                                                        v-if="row.canEnter"
                                                        type="button"
                                                        :class="button"
                                                        data-test="enter-record"
                                                        @click="startEnter(row)"
                                                    >
                                                        Add a record
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Below 1024 px: one card per person, only what needs attention. -->
                                <ul
                                    class="flex flex-col gap-3 lg:hidden"
                                    aria-label="Credentials register"
                                >
                                    <li
                                        v-for="row in register.rows"
                                        :key="row.id"
                                        class="flex flex-col gap-2 rounded-[14px] border bg-card p-4"
                                        data-test="register-card"
                                    >
                                        <p class="font-medium">
                                            {{ row.name }}
                                        </p>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                [row.role, row.branch]
                                                    .filter((p) => p)
                                                    .join(' · ')
                                            }}
                                        </p>
                                        <ul
                                            v-if="attention(row).length"
                                            class="flex flex-col gap-1.5"
                                        >
                                            <li
                                                v-for="a in attention(row)"
                                                :key="a.type.typeId"
                                                class="flex flex-wrap items-center gap-2 text-sm"
                                            >
                                                <span
                                                    class="min-w-0 [overflow-wrap:anywhere]"
                                                    >{{ a.type.name }}</span
                                                >
                                                <V2StatusChip
                                                    :expired="
                                                        a.cell.status.code ===
                                                        'expired'
                                                    "
                                                    :tone="
                                                        chipTone(
                                                            a.cell.status.code,
                                                        )
                                                    "
                                                    :label="
                                                        chipLabel(
                                                            a.cell.status,
                                                            cellRecord(a.cell),
                                                        )
                                                    "
                                                />
                                                <V2Chip
                                                    v-if="a.cell.waiting"
                                                    tone="neutral"
                                                    label="Waiting for check"
                                                />
                                            </li>
                                        </ul>
                                        <p
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                attention(row).length
                                                    ? 'All others valid'
                                                    : 'All valid'
                                            }}
                                        </p>
                                        <button
                                            v-if="row.canEnter"
                                            type="button"
                                            :class="`${button} self-start`"
                                            @click="startEnter(row)"
                                        >
                                            Add a record
                                        </button>
                                    </li>
                                </ul>
                            </template>
                        </template>
                    </section>

                    <!-- Credential types -->
                    <section
                        v-if="
                            credentials.tab === 'settings' &&
                            credentials.settings
                        "
                        aria-labelledby="v2-types-title"
                        class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
                        data-test="credential-types"
                    >
                        <h2 id="v2-types-title" class="sr-only">
                            Credential types
                        </h2>
                        <form
                            class="flex flex-col gap-4 self-start rounded-[14px] border bg-card p-4"
                            data-test="add-type"
                            @submit.prevent="addType"
                        >
                            <h3 class="text-sm font-semibold">
                                Add a credential type
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                KPOne ships no list. For example: Annual
                                Practising Certificate (APC), BLS, ACLS,
                                Hepatitis B vaccination, Radiation safety.
                                Nothing counts until you save it.
                            </p>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Name"
                                required
                                :error="errors.name"
                            >
                                <input
                                    :id="id"
                                    v-model="newType.name"
                                    maxlength="120"
                                    :class="inputClass"
                                    :aria-describedby="describedBy"
                                />
                            </V2Field>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Short code"
                                required
                                hint="For example APC. It cannot change later."
                                :error="errors.code"
                            >
                                <input
                                    :id="id"
                                    v-model="newType.code"
                                    maxlength="16"
                                    :class="`${inputClass} w-40 uppercase`"
                                    autocomplete="off"
                                    :aria-describedby="describedBy"
                                />
                            </V2Field>
                            <V2OptionGroup
                                v-model="newType.health"
                                :options="yesNo"
                                label="Health item"
                                required
                                :error="errors.health_item"
                            />
                            <p class="text-xs text-muted-foreground">
                                A health item records only Done or Not done and
                                a date, and is seen only by the person and by
                                managers. It does not expire.
                            </p>
                            <V2OptionGroup
                                v-if="newType.health !== 'yes'"
                                v-model="newType.expires"
                                :options="yesNo"
                                label="Expires"
                                required
                                :error="errors.expires"
                            />
                            <template
                                v-if="
                                    newType.health !== 'yes' &&
                                    newType.expires === 'yes'
                                "
                            >
                                <V2OptionGroup
                                    v-model="newType.permit"
                                    :options="yesNo"
                                    label="Work permit"
                                    required
                                    :error="errors.work_permit"
                                />
                                <p class="text-xs text-muted-foreground">
                                    For a foreign worker's permit. Its expiry
                                    shows as a warning on the staff file and
                                    here. It cannot change later.
                                </p>
                            </template>
                            <V2Field
                                v-if="newType.health !== 'yes'"
                                v-slot="{ id, describedBy }"
                                label="Warn from (days before expiry)"
                                required
                                :error="errors.warn_from_days"
                            >
                                <input
                                    :id="id"
                                    v-model="newType.warn"
                                    type="number"
                                    min="1"
                                    max="365"
                                    inputmode="numeric"
                                    :class="`${inputClass} w-32`"
                                    :aria-describedby="describedBy"
                                />
                            </V2Field>
                            <fieldset class="flex flex-col gap-1">
                                <legend class="mb-1 text-sm font-medium">
                                    Roles that need it
                                </legend>
                                <label
                                    v-for="r in credentials.options.roles"
                                    :key="r.value"
                                    class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                                >
                                    <input
                                        v-model="newType.roles"
                                        type="checkbox"
                                        :value="r.value"
                                        class="size-4"
                                    />
                                    {{ r.label }}
                                </label>
                                <p
                                    v-if="errors.roles"
                                    role="alert"
                                    class="text-sm text-destructive"
                                >
                                    {{ errors.roles }}
                                </p>
                            </fieldset>
                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    :class="primaryButton"
                                    :disabled="
                                        newType.processing ||
                                        addTypeMissing !== ''
                                    "
                                    data-test="type-save"
                                >
                                    {{
                                        newType.processing
                                            ? 'Saving…'
                                            : 'Save type'
                                    }}
                                </button>
                                <span
                                    v-if="addTypeMissing"
                                    class="text-sm text-muted-foreground"
                                    >{{ addTypeMissing }}</span
                                >
                            </div>
                        </form>

                        <div class="flex flex-col gap-3">
                            <div
                                v-if="!credentials.settings.types.length"
                                class="rounded-[14px] border bg-card p-6 text-center"
                            >
                                <p class="font-medium">
                                    No credential types yet.
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    Add the first one. Until then the register
                                    is empty and nobody sees warnings.
                                </p>
                            </div>
                            <article
                                v-for="t in credentials.settings.types"
                                :key="t.typeId"
                                class="flex flex-col gap-2 rounded-[14px] border bg-card p-4"
                                :class="{ 'opacity-70': !t.isActive }"
                                data-test="type-card"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3
                                        class="text-sm font-semibold [overflow-wrap:anywhere]"
                                    >
                                        {{ t.name }}
                                    </h3>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ t.code }}</span
                                    >
                                    <V2Chip
                                        v-if="!t.isActive"
                                        tone="neutral"
                                        label="Archived"
                                    />
                                </div>
                                <p class="text-sm">
                                    {{
                                        t.healthItem
                                            ? 'Health item · Done or Not done'
                                            : t.workPermit
                                              ? `Work permit · warn from ${t.warnFromDays} days`
                                              : t.expires
                                                ? `Expires · warn from ${t.warnFromDays} days`
                                                : 'Does not expire'
                                    }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ roleList(t.roles) }}
                                </p>
                                <div
                                    v-if="t.isActive"
                                    class="flex flex-wrap gap-2"
                                >
                                    <button
                                        type="button"
                                        :class="button"
                                        @click="startEdit(t)"
                                    >
                                        Change
                                    </button>
                                    <button
                                        type="button"
                                        :class="dangerButton"
                                        @click="archiving = t"
                                    >
                                        Archive
                                    </button>
                                </div>
                                <details class="text-sm">
                                    <summary
                                        class="min-h-11 cursor-pointer content-center text-muted-foreground lg:min-h-0"
                                    >
                                        Saved versions ({{ t.versions.length }})
                                    </summary>
                                    <ol
                                        class="mt-2 flex flex-col gap-1 text-xs"
                                    >
                                        <li
                                            v-for="v in t.versions"
                                            :key="v.version"
                                        >
                                            Version {{ v.version }}:
                                            {{ v.name }} · warn from
                                            {{ v.warnFromDays }} days ·
                                            {{ roleList(v.roles) }} ·
                                            {{ v.by }},
                                            {{ dateLabel(v.at) }}
                                        </li>
                                    </ol>
                                </details>
                            </article>
                        </div>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Add or renew a credential -->
        <V2Dialog
            :open="sheet.open"
            :title="sheetTitle"
            :description="`${sheet.person ? `For ${sheet.person}. ` : ''}Someone else checks it before it counts.${sheet.renewing ? ' Until then the current version stays in force.' : ''}`"
            size="tall"
            :confirm-label="
                sheetForm.processing ? 'Sending…' : 'Send for check'
            "
            :busy="sheetForm.processing"
            :confirm-disabled="sheetMissing !== ''"
            :confirm-disabled-reason="sheetMissing"
            data-test="credential-sheet"
            @update:open="(open: boolean) => (sheet.open = open)"
            @confirm="sendRecord"
        >
            <template v-if="sheet.type">
                <p
                    v-if="sheetErrors.credentials || sheetErrors.type_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ sheetErrors.credentials ?? sheetErrors.type_id }}
                </p>
                <template v-if="sheet.type.healthItem">
                    <p class="text-xs text-muted-foreground">
                        A health item records only Done or Not done and the
                        date. Do not add test results or notes.
                    </p>
                    <V2OptionGroup
                        :model-value="sheetForm.health || null"
                        :options="healthOptions"
                        label="Done or not done"
                        required
                        :error="sheetErrors.health"
                        @update:model-value="sheetForm.health = $event ?? ''"
                    />
                    <V2DateField
                        v-if="sheetForm.health === 'done'"
                        v-model="sheetForm.issuedOn"
                        label="Date done"
                        required
                        :error="sheetErrors.issued_on"
                    />
                </template>
                <template v-else>
                    <V2DateField
                        v-model="sheetForm.issuedOn"
                        label="Issue date"
                        required
                        :error="sheetErrors.issued_on"
                    />
                    <V2DateField
                        v-if="sheet.type.expires"
                        v-model="sheetForm.expiresOn"
                        label="Expiry date"
                        required
                        :error="sheetErrors.expires_on"
                    />
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Issuing body (optional)"
                        :error="sheetErrors.issuing_body"
                    >
                        <input
                            :id="id"
                            v-model="sheetForm.issuingBody"
                            maxlength="120"
                            :class="inputClass"
                            autocomplete="off"
                            :aria-describedby="describedBy"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy }"
                        label="Reference number (optional)"
                        hint="Stored encrypted and shown as its last four."
                        :error="sheetErrors.reference"
                    >
                        <input
                            :id="id"
                            v-model="sheetForm.reference"
                            maxlength="40"
                            :class="inputClass"
                            autocomplete="off"
                            :aria-describedby="describedBy"
                        />
                    </V2Field>
                    <FileChooseButton
                        accept="application/pdf,image/jpeg,image/png"
                        :label="
                            sheetForm.file
                                ? 'Choose another file'
                                : 'Choose a certificate file (optional)'
                        "
                        :status="
                            sheetForm.file
                                ? sheetForm.file.name
                                : 'No file chosen. PDF, JPG or PNG, up to 10 MB. It is kept in the staff file as a Certificate.'
                        "
                        status-id="v2-credential-file-status"
                        @change="onFile"
                    />
                    <p
                        v-if="sheetErrors.file"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ sheetErrors.file }}
                    </p>
                </template>
            </template>
        </V2Dialog>

        <!-- A manager adds a record for someone -->
        <V2Dialog
            :open="enter.open"
            title="Add a record"
            :description="
                enter.row
                    ? `For ${enter.row.name}. Another manager checks it.`
                    : ''
            "
            confirm-label="Continue"
            :confirm-disabled="enterMissing !== ''"
            :confirm-disabled-reason="enterMissing"
            @update:open="(open: boolean) => !open && (enter.open = false)"
            @confirm="continueEnter"
        >
            <V2OptionGroup
                :model-value="enter.typeId || null"
                :options="enterTypeOptions"
                label="Credential"
                required
                @update:model-value="enter.typeId = $event ?? ''"
            />
        </V2Dialog>

        <!-- Accept or reject a waiting record -->
        <V2Dialog
            :open="decision.item !== null"
            :title="
                decision.kind === 'accept'
                    ? 'Accept this record?'
                    : 'Reject this record?'
            "
            :description="
                decision.item
                    ? decision.kind === 'accept'
                        ? `${decision.item.person} · ${decision.item.name}. It replaces the version in force.`
                        : `${decision.item.person} · ${decision.item.name}. The rejection is kept with its reason.`
                    : ''
            "
            :confirm-label="
                decision.processing
                    ? decision.kind === 'accept'
                        ? 'Accepting…'
                        : 'Rejecting…'
                    : decision.kind === 'accept'
                      ? 'Accept'
                      : 'Reject'
            "
            :tone="decision.kind === 'accept' ? 'default' : 'danger'"
            :busy="decision.processing"
            :confirm-disabled="decisionMissing !== ''"
            :confirm-disabled-reason="decisionMissing"
            @update:open="(open: boolean) => !open && (decision.item = null)"
            @confirm="sendDecision"
        >
            <p
                v-if="errors.reason ?? errors.credentials"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.reason ?? errors.credentials }}
            </p>
            <V2OptionGroup
                v-if="decision.kind === 'reject'"
                :model-value="decision.reason || null"
                :options="credentials.options.rejectReasons"
                label="Why it is rejected"
                required
                :error="errors.reason"
                @update:model-value="decision.reason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Archive a credential type -->
        <V2Dialog
            :open="archiving !== null"
            title="Archive this credential type?"
            :description="
                archiving
                    ? `${archiving.name}. It leaves the register and takes no new records. Records already kept stay. It cannot come back; add a new type instead.`
                    : ''
            "
            :confirm-label="archiveBusy ? 'Archiving…' : 'Archive'"
            tone="danger"
            :busy="archiveBusy"
            @update:open="(open: boolean) => !open && (archiving = null)"
            @confirm="archiveType"
        >
            <p
                v-if="errors.credentials"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.credentials }}
            </p>
        </V2Dialog>

        <!-- Change a credential type -->
        <V2Dialog
            :open="edit.open"
            :title="`Change ${edit.type?.name ?? ''}`"
            description="Saves a new version; earlier versions stay listed. Whether it expires or is a health item cannot change."
            :confirm-label="edit.processing ? 'Saving…' : 'Save new version'"
            :busy="edit.processing"
            :confirm-disabled="edit.name.trim() === ''"
            confirm-disabled-reason="Enter a name to continue."
            data-test="type-sheet"
            @update:open="(open: boolean) => (edit.open = open)"
            @confirm="saveEdit"
        >
            <p
                v-if="errors.credentials"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.credentials }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Name"
                required
                :error="errors.name"
            >
                <input
                    :id="id"
                    v-model="edit.name"
                    maxlength="120"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-if="!edit.type?.healthItem"
                v-slot="{ id, describedBy }"
                label="Warn from (days before expiry)"
                required
                :error="errors.warn_from_days"
            >
                <input
                    :id="id"
                    v-model="edit.warn"
                    type="number"
                    min="1"
                    max="365"
                    inputmode="numeric"
                    :class="`${inputClass} w-32`"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <fieldset class="flex flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Roles that need it
                </legend>
                <label
                    v-for="r in credentials.options.roles"
                    :key="r.value"
                    class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                >
                    <input
                        v-model="edit.roles"
                        type="checkbox"
                        :value="r.value"
                        class="size-4"
                    />
                    {{ r.label }}
                </label>
            </fieldset>
        </V2Dialog>
    </PageFrame>
</template>
