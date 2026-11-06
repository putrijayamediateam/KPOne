<script setup lang="ts">
// HR-4a /hr/records: My HR details (everyone) and Staff files (Director, HR Manager). HR-PLAN 11.2 and 11.6.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import CorrectionSheet from '@/components/hr/records/CorrectionSheet.vue';
import DocumentList from '@/components/hr/records/DocumentList.vue';
import OwnValue from '@/components/hr/records/OwnValue.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import type {
    Address,
    EmergencyContact,
    RecordOptions,
    StaffDocument,
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
    workerId?: string;
    documents?: StaffDocument[];
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
        settings: {
            graceDays: number;
            retentions: {
                category: string;
                label: string;
                days: number | null;
                pendingDays: number | null;
                pendingFrom: string | null;
                lockVersion: number;
            }[];
            items: {
                id: string;
                list: 'onboarding' | 'offboarding';
                label: string;
                aboutAccount: boolean;
                lockVersion: number;
            }[];
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
const identityMask = computed(() => {
    const id = my.value?.personal?.identity;

    return id
        ? `${id.kind === 'ic' ? 'IC •••• •••• ' : 'Passport •••• '}${id.number.slice(-4)}`
        : notGiven;
});
// The correction sheet's "Recorded" line masks your identity and account, as the page does.
const recordedFor = (kind: string): string => {
    const p = my.value?.personal;

    switch (kind) {
        case 'legal_name':
            return p?.legalName ?? notGiven;
        case 'identity':
            return identityMask.value;
        case 'date_of_birth':
            return p?.dateOfBirth ? dateLabel(p.dateOfBirth) : notGiven;
        case 'nationality':
            return p?.nationality ?? notGiven;
        default:
            return my.value?.bank
                ? `${my.value.bank.bankName} •••• ${my.value.bank.account.slice(-4)}`
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

// ---- HR-4b settings ----
type Retention = {
    category: string;
    label: string;
    days: number | null;
    pendingDays: number | null;
    pendingFrom: string | null;
    lockVersion: number;
};
// Saving a retention is confirmed in words, because the daily purge deletes files (Suresh, HR-4b S1).
const retentionConfirm = reactive({
    row: null as Retention | null,
    value: '',
    key: '',
    processing: false,
});
// "Now: not set. Changes to 2,555 days on 13 Nov 2026." (Daniel)
const dayCount = (n: number) => `${n.toLocaleString('en-US')} days`;
const retentionLine = (r: Retention) => {
    const now = `Now: ${r.days === null ? 'not set' : dayCount(r.days)}.`;

    return r.pendingDays && r.pendingFrom
        ? `${now} Changes to ${dayCount(r.pendingDays)} on ${dateLabel(r.pendingFrom)}.`
        : now;
};
const askRetention = (r: Retention) =>
    Object.assign(retentionConfirm, {
        row: r,
        value: String(retentionDays[r.category] ?? '').trim(),
        key: crypto.randomUUID(),
    });
const retentionMessage = computed(() => {
    const r = retentionConfirm.row;

    if (!r) {
        return '';
    }

    const grace = props.records.settings?.graceDays ?? 7;

    if (retentionConfirm.value === '') {
        return `${r.label}: Not set. Nothing of this type is deleted. This applies at once.`;
    }

    const days = Number(retentionConfirm.value);

    if (r.days !== null && days >= r.days) {
        return `${r.label}: ${dayCount(days)} after the person's last day. This keeps files longer and applies at once.`;
    }

    return `${r.label}: ${dayCount(days)} after the person's last day. This starts in ${grace} days; from then the daily purge deletes these files of people who left more than ${dayCount(days)} earlier. Until then you can change it back.`;
});
const sendRetention = () => {
    const r = retentionConfirm.row;

    if (!r) {
        return;
    }

    retentionConfirm.processing = true;
    router.put(
        '/hr/records/settings/retention',
        {
            category: r.category,
            retention_days: retentionConfirm.value,
            expected_lock_version: r.lockVersion,
            idempotency_key: retentionConfirm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (retentionConfirm.row = null),
            onFinish: () => (retentionConfirm.processing = false),
        },
    );
};

const retentionDays = reactive<Record<string, string | number>>({});
watch(
    () => props.records.settings?.retentions,
    (list) => {
        for (const r of list ?? []) {
            retentionDays[r.category] = r.days === null ? '' : String(r.days);
        }
    },
    { immediate: true },
);
const newItem = reactive({
    list: 'onboarding',
    label: '',
    aboutAccount: false,
    processing: false,
});
const addItem = () => {
    newItem.processing = true;
    router.post(
        '/hr/records/settings/checklist-items',
        {
            list: newItem.list,
            label: newItem.label,
            about_account: newItem.aboutAccount,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                Object.assign(newItem, { label: '', aboutAccount: false }),
            onFinish: () => (newItem.processing = false),
        },
    );
};
const archiveItem = (item: { id: string; lockVersion: number }) =>
    router.post(
        `/hr/records/settings/checklist-items/${item.id}/archive`,
        {
            expected_lock_version: item.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true },
    );

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
                                <dd class="flex flex-col gap-1">
                                    <template v-if="my.bank">
                                        <span class="break-words"
                                            >{{ my.bank.bankName }} ·
                                            {{ my.bank.holder }}</span
                                        >
                                        <OwnValue
                                            label="Account number"
                                            field="account number"
                                            :mask="`Account •••• ${my.bank.account.slice(-4)}`"
                                            :value="my.bank.account"
                                            :last4="my.bank.account.slice(-4)"
                                        />
                                    </template>
                                    <span v-else>{{ notGiven }}</span>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="pendingKinds.has('bank')" class="mt-3">
                            <StatusBadge
                                status="pending"
                                label="Correction waiting"
                                tone="neutral"
                            />
                        </p>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            class="mt-3 max-lg:min-h-11"
                            data-test="ask-bank"
                            @click="askCorrection('bank')"
                        >
                            Ask for a correction
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
                            <dd
                                v-if="
                                    item.kind === 'identity' &&
                                    my.personal.identity
                                "
                            >
                                <OwnValue
                                    label="Identity number"
                                    field="identity number"
                                    :mask="identityMask"
                                    :value="ownIdentity"
                                    :last4="
                                        my.personal.identity.number.slice(-4)
                                    "
                                />
                            </dd>
                            <dd v-else class="break-words">
                                {{ item.value ?? notGiven }}
                            </dd>
                            <dd>
                                <StatusBadge
                                    v-if="pendingKinds.has(item.kind)"
                                    status="pending"
                                    label="Correction waiting"
                                    tone="neutral"
                                    :data-test="`waiting-${item.kind}`"
                                />
                                <Button
                                    v-else
                                    variant="outline"
                                    size="sm"
                                    class="max-lg:min-h-11"
                                    :data-test="`ask-${item.kind}`"
                                    @click="askCorrection(item.kind)"
                                >
                                    Ask for a correction
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

                <section
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                    aria-labelledby="my-documents"
                    data-test="my-documents"
                >
                    <h3 id="my-documents" class="text-sm font-semibold">
                        My documents
                    </h3>
                    <DocumentList
                        :documents="my.documents ?? []"
                        :worker-id="my.workerId ?? ''"
                        :can-open="true"
                        empty-title="No documents yet."
                        empty-description="HR adds your contract and other documents here. Ask HR if one is missing."
                    />
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
                    <Label for="files-branch">Branch</Label>
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="files-branch"
                        v-model="filters.branch"
                        label="Branch"
                        :options="branchOptions"
                    />
                </div>
                <div class="grid gap-1.5 lg:w-44">
                    <Label for="files-type">Type</Label>
                    <OperationalSelect
                        trigger-class="max-lg:h-11"
                        id="files-type"
                        v-model="filters.type"
                        label="Type"
                        :options="typeOptions"
                    />
                </div>
                <div class="grid gap-1.5 lg:w-48">
                    <Label for="files-status">Status</Label>
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
                                        class="text-foreground underline underline-offset-4"
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

        <!-- HR-4b settings: retention per document type and checklist items (managers, under Staff files) -->
        <details
            v-if="records.tab === 'files' && records.settings"
            class="rounded-xl border bg-card p-4"
            data-test="records-settings"
        >
            <summary
                class="min-h-11 cursor-pointer content-center text-sm font-semibold lg:min-h-0"
            >
                Documents and checklist settings
            </summary>
            <div class="mt-4 grid gap-6 lg:grid-cols-2">
                <section
                    aria-labelledby="retention-title"
                    class="flex flex-col gap-3"
                >
                    <h3 id="retention-title" class="text-sm font-semibold">
                        How long documents are kept
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Days after the person's last employment period ends.
                        KPOne ships no number; until you enter one, documents of
                        that type are kept. The files of anyone with no ended
                        employment period are always kept. A first or shorter
                        retention starts after {{ records.settings.graceDays }}
                        days, so a mistake can be undone.
                    </p>
                    <ErrorState
                        v-if="errors.records && !retentionConfirm.row"
                        :message="errors.records"
                    />
                    <form
                        v-for="r in records.settings.retentions"
                        :key="r.category"
                        class="flex flex-col gap-1"
                        data-test="retention-form"
                        @submit.prevent="askRetention(r)"
                    >
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="grid min-w-0 gap-1.5">
                                <Label :for="`retention-${r.category}`"
                                    >{{ r.label }} (days)</Label
                                >
                                <Input
                                    :id="`retention-${r.category}`"
                                    v-model="retentionDays[r.category]"
                                    type="number"
                                    min="1"
                                    max="36500"
                                    inputmode="numeric"
                                    class="w-32 max-lg:h-11"
                                    placeholder="Not set"
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                class="max-lg:min-h-11"
                                >Save</Button
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ retentionLine(r) }}
                        </p>
                    </form>
                    <InputError :message="errors.retention_days" />
                </section>
                <section
                    aria-labelledby="items-title"
                    class="flex flex-col gap-3"
                >
                    <h3 id="items-title" class="text-sm font-semibold">
                        Checklist items
                    </h3>
                    <div
                        v-for="list in ['onboarding', 'offboarding'] as const"
                        :key="list"
                        class="flex flex-col gap-2"
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
                                        · asks the account administrator</span
                                    ></span
                                >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="max-lg:min-h-11"
                                    @click="archiveItem(item)"
                                    >Archive</Button
                                >
                            </li>
                        </ul>
                    </div>
                    <form
                        class="flex flex-col gap-3 border-t pt-3"
                        @submit.prevent="addItem"
                    >
                        <div class="grid gap-1.5">
                            <Label for="item-list"
                                ><span id="item-list-label">List</span></Label
                            >
                            <OperationalSelect
                                id="item-list"
                                labelledby="item-list-label"
                                v-model="newItem.list"
                                trigger-class="max-lg:h-11"
                                label="List"
                                :options="[
                                    {
                                        value: 'onboarding',
                                        label: 'Onboarding',
                                    },
                                    {
                                        value: 'offboarding',
                                        label: 'Offboarding',
                                    },
                                ]"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="item-label">Item</Label>
                            <Input
                                id="item-label"
                                v-model="newItem.label"
                                maxlength="160"
                                class="max-lg:h-11"
                                :aria-invalid="!!errors.label"
                            />
                            <InputError :message="errors.label" />
                        </div>
                        <label
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                        >
                            <input
                                v-model="newItem.aboutAccount"
                                type="checkbox"
                                class="size-4"
                            />
                            About the KPOne account (HR asks the account
                            administrator)
                        </label>
                        <Button
                            type="submit"
                            variant="outline"
                            class="self-start max-lg:min-h-11"
                            :disabled="newItem.processing"
                            >Add item</Button
                        >
                    </form>
                </section>
            </div>
        </details>

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
                        <Label for="open-file-user">Staff member</Label>
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
            :open="retentionConfirm.row !== null"
            title="Save this retention?"
            :description="retentionMessage"
            confirm-label="Save"
            processing-label="Saving…"
            :processing="retentionConfirm.processing"
            :error="errors.retention_days ?? errors.records"
            @update:open="(v: boolean) => !v && (retentionConfirm.row = null)"
            @confirm="sendRetention"
        />

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
