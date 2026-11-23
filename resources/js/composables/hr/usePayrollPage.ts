import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import type {
    ExportRun,
    Option,
    Person,
    Profile,
    RunRow,
    StatutoryTable,
    MtdSchedule,
} from '@/components/hr/payroll/types';
import { day } from '@/components/hr/payroll/types';
import { myr } from '@/types/billing';

/**
 * HR-7a /hr/payroll page state: runs, exports, pay profiles, tables and settings. Every request, lock version and
 * idempotency key of `pages/HR/Payroll.vue` lives here. KPOne ships no statutory number, makes no payment and files
 * nothing.
 */
export type Tab = 'runs' | 'profiles' | 'tables' | 'settings' | 'exports';
export type Group = {
    id: string;
    name: string;
    payDay: number | null;
    isActive: boolean;
    lockVersion: number;
};
export type ItemType = {
    id: string;
    name: string;
    kind: string;
    kindLabel: string;
    version: number;
    countsEpf: boolean;
    countsSocso: boolean;
    countsEis: boolean;
    countsMtd: boolean;
    countsHrd: boolean;
    isActive: boolean;
    lockVersion: number;
};

export type PayrollPageProps = {
    payroll: {
        tab: Tab;
        tabs: Tab[];
        today: string;
        canApprove: boolean;
        options: {
            schemes: Option[];
            rounding: Option[];
            kinds: Option[];
            tableRejectReasons: Option[];
            profileRejectReasons: Option[];
            groups: Option[];
            allowanceTypes: Option[];
            deductionTypes: Option[];
            refundTypes: Option[];
            categories: { epf: string[]; socso: string[] };
            suggestedItemTypes: string[];
            maxRows: number;
            maxItems: number;
            mtdRounding: Option[];
            mtdCategories: Option[];
            mtdReliefs: Option[];
            maxMtdBands: number;
        };
        setUp: { groups: boolean; rates: boolean; checkedTables: boolean };
        runs: {
            runs: RunRow[];
            months: Option[];
            prepareRefusal: string | null;
        } | null;
        exports: { runs: ExportRun[] } | null;
        profiles: {
            people: Person[];
            waitingCount: number;
            missingCount: number;
        } | null;
        tables: {
            tables: StatutoryTable[];
            waitingCount: number;
            mtdSchedules: MtdSchedule[];
        } | null;
        settings: {
            lineTypes: {
                version: number;
                overtime: string | null;
                unpaidLeave: string | null;
                claim: string | null;
            };
            groups: Group[];
            itemTypes: ItemType[];
            rates: {
                version: number;
                daily: string | null;
                hourly: string | null;
                history: {
                    version: number;
                    daily: string;
                    hourly: string;
                    by: string;
                    at: string;
                }[];
            };
        } | null;
    };
};

export function usePayrollPage(props: PayrollPageProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.payroll ?? errors.value.records,
    );
    const tabLabels: Record<Tab, string> = {
        runs: 'Runs',
        profiles: 'Pay profiles',
        tables: 'Tables',
        settings: 'Settings',
        exports: 'Exports',
    };
    const tabs = computed(() =>
        props.payroll.tabs.map((value) => ({ value, label: tabLabels[value] })),
    );
    const selectTab = (value: string | number) =>
        router.get('/hr/payroll', { tab: value }, { preserveScroll: true });
    const money = (sen: number | null) =>
        sen === null ? 'Amount unavailable' : myr(sen);
    const rateLine = (p: Profile) =>
        p.payType === 'hourly'
            ? `Hourly · ${money(p.rateSen)} an hour`
            : `Monthly salary · ${money(p.rateSen)}`;
    const busy = ref(false);
    const anyDialog = computed(
        () =>
            decision.target !== null ||
            withdrawing.target !== null ||
            profileSheet.open ||
            tableSheet.value ||
            groupForm.open ||
            typeForm.open,
    );

    // ---- Pay profiles ----
    const profileSheet = reactive({
        open: false,
        person: null as Person | null,
    });
    const enterFor = (person: Person) =>
        Object.assign(profileSheet, { open: true, person });

    // ---- Tables ----
    const tableSheet = ref(false);
    const mtdSheet = ref(false);

    // ---- Decide and withdraw (profiles and tables) ----
    type Target = {
        kind: 'profile' | 'table' | 'mtd';
        id: string;
        lockVersion: number;
        label: string;
    };
    const decision = reactive({
        target: null as Target | null,
        action: 'check' as 'check' | 'reject',
        reason: '',
        key: '',
    });
    const askDecide = (target: Target, action: 'check' | 'reject') =>
        Object.assign(decision, {
            target,
            action,
            reason: '',
            key: crypto.randomUUID(),
        });
    const targetPath = (t: Target) =>
        t.kind === 'profile'
            ? 'profiles'
            : t.kind === 'mtd'
              ? 'mtd-schedules'
              : 'tables';
    const noun = (t: Target | null) =>
        t?.kind === 'mtd'
            ? 'MTD schedule'
            : t?.kind === 'table'
              ? 'table'
              : 'pay profile';
    const decide = () => {
        const t = decision.target;

        if (!t) {
            return;
        }

        busy.value = true;
        const verb =
            t.kind === 'profile'
                ? decision.action === 'check'
                    ? 'confirm'
                    : 'reject'
                : decision.action;
        router.post(
            `/hr/payroll/${targetPath(t)}/${t.id}/${verb}`,
            {
                reason: decision.action === 'reject' ? decision.reason : null,
                expected_lock_version: t.lockVersion,
                idempotency_key: decision.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (decision.target = null),
                onFinish: () => (busy.value = false),
            },
        );
    };
    const withdrawing = reactive({ target: null as Target | null, key: '' });
    const askWithdraw = (target: Target) =>
        Object.assign(withdrawing, { target, key: crypto.randomUUID() });
    const withdraw = () => {
        const t = withdrawing.target;

        if (!t) {
            return;
        }

        busy.value = true;
        router.post(
            `/hr/payroll/${targetPath(t)}/${t.id}/withdraw`,
            {
                expected_lock_version: t.lockVersion,
                idempotency_key: withdrawing.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (withdrawing.target = null),
                onFinish: () => (busy.value = false),
            },
        );
    };
    const profileTarget = (person: Person, p: Profile): Target => ({
        kind: 'profile',
        id: p.id,
        lockVersion: p.lockVersion,
        label: `${person.name} · applies from ${day(p.effectiveFrom)}`,
    });
    const tableTarget = (t: StatutoryTable): Target => ({
        kind: 'table',
        id: t.id,
        lockVersion: t.lockVersion,
        label: `${t.schemeLabel} · ${t.category} · from ${day(t.effectiveFrom)}`,
    });
    const mtdTarget = (m: MtdSchedule): Target => ({
        kind: 'mtd',
        id: m.id,
        lockVersion: m.lockVersion,
        label: `MTD schedule for ${m.year}`,
    });

    // ---- Settings ----
    const groupForm = reactive({
        open: false,
        group: null as Group | null,
        name: '',
        payDay: '',
        isActive: true,
        key: '',
        processing: false,
    });
    const openGroup = (group: Group | null) =>
        Object.assign(groupForm, {
            open: true,
            group,
            name: group?.name ?? '',
            payDay:
                group?.payDay === null || !group ? '' : String(group.payDay),
            isActive: group?.isActive ?? true,
            key: crypto.randomUUID(),
        });
    const saveGroup = () => {
        groupForm.processing = true;
        router.post(
            '/hr/payroll/groups',
            {
                group_id: groupForm.group?.id ?? null,
                name: groupForm.name,
                pay_day: groupForm.payDay,
                is_active: groupForm.isActive,
                expected_lock_version: groupForm.group?.lockVersion ?? null,
                idempotency_key: groupForm.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (groupForm.open = false),
                onFinish: () => (groupForm.processing = false),
            },
        );
    };
    const schemeFlags = [
        { key: 'countsEpf', field: 'counts_epf', label: 'EPF' },
        { key: 'countsSocso', field: 'counts_socso', label: 'SOCSO' },
        { key: 'countsEis', field: 'counts_eis', label: 'EIS' },
        { key: 'countsMtd', field: 'counts_mtd', label: 'MTD' },
        { key: 'countsHrd', field: 'counts_hrd', label: 'HRD' },
    ] as const;
    type FlagKey = (typeof schemeFlags)[number]['key'];
    const typeForm = reactive({
        open: false,
        type: null as ItemType | null,
        name: '',
        kind: 'earnings',
        flags: {
            countsEpf: false,
            countsSocso: false,
            countsEis: false,
            countsMtd: false,
            countsHrd: false,
        } as Record<FlagKey, boolean>,
        isActive: true,
        key: '',
        processing: false,
    });
    const openType = (type: ItemType | null, name = '') =>
        Object.assign(typeForm, {
            open: true,
            type,
            name: type?.name ?? name,
            kind:
                type?.kind ??
                (/deduction|unpaid/i.test(name)
                    ? 'deduction'
                    : /refund/i.test(name)
                      ? 'refund'
                      : 'earnings'),
            flags: {
                countsEpf: type?.countsEpf ?? false,
                countsSocso: type?.countsSocso ?? false,
                countsEis: type?.countsEis ?? false,
                countsMtd: type?.countsMtd ?? false,
                countsHrd: type?.countsHrd ?? false,
            },
            isActive: type?.isActive ?? true,
            key: crypto.randomUUID(),
        });
    const saveType = () => {
        typeForm.processing = true;
        router.post(
            '/hr/payroll/item-types',
            {
                item_type_id: typeForm.type?.id ?? null,
                name: typeForm.name,
                kind: typeForm.kind,
                counts_epf: typeForm.flags.countsEpf,
                counts_socso: typeForm.flags.countsSocso,
                counts_eis: typeForm.flags.countsEis,
                counts_mtd: typeForm.flags.countsMtd,
                counts_hrd: typeForm.flags.countsHrd,
                is_active: typeForm.isActive,
                expected_lock_version: typeForm.type?.lockVersion ?? null,
                idempotency_key: typeForm.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (typeForm.open = false),
                onFinish: () => (typeForm.processing = false),
            },
        );
    };
    const unusedSuggestions = computed(() =>
        props.payroll.options.suggestedItemTypes.filter(
            (name) =>
                !(props.payroll.settings?.itemTypes ?? []).some(
                    (t) => t.name.toLowerCase() === name.toLowerCase(),
                ),
        ),
    );
    const lineTypes = reactive({
        overtime: props.payroll.settings?.lineTypes.overtime ?? '',
        unpaidLeave: props.payroll.settings?.lineTypes.unpaidLeave ?? '',
        claim: props.payroll.settings?.lineTypes.claim ?? '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const saveLineTypes = () => {
        lineTypes.processing = true;
        router.post(
            '/hr/payroll/line-types',
            {
                overtime_type_id: lineTypes.overtime,
                unpaid_leave_type_id: lineTypes.unpaidLeave,
                claim_type_id: lineTypes.claim,
                expected_version:
                    props.payroll.settings?.lineTypes.version ?? 0,
                idempotency_key: lineTypes.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (lineTypes.key = crypto.randomUUID()),
                onFinish: () => (lineTypes.processing = false),
            },
        );
    };
    const rates = reactive({
        daily: props.payroll.settings?.rates.daily ?? '',
        hourly: props.payroll.settings?.rates.hourly ?? '',
        key: crypto.randomUUID(),
        processing: false,
    });
    const saveRates = () => {
        rates.processing = true;
        router.post(
            '/hr/payroll/rates',
            {
                daily_divisor: rates.daily,
                hourly_divisor: rates.hourly,
                expected_version: props.payroll.settings?.rates.version ?? 0,
                idempotency_key: rates.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (rates.key = crypto.randomUUID()),
                onFinish: () => (rates.processing = false),
            },
        );
    };

    return {
        errors,
        failure,
        tabLabels,
        tabs,
        selectTab,
        money,
        rateLine,
        busy,
        anyDialog,
        profileSheet,
        enterFor,
        tableSheet,
        mtdSheet,
        decision,
        askDecide,
        targetPath,
        noun,
        decide,
        withdrawing,
        askWithdraw,
        withdraw,
        profileTarget,
        tableTarget,
        mtdTarget,
        groupForm,
        openGroup,
        saveGroup,
        schemeFlags,
        typeForm,
        openType,
        saveType,
        unusedSuggestions,
        lineTypes,
        saveLineTypes,
        rates,
        saveRates,
    };
}
