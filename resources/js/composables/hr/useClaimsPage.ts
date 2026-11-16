import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import type {
    ApprovalRow,
    CategoryOption,
    ClaimRow,
    Option,
    PayRow,
} from '@/components/hr/claims/types';
import { myr } from '@/types/billing';

/**
 * HR staff claims page state (HR-6): every request, lock version and idempotency key of /hr/claims. Shared by the
 * Classic page (`pages/HR/Claims.vue`) and Version 2 (`pages/HR/V2/Claims.vue`), so both send identical requests.
 * Moved out of the Classic page unchanged; the Classic template did not change. KPOne pays nothing: Pay records that
 * someone paid outside KPOne.
 */
export type Tab = 'my' | 'approvals' | 'pay' | 'monthly' | 'settings';
export type Category = {
    id: string;
    name: string;
    needsReceipt: boolean;
    perClaimLimitSen: number | null;
    monthlyLimitSen: number | null;
    isActive: boolean;
    version: number;
    lockVersion: number;
    versions: {
        version: number;
        name: string;
        needsReceipt: boolean;
        perClaimLimitSen: number | null;
        monthlyLimitSen: number | null;
        isActive: boolean;
        by: string;
        on: string;
    }[];
};
export type MonthlyRow = {
    branch: string;
    category: string;
    claims: number;
    totalSen: number;
    paidSen: number;
    unpaidSen: number;
};

export type ClaimsPageProps = {
    claims: {
        tab: Tab;
        tabs: Tab[];
        branch: string | null;
        today: string;
        setUp: boolean;
        options: {
            categories: CategoryOption[];
            rejectReasons: Option[];
            reversalReasons: Option[];
            methods: Option[];
            suggestedCategories: string[];
            suggestedDaysBack: number;
            daysBack: number | null;
            maxReceipts: number;
        };
        my: ClaimRow[] | null;
        approvals: { claims: ApprovalRow[] } | null;
        pay: { claims: PayRow[] } | null;
        monthly: {
            month: string;
            monthLabel: string;
            rows: MonthlyRow[];
            total: Omit<MonthlyRow, 'branch' | 'category'>;
        } | null;
        settings: {
            version: number;
            directorLevelSen: number | null;
            daysBack: number | null;
            categories: Category[];
        } | null;
    };
};

export function useClaimsPage(props: ClaimsPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const tabLabels: Record<Tab, string> = {
        my: 'My claims',
        approvals: 'Approvals',
        pay: 'Pay',
        monthly: 'Monthly',
        settings: 'Settings',
    };
    const tabs = computed(() =>
        props.claims.tabs.map((value) => ({ value, label: tabLabels[value] })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/claims', { tab: value }, { preserveScroll: true });
    const rm = (sen: number | null) =>
        sen === null ? '' : (sen / 100).toFixed(2);

    // ---- My claims ----
    const sheet = reactive({ open: false, from: null as ClaimRow | null });
    const newClaim = () => Object.assign(sheet, { open: true, from: null });
    const resend = (row: ClaimRow) =>
        Object.assign(sheet, { open: true, from: row });
    const withdrawing = ref<ClaimRow | null>(null);
    const withdrawKey = ref('');
    const busy = ref(false);
    const askWithdraw = (row: ClaimRow) => {
        withdrawing.value = row;
        withdrawKey.value = crypto.randomUUID();
    };
    const withdraw = () => {
        const row = withdrawing.value;

        if (!row) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/claims/${row.id}/withdraw`,
            {
                expected_lock_version: row.lockVersion,
                idempotency_key: withdrawKey.value,
            },
            {
                preserveScroll: true,
                onSuccess: () => (withdrawing.value = null),
                onFinish: () => (busy.value = false),
            },
        );
    };

    // ---- Approvals ----
    const decision = reactive({
        row: null as ApprovalRow | null,
        kind: 'approve' as 'approve' | 'reject',
        reason: '',
        note: '',
        key: '',
    });
    const askDecision = (row: ApprovalRow, kind: 'approve' | 'reject') =>
        Object.assign(decision, {
            row,
            kind,
            reason: '',
            note: '',
            key: crypto.randomUUID(),
        });
    const decide = () => {
        const row = decision.row;

        if (!row) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/claims/${row.id}/${decision.kind}`,
            {
                reason: decision.kind === 'reject' ? decision.reason : null,
                note: decision.kind === 'reject' ? decision.note : null,
                expected_lock_version: row.lockVersion,
                idempotency_key: decision.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (decision.row = null),
                onFinish: () => (busy.value = false),
            },
        );
    };
    const limitLine = (row: ApprovalRow) =>
        [
            row.perClaimLimitSen
                ? `Limit ${myr(row.perClaimLimitSen)} a claim`
                : '',
            row.monthlyLimitSen ? `${myr(row.monthlyLimitSen)} a month` : '',
            `${myr(row.monthTotalSen)} in ${row.category} this month`,
        ]
            .filter((p) => p)
            .join(' · ');

    // ---- Pay ----
    const payment = reactive({
        row: null as PayRow | null,
        method: '',
        paidOn: '',
        reference: '',
        key: '',
    });
    const askPay = (row: PayRow) =>
        Object.assign(payment, {
            row,
            method: '',
            paidOn: props.claims.today,
            reference: '',
            key: crypto.randomUUID(),
        });
    const pay = () => {
        const row = payment.row;

        if (!row) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/claims/${row.id}/pay`,
            {
                method: payment.method,
                paid_on: payment.paidOn,
                reference: payment.reference,
                expected_lock_version: row.lockVersion,
                idempotency_key: payment.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (payment.row = null),
                onFinish: () => (busy.value = false),
            },
        );
    };
    const reversal = reactive({
        row: null as PayRow | null,
        reason: '',
        key: '',
    });
    const askReverse = (row: PayRow) =>
        Object.assign(reversal, { row, reason: '', key: crypto.randomUUID() });
    const reverse = () => {
        const row = reversal.row;

        if (!row) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/claims/${row.id}/reverse`,
            {
                reason: reversal.reason,
                expected_lock_version: row.lockVersion,
                idempotency_key: reversal.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (reversal.row = null),
                onFinish: () => (busy.value = false),
            },
        );
    };

    // ---- Monthly ----
    const month = ref(props.claims.monthly?.month ?? '');
    watch(
        () => props.claims.monthly?.month,
        (m) => (month.value = m ?? ''),
    );
    const showMonth = () =>
        router.get(
            '/hr/claims',
            { tab: 'monthly', month: month.value },
            { preserveScroll: true },
        );

    // ---- Settings ----
    const settingsForm = reactive({
        level: '',
        days: '',
        key: '',
        processing: false,
    });
    watch(
        () => props.claims.settings,
        (s) =>
            Object.assign(settingsForm, {
                level: rm(s?.directorLevelSen ?? null),
                days: s?.daysBack ? String(s.daysBack) : '',
                key: crypto.randomUUID(),
            }),
        { immediate: true },
    );
    const saveSettings = () => {
        settingsForm.processing = true;
        router.post(
            '/hr/claims/settings',
            {
                director_level: settingsForm.level,
                days_back: settingsForm.days,
                expected_version: props.claims.settings?.version ?? 0,
                idempotency_key: settingsForm.key,
            },
            {
                preserveScroll: true,
                onFinish: () => (settingsForm.processing = false),
            },
        );
    };
    const categoryForm = reactive({
        open: false,
        category: null as Category | null,
        name: '',
        needsReceipt: true,
        perClaim: '',
        monthly: '',
        isActive: true,
        key: '',
        processing: false,
    });
    const editCategory = (category: Category | null, name = '') =>
        Object.assign(categoryForm, {
            open: true,
            category,
            name: category?.name ?? name,
            needsReceipt: category?.needsReceipt ?? true,
            perClaim: rm(category?.perClaimLimitSen ?? null),
            monthly: rm(category?.monthlyLimitSen ?? null),
            isActive: category?.isActive ?? true,
            key: crypto.randomUUID(),
        });
    const saveCategory = () => {
        categoryForm.processing = true;
        router.post(
            '/hr/claims/categories',
            {
                category_id: categoryForm.category?.id ?? null,
                name: categoryForm.name,
                needs_receipt: categoryForm.needsReceipt,
                per_claim_limit: categoryForm.perClaim,
                monthly_limit: categoryForm.monthly,
                is_active: categoryForm.isActive,
                ...(categoryForm.category
                    ? {
                          expected_lock_version:
                              categoryForm.category.lockVersion,
                      }
                    : {}),
                idempotency_key: categoryForm.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (categoryForm.open = false),
                onFinish: () => (categoryForm.processing = false),
            },
        );
    };
    const unusedSuggestions = computed(() =>
        props.claims.options.suggestedCategories.filter(
            (s) =>
                !(props.claims.settings?.categories ?? []).some(
                    (c) => c.name.toLowerCase() === s.toLowerCase(),
                ),
        ),
    );
    const limitWords = (c: {
        perClaimLimitSen: number | null;
        monthlyLimitSen: number | null;
    }) =>
        [
            c.perClaimLimitSen
                ? `${myr(c.perClaimLimitSen)} a claim`
                : 'No limit a claim',
            c.monthlyLimitSen
                ? `${myr(c.monthlyLimitSen)} a month`
                : 'no monthly limit',
        ].join(' · ');
    const dialogOpen = computed(
        () =>
            sheet.open ||
            !!withdrawing.value ||
            !!decision.row ||
            !!payment.row ||
            !!reversal.row ||
            categoryForm.open,
    );

    return {
        page,
        errors,
        tabs,
        selectTab,
        rm,
        sheet,
        newClaim,
        resend,
        withdrawing,
        withdrawKey,
        busy,
        askWithdraw,
        withdraw,
        decision,
        askDecision,
        decide,
        limitLine,
        payment,
        askPay,
        pay,
        reversal,
        askReverse,
        reverse,
        month,
        showMonth,
        settingsForm,
        saveSettings,
        categoryForm,
        editCategory,
        saveCategory,
        unusedSuggestions,
        limitWords,
        dialogOpen,
    };
}
