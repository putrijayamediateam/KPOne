import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import type { PayslipRow } from '@/components/hr/payroll/types';
import type {
    Address,
    EmergencyContact,
    RecordOptions,
    StaffDocument,
    WorkerHeader,
} from '@/components/hr/records/types';
import { dateLabel } from '@/components/hr/records/types';

/**
 * HR-4a /hr/records page state: My HR details, Staff files and Settings. Every request, lock version and idempotency
 * key lives here, shared by the Classic page (`pages/HR/Records.vue`) and Version 2 (`pages/HR/V2/Records.vue`), so both
 * send identical requests. Moved out of the Classic page unchanged; the Classic template did not change. Sensitive
 * values: your own are hidden on screen until you press Show (nothing is fetched); everyone else's stay masked until the
 * audited Show on their staff file.
 */
export type Tab = 'my' | 'files';
export type FileRow = WorkerHeader & {
    branchId: number | null;
    branch: string | null;
    attention: string[];
};
export type MyCorrection = {
    id: string;
    kind: string;
    kindLabel: string;
    status: 'pending' | 'applied' | 'rejected' | 'withdrawn';
    reasonLabel: string;
    decisionLabel: string | null;
    askedAt: string;
    lockVersion: number;
};
export type My = {
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

export type Retention = {
    category: string;
    label: string;
    days: number | null;
    pendingDays: number | null;
    pendingFrom: string | null;
    lockVersion: number;
};

export type RecordsPageProps = {
    records: {
        tab: Tab;
        tabs: Tab[];
        options: RecordOptions;
        canManage: boolean;
        my: My | null;
        payslips: PayslipRow[];
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
    // HR-5c: how many SOPs you still have to read.
    sopsToRead?: number;
    // HR-8: how many memos you still have to read.
    memosToRead?: number;
    // HR-10a: how many current letters HR issued to you.
    lettersMine?: number;
};

export function useRecordsPage(props: RecordsPageProps) {
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
                    : props.records.files &&
                        props.records.files.attentionCount > 0
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
        emergency: {
            name: '',
            relationship: '',
            phone: '',
        } as EmergencyContact,
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
                retentionDays[r.category] =
                    r.days === null ? '' : String(r.days);
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

    return {
        page,
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
    };
}
