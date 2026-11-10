<script setup lang="ts">
// HR-4a /hr/records/{worker}: one staff file with Employment, Personal and Bank tabs (Documents and Checklist arrive with
// HR-4b). Sensitive values arrive masked; only Show (audited) releases one. HR-PLAN 11.2, 11.3 and 11.6.
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ChecklistTab from '@/components/hr/records/ChecklistTab.vue';
import DocumentList from '@/components/hr/records/DocumentList.vue';
import MaskedValue from '@/components/hr/records/MaskedValue.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import type {
    ChecklistPeriod,
    RecordOptions,
    StaffDocument,
    WorkerHeader,
} from '@/components/hr/records/types';
import { dateLabel, statusTone } from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
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
import { Textarea } from '@/components/ui/textarea';

type Tab = 'employment' | 'personal' | 'bank' | 'documents' | 'checklist';
type Period = {
    id: string;
    type: string;
    typeLabel: string;
    startsOn: string;
    probationEndsOn: string | null;
    confirmedOn: string | null;
    endsOn: string | null;
    endReason: string | null;
    endReasonLabel: string | null;
    hasNote: boolean;
    lockVersion: number;
};
type Bank = {
    id: string;
    bankName: string;
    mask: string;
    enteredBy: string;
    enteredAt: string;
    lockVersion: number;
    enteredByMe?: boolean;
};
type Correction = {
    id: string;
    kind: string;
    kindLabel: string;
    reasonLabel: string;
    hasNote: boolean;
    recorded: string;
    new: string;
    askedBy: string;
    askedAt: string;
    lockVersion: number;
};

const props = defineProps<{
    file: {
        tab: Tab;
        options: RecordOptions;
        canManage: boolean;
        manageRefusal: string | null;
        isSelf: boolean;
        workers: { id: string; name: string }[];
        worker: WorkerHeader & { lockVersion: number };
        attention: string[];
        signIn: {
            state: 'none' | 'open' | 'linked';
            label: string;
            requestId: string | null;
            email: string | null;
            accountName: string | null;
            at: string | null;
            lockVersion: number | null;
        } | null;
        periods: Period[];
        positions: {
            title: string;
            fromDate: string;
            department: string | null;
            reportsTo: string | null;
        }[];
        personal: {
            legalName: boolean;
            preferredName: string | null;
            identity: {
                kind: 'ic' | 'passport';
                last4: string;
                country: string | null;
                mask: string;
            } | null;
            dateOfBirth: boolean;
            nationality: string | null;
            address: boolean;
            personalPhone: boolean;
            emergencyContact: boolean;
            lockVersion: number;
        };
        bank: { confirmed: Bank | null; waiting: Bank | null };
        corrections: Correction[];
        canOpenDocuments: boolean;
        documents: StaffDocument[];
        checklist: ChecklistPeriod[] | null;
        changes: {
            area: string;
            fields: string[];
            reason: string | null;
            by: string;
            at: string;
        }[];
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const w = computed(() => props.file.worker);

// ---- HR-15a: a locum's KPOne sign-in ----
const signIn = reactive({
    email: '',
    key: crypto.randomUUID(),
    processing: false,
    cancelOpen: false,
});
const askSignIn = () => {
    signIn.processing = true;
    router.post(
        `/hr/records/${w.value.id}/sign-in`,
        { email: signIn.email, idempotency_key: signIn.key },
        {
            preserveScroll: true,
            onSuccess: () => {
                signIn.email = '';
                signIn.key = crypto.randomUUID();
            },
            onFinish: () => (signIn.processing = false),
        },
    );
};
const cancelSignIn = () => {
    const current = props.file.signIn;

    if (!current?.requestId) {
        return;
    }

    signIn.processing = true;
    router.post(
        `/hr/records/${w.value.id}/sign-in/${current.requestId}/cancel`,
        {
            expected_lock_version: current.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        {
            preserveScroll: true,
            onSuccess: () => (signIn.cancelOpen = false),
            onFinish: () => (signIn.processing = false),
        },
    );
};
const tabs = [
    { value: 'employment', label: 'Employment' },
    { value: 'personal', label: 'Personal' },
    { value: 'bank', label: 'Bank' },
    { value: 'documents', label: 'Documents' },
    { value: 'checklist', label: 'Checklist' },
];
const selectTab = (value: string | number) =>
    router.get(
        `/hr/records/${w.value.id}`,
        { tab: value },
        { preserveScroll: true },
    );
const notGiven = 'Not given';
// KPOne formats: "6 Nov 2026, 7:24 AM".
const timeLabel = (iso: string) => {
    const d = new Date(iso);
    const day = new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(d);
    const time = new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(d);

    return `${day}, ${time}`;
};
const fieldWords = (fields: string[]) =>
    fields.map((f) => f.replaceAll('_', ' ')).join(', ');

// ---- Employment ----
const period = reactive({
    open: false,
    editing: null as Period | null,
    type: '',
    startsOn: '',
    probationEndsOn: '',
    confirmedOn: '',
    endsOn: '',
    endReason: '',
    note: '',
    editReason: '',
    key: '',
    processing: false,
});
const openPeriod = (p: Period | null) =>
    Object.assign(period, {
        open: true,
        editing: p,
        type: p?.type ?? '',
        startsOn: p?.startsOn ?? '',
        probationEndsOn: p?.probationEndsOn ?? '',
        confirmedOn: p?.confirmedOn ?? '',
        endsOn: p?.endsOn ?? '',
        endReason: p?.endReason ?? '',
        note: '',
        editReason: '',
        key: crypto.randomUUID(),
    });
const needsEditReason = computed(
    () =>
        period.editing !== null &&
        (period.editing.endsOn !== null ||
            period.type !== period.editing.type ||
            period.startsOn !== period.editing.startsOn),
);
const savePeriod = () => {
    period.processing = true;
    const data = {
        type: period.type,
        starts_on: period.startsOn,
        probation_ends_on: period.probationEndsOn,
        confirmed_on: period.confirmedOn,
        ends_on: period.endsOn,
        end_reason: period.endsOn ? period.endReason : '',
        ...(period.note ? { note: period.note } : {}),
        edit_reason: needsEditReason.value ? period.editReason : undefined,
        expected_lock_version: period.editing?.lockVersion,
        idempotency_key: period.key,
    };
    const options = {
        preserveScroll: true,
        onSuccess: () => (period.open = false),
        onFinish: () => (period.processing = false),
    };

    if (period.editing) {
        router.put(
            `/hr/records/${w.value.id}/periods/${period.editing.id}`,
            data,
            options,
        );
    } else {
        router.post(`/hr/records/${w.value.id}/periods`, data, options);
    }
};

const position = reactive({
    open: false,
    title: '',
    departmentId: '',
    reportsTo: '',
    fromDate: '',
    key: '',
    processing: false,
});
const openPosition = () =>
    Object.assign(position, {
        open: true,
        title: '',
        departmentId: '',
        reportsTo: '',
        fromDate: '',
        key: crypto.randomUUID(),
    });
const savePosition = () => {
    position.processing = true;
    router.post(
        `/hr/records/${w.value.id}/positions`,
        {
            title: position.title,
            department_id: position.departmentId,
            reports_to: position.reportsTo,
            from_date: position.fromDate,
            idempotency_key: position.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (position.open = false),
            onFinish: () => (position.processing = false),
        },
    );
};

// ---- Personal ----
// The manager's form starts empty for hidden values; an empty field keeps what is recorded.
const personal = reactive({
    open: false,
    legalName: '',
    preferredName: '',
    identityKind: '',
    identityNumber: '',
    passportCountry: '',
    dateOfBirth: '',
    nationality: '',
    personalPhone: '',
    line1: '',
    line2: '',
    city: '',
    state: '',
    postcode: '',
    country: '',
    emName: '',
    emRelationship: '',
    emPhone: '',
    key: '',
    processing: false,
});
const openPersonal = () =>
    Object.assign(personal, {
        open: true,
        legalName: '',
        preferredName: props.file.personal.preferredName ?? '',
        identityKind: '',
        identityNumber: '',
        passportCountry: '',
        dateOfBirth: '',
        nationality: props.file.personal.nationality ?? '',
        personalPhone: '',
        line1: '',
        line2: '',
        city: '',
        state: '',
        postcode: '',
        country: '',
        emName: '',
        emRelationship: '',
        emPhone: '',
        key: crypto.randomUUID(),
    });
const savePersonal = () => {
    const data: Record<string, FormDataConvertible> = {
        preferred_name: personal.preferredName,
        nationality: personal.nationality,
        expected_lock_version: props.file.personal.lockVersion,
        idempotency_key: personal.key,
    };

    if (personal.legalName) {
        data.legal_name = personal.legalName;
    }

    if (personal.identityKind) {
        data.identity_kind = personal.identityKind;
        data.identity_number = personal.identityNumber;
        data.passport_country = personal.passportCountry;
    }

    if (personal.dateOfBirth) {
        data.date_of_birth = personal.dateOfBirth;
    }

    if (personal.personalPhone) {
        data.personal_phone = personal.personalPhone;
    }

    if (personal.line1) {
        data.address = {
            line1: personal.line1,
            line2: personal.line2,
            city: personal.city,
            state: personal.state,
            postcode: personal.postcode,
            country: personal.country,
        };
    }

    if (personal.emName || personal.emPhone) {
        data.emergency_contact = {
            name: personal.emName,
            relationship: personal.emRelationship,
            phone: personal.emPhone,
        };
    }

    personal.processing = true;
    router.put(`/hr/records/${w.value.id}/personal`, data, {
        preserveScroll: true,
        onSuccess: () => (personal.open = false),
        onFinish: () => (personal.processing = false),
    });
};

// ---- Corrections ----
const personalCorrections = computed(() =>
    props.file.corrections.filter((c) => c.kind !== 'bank'),
);
const bankCorrections = computed(() =>
    props.file.corrections.filter((c) => c.kind === 'bank'),
);
const correctionDecision = reactive({
    correction: null as Correction | null,
    decision: 'apply' as 'apply' | 'reject',
    reason: '',
    key: '',
    processing: false,
});
const startCorrectionDecision = (c: Correction, decision: 'apply' | 'reject') =>
    Object.assign(correctionDecision, {
        correction: c,
        decision,
        reason: '',
        key: crypto.randomUUID(),
    });
const sendCorrectionDecision = () => {
    const c = correctionDecision.correction;

    if (!c) {
        return;
    }

    correctionDecision.processing = true;
    router.post(
        `/hr/records/${w.value.id}/corrections/${c.id}/${correctionDecision.decision}`,
        {
            tab: c.kind === 'bank' ? 'bank' : 'personal',
            reason:
                correctionDecision.decision === 'reject'
                    ? correctionDecision.reason
                    : undefined,
            expected_lock_version: c.lockVersion,
            idempotency_key: correctionDecision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (correctionDecision.correction = null),
            onFinish: () => (correctionDecision.processing = false),
        },
    );
};

// ---- Documents (HR-4b) ----
const upload = reactive({
    open: false,
    category: '',
    issuedOn: '',
    expiresOn: '',
    file: null as File | null,
    key: '',
    processing: false,
});
const openUpload = () =>
    Object.assign(upload, {
        open: true,
        category: '',
        issuedOn: '',
        expiresOn: '',
        file: null,
        key: crypto.randomUUID(),
    });
const onFile = (event: Event) => {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
};
const sendUpload = () => {
    upload.processing = true;
    router.post(
        `/hr/records/${w.value.id}/documents`,
        {
            category: upload.category,
            issued_on: upload.issuedOn,
            expires_on: upload.expiresOn,
            file: upload.file,
            idempotency_key: upload.key,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => (upload.open = false),
            onFinish: () => (upload.processing = false),
        },
    );
};
const removal = reactive({
    document: null as StaffDocument | null,
    reason: '',
    key: '',
    processing: false,
});
const startRemoval = (d: StaffDocument) =>
    Object.assign(removal, {
        document: d,
        reason: '',
        key: crypto.randomUUID(),
    });
const sendRemoval = () => {
    const d = removal.document;

    if (!d) {
        return;
    }

    removal.processing = true;
    router.post(
        `/hr/records/${w.value.id}/documents/${d.id}/remove`,
        {
            reason: removal.reason,
            expected_lock_version: d.lockVersion,
            idempotency_key: removal.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (removal.document = null),
            onFinish: () => (removal.processing = false),
        },
    );
};

// ---- Bank ----
const bankForm = reactive({
    open: false,
    bankName: '',
    holder: '',
    account: '',
    key: '',
    processing: false,
});
const openBank = () =>
    Object.assign(bankForm, {
        open: true,
        bankName: '',
        holder: '',
        account: '',
        key: crypto.randomUUID(),
    });
const saveBank = () => {
    bankForm.processing = true;
    router.post(
        `/hr/records/${w.value.id}/bank`,
        {
            bank_name: bankForm.bankName,
            holder: bankForm.holder,
            account: bankForm.account,
            idempotency_key: bankForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (bankForm.open = false),
            onFinish: () => (bankForm.processing = false),
        },
    );
};
const bankDecision = reactive({
    open: false,
    decision: 'confirm' as 'confirm' | 'reject' | 'withdraw',
    reason: '',
    key: '',
    processing: false,
});
// The confirmer looks at the full new account before Confirm is offered (Suresh, S2); the view is audited.
const waitingShown = ref(false);
const startBankDecision = (decision: 'confirm' | 'reject' | 'withdraw') =>
    Object.assign(bankDecision, {
        open: true,
        decision,
        reason: '',
        key: crypto.randomUUID(),
    });
const sendBankDecision = () => {
    const bank = props.file.bank.waiting;

    if (!bank) {
        return;
    }

    bankDecision.processing = true;
    const url =
        bankDecision.decision === 'withdraw'
            ? `/hr/records/${w.value.id}/bank/${bank.id}/withdraw`
            : `/hr/records/${w.value.id}/bank/${bank.id}/${bankDecision.decision}`;
    router.post(
        url,
        {
            reason:
                bankDecision.decision === 'reject'
                    ? bankDecision.reason
                    : undefined,
            expected_lock_version: bank.lockVersion,
            idempotency_key: bankDecision.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (bankDecision.open = false),
            onFinish: () => (bankDecision.processing = false),
        },
    );
};
const bankDialog = computed(() => {
    const waiting = props.file.bank.waiting;
    const compare = waiting
        ? `Recorded: ${props.file.bank.confirmed ? `${props.file.bank.confirmed.bankName} ${props.file.bank.confirmed.mask.replace('Account ', '')}` : notGiven} · New: ${waiting.bankName} ${waiting.mask.replace('Account ', '')}`
        : '';

    return {
        confirm: {
            title: 'Confirm these bank details?',
            label: 'Confirm',
            busy: 'Confirming…',
            description: `${compare}. The new account replaces the recorded one.`,
        },
        reject: {
            title: 'Reject this bank change?',
            label: 'Reject',
            busy: 'Rejecting…',
            description: `${compare}. The recorded account stays in force.`,
        },
        withdraw: {
            title: 'Withdraw this bank change?',
            label: 'Withdraw',
            busy: 'Withdrawing…',
            description: 'The change is kept as withdrawn.',
        },
    }[bankDecision.decision];
});
</script>

<template>
    <Head :title="`${w.name} · Staff file`" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-2">
            <Link
                href="/hr/records?tab=files"
                class="min-h-11 content-center self-start text-sm text-muted-foreground underline-offset-4 hover:underline lg:min-h-0"
                >← Staff files</Link
            >
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-balance">{{ w.name }}</h1>
                <span class="text-sm text-muted-foreground">{{ w.role }}</span>
                <StatusBadge
                    :status="w.status"
                    :label="w.statusLabel"
                    :tone="statusTone(w.status)"
                />
                <StatusBadge
                    v-if="w.kind === 'locum'"
                    status="locum"
                    :label="
                        file.signIn?.state === 'linked'
                            ? 'Locum with a KPOne sign-in'
                            : 'Locum, no KPOne sign-in'
                    "
                    tone="neutral"
                />
            </div>
            <SyntheticNote />
            <p
                v-if="file.manageRefusal"
                class="text-sm text-muted-foreground"
                data-test="manage-refusal"
            >
                {{ file.manageRefusal }}
            </p>
            <p
                v-if="file.isSelf"
                class="text-sm text-muted-foreground"
                data-test="self-note"
            >
                This is your own file. Someone else manages it; use My HR
                details for your contact details and corrections.
            </p>
        </div>

        <div
            v-if="file.attention.length"
            class="rounded-xl border border-warning/30 bg-warning/5 p-3 text-sm"
            data-test="attention"
        >
            <p class="font-medium">
                {{
                    file.attention.length === 1
                        ? 'Needs attention'
                        : `${file.attention.length} things need attention`
                }}
            </p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="reason in file.attention" :key="reason">
                    {{ reason }}
                </li>
            </ul>
        </div>

        <OperationalTabs
            :model-value="file.tab"
            :tabs="tabs"
            label="Staff file sections"
            class="min-w-0 max-lg:[&>button]:h-11"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="
                (errors.records || errors.bank || errors.correction) &&
                !period.open &&
                !position.open &&
                !personal.open &&
                !bankForm.open &&
                !bankDecision.open &&
                !correctionDecision.correction
            "
            :message="errors.records ?? errors.bank ?? errors.correction ?? ''"
        />

        <!-- Employment -->
        <section
            v-if="file.tab === 'employment'"
            aria-labelledby="employment-title"
            class="flex flex-col gap-4"
        >
            <div
                v-if="file.signIn"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                data-test="locum-sign-in"
            >
                <h2 class="text-base font-semibold">KPOne sign-in</h2>
                <template v-if="file.signIn.state === 'none'">
                    <p class="text-sm text-muted-foreground">
                        A locum has no KPOne sign-in unless HR asks for one.
                        With a sign-in they see only their own approved shifts:
                        no patient, clinical or pay details.
                    </p>
                    <form
                        v-if="file.canManage"
                        class="flex flex-col gap-2"
                        @submit.prevent="askSignIn"
                    >
                        <Label for="locum-sign-in-email"
                            >Email the locum will sign in with</Label
                        >
                        <div class="flex flex-wrap gap-2">
                            <Input
                                id="locum-sign-in-email"
                                v-model="signIn.email"
                                type="email"
                                autocomplete="off"
                                class="max-w-sm min-w-0 flex-1 max-lg:min-h-11"
                                :aria-invalid="!!errors.email"
                                aria-describedby="locum-sign-in-help"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                class="max-lg:min-h-11"
                                :disabled="
                                    signIn.processing || !signIn.email.trim()
                                "
                                data-test="ask-sign-in"
                                >{{
                                    signIn.processing
                                        ? 'Asking…'
                                        : 'Ask for a sign-in'
                                }}</Button
                            >
                        </div>
                        <p
                            id="locum-sign-in-help"
                            class="text-sm text-muted-foreground"
                        >
                            The account administrator creates the account on the
                            Staff page and links it here. HR creates no account.
                        </p>
                        <InputError :message="errors.email" />
                    </form>
                </template>
                <template v-else-if="file.signIn.state === 'open'">
                    <p class="text-sm">
                        {{ file.signIn.label }}. Asked on
                        {{ file.signIn.at }} for {{ file.signIn.email }}.
                    </p>
                    <div v-if="file.canManage">
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            data-test="cancel-sign-in"
                            @click="signIn.cancelOpen = true"
                            >Cancel request</Button
                        >
                    </div>
                </template>
                <p v-else class="text-sm">
                    {{ file.signIn.label }} as {{ file.signIn.accountName }},
                    linked on {{ file.signIn.at }}.
                </p>
            </div>
            <OperationalConfirmDialog
                v-model:open="signIn.cancelOpen"
                title="Cancel the sign-in request?"
                description="The account administrator will no longer see it on the Staff page. You can ask again later."
                confirm-label="Cancel request"
                cancel-label="Keep it"
                processing-label="Cancelling…"
                :processing="signIn.processing"
                :error="errors.request"
                @confirm="cancelSignIn"
            />
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="employment-title" class="text-base font-semibold">
                    Employment periods
                </h2>
                <Button
                    v-if="file.canManage"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="add-period"
                    @click="openPeriod(null)"
                    >Add employment period</Button
                >
            </div>
            <EmptyState
                v-if="!file.periods.length"
                title="No employment period yet."
                description="Add the first period with its start date and type."
            />
            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="p in file.periods"
                    :key="p.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-xl border bg-card p-4"
                    data-test="period"
                >
                    <div class="min-w-0 text-sm">
                        <p class="font-medium">{{ p.typeLabel }}</p>
                        <p>
                            From {{ dateLabel(p.startsOn) }}
                            <template v-if="p.endsOn"
                                >to {{ dateLabel(p.endsOn) }}</template
                            >
                        </p>
                        <p
                            v-if="p.probationEndsOn"
                            class="text-muted-foreground"
                        >
                            Probation to {{ dateLabel(p.probationEndsOn) }}
                            <template v-if="p.confirmedOn">
                                · confirmed
                                {{ dateLabel(p.confirmedOn) }}</template
                            >
                        </p>
                        <p
                            v-if="p.endReasonLabel"
                            class="text-muted-foreground"
                        >
                            Ended: {{ p.endReasonLabel
                            }}<template v-if="p.hasNote"> (note kept)</template>
                        </p>
                    </div>
                    <Button
                        v-if="file.canManage"
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="openPeriod(p)"
                        >{{ p.endsOn ? 'Change' : 'Change or end' }}</Button
                    >
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">
                Ending a period changes nothing on the KPOne account. Ask the
                account administrator to deactivate it.
            </p>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold">Position history</h2>
                <Button
                    v-if="file.canManage"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="add-position"
                    @click="openPosition"
                    >Add position</Button
                >
            </div>
            <EmptyState
                v-if="!file.positions.length"
                title="No position recorded."
                description="Add a title and the date it applies from. The directory job title is not changed."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="(pos, i) in file.positions"
                    :key="i"
                    class="flex flex-col gap-0.5 p-4 text-sm"
                >
                    <span class="font-medium">{{ pos.title }}</span>
                    <span class="text-muted-foreground">
                        From {{ dateLabel(pos.fromDate) }}
                        <template v-if="pos.department">
                            · {{ pos.department }}</template
                        >
                        <template v-if="pos.reportsTo">
                            · reports to {{ pos.reportsTo }}</template
                        >
                    </span>
                </li>
            </ul>

            <details class="rounded-xl border bg-card p-4 text-sm">
                <summary
                    class="min-h-11 cursor-pointer content-center font-medium lg:min-h-0"
                >
                    Recent changes
                </summary>
                <p
                    v-if="!file.changes.length"
                    class="mt-2 text-muted-foreground"
                >
                    No changes yet.
                </p>
                <ul v-else class="mt-2 flex flex-col gap-1">
                    <li v-for="(c, i) in file.changes" :key="i">
                        {{ timeLabel(c.at) }} · {{ c.by }} · {{ c.area }}:
                        {{ fieldWords(c.fields) }}
                    </li>
                </ul>
                <p class="mt-2 text-xs text-muted-foreground">
                    Field names only. Values are never kept in the change list.
                </p>
            </details>
        </section>

        <!-- Personal -->
        <section
            v-if="file.tab === 'personal'"
            aria-labelledby="personal-title"
            class="flex flex-col gap-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="personal-title" class="text-base font-semibold">
                    Personal details
                </h2>
                <Button
                    v-if="file.canManage && w.kind === 'staff'"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="edit-personal"
                    @click="openPersonal"
                    >Edit</Button
                >
            </div>
            <dl
                class="grid gap-4 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2"
            >
                <template v-if="w.kind === 'locum'">
                    <div>
                        <dt class="text-muted-foreground">Contact phone</dt>
                        <dd>
                            <MaskedValue
                                v-if="w.hasLocumPhone"
                                label="Contact phone"
                                field="contact phone"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="locum_phone"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                </template>
                <template v-else>
                    <div>
                        <dt class="text-muted-foreground">Legal name</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.legalName"
                                label="Legal name"
                                field="legal name"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="legal_name"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Preferred name</dt>
                        <dd>{{ file.personal.preferredName ?? notGiven }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Identity number</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.identity"
                                label="Identity number"
                                field="identity number"
                                :mask="file.personal.identity.mask"
                                :last4="file.personal.identity.last4"
                                :worker-id="w.id"
                                show-field="identity"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Date of birth</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.dateOfBirth"
                                label="Date of birth"
                                field="date of birth"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="date_of_birth"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Nationality</dt>
                        <dd>{{ file.personal.nationality ?? notGiven }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Address</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.address"
                                label="Address"
                                field="address"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="address"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Personal phone</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.personalPhone"
                                label="Personal phone"
                                field="personal phone"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="personal_phone"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Emergency contact</dt>
                        <dd>
                            <MaskedValue
                                v-if="file.personal.emergencyContact"
                                label="Emergency contact"
                                field="emergency contact"
                                mask="Hidden"
                                :worker-id="w.id"
                                show-field="emergency_contact"
                                :reset-key="file.tab"
                            />
                            <span v-else>{{ notGiven }}</span>
                        </dd>
                    </div>
                </template>
            </dl>

            <section
                v-if="personalCorrections.length"
                aria-labelledby="personal-corrections"
                class="flex flex-col gap-3"
            >
                <h3 id="personal-corrections" class="text-sm font-semibold">
                    Corrections waiting
                </h3>
                <div
                    v-for="c in personalCorrections"
                    :key="c.id"
                    class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                    data-test="correction"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ c.kindLabel }}</span>
                        <StatusBadge
                            status="pending"
                            label="Correction waiting"
                            tone="warning"
                        />
                    </div>
                    <p>Recorded: {{ c.recorded }} · New: {{ c.new }}</p>
                    <MaskedValue
                        v-if="c.new === 'Hidden' || c.kind === 'identity'"
                        label="Requested value"
                        field="requested value"
                        :mask="c.new"
                        :worker-id="w.id"
                        show-field="correction"
                        :extra="{ correction_id: c.id }"
                        :reset-key="file.tab"
                    />
                    <p class="text-muted-foreground">
                        {{ c.reasonLabel
                        }}<template v-if="c.hasNote"> · note given</template> ·
                        asked by {{ c.askedBy }}
                    </p>
                    <div v-if="file.canManage" class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            @click="startCorrectionDecision(c, 'apply')"
                            >Apply</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            @click="startCorrectionDecision(c, 'reject')"
                            >Reject</Button
                        >
                    </div>
                </div>
            </section>
        </section>

        <!-- Bank -->
        <section
            v-if="file.tab === 'bank'"
            aria-labelledby="bank-title"
            class="flex flex-col gap-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="bank-title" class="text-base font-semibold">
                    Bank details
                </h2>
                <Button
                    v-if="
                        file.canManage &&
                        !file.bank.waiting &&
                        w.kind === 'staff'
                    "
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="enter-bank"
                    @click="openBank"
                    >{{
                        file.bank.confirmed
                            ? 'Change bank details'
                            : 'Enter bank details'
                    }}</Button
                >
            </div>
            <div
                v-if="file.bank.confirmed"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                data-test="bank-confirmed"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{
                        file.bank.confirmed.bankName
                    }}</span>
                    <StatusBadge
                        status="confirmed"
                        label="Confirmed"
                        tone="success"
                    />
                </div>
                <MaskedValue
                    label="Account number"
                    field="account number"
                    :mask="file.bank.confirmed.mask"
                    :last4="file.bank.confirmed.mask.slice(-4)"
                    :worker-id="w.id"
                    show-field="bank_account"
                    :extra="{ bank_id: file.bank.confirmed.id }"
                    :reset-key="file.tab"
                />
            </div>
            <EmptyState
                v-else-if="!file.bank.waiting"
                title="No bank details yet."
                description="Enter them here; someone else confirms them before they are used."
            />
            <div
                v-if="file.bank.waiting"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                data-test="bank-waiting"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">Bank change</span>
                    <StatusBadge
                        status="waiting"
                        label="Waiting for confirmation"
                        tone="warning"
                    />
                </div>
                <p data-test="bank-compare">
                    Recorded:
                    {{
                        file.bank.confirmed
                            ? `${file.bank.confirmed.bankName} ${file.bank.confirmed.mask.replace('Account ', '')}`
                            : notGiven
                    }}
                    · New: {{ file.bank.waiting.bankName }}
                    {{ file.bank.waiting.mask.replace('Account ', '') }}
                </p>
                <MaskedValue
                    v-if="!file.bank.waiting.enteredByMe && file.canManage"
                    label="New account number"
                    field="new account number"
                    :mask="file.bank.waiting.mask"
                    :last4="file.bank.waiting.mask.slice(-4)"
                    :worker-id="w.id"
                    show-field="bank_account"
                    :extra="{ bank_id: file.bank.waiting.id }"
                    :reset-key="file.tab"
                    data-test="bank-waiting-show"
                    @shown="waitingShown = true"
                />
                <p class="text-muted-foreground">
                    Entered by {{ file.bank.waiting.enteredBy }},
                    {{ timeLabel(file.bank.waiting.enteredAt) }}
                </p>
                <template v-if="file.bank.waiting.enteredByMe">
                    <p class="text-muted-foreground" data-test="someone-else">
                        Someone else confirms this.
                    </p>
                    <div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            data-test="bank-withdraw"
                            @click="startBankDecision('withdraw')"
                            >Withdraw</Button
                        >
                    </div>
                </template>
                <div v-else-if="file.canManage" class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        data-test="bank-confirm"
                        :disabled="!waitingShown"
                        @click="startBankDecision('confirm')"
                        >Confirm</Button
                    >
                    <p
                        v-if="!waitingShown"
                        class="w-full text-xs text-muted-foreground"
                        data-test="show-before-confirm"
                    >
                        Show the new account number to check it before you
                        confirm.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        data-test="bank-reject"
                        @click="startBankDecision('reject')"
                        >Reject</Button
                    >
                </div>
            </div>
            <div
                v-for="c in bankCorrections"
                :key="c.id"
                class="flex flex-col gap-2 rounded-xl border bg-card p-4 text-sm"
                data-test="correction"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">Bank correction from staff</span>
                    <StatusBadge
                        status="pending"
                        label="Correction waiting"
                        tone="warning"
                    />
                </div>
                <p>Recorded: {{ c.recorded }} · New: {{ c.new }}</p>
                <MaskedValue
                    v-if="file.canManage"
                    label="Requested account"
                    field="requested account"
                    :mask="c.new"
                    :last4="c.new.slice(-4)"
                    :worker-id="w.id"
                    show-field="correction"
                    :extra="{ correction_id: c.id }"
                    :reset-key="file.tab"
                />
                <p class="text-muted-foreground">
                    {{ c.reasonLabel }} · asked by {{ c.askedBy }}. Applying it
                    makes a bank change that a third person confirms.
                </p>
                <div v-if="file.canManage" class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="startCorrectionDecision(c, 'apply')"
                        >Apply</Button
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        class="max-lg:min-h-11"
                        @click="startCorrectionDecision(c, 'reject')"
                        >Reject</Button
                    >
                </div>
            </div>
        </section>

        <!-- Documents (HR-4b) -->
        <section
            v-if="file.tab === 'documents'"
            aria-labelledby="documents-title"
            class="flex flex-col gap-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="documents-title" class="text-base font-semibold">
                    Documents
                </h2>
                <Button
                    v-if="file.canManage"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="add-document"
                    @click="openUpload"
                    >Add a document</Button
                >
            </div>
            <ErrorState
                v-if="errors.document && !removal.document"
                :message="errors.document"
            />
            <DocumentList
                :documents="file.documents"
                :worker-id="w.id"
                :can-open="file.canOpenDocuments"
                :can-remove="file.canManage"
                empty-title="No documents yet."
                empty-description="Add a contract, offer letter, identity copy, certificate or resignation letter. PDF, JPG or PNG, up to 10 MB."
                @remove="startRemoval"
            />
        </section>

        <!-- Checklist (HR-4b) -->
        <section
            v-if="file.tab === 'checklist' && file.checklist"
            aria-labelledby="checklist-title"
            class="flex flex-col gap-4"
        >
            <h2 id="checklist-title" class="text-base font-semibold">
                Onboarding and offboarding
            </h2>
            <ChecklistTab
                :periods="file.checklist"
                :worker-id="w.id"
                :can-manage="file.canManage"
            />
        </section>

        <!-- Upload sheet -->
        <Sheet v-model:open="upload.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="document-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Add a document</SheetTitle>
                    <SheetDescription
                        >PDF, JPG or PNG, up to 10 MB. The file is checked and
                        stored encrypted; its name is not
                        kept.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendUpload"
                >
                    <div class="grid gap-1.5">
                        <Label for="document-category"
                            ><span id="document-category-label"
                                >Type *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="document-category"
                            labelledby="document-category-label"
                            v-model="upload.category"
                            trigger-class="max-lg:h-11"
                            label="Type"
                            placeholder="Choose the type"
                            :options="file.options.documentCategories"
                            :invalid="!!errors.category"
                        />
                        <InputError :message="errors.category" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="document-issued"
                            >Issue date (optional)</Label
                        >
                        <DateField
                            id="document-issued"
                            v-model="upload.issuedOn"
                        />
                        <InputError :message="errors.issued_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="document-expires"
                            >Expiry date (optional)</Label
                        >
                        <DateField
                            id="document-expires"
                            v-model="upload.expiresOn"
                        />
                        <InputError :message="errors.expires_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="document-file">File *</Label>
                        <input
                            id="document-file"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="min-h-11 text-sm file:mr-3 file:rounded-md file:border file:bg-card file:px-3 file:py-2"
                            :aria-invalid="!!errors.file"
                            @change="onFile"
                        />
                        <InputError :message="errors.file" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="upload.processing || !upload.file"
                        data-test="document-save"
                    >
                        {{ upload.processing ? 'Adding…' : 'Add document' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Remove document dialog -->
        <OperationalConfirmDialog
            :open="removal.document !== null"
            title="Remove this document?"
            :description="
                removal.document
                    ? `${removal.document.categoryLabel}. The file is deleted; the record that it existed stays.`
                    : ''
            "
            confirm-label="Remove"
            processing-label="Removing…"
            destructive
            :processing="removal.processing"
            :error="errors.reason ?? errors.document ?? errors.records"
            @update:open="(v: boolean) => !v && (removal.document = null)"
            @confirm="sendRemoval"
        >
            <div class="grid gap-1.5">
                <Label for="document-remove-reason"
                    ><span id="document-remove-reason-label"
                        >Why it is removed *</span
                    ></Label
                >
                <OperationalSelect
                    id="document-remove-reason"
                    labelledby="document-remove-reason-label"
                    v-model="removal.reason"
                    trigger-class="max-lg:h-11"
                    label="Why it is removed"
                    placeholder="Choose a reason"
                    :options="file.options.documentRemovalReasons"
                    :invalid="!!errors.reason"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Period sheet -->
        <Sheet v-model:open="period.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="period-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        period.editing
                            ? 'Change employment period'
                            : 'Add employment period'
                    }}</SheetTitle>
                    <SheetDescription
                        >Ending a period changes nothing on the KPOne
                        account.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="savePeriod"
                >
                    <ErrorState
                        v-if="errors.records"
                        :message="errors.records"
                    />
                    <div class="grid gap-1.5">
                        <Label for="period-type">Type</Label>
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="period-type"
                            v-model="period.type"
                            label="Type"
                            placeholder="Choose the type"
                            :options="file.options.types"
                            :invalid="!!errors.type"
                        />
                        <InputError :message="errors.type" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="period-start">Start date</Label>
                        <DateField
                            id="period-start"
                            v-model="period.startsOn"
                            :aria-invalid="!!errors.starts_on"
                        />
                        <InputError :message="errors.starts_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="period-probation"
                            >Probation ends (optional)</Label
                        >
                        <DateField
                            id="period-probation"
                            v-model="period.probationEndsOn"
                        />
                        <InputError :message="errors.probation_ends_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="period-confirmed"
                            >Confirmed on (optional)</Label
                        >
                        <DateField
                            id="period-confirmed"
                            v-model="period.confirmedOn"
                        />
                        <InputError :message="errors.confirmed_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="period-end">End date (optional)</Label>
                        <DateField
                            id="period-end"
                            v-model="period.endsOn"
                            :aria-invalid="!!errors.ends_on"
                        />
                        <InputError :message="errors.ends_on" />
                    </div>
                    <div v-if="period.endsOn" class="grid gap-1.5">
                        <Label for="period-end-reason">Why it ended</Label>
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="period-end-reason"
                            v-model="period.endReason"
                            label="Why it ended"
                            placeholder="Choose a reason"
                            :options="file.options.endReasons"
                            :invalid="!!errors.end_reason"
                        />
                        <InputError :message="errors.end_reason" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="period-note">Note (optional)</Label>
                        <Textarea
                            id="period-note"
                            v-model="period.note"
                            maxlength="200"
                            aria-describedby="period-note-help"
                        />
                        <p
                            id="period-note-help"
                            class="text-xs text-muted-foreground"
                        >
                            Do not write medical details here.
                            <template v-if="period.editing?.hasNote">
                                Leave empty to keep the note.</template
                            >
                        </p>
                        <InputError :message="errors.note" />
                    </div>
                    <div v-if="needsEditReason" class="grid gap-1.5">
                        <Label for="period-edit-reason"
                            >Why this is being changed</Label
                        >
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="period-edit-reason"
                            v-model="period.editReason"
                            label="Why this is being changed"
                            placeholder="Choose a reason"
                            :options="file.options.editReasons"
                            :invalid="!!errors.edit_reason"
                        />
                        <InputError :message="errors.edit_reason" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="period.processing"
                        data-test="period-save"
                    >
                        {{ period.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Position sheet -->
        <Sheet v-model:open="position.open">
            <SheetContent class="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Add position</SheetTitle>
                    <SheetDescription
                        >The directory job title in Staff and access is not
                        changed.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="savePosition"
                >
                    <div class="grid gap-1.5">
                        <Label for="position-title">Title</Label>
                        <Input
                            id="position-title"
                            v-model="position.title"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.title"
                        />
                        <InputError :message="errors.title" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="position-department"
                            >Department (optional)</Label
                        >
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="position-department"
                            v-model="position.departmentId"
                            label="Department (optional)"
                            :options="[
                                { value: '', label: 'None' },
                                ...file.options.departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                })),
                            ]"
                        />
                        <InputError :message="errors.department_id" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="position-reports-to"
                            >Reports to (optional)</Label
                        >
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="position-reports-to"
                            v-model="position.reportsTo"
                            label="Reports to (optional)"
                            :options="[
                                { value: '', label: 'Nobody' },
                                ...file.workers.map((x) => ({
                                    value: x.id,
                                    label: x.name,
                                })),
                            ]"
                        />
                        <InputError :message="errors.reports_to" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="position-from">Applies from</Label>
                        <DateField
                            id="position-from"
                            v-model="position.fromDate"
                            :aria-invalid="!!errors.from_date"
                        />
                        <InputError :message="errors.from_date" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="position.processing"
                    >
                        {{ position.processing ? 'Saving…' : 'Add position' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Personal sheet -->
        <Sheet v-model:open="personal.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="personal-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Edit personal details</SheetTitle>
                    <SheetDescription
                        >Hidden values are not filled in. Leave a field empty to
                        keep what is recorded.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="savePersonal"
                >
                    <ErrorState
                        v-if="errors.records"
                        :message="errors.records"
                    />
                    <div class="grid gap-1.5">
                        <Label for="personal-legal">Legal name</Label>
                        <Input
                            id="personal-legal"
                            v-model="personal.legalName"
                            autocomplete="off"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.legal_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="personal-preferred">Preferred name</Label>
                        <Input
                            id="personal-preferred"
                            v-model="personal.preferredName"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.preferred_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="personal-identity-kind"
                            >Identity document</Label
                        >
                        <OperationalSelect
                            trigger-class="max-lg:h-11"
                            id="personal-identity-kind"
                            v-model="personal.identityKind"
                            label="Identity document"
                            :options="[
                                { value: '', label: 'Keep what is recorded' },
                                { value: 'ic', label: 'IC (MyKad)' },
                                { value: 'passport', label: 'Passport' },
                            ]"
                        />
                        <InputError :message="errors.identity_kind" />
                    </div>
                    <template v-if="personal.identityKind">
                        <div class="grid gap-1.5">
                            <Label for="personal-identity-number">{{
                                personal.identityKind === 'ic'
                                    ? 'IC number'
                                    : 'Passport number'
                            }}</Label>
                            <Input
                                id="personal-identity-number"
                                v-model="personal.identityNumber"
                                autocomplete="off"
                                class="max-lg:h-11"
                                :aria-invalid="!!errors.identity_number"
                            />
                            <InputError :message="errors.identity_number" />
                        </div>
                        <div
                            v-if="personal.identityKind === 'passport'"
                            class="grid gap-1.5"
                        >
                            <Label for="personal-passport-country"
                                >Issuing country (2-letter code)</Label
                            >
                            <Input
                                id="personal-passport-country"
                                v-model="personal.passportCountry"
                                maxlength="2"
                                class="max-lg:h-11"
                            />
                            <InputError :message="errors.passport_country" />
                        </div>
                    </template>
                    <div class="grid gap-1.5">
                        <Label for="personal-dob">Date of birth</Label>
                        <DateField
                            id="personal-dob"
                            v-model="personal.dateOfBirth"
                        />
                        <InputError :message="errors.date_of_birth" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="personal-nationality">Nationality</Label>
                        <Input
                            id="personal-nationality"
                            v-model="personal.nationality"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.nationality" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="personal-phone">Personal phone</Label>
                        <Input
                            id="personal-phone"
                            v-model="personal.personalPhone"
                            type="tel"
                            class="max-lg:h-11"
                        />
                        <InputError :message="errors.personal_phone" />
                    </div>
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
                            <Label :for="`personal-${part.key}`">{{
                                part.label
                            }}</Label>
                            <Input
                                :id="`personal-${part.key}`"
                                v-model="personal[part.key]"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="errors[`address.${part.key}`]"
                            />
                        </div>
                    </fieldset>
                    <fieldset class="grid gap-3">
                        <legend class="mb-1 text-sm font-semibold">
                            Emergency contact
                        </legend>
                        <div class="grid gap-1.5">
                            <Label for="personal-em-name">Name</Label>
                            <Input
                                id="personal-em-name"
                                v-model="personal.emName"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="errors['emergency_contact.name']"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="personal-em-rel">Relationship</Label>
                            <Input
                                id="personal-em-rel"
                                v-model="personal.emRelationship"
                                class="max-lg:h-11"
                            />
                            <InputError
                                :message="
                                    errors['emergency_contact.relationship']
                                "
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="personal-em-phone">Phone</Label>
                            <Input
                                id="personal-em-phone"
                                v-model="personal.emPhone"
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
                        :disabled="personal.processing"
                        data-test="personal-save"
                    >
                        {{ personal.processing ? 'Saving…' : 'Save' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Bank sheet -->
        <Sheet v-model:open="bankForm.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="bank-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        file.bank.confirmed
                            ? 'Change bank details'
                            : 'Enter bank details'
                    }}</SheetTitle>
                    <SheetDescription
                        >Saved as waiting. Someone other than you and the staff
                        member confirms it.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveBank"
                >
                    <ErrorState
                        v-if="errors.bank || errors.records"
                        :message="errors.bank ?? errors.records ?? ''"
                    />
                    <div class="grid gap-1.5">
                        <Label for="bank-name">Bank name</Label>
                        <Input
                            id="bank-name"
                            v-model="bankForm.bankName"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.bank_name"
                        />
                        <InputError :message="errors.bank_name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="bank-holder">Account holder</Label>
                        <Input
                            id="bank-holder"
                            v-model="bankForm.holder"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.holder"
                        />
                        <InputError :message="errors.holder" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="bank-account">Account number</Label>
                        <Input
                            id="bank-account"
                            v-model="bankForm.account"
                            inputmode="numeric"
                            autocomplete="off"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.account"
                        />
                        <InputError :message="errors.account" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="bankForm.processing"
                        data-test="bank-save"
                    >
                        {{
                            bankForm.processing
                                ? 'Saving…'
                                : 'Save for confirmation'
                        }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <!-- Bank decision dialog -->
        <OperationalConfirmDialog
            :open="bankDecision.open"
            :title="bankDialog.title"
            :description="bankDialog.description"
            :confirm-label="bankDialog.label"
            :processing-label="bankDialog.busy"
            :processing="bankDecision.processing"
            :error="errors.reason ?? errors.bank ?? errors.records"
            @update:open="(v: boolean) => (bankDecision.open = v)"
            @confirm="sendBankDecision"
        >
            <div v-if="bankDecision.decision === 'reject'" class="grid gap-1.5">
                <Label for="bank-reject-reason">Why it is rejected</Label>
                <OperationalSelect
                    trigger-class="max-lg:h-11"
                    id="bank-reject-reason"
                    v-model="bankDecision.reason"
                    label="Why it is rejected"
                    placeholder="Choose a reason"
                    :options="file.options.bankRejectReasons"
                    :invalid="!!errors.reason"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Correction decision dialog -->
        <OperationalConfirmDialog
            :open="correctionDecision.correction !== null"
            :title="
                correctionDecision.decision === 'apply'
                    ? 'Apply this correction?'
                    : 'Reject this correction?'
            "
            :description="
                correctionDecision.correction
                    ? `${correctionDecision.correction.kindLabel}. Recorded: ${correctionDecision.correction.recorded} · New: ${correctionDecision.correction.new}.`
                    : ''
            "
            :confirm-label="
                correctionDecision.decision === 'apply' ? 'Apply' : 'Reject'
            "
            :processing-label="
                correctionDecision.decision === 'apply'
                    ? 'Applying…'
                    : 'Rejecting…'
            "
            :processing="correctionDecision.processing"
            :error="
                errors.reason ??
                errors.correction ??
                errors.identity_number ??
                errors.bank ??
                errors.records
            "
            @update:open="
                (v: boolean) => !v && (correctionDecision.correction = null)
            "
            @confirm="sendCorrectionDecision"
        >
            <div
                v-if="correctionDecision.decision === 'reject'"
                class="grid gap-1.5"
            >
                <Label for="correction-reject-reason">Why it is rejected</Label>
                <OperationalSelect
                    trigger-class="max-lg:h-11"
                    id="correction-reject-reason"
                    v-model="correctionDecision.reason"
                    label="Why it is rejected"
                    placeholder="Choose a reason"
                    :options="file.options.correctionRejectReasons"
                    :invalid="!!errors.reason"
                />
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
