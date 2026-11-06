<script setup lang="ts">
// HR-5a /hr/credentials: My credentials (everyone), the register and credential types (Director, HR Manager).
// HR-PLAN 12.2 and 12.6, with Daniel's early look folded in.
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import RecordSheet from '@/components/hr/credentials/RecordSheet.vue';
import StatusChip from '@/components/hr/credentials/StatusChip.vue';
import type {
    Card,
    CredentialStatus,
    CredentialTypeHead,
    Option,
    RecordView,
} from '@/components/hr/credentials/types';
import MaskedValue from '@/components/hr/records/MaskedValue.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { dateLabel } from '@/components/hr/records/types';
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
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'my' | 'register' | 'settings';
type Cell = {
    status: CredentialStatus;
    waiting: boolean;
    required: boolean;
    expiresOn: string | null;
    doneOn: string | null;
} | null;
type Row = {
    id: string;
    name: string;
    role: string;
    branchId: number | null;
    branch: string | null;
    mmc: 'verified' | 'not_verified' | 'none' | null;
    canEnter: boolean;
    cells: Record<string, Cell>;
};
type Waiting = CredentialTypeHead & {
    person: string;
    workerId: string;
    recorded: RecordView | null;
    new: RecordView;
    enteredBy: string;
    enteredAt: string;
    decideRefusal: string | null;
};
type TypeSetting = CredentialTypeHead & {
    isActive: boolean;
    lockVersion: number;
    versions: {
        version: number;
        name: string;
        warnFromDays: number;
        roles: string[];
        by: string;
        at: string;
    }[];
};

const props = defineProps<{
    credentials: {
        tab: Tab;
        tabs: Tab[];
        today: string;
        canManage: boolean;
        setUp: boolean;
        options: {
            roles: Option[];
            rejectReasons: Option[];
            suggestedWarnDays: number;
        };
        my: {
            hasFile: boolean;
            mmc: { number: string | null; verified: boolean } | null;
            workerId?: string;
            canOpenDocuments?: boolean;
            cards?: Card[];
        } | null;
        register: {
            filters: {
                branch: number | null;
                role: string | null;
                type: string | null;
                status: string | null;
            };
            types: CredentialTypeHead[];
            rows: Row[];
            total: number;
            summary: {
                expiring: number;
                expired: number;
                not_recorded: number;
                waiting: number;
            };
            waiting: Waiting[];
            branches: { id: number; name: string }[];
        } | null;
        settings: { types: TypeSetting[] } | null;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const tabLabels: Record<Tab, string> = {
    my: 'My credentials',
    register: 'Register',
    settings: 'Credential types',
};
const tabs = computed(() =>
    props.credentials.tabs.map((value) => ({
        value,
        label:
            value === 'register' &&
            props.credentials.register &&
            props.credentials.register.summary.waiting > 0
                ? `Register (${props.credentials.register.summary.waiting} waiting)`
                : tabLabels[value],
    })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/credentials', { tab: value }, { preserveScroll: true });
const roleLabel = (role: string) =>
    props.credentials.options.roles.find((r) => r.value === role)?.label ??
    role;
const roleList = (roles: string[]) =>
    roles.length ? roles.map(roleLabel).join(', ') : 'No role needs it';

// ---- Add or renew (own, or for someone from the register) ----
const sheet = reactive({
    open: false,
    type: null as CredentialTypeHead | null,
    url: '',
    renewing: false,
    person: null as string | null,
});
const openOwn = (card: Card) =>
    Object.assign(sheet, {
        open: true,
        type: card,
        url: '/hr/credentials/my',
        renewing: card.inForce !== null,
        person: null,
    });

// ---- Register ----
const register = computed(() => props.credentials.register);
const filters = reactive({ branch: '', role: '', type: '', status: '' });
watch(
    () => props.credentials.register?.filters,
    (f) => {
        filters.branch = f?.branch ? String(f.branch) : '';
        filters.role = f?.role ?? '';
        filters.type = f?.type ?? '';
        filters.status = f?.status ?? '';
    },
    { immediate: true },
);
const applyFilters = () =>
    router.get(
        '/hr/credentials',
        {
            tab: 'register',
            branch: filters.branch || undefined,
            role: filters.role || undefined,
            type: filters.type || undefined,
            status: filters.status || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
const clearFilters = () => {
    Object.assign(filters, { branch: '', role: '', type: '', status: '' });
    applyFilters();
};
const filtered = computed(() =>
    Object.values(register.value?.filters ?? {}).some((v) => v),
);
const branchOptions = computed(() => [
    { value: '', label: 'All branches' },
    ...(register.value?.branches ?? []).map((b) => ({
        value: String(b.id),
        label: b.name,
    })),
]);
const roleOptions = computed(() => [
    { value: '', label: 'All roles' },
    ...props.credentials.options.roles,
]);
const typeOptions = computed(() => [
    { value: '', label: 'All credentials' },
    ...(register.value?.types ?? []).map((t) => ({
        value: t.typeId,
        label: t.name,
    })),
]);
const statusOptions = [
    { value: '', label: 'Any status' },
    { value: 'expiring', label: 'Expiring' },
    { value: 'expired', label: 'Expired' },
    { value: 'not_recorded', label: 'Not recorded' },
    { value: 'waiting', label: 'Waiting for check' },
];
// "3 expiring · 1 expired · 2 not recorded · 1 waiting for check"
const summaryLine = computed(() => {
    const s = register.value?.summary;

    if (!s) {
        return '';
    }

    const parts = [
        s.expiring ? `${s.expiring} expiring` : '',
        s.expired ? `${s.expired} expired` : '',
        s.not_recorded ? `${s.not_recorded} not recorded` : '',
        s.waiting ? `${s.waiting} waiting for check` : '',
    ].filter((p) => p);

    return parts.length ? parts.join(' · ') : 'Everything recorded is valid.';
});
const cellRecord = (cell: NonNullable<Cell>): RecordView | null =>
    cell.expiresOn || cell.doneOn
        ? ({
              expiresOn: cell.expiresOn,
              issuedOn: cell.doneOn,
          } as RecordView)
        : null;
// Below 1024 px: only what needs attention, then "All others valid".
const attention = (row: Row) =>
    (register.value?.types ?? [])
        .map((t) => ({ type: t, cell: row.cells[t.typeId] }))
        .filter(
            (x): x is { type: CredentialTypeHead; cell: NonNullable<Cell> } =>
                !!x.cell &&
                (x.cell.waiting ||
                    [
                        'expiring',
                        'expired',
                        'not_recorded',
                        'not_done',
                    ].includes(x.cell.status.code)),
        );

const enter = reactive({
    open: false,
    row: null as Row | null,
    typeId: '',
});
const startEnter = (row: Row) =>
    Object.assign(enter, { open: true, row, typeId: '' });
const continueEnter = () => {
    const type = register.value?.types.find((t) => t.typeId === enter.typeId);

    if (!type || !enter.row) {
        return;
    }

    Object.assign(sheet, {
        open: true,
        type,
        url: `/hr/credentials/workers/${enter.row.id}`,
        renewing:
            !!enter.row.cells[type.typeId]?.status &&
            !['not_recorded', 'not_required'].includes(
                enter.row.cells[type.typeId]?.status.code ?? '',
            ),
        person: enter.row.name,
    });
    enter.open = false;
};
const enterTypeOptions = computed(() =>
    (register.value?.types ?? []).map((t) => ({
        value: t.typeId,
        label: t.name,
    })),
);

const decision = reactive({
    item: null as Waiting | null,
    kind: 'accept' as 'accept' | 'reject',
    reason: '',
    key: '',
    processing: false,
});
const askDecision = (item: Waiting, kind: 'accept' | 'reject') =>
    Object.assign(decision, {
        item,
        kind,
        reason: '',
        key: crypto.randomUUID(),
    });
const sendDecision = () => {
    const item = decision.item;

    if (!item) {
        return;
    }

    decision.processing = true;
    router.post(
        `/hr/credentials/records/${item.new.id}/${decision.kind}`,
        {
            reason: decision.kind === 'reject' ? decision.reason : null,
            expected_lock_version: item.new.lockVersion,
            idempotency_key: decision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (decision.item = null),
            onFinish: () => (decision.processing = false),
        },
    );
};
const recordSummary = (r: RecordView | null, type: CredentialTypeHead) => {
    if (!r) {
        return 'Nothing recorded';
    }

    if (type.healthItem) {
        return r.healthDone ? `Done · ${dateLabel(r.issuedOn)}` : 'Not done';
    }

    return [
        `Issued ${dateLabel(r.issuedOn)}`,
        r.expiresOn ? `expires ${dateLabel(r.expiresOn)}` : 'no expiry',
        r.issuingBody,
        r.referenceLast4 ? `reference •••• ${r.referenceLast4}` : null,
    ]
        .filter((p) => p)
        .join(' · ');
};

// ---- Credential types ----
const newType = reactive({
    code: '',
    name: '',
    expires: 'yes',
    health: 'no',
    warn: '',
    roles: [] as string[],
    processing: false,
});
watch(
    () => props.credentials.options.suggestedWarnDays,
    (d) => (newType.warn = String(d)),
    { immediate: true },
);
const yesNo = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];
const addType = () => {
    newType.processing = true;
    router.post(
        '/hr/credentials/types',
        {
            code: newType.code,
            name: newType.name,
            expires:
                newType.health === 'yes' ? false : newType.expires === 'yes',
            health_item: newType.health === 'yes',
            warn_from_days: newType.warn,
            roles: newType.roles,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () =>
                Object.assign(newType, {
                    code: '',
                    name: '',
                    roles: [],
                    expires: 'yes',
                    health: 'no',
                    warn: String(props.credentials.options.suggestedWarnDays),
                }),
            onFinish: () => (newType.processing = false),
        },
    );
};
const edit = reactive({
    open: false,
    type: null as TypeSetting | null,
    name: '',
    warn: '',
    roles: [] as string[],
    key: '',
    processing: false,
});
const startEdit = (t: TypeSetting) =>
    Object.assign(edit, {
        open: true,
        type: t,
        name: t.name,
        warn: String(t.warnFromDays),
        roles: [...t.roles],
        key: crypto.randomUUID(),
    });
const saveEdit = () => {
    const t = edit.type;

    if (!t) {
        return;
    }

    edit.processing = true;
    router.post(
        '/hr/credentials/types',
        {
            type_id: t.typeId,
            name: edit.name,
            warn_from_days: edit.warn,
            roles: edit.roles,
            expected_lock_version: t.lockVersion,
            idempotency_key: edit.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (edit.open = false),
            onFinish: () => (edit.processing = false),
        },
    );
};
const archiving = ref<TypeSetting | null>(null);
const archiveBusy = ref(false);
const archiveType = () => {
    const t = archiving.value;

    if (!t) {
        return;
    }

    archiveBusy.value = true;
    router.post(
        `/hr/credentials/types/${t.typeId}/archive`,
        {
            expected_lock_version: t.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (archiving.value = null),
            onFinish: () => (archiveBusy.value = false),
        },
    );
};
</script>

<template>
    <Head :title="credentials.canManage ? 'Credentials' : 'My credentials'" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                :title="
                    credentials.canManage ? 'Credentials' : 'My credentials'
                "
                :description="
                    credentials.canManage
                        ? 'Certificates and renewals for everyone, checked by someone other than the person. Warnings only: nothing here stops clinical work.'
                        : 'Your certificates and renewals. Someone else checks each one before it counts.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="credentials.tab"
            :tabs="tabs"
            label="Credential sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="
                errors.credentials &&
                !sheet.open &&
                !decision.item &&
                !edit.open
            "
            :message="errors.credentials"
        />

        <!-- My credentials -->
        <section
            v-if="credentials.tab === 'my' && credentials.my"
            aria-labelledby="my-credentials-title"
            class="flex flex-col gap-4"
            data-test="my-credentials"
        >
            <h2 id="my-credentials-title" class="sr-only">My credentials</h2>
            <div
                v-if="credentials.my.mmc"
                class="rounded-xl border bg-card p-4 text-sm"
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
                    From Clinician credentials. The Director updates this.
                </p>
            </div>
            <EmptyState
                v-if="!credentials.my.hasFile"
                title="HR has not opened your staff file yet."
                description="When they do, your certificates appear here. Ask HR if you expected to see them."
            />
            <EmptyState
                v-else-if="!credentials.setUp"
                title="Credentials are not set up yet."
                description="HR enters which certificates each role needs. Nothing is needed from you until then."
            />
            <EmptyState
                v-else-if="!credentials.my.cards?.length"
                title="Your role needs no credentials."
                description="If HR adds one your role needs, it appears here."
            />
            <ul
                v-else
                class="grid gap-4 md:grid-cols-2"
                aria-label="My credentials"
            >
                <li
                    v-for="card in credentials.my.cards"
                    :key="card.typeId"
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                    data-test="credential-card"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold break-words">
                                {{ card.name }}
                            </h3>
                            <p
                                v-if="!card.required"
                                class="text-xs text-muted-foreground"
                            >
                                Not required for your role
                            </p>
                        </div>
                        <StatusChip
                            :status="card.status"
                            :record="card.inForce"
                        />
                    </div>
                    <dl
                        v-if="card.inForce && !card.healthItem"
                        class="grid gap-2 text-sm sm:grid-cols-2"
                    >
                        <div>
                            <dt class="text-muted-foreground">Issued</dt>
                            <dd>{{ dateLabel(card.inForce.issuedOn) }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Expires</dt>
                            <dd>
                                {{
                                    card.inForce.expiresOn
                                        ? dateLabel(card.inForce.expiresOn)
                                        : 'No expiry'
                                }}
                            </dd>
                        </div>
                        <div v-if="card.inForce.issuingBody">
                            <dt class="text-muted-foreground">Issuing body</dt>
                            <dd class="break-words">
                                {{ card.inForce.issuingBody }}
                            </dd>
                        </div>
                        <div
                            v-if="card.inForce.referenceLast4"
                            class="sm:col-span-2"
                        >
                            <dt class="text-muted-foreground">Reference</dt>
                            <dd>
                                <MaskedValue
                                    label="Reference"
                                    field="reference"
                                    :mask="`•••• ${card.inForce.referenceLast4}`"
                                    :last4="card.inForce.referenceLast4"
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
                            <dt class="text-muted-foreground">Certificate</dt>
                            <dd>
                                <a
                                    :href="`/hr/records/${credentials.my.workerId}/documents/${card.inForce.documentId}`"
                                    class="inline-flex min-h-11 items-center underline underline-offset-4 lg:min-h-0"
                                    >Open certificate</a
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Opening a document is recorded.</span
                                >
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="card.waiting"
                        class="flex flex-col gap-1 rounded-lg border border-dashed p-3 text-sm"
                        data-test="credential-waiting"
                    >
                        <StatusBadge
                            status="waiting"
                            label="Waiting for check"
                            tone="neutral"
                            class="self-start"
                        />
                        <p>{{ recordSummary(card.waiting, card) }}</p>
                        <p class="text-xs text-muted-foreground">
                            Entered by {{ card.waiting.enteredBy }} on
                            {{ dateLabel(card.waiting.enteredAt) }}. Someone
                            else checks this.
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
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            :disabled="!!card.waiting"
                            :data-test="card.inForce ? 'renew' : 'add'"
                            @click="openOwn(card)"
                        >
                            {{
                                card.inForce
                                    ? card.healthItem
                                        ? 'Update'
                                        : 'Renew'
                                    : 'Add'
                            }}
                        </Button>
                        <p
                            v-if="card.waiting"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            You can send another once this one is checked.
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Register -->
        <section
            v-if="credentials.tab === 'register' && register"
            aria-labelledby="register-title"
            class="flex flex-col gap-4"
            data-test="credentials-register"
        >
            <h2 id="register-title" class="sr-only">Credentials register</h2>
            <EmptyState
                v-if="!credentials.setUp"
                title="Credentials are not set up yet."
                description="Add the credential types and the roles that need them under Credential types."
            />
            <template v-else>
                <p class="text-sm font-medium" data-test="register-summary">
                    {{ summaryLine }}
                </p>
                <form
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4 lg:flex-row lg:flex-wrap lg:items-end"
                    @submit.prevent="applyFilters"
                >
                    <div class="grid gap-1.5 lg:w-48">
                        <Label for="register-branch">Branch</Label>
                        <OperationalSelect
                            id="register-branch"
                            v-model="filters.branch"
                            trigger-class="max-lg:h-11"
                            label="Branch"
                            :options="branchOptions"
                        />
                    </div>
                    <div class="grid gap-1.5 lg:w-48">
                        <Label for="register-role">Role</Label>
                        <OperationalSelect
                            id="register-role"
                            v-model="filters.role"
                            trigger-class="max-lg:h-11"
                            label="Role"
                            :options="roleOptions"
                        />
                    </div>
                    <div class="grid gap-1.5 lg:w-56">
                        <Label for="register-type">Credential</Label>
                        <OperationalSelect
                            id="register-type"
                            v-model="filters.type"
                            trigger-class="max-lg:h-11"
                            label="Credential"
                            :options="typeOptions"
                        />
                    </div>
                    <div class="grid gap-1.5 lg:w-48">
                        <Label for="register-status">Status</Label>
                        <OperationalSelect
                            id="register-status"
                            v-model="filters.status"
                            trigger-class="max-lg:h-11"
                            label="Status"
                            :options="statusOptions"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" class="max-lg:min-h-11"
                            >Show</Button
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
                </form>

                <section
                    v-if="register.waiting.length"
                    aria-labelledby="waiting-title"
                    class="flex flex-col gap-3"
                    data-test="register-waiting"
                >
                    <h3 id="waiting-title" class="text-sm font-semibold">
                        Waiting for check
                    </h3>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="w in register.waiting"
                            :key="w.new.id"
                            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                            data-test="waiting-record"
                        >
                            <p class="text-sm">
                                <span class="font-semibold">{{
                                    w.person
                                }}</span>
                                · {{ w.name }}
                            </p>
                            <div class="grid gap-3 text-sm md:grid-cols-2">
                                <div>
                                    <p class="text-xs text-muted-foreground">
                                        Recorded now
                                    </p>
                                    <p class="break-words">
                                        {{ recordSummary(w.recorded, w) }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">
                                        New
                                    </p>
                                    <p class="break-words">
                                        {{ recordSummary(w.new, w) }}
                                    </p>
                                    <a
                                        v-if="w.new.documentId"
                                        :href="`/hr/records/${w.workerId}/documents/${w.new.documentId}`"
                                        class="inline-flex min-h-11 items-center text-sm underline underline-offset-4 lg:min-h-0"
                                        >Open certificate</a
                                    >
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Entered by {{ w.enteredBy }} on
                                {{ dateLabel(w.enteredAt) }}.
                            </p>
                            <p
                                v-if="w.decideRefusal"
                                class="text-sm text-muted-foreground"
                            >
                                {{ w.decideRefusal }}
                            </p>
                            <div v-else class="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="max-lg:min-h-11"
                                    data-test="accept"
                                    @click="askDecision(w, 'accept')"
                                    >Accept</Button
                                >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    class="max-lg:min-h-11"
                                    data-test="reject"
                                    @click="askDecision(w, 'reject')"
                                    >Reject</Button
                                >
                            </div>
                        </li>
                    </ul>
                </section>

                <EmptyState
                    v-if="register.rows.length === 0"
                    :title="
                        register.total === 0
                            ? 'No staff files yet.'
                            : 'Nobody matches.'
                    "
                    :description="
                        register.total === 0
                            ? 'The register lists everyone with a staff file. Open staff files under KPOne HR staff files.'
                            : 'Try another filter, or clear the filters.'
                    "
                />
                <template v-else>
                    <!-- From 1024 px: person × credential, header row and person column sticky (Daniel). -->
                    <div
                        class="hidden max-h-[70vh] overflow-auto rounded-xl border bg-card lg:block"
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
                                        <span class="sr-only">Actions</span>
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
                                                [row.role, row.branch]
                                                    .filter((p) => p)
                                                    .join(' · ')
                                            }}
                                        </span>
                                        <span
                                            v-if="row.mmc"
                                            class="block text-xs font-normal text-muted-foreground"
                                            >MMC:
                                            {{
                                                row.mmc === 'verified'
                                                    ? 'verified'
                                                    : row.mmc === 'not_verified'
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
                                        <template v-if="row.cells[t.typeId]">
                                            <StatusChip
                                                :status="
                                                    row.cells[t.typeId]!.status
                                                "
                                                :record="
                                                    cellRecord(
                                                        row.cells[t.typeId]!,
                                                    )
                                                "
                                                :name="t.name"
                                                short
                                            />
                                            <StatusBadge
                                                v-if="
                                                    row.cells[t.typeId]!.waiting
                                                "
                                                status="waiting"
                                                label="Waiting"
                                                tone="neutral"
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
                                        <Button
                                            v-if="row.canEnter"
                                            variant="outline"
                                            size="sm"
                                            data-test="enter-record"
                                            @click="startEnter(row)"
                                            >Add a record</Button
                                        >
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
                            class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                            data-test="register-card"
                        >
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="text-sm text-muted-foreground">
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
                                    <span class="min-w-0 break-words">{{
                                        a.type.name
                                    }}</span>
                                    <StatusChip
                                        :status="a.cell.status"
                                        :record="cellRecord(a.cell)"
                                    />
                                    <StatusBadge
                                        v-if="a.cell.waiting"
                                        status="waiting"
                                        label="Waiting for check"
                                        tone="neutral"
                                    />
                                </li>
                            </ul>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    attention(row).length
                                        ? 'All others valid'
                                        : 'All valid'
                                }}
                            </p>
                            <Button
                                v-if="row.canEnter"
                                variant="outline"
                                size="sm"
                                class="self-start max-lg:min-h-11"
                                @click="startEnter(row)"
                                >Add a record</Button
                            >
                        </li>
                    </ul>
                </template>
            </template>
        </section>

        <!-- Credential types -->
        <section
            v-if="credentials.tab === 'settings' && credentials.settings"
            aria-labelledby="types-title"
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]"
            data-test="credential-types"
        >
            <h2 id="types-title" class="sr-only">Credential types</h2>
            <form
                class="flex flex-col gap-4 self-start rounded-xl border bg-card p-4"
                data-test="add-type"
                @submit.prevent="addType"
            >
                <h3 class="text-sm font-semibold">Add a credential type</h3>
                <p class="text-xs text-muted-foreground">
                    KPOne ships no list. For example: Annual Practising
                    Certificate (APC), BLS, ACLS, Hepatitis B vaccination,
                    Radiation safety. Nothing counts until you save it.
                </p>
                <div class="grid gap-1.5">
                    <Label for="type-name">Name *</Label>
                    <Input
                        id="type-name"
                        v-model="newType.name"
                        maxlength="120"
                        class="max-lg:h-11"
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="type-code">Short code *</Label>
                    <Input
                        id="type-code"
                        v-model="newType.code"
                        maxlength="16"
                        class="w-40 uppercase max-lg:h-11"
                        autocomplete="off"
                    />
                    <p class="text-xs text-muted-foreground">
                        For example APC. It cannot change later.
                    </p>
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="type-health"
                        ><span id="type-health-label"
                            >Health item *</span
                        ></Label
                    >
                    <OperationalSelect
                        id="type-health"
                        labelledby="type-health-label"
                        v-model="newType.health"
                        trigger-class="w-40 max-lg:h-11"
                        label="Health item"
                        :options="yesNo"
                    />
                    <p class="text-xs text-muted-foreground">
                        A health item records only Done or Not done and a date,
                        and is seen only by the person and by managers. It does
                        not expire.
                    </p>
                    <InputError :message="errors.health_item" />
                </div>
                <div v-if="newType.health !== 'yes'" class="grid gap-1.5">
                    <Label for="type-expires"
                        ><span id="type-expires-label">Expires *</span></Label
                    >
                    <OperationalSelect
                        id="type-expires"
                        labelledby="type-expires-label"
                        v-model="newType.expires"
                        trigger-class="w-40 max-lg:h-11"
                        label="Expires"
                        :options="yesNo"
                    />
                    <InputError :message="errors.expires" />
                </div>
                <div v-if="newType.health !== 'yes'" class="grid gap-1.5">
                    <Label for="type-warn"
                        >Warn from (days before expiry) *</Label
                    >
                    <Input
                        id="type-warn"
                        v-model="newType.warn"
                        type="number"
                        min="1"
                        max="365"
                        inputmode="numeric"
                        class="w-32 max-lg:h-11"
                    />
                    <InputError :message="errors.warn_from_days" />
                </div>
                <fieldset class="flex flex-col gap-1">
                    <legend class="mb-1 text-sm font-medium">
                        Roles that need it
                    </legend>
                    <label
                        v-for="r in credentials.options.roles"
                        :key="r.value"
                        class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
                    >
                        <input
                            v-model="newType.roles"
                            type="checkbox"
                            :value="r.value"
                            class="size-4"
                        />
                        {{ r.label }}
                    </label>
                    <InputError :message="errors.roles" />
                </fieldset>
                <Button
                    type="submit"
                    class="self-start max-lg:min-h-11"
                    :disabled="newType.processing"
                    data-test="type-save"
                    >{{ newType.processing ? 'Saving…' : 'Save type' }}</Button
                >
            </form>

            <div class="flex flex-col gap-3">
                <EmptyState
                    v-if="!credentials.settings.types.length"
                    title="No credential types yet."
                    description="Add the first one. Until then the register is empty and nobody sees warnings."
                />
                <article
                    v-for="t in credentials.settings.types"
                    :key="t.typeId"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4"
                    :class="{ 'opacity-70': !t.isActive }"
                    data-test="type-card"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold break-words">
                            {{ t.name }}
                        </h3>
                        <span class="text-xs text-muted-foreground">{{
                            t.code
                        }}</span>
                        <StatusBadge
                            v-if="!t.isActive"
                            status="archived"
                            label="Archived"
                            tone="neutral"
                        />
                    </div>
                    <p class="text-sm">
                        {{
                            t.healthItem
                                ? 'Health item · Done or Not done'
                                : t.expires
                                  ? `Expires · warn from ${t.warnFromDays} days`
                                  : 'Does not expire'
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ roleList(t.roles) }}
                    </p>
                    <div v-if="t.isActive" class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            @click="startEdit(t)"
                            >Change</Button
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="max-lg:min-h-11"
                            @click="archiving = t"
                            >Archive</Button
                        >
                    </div>
                    <details class="text-sm">
                        <summary
                            class="min-h-11 cursor-pointer content-center text-muted-foreground lg:min-h-0"
                        >
                            Saved versions ({{ t.versions.length }})
                        </summary>
                        <ol class="mt-2 flex flex-col gap-1 text-xs">
                            <li v-for="v in t.versions" :key="v.version">
                                Version {{ v.version }}: {{ v.name }} · warn
                                from {{ v.warnFromDays }} days ·
                                {{ roleList(v.roles) }} · {{ v.by }},
                                {{ dateLabel(v.at) }}
                            </li>
                        </ol>
                    </details>
                </article>
            </div>
        </section>

        <RecordSheet
            v-model:open="sheet.open"
            :type="sheet.type"
            :url="sheet.url"
            :renewing="sheet.renewing"
            :person="sheet.person"
        />

        <OperationalConfirmDialog
            :open="enter.open"
            title="Add a record"
            :description="
                enter.row
                    ? `For ${enter.row.name}. Another manager checks it.`
                    : ''
            "
            confirm-label="Continue"
            @update:open="(v: boolean) => !v && (enter.open = false)"
            @confirm="continueEnter"
        >
            <div class="grid gap-1.5">
                <Label for="enter-type"
                    ><span id="enter-type-label">Credential *</span></Label
                >
                <OperationalSelect
                    id="enter-type"
                    labelledby="enter-type-label"
                    v-model="enter.typeId"
                    trigger-class="max-lg:h-11"
                    label="Credential"
                    placeholder="Choose the credential"
                    :options="enterTypeOptions"
                />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
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
            :confirm-label="decision.kind === 'accept' ? 'Accept' : 'Reject'"
            :processing-label="
                decision.kind === 'accept' ? 'Accepting…' : 'Rejecting…'
            "
            :processing="decision.processing"
            :error="errors.reason ?? errors.credentials"
            @update:open="(v: boolean) => !v && (decision.item = null)"
            @confirm="sendDecision"
        >
            <div v-if="decision.kind === 'reject'" class="grid gap-1.5">
                <Label for="reject-reason"
                    ><span id="reject-reason-label"
                        >Why it is rejected *</span
                    ></Label
                >
                <OperationalSelect
                    id="reject-reason"
                    labelledby="reject-reason-label"
                    v-model="decision.reason"
                    trigger-class="max-lg:h-11"
                    label="Why it is rejected"
                    placeholder="Choose a reason"
                    :options="credentials.options.rejectReasons"
                    :invalid="!!errors.reason"
                />
            </div>
        </OperationalConfirmDialog>

        <OperationalConfirmDialog
            :open="archiving !== null"
            title="Archive this credential type?"
            :description="
                archiving
                    ? `${archiving.name}. It leaves the register and takes no new records. Records already kept stay. It cannot come back; add a new type instead.`
                    : ''
            "
            confirm-label="Archive"
            processing-label="Archiving…"
            :processing="archiveBusy"
            :error="errors.credentials"
            @update:open="(v: boolean) => !v && (archiving = null)"
            @confirm="archiveType"
        />

        <Sheet v-model:open="edit.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="type-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Change {{ edit.type?.name }}</SheetTitle>
                    <SheetDescription
                        >Saves a new version; earlier versions stay listed.
                        Whether it expires or is a health item cannot
                        change.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveEdit"
                >
                    <ErrorState
                        v-if="errors.credentials"
                        :message="errors.credentials"
                    />
                    <div class="grid gap-1.5">
                        <Label for="edit-name">Name *</Label>
                        <Input
                            id="edit-name"
                            v-model="edit.name"
                            maxlength="120"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div v-if="!edit.type?.healthItem" class="grid gap-1.5">
                        <Label for="edit-warn"
                            >Warn from (days before expiry) *</Label
                        >
                        <Input
                            id="edit-warn"
                            v-model="edit.warn"
                            type="number"
                            min="1"
                            max="365"
                            inputmode="numeric"
                            class="w-32 max-lg:h-11"
                        />
                        <InputError :message="errors.warn_from_days" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Roles that need it
                        </legend>
                        <label
                            v-for="r in credentials.options.roles"
                            :key="r.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
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
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="edit.processing"
                    >
                        {{ edit.processing ? 'Saving…' : 'Save new version' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
