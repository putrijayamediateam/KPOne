<script setup lang="ts">
// HR-4a /hr/records: My HR details (everyone) and Staff files (Director, HR Manager). HR-PLAN 11.2 and 11.6.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import CorrectionSheet from '@/components/hr/records/CorrectionSheet.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import type {
    Address,
    EmergencyContact,
    RecordOptions,
    WorkerHeader,
} from '@/components/hr/records/types';
import {
    addressLine,
    dateLabel,
    statusTone,
} from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'my' | 'files';
type FileRow = WorkerHeader & {
    branchId: number | null;
    branch: string | null;
    attention: string[];
};
type MyCorrection = {
    id: string;
    kind: string;
    kindLabel: string;
    status: 'pending' | 'applied' | 'rejected' | 'withdrawn';
    reasonLabel: string;
    decisionLabel: string | null;
    askedAt: string;
    lockVersion: number;
};
type My = {
    hasFile: boolean;
    worker?: WorkerHeader;
    position?: { title: string; department: string | null } | null;
    periods?: {
        typeLabel: string;
        startsOn: string;
        probationEndsOn: string | null;
        confirmedOn: string | null;
        endsOn: string | null;
    }[];
    personal?: {
        legalName: string | null;
        preferredName: string | null;
        identity: {
            kind: 'ic' | 'passport';
            number: string;
            country: string | null;
        } | null;
        dateOfBirth: string | null;
        nationality: string | null;
        address: Address | null;
        personalPhone: string | null;
        emergencyContact: EmergencyContact | null;
        lockVersion: number;
    };
    bank?: { bankName: string; holder: string; account: string } | null;
    corrections?: MyCorrection[];
};

const props = defineProps<{
    records: {
        tab: Tab;
        tabs: Tab[];
        options: RecordOptions;
        canManage: boolean;
        my: My | null;
        files: {
            filters: {
                q: string;
                branch: number | null;
                type: string | null;
                status: string | null;
            };
            rows: FileRow[];
            total: number;
            attentionCount: number;
            branches: { id: number; name: string }[];
            withoutFile: { id: number; name: string }[];
        } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const tabs = computed(() =>
    props.records.tabs.map((value) => ({
        value,
        label:
            value === 'my'
                ? 'My HR details'
                : props.records.files && props.records.files.attentionCount > 0
                  ? `Staff files (${props.records.files.attentionCount})`
                  : 'Staff files',
    })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/records', { tab: value }, { preserveScroll: true });

// ---- My HR details ----
const my = computed(() => props.records.my);
const notGiven = 'Not given';
const ownIdentity = computed(() => {
    const id = my.value?.personal?.identity;

    if (!id) {
        return notGiven;
    }

    return id.kind === 'ic'
        ? `IC ${id.number}`
        : `Passport ${id.number}${id.country ? ` (${id.country})` : ''}`;
});
const recordedFor = (kind: string): string => {
    const p = my.value?.personal;

    switch (kind) {
        case 'legal_name':
            return p?.legalName ?? notGiven;
        case 'identity':
            return ownIdentity.value;
        case 'date_of_birth':
            return p?.dateOfBirth ? dateLabel(p.dateOfBirth) : notGiven;
        case 'nationality':
            return p?.nationality ?? notGiven;
        default:
            return my.value?.bank
                ? `${my.value.bank.bankName} ${my.value.bank.account}`
                : notGiven;
    }
};
const pendingKinds = computed(
    () =>
        new Set(
            (my.value?.corrections ?? [])
                .filter((c) => c.status === 'pending')
                .map((c) => c.kind),
        ),
);
const correctionKind = ref('');
const correctionOpen = ref(false);
const askCorrection = (kind: string) => {
    correctionKind.value = kind;
    correctionOpen.value = true;
};

const contactOpen = ref(false);
const contact = reactive({
    address: {
        line1: '',
        line2: '',
        city: '',
        state: '',
        postcode: '',
        country: '',
    } as Address,
    phone: '',
    emergency: { name: '', relationship: '', phone: '' } as EmergencyContact,
    key: '',
    processing: false,
});
const editContact = () => {
    const p = my.value?.personal;
    Object.assign(contact.address, {
        line1: '',
        line2: '',
        city: '',
        state: '',
        postcode: '',
        country: '',
        ...(p?.address ?? {}),
    });
    contact.phone = p?.personalPhone ?? '';
    Object.assign(contact.emergency, {
        name: '',
        relationship: '',
        phone: '',
        ...(p?.emergencyContact ?? {}),
    });
    contact.key = crypto.randomUUID();
    contactOpen.value = true;
};
const saveContact = () => {
    contact.processing = true;
    router.put(
        '/hr/records/my/contact',
        {
            address: contact.address,
            personal_phone: contact.phone,
            emergency_contact: contact.emergency,
            expected_lock_version: my.value?.personal?.lockVersion ?? 0,
            idempotency_key: contact.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (contactOpen.value = false),
            onFinish: () => (contact.processing = false),
        },
    );
};

const withdrawing = ref<MyCorrection | null>(null);
const withdrawBusy = ref(false);
const withdrawCorrection = () => {
    const c = withdrawing.value;

    if (!c) {
        return;
    }

    withdrawBusy.value = true;
    router.post(
        `/hr/records/my/corrections/${c.id}/withdraw`,
        {
            expected_lock_version: c.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (withdrawing.value = null),
            onFinish: () => (withdrawBusy.value = false),
        },
    );
};
const correctionTone = (status: MyCorrection['status']) =>
    status === 'pending'
        ? 'warning'
        : status === 'applied'
          ? 'success'
          : 'neutral';
const correctionStatus = (c: MyCorrection) =>
    ({
        pending: 'Waiting for HR',
        applied: 'Applied',
        rejected: 'Not applied',
        withdrawn: 'Withdrawn',
    })[c.status];

// ---- Staff files ----
const files = computed(() => props.records.files);
const filters = reactive({ q: '', branch: '', type: '', status: '' });
watch(
    () => props.records.files?.filters,
    (f) => {
        filters.q = f?.q ?? '';
        filters.branch = f?.branch ? String(f.branch) : '';
        filters.type = f?.type ?? '';
        filters.status = f?.status ?? '';
    },
    { immediate: true },
);
const applyFilters = () =>
    router.get(
        '/hr/records',
        {
            tab: 'files',
            q: filters.q || undefined,
            branch: filters.branch || undefined,
            type: filters.type || undefined,
            status: filters.status || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
const clearFilters = () => {
    Object.assign(filters, { q: '', branch: '', type: '', status: '' });
    applyFilters();
};
const filtered = computed(
    () =>
        !!(
            files.value &&
            (files.value.filters.q ||
                files.value.filters.branch ||
                files.value.filters.type ||
                files.value.filters.status)
        ),
);
const branchOptions = computed(() => [
    { value: '', label: 'All branches' },
    ...(files.value?.branches ?? []).map((b) => ({
        value: String(b.id),
        label: b.name,
    })),
]);
const typeOptions = computed(() => [
    { value: '', label: 'All types' },
    ...props.records.options.types,
]);
const statusOptions = [
    { value: '', label: 'Any status' },
    { value: 'active', label: 'Active' },
    { value: 'probation', label: 'On probation' },
    { value: 'ended', label: 'Left' },
    { value: 'none', label: 'No employment period' },
];
const attentionLabel = (reasons: string[]) =>
    reasons.length === 1
        ? reasons[0]
        : `${reasons.length} things need attention`;

const openFile = reactive({
    open: false,
    userId: '',
    key: '',
    processing: false,
});
const startOpenFile = () => {
    Object.assign(openFile, {
        open: true,
        userId: '',
        key: crypto.randomUUID(),
    });
};
const sendOpenFile = () => {
    openFile.processing = true;
    router.post(
        '/hr/records/files',
        { user_id: openFile.userId, idempotency_key: openFile.key },
        { onFinish: () => (openFile.processing = false) },
    );
};
const locum = reactive({
    open: false,
    name: '',
    phone: '',
    key: '',
    processing: false,
});
const startLocum = () =>
    Object.assign(locum, {
        open: true,
        name: '',
        phone: '',
        key: crypto.randomUUID(),
    });
const sendLocum = () => {
    locum.processing = true;
    router.post(
        '/hr/records/locums',
        {
            display_name: locum.name,
            phone: locum.phone,
            idempotency_key: locum.key,
        },
        { onFinish: () => (locum.processing = false) },
    );
};
</script>

<template>
    <Head
        :title="
            records.tabs.includes('files') ? 'Staff files' : 'My HR details'
        "
    />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                :title="
                    records.tabs.includes('files')
                        ? 'Staff files'
                        : 'My HR details'
                "
                :description="
                    records.tabs.includes('files')
                        ? 'Employment, personal and bank details. Sensitive values stay hidden until someone presses Show.'
                        : 'Your employment, personal and bank details. Change your contact details here, or ask HR to correct the rest.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="records.tab"
            :tabs="tabs"
            label="Staff file sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="errors.records && !correctionOpen && !contactOpen"
            :message="errors.records"
        />

        <!-- My HR details -->
        <section
            v-if="records.tab === 'my' && my"
            aria-labelledby="my-hr-title"
            class="flex flex-col gap-4"
            data-test="my-hr"
        >
            <h2 id="my-hr-title" class="sr-only">My HR details</h2>
            <EmptyState
                v-if="!my.hasFile"
                title="HR has not opened your staff file yet."
                description="When they do, your employment, personal and bank details appear here. Ask HR if you expected to see them."
            />
            <template v-else-if="my.worker && my.personal">
                <div
                    class="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-4"
                >
                    <p class="text-base font-semibold">{{ my.worker.name }}</p>
                    <span class="text-sm text-muted-foreground">{{
                        my.worker.role
                    }}</span>
                    <StatusBadge
                        :status="my.worker.status"
                        :label="my.worker.statusLabel"
                        :tone="statusTone(my.worker.status)"
                    />
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <section
                        class="rounded-xl border bg-card p-4"
                        aria-labelledby="my-employment"
                    >
                        <h3
                            id="my-employment"
                            class="mb-3 text-sm font-semibold"
                        >
                            Employment
                        </h3>
                        <dl class="grid gap-3 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Position</dt>
                                <dd>
                                    {{
                                        my.position
                                            ? [
                                                  my.position.title,
                                                  my.position.department,
                                              ]
                                                  .filter((p) => p)
                                                  .join(' · ')
                                            : notGiven
                                    }}
                                </dd>
                            </div>
                            <div v-for="(p, i) in my.periods" :key="i">
                                <dt class="text-muted-foreground">
                                    {{ p.typeLabel }}
                                </dt>
                                <dd>
                                    From {{ dateLabel(p.startsOn) }}
                                    <template v-if="p.endsOn"
                                        >to {{ dateLabel(p.endsOn) }}</template
                                    >
                                    <template
                                        v-if="
                                            p.probationEndsOn && !p.confirmedOn
                                        "
                                    >
                                        · probation to
                                        {{
                                            dateLabel(p.probationEndsOn)
                                        }}</template
                                    >
                                    <template v-if="p.confirmedOn">
                                        · confirmed
                                        {{ dateLabel(p.confirmedOn) }}</template
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
                        class="rounded-xl border bg-card p-4"
                        aria-labelledby="my-bank"
                    >
                        <h3 id="my-bank" class="mb-3 text-sm font-semibold">
                            Bank
                        </h3>
                        <dl class="grid gap-3 text-sm">
                            <div>
                                <dt class="text-muted-foreground">Account</dt>
                                <dd class="break-words">
                                    {{
                                        my.bank
                                            ? `${my.bank.bankName} · ${my.bank.holder} · ${my.bank.account}`
                                            : notGiven
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <Button
                            variant="outline"
                            size="sm"
                            class="mt-3 max-lg:min-h-11"
                            :disabled="pendingKinds.has('bank')"
                            data-test="ask-bank"
                            @click="askCorrection('bank')"
                        >
                            {{
                                pendingKinds.has('bank')
                                    ? 'Correction waiting'
                                    : 'Ask for a correction'
                            }}
                        </Button>
                    </section>
                </div>

                <section
                    class="rounded-xl border bg-card p-4"
                    aria-labelledby="my-personal"
                >
                    <h3 id="my-personal" class="mb-3 text-sm font-semibold">
                        Personal
                    </h3>
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div
                            v-for="item in [
                                {
                                    kind: 'legal_name',
                                    label: 'Legal name',
                                    value: my.personal.legalName,
                                },
                                {
                                    kind: 'identity',
                                    label: 'Identity number',
                                    value: my.personal.identity
                                        ? ownIdentity
                                        : null,
                                },
                                {
                                    kind: 'date_of_birth',
                                    label: 'Date of birth',
                                    value: my.personal.dateOfBirth
                                        ? dateLabel(my.personal.dateOfBirth)
                                        : null,
                                },
                                {
                                    kind: 'nationality',
                                    label: 'Nationality',
                                    value: my.personal.nationality,
                                },
                            ]"
                            :key="item.kind"
                            class="flex flex-col gap-1"
                        >
                            <dt class="text-muted-foreground">
                                {{ item.label }}
                            </dt>
                            <dd class="break-words">
                                {{ item.value ?? notGiven }}
                            </dd>
                            <dd>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="max-lg:min-h-11"
                                    :disabled="pendingKinds.has(item.kind)"
                                    :data-test="`ask-${item.kind}`"
                                    @click="askCorrection(item.kind)"
                                >
                                    {{
                                        pendingKinds.has(item.kind)
                                            ? 'Correction waiting'
                                            : 'Ask for a correction'
                                    }}
                                </Button>
                            </dd>
                        </div>
                        <div class="flex flex-col gap-1">
                            <dt class="text-muted-foreground">
                                Preferred name
                            </dt>
                            <dd>{{ my.personal.preferredName ?? notGiven }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 border-t pt-4">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h4 class="text-sm font-semibold">
                                Contact details you can change
                            </h4>
                            <Button
                                variant="outline"
                                size="sm"
                                class="max-lg:min-h-11"
                                data-test="edit-contact"
                                @click="editContact"
                                >Edit</Button
                            >
                        </div>
                        <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-muted-foreground">Address</dt>
                                <dd class="break-words">
                                    {{
                                        addressLine(my.personal.address) ||
                                        notGiven
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Personal phone
                                </dt>
                                <dd>
                                    {{ my.personal.personalPhone ?? notGiven }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Emergency contact
                                </dt>
                                <dd class="break-words">
                                    {{
                                        my.personal.emergencyContact
                                            ? [
                                                  my.personal.emergencyContact
                                                      .name,
                                                  my.personal.emergencyContact
                                                      .relationship,
                                                  my.personal.emergencyContact
                                                      .phone,
                                              ]
                                                  .filter((p) => p)
                                                  .join(' · ')
                                            : notGiven
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section
                    v-if="my.corrections?.length"
                    class="rounded-xl border bg-card p-4"
                    aria-labelledby="my-corrections"
                >
                    <h3 id="my-corrections" class="mb-3 text-sm font-semibold">
                        My corrections
                    </h3>
                    <ul class="flex flex-col divide-y">
                        <li
                            v-for="c in my.corrections"
                            :key="c.id"
                            class="flex flex-wrap items-center gap-2 py-2 text-sm"
                            data-test="my-correction"
                        >
                            <span class="font-medium">{{ c.kindLabel }}</span>
                            <span class="text-muted-foreground">{{
                                c.reasonLabel
                            }}</span>
                            <StatusBadge
                                :status="c.status"
                                :label="correctionStatus(c)"
                                :tone="correctionTone(c.status)"
                            />
                            <span
                                v-if="c.decisionLabel"
                                class="text-muted-foreground"
                                >{{ c.decisionLabel }}</span
                            >
                            <Button
                                v-if="c.status === 'pending'"
                                variant="outline"
                                size="sm"
                                class="ml-auto max-lg:min-h-11"
                                @click="withdrawing = c"
                                >Withdraw</Button
                            >
                        </li>
                    </ul>
                </section>
            </template>
        </section>

        <!-- Staff files -->
        <section
            v-if="records.tab === 'files' && files"
            aria-labelledby="files-title"
            class="flex flex-col gap-4"
            data-test="staff-files"
        >
            <h2 id="files-title" class="sr-only">Staff files</h2>
            <form
                class="flex flex-col gap-3 rounded-xl border bg-card p-4 lg:flex-row lg:flex-wrap lg:items-end"
                @submit.prevent="applyFilters"
            >
                <div class="grid min-w-0 gap-1.5 lg:w-64">
                    <Label for="files-search">Search by name</Label>
                    <Input
                        id="files-search"
                        v-model="filters.q"
                        type="search"
                        class="max-lg:h-11"
                        autocomplete="off"
                    />
                </div>
                <div class="grid gap-1.5 lg:w-48">
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="files-branch"
                        v-model="filters.branch"
                        label="Branch"
                        :options="branchOptions"
                    />
                </div>
                <div class="grid gap-1.5 lg:w-44">
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="files-type"
                        v-model="filters.type"
                        label="Type"
                        :options="typeOptions"
                    />
                </div>
                <div class="grid gap-1.5 lg:w-48">
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="files-status"
                        v-model="filters.status"
                        label="Status"
                        :options="statusOptions"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" class="max-lg:min-h-11"
                        >Search</Button
                    >
                    <Button
                        v-if="filtered"
                        type="button"
                        variant="ghost"
                        class="max-lg:min-h-11"
                        @click="clearFilters"
                        >Clear</Button
                    >
                </div>
                <div
                    v-if="records.canManage"
                    class="flex flex-wrap gap-2 lg:ml-auto"
                >
                    <Button
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="open-file"
                        @click="startOpenFile"
                        >Open a staff file</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="add-locum"
                        @click="startLocum"
                        >Add a locum</Button
                    >
                </div>
            </form>

            <EmptyState
                v-if="files.total === 0"
                title="No staff files yet."
                description="Open a staff file for someone with a KPOne account, or add a locum. Files are made one at a time, never in bulk."
            />
            <EmptyState
                v-else-if="files.rows.length === 0"
                title="No staff files match."
                description="Try another name, or clear the filters."
            />
            <template v-else>
                <div class="hidden lg:block">
                    <OperationalTable
                        label="Staff files"
                        :columns="6"
                        min-width="960px"
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Branch</th>
                                <th scope="col">Role</th>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Needs attention</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr
                                v-for="row in files.rows"
                                :key="row.id"
                                data-test="file-row"
                            >
                                <th scope="row" class="font-medium">
                                    <Link
                                        :href="`/hr/records/${row.id}`"
                                        class="underline-offset-4 hover:underline"
                                        >{{ row.name }}</Link
                                    >
                                </th>
                                <td>{{ row.branch ?? '—' }}</td>
                                <td>
                                    {{ row.role }}
                                    <StatusBadge
                                        v-if="row.kind === 'locum'"
                                        status="locum"
                                        label="No KPOne sign-in"
                                        tone="neutral"
                                    />
                                </td>
                                <td>{{ row.typeLabel ?? '—' }}</td>
                                <td>
                                    <StatusBadge
                                        :status="row.status"
                                        :label="row.statusLabel"
                                        :tone="statusTone(row.status)"
                                    />
                                </td>
                                <td>
                                    <template v-if="row.attention.length">
                                        <StatusBadge
                                            status="attention"
                                            :label="
                                                attentionLabel(row.attention)
                                            "
                                            tone="warning"
                                        />
                                        <ul
                                            v-if="row.attention.length > 1"
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            <li
                                                v-for="reason in row.attention"
                                                :key="reason"
                                            >
                                                {{ reason }}
                                            </li>
                                        </ul>
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                            </tr>
                        </template>
                    </OperationalTable>
                </div>
                <ul
                    class="flex flex-col gap-3 lg:hidden"
                    aria-label="Staff files"
                >
                    <li
                        v-for="row in files.rows"
                        :key="row.id"
                        class="rounded-xl border bg-card p-4"
                        data-test="file-card"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="`/hr/records/${row.id}`"
                                class="min-h-11 content-center font-medium underline underline-offset-4"
                                >{{ row.name }}</Link
                            >
                            <StatusBadge
                                :status="row.status"
                                :label="row.statusLabel"
                                :tone="statusTone(row.status)"
                            />
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                [row.role, row.branch, row.typeLabel]
                                    .filter((p) => p)
                                    .join(' · ')
                            }}
                        </p>
                        <p v-if="row.kind === 'locum'" class="mt-1">
                            <StatusBadge
                                status="locum"
                                label="No KPOne sign-in"
                                tone="neutral"
                            />
                        </p>
                        <div v-if="row.attention.length" class="mt-2">
                            <StatusBadge
                                status="attention"
                                :label="attentionLabel(row.attention)"
                                tone="warning"
                            />
                            <ul
                                v-if="row.attention.length > 1"
                                class="mt-1 text-xs text-muted-foreground"
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

        <CorrectionSheet
            v-model:open="correctionOpen"
            :kind="correctionKind"
            :kinds="records.options.correctionKinds"
            :reasons="records.options.correctionReasons"
            :recorded="recordedFor(correctionKind)"
        />

        <Sheet v-model:open="contactOpen">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="contact-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Your contact details</SheetTitle>
                    <SheetDescription
                        >You can change these yourself. HR sees the
                        change.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveContact"
                >
                    <ErrorState
                        v-if="errors.records"
                        :message="errors.records"
                    />
                    <fieldset class="grid gap-3">
                        <legend class="mb-1 text-sm font-semibold">
                            Address
                        </legend>
                        <div
                            v-for="part in [
                                { key: 'line1', label: 'Address line 1' },
                                {
                                    key: 'line2',
                                    label: 'Address line 2 (optional)',
                                },
                                { key: 'city', label: 'City' },
                                { key: 'state', label: 'State' },
                                { key: 'postcode', label: 'Postcode' },
                                { key: 'country', label: 'Country' },
                            ] as const"
                            :key="part.key"
                            class="grid gap-1.5"
                        >
                            <Label :for="`contact-${part.key}`">{{
                                part.label
                            }}</Label>
                            <Input
                                :id="`contact-${part.key}`"
                                v-model="contact.address[part.key]"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="errors[`address.${part.key}`]"
                            />
                        </div>
                    </fieldset>
                    <div class="grid gap-1.5">
                        <Label for="contact-phone">Personal phone</Label>
                        <Input
                            id="contact-phone"
                            v-model="contact.phone"
                            type="tel"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.personal_phone"
                        />
                        <InputError :message="errors.personal_phone" />
                    </div>
                    <fieldset class="grid gap-3">
                        <legend class="mb-1 text-sm font-semibold">
                            Emergency contact
                        </legend>
                        <div class="grid gap-1.5">
                            <Label for="contact-em-name">Name</Label>
                            <Input
                                id="contact-em-name"
                                v-model="contact.emergency.name"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="errors['emergency_contact.name']"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="contact-em-rel">Relationship</Label>
                            <Input
                                id="contact-em-rel"
                                v-model="contact.emergency.relationship"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="
                                    errors['emergency_contact.relationship']
                                "
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="contact-em-phone">Phone</Label>
                            <Input
                                id="contact-em-phone"
                                v-model="contact.emergency.phone"
                                type="tel"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="errors['emergency_contact.phone']"
                            />
                        </div>
                    </fieldset>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="contact.processing"
                        data-test="contact-save"
                    >
                        {{ contact.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <Sheet v-model:open="openFile.open">
            <SheetContent class="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Open a staff file</SheetTitle>
                    <SheetDescription
                        >For someone who already has a KPOne account. HR never
                        changes the account.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendOpenFile"
                >
                    <p
                        v-if="!files?.withoutFile.length"
                        class="text-sm text-muted-foreground"
                    >
                        Everyone with a KPOne account already has a staff file.
                    </p>
                    <div v-else class="grid gap-1.5">
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="open-file-user"
                            v-model="openFile.userId"
                            label="Staff member"
                            placeholder="Choose a staff member"
                            :options="
                                (files?.withoutFile ?? []).map((u) => ({
                                    value: String(u.id),
                                    label: u.name,
                                }))
                            "
                            :invalid="!!errors.user_id"
                        />
                        <InputError :message="errors.user_id" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="
                            openFile.processing || !files?.withoutFile.length
                        "
                    >
                        {{
                            openFile.processing ? 'Opening…' : 'Open staff file'
                        }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <Sheet v-model:open="locum.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="locum-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Add a locum</SheetTitle>
                    <SheetDescription
                        >A locum has a staff file but no KPOne
                        sign-in.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendLocum"
                >
                    <div class="grid gap-1.5">
                        <Label for="locum-name">Name</Label>
                        <Input
                            id="locum-name"
                            v-model="locum.name"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.display_name"
                        />
                        <InputError :message="errors.display_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="locum-phone"
                            >Contact phone (optional)</Label
                        >
                        <Input
                            id="locum-phone"
                            v-model="locum.phone"
                            type="tel"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.phone"
                        />
                        <InputError :message="errors.phone" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="locum.processing"
                        data-test="locum-save"
                    >
                        {{ locum.processing ? 'Adding…' : 'Add locum' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="withdrawing !== null"
            title="Withdraw this correction?"
            :description="
                withdrawing
                    ? `${withdrawing.kindLabel}. HR will no longer see it.`
                    : ''
            "
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="withdrawBusy"
            :error="errors.correction"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdrawCorrection"
        />
    </div>
</template>
