import type { FormDataConvertible } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import type {
    ChecklistPeriod,
    RecordOptions,
    StaffDocument,
    WorkerHeader,
} from '@/components/hr/records/types';

/**
 * HR-4a /hr/records/{worker} staff file state: Employment, Personal, Bank, Documents and Checklist. Every request,
 * lock version and idempotency key lives here, shared by the Classic page (`pages/HR/StaffFile.vue`) and Version 2
 * (`pages/HR/V2/StaffFile.vue`), so both send identical requests. Moved out of the Classic page unchanged; the Classic
 * template did not change. Sensitive values arrive masked; only the audited Show (a separate request, never made here)
 * releases one.
 */
export type Tab =
    'employment' | 'personal' | 'bank' | 'documents' | 'checklist';
export type Period = {
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
export type Bank = {
    id: string;
    bankName: string;
    mask: string;
    enteredBy: string;
    enteredAt: string;
    lockVersion: number;
    enteredByMe?: boolean;
};
export type Correction = {
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

export type StaffFilePageProps = {
    file: {
        tab: Tab;
        options: RecordOptions;
        canManage: boolean;
        manageRefusal: string | null;
        /** HR-21: the staff member's work permits that expire soon or have expired, in words (managers only). */
        workPermits: string[];
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
};

export function useStaffFilePage(props: StaffFilePageProps) {
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
    const startCorrectionDecision = (
        c: Correction,
        decision: 'apply' | 'reject',
    ) =>
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

    return {
        page,
        errors,
        w,
        signIn,
        askSignIn,
        cancelSignIn,
        tabs,
        selectTab,
        notGiven,
        timeLabel,
        fieldWords,
        period,
        openPeriod,
        needsEditReason,
        savePeriod,
        position,
        openPosition,
        savePosition,
        personal,
        openPersonal,
        savePersonal,
        personalCorrections,
        bankCorrections,
        correctionDecision,
        startCorrectionDecision,
        sendCorrectionDecision,
        upload,
        openUpload,
        onFile,
        sendUpload,
        removal,
        startRemoval,
        sendRemoval,
        bankForm,
        openBank,
        saveBank,
        bankDecision,
        waitingShown,
        startBankDecision,
        sendBankDecision,
        bankDialog,
    };
}
