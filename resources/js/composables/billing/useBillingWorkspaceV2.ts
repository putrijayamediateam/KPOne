import { router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import type {
    BillingPage,
    BillingState,
    Receipt,
    Responsibility,
} from '@/types/billing';
import { toSen } from '@/types/billing';

/**
 * BV2-0 (Version 2 only): a copy of the Classic Patient Billing workspace's state and actions
 * (components/billing/BillingWorkspace.vue, script from `responsibilityTotals` to the end), for the Version 2 page.
 * Classic is untouched and does not use this file. The copy is deliberate (owner decision via the coordinator,
 * 2026-11-13: Classic stays byte-identical and no existing test is edited); a parity test pins it to the Classic
 * script, so any change to one must be made to both. Same payloads, keys, lock versions and idempotency key.
 * Recorded as debt: a later shared-composable refactor needs its own approval.
 */
export function useBillingWorkspaceV2(props: {
    readonly billing: BillingPage;
    readonly manage: boolean;
}) {
    const responsibilityTotals: Array<[keyof BillingState, string]> = [
        ['self_pay', 'Self-pay received'],
        ['panel', 'Panel responsibility'],
        ['deferred', 'Pay later still owed'],
    ];
    const invoiceTabs = [
        { value: 'all', label: 'All' },
        { value: 'items', label: 'Items' },
        { value: 'services', label: 'Services' },
        { value: 'documents', label: 'Documents' },
    ];
    const page = usePage();
    const busy = ref(false);
    // BIL-25 (Siti): a reversed receipt is a settled correction, so it stays grey.
    // 'reversed' has no tone in presentation.ts today; this keeps it grey if one is added.
    const receiptTone = (status: string) =>
        status === 'reversed' ? ('neutral' as const) : undefined;
    const contextCleared = ref(false);
    const completionOpen = ref(false);
    const paymentOpen = ref(false);
    const responsibilityOpen = ref(false);
    const responsibilityKind = ref<'panel' | 'deferment'>('panel');
    const reversalOpen = ref(false);
    const reversalReceipt = ref<Receipt | null>(null);
    const closeDialogs = () => {
        paymentOpen.value = false;
        responsibilityOpen.value = false;
        reversalOpen.value = false;
    };
    const updateDialog = (
        kind: 'payment' | 'responsibility' | 'reversal',
        open: boolean,
    ) => {
        if (busy.value) {
            return;
        }

        if (kind === 'payment') {
            paymentOpen.value = open;
        }

        if (kind === 'responsibility') {
            responsibilityOpen.value = open;
        }

        if (kind === 'reversal') {
            reversalOpen.value = open;
        }
    };
    const openEntry = (kind: 'payment' | 'panel' | 'deferment') => {
        if (busy.value || contextCleared.value) {
            return;
        }

        if (
            kind === 'payment'
                ? !props.billing.can.pay
                : kind === 'panel'
                  ? !props.billing.can.panelPropose
                  : !props.billing.can.deferPropose
        ) {
            return;
        }

        error.value = '';

        if (kind === 'payment') {
            paymentOpen.value = true;
        } else {
            responsibilityKind.value = kind;
            responsibilityOpen.value = true;
        }
    };
    const openReversal = (receipt: Receipt) => {
        if (
            busy.value ||
            !props.billing.can.reverse ||
            receipt.status !== 'posted'
        ) {
            return;
        }

        error.value = '';
        reversalReceipt.value = receipt;
        reversalOpen.value = true;
    };
    const error = ref('');
    const tab = ref('all');
    const form = reactive({
        amount: '',
        responsibility_amount: '',
        method: '',
        reference: '',
        reason: '',
        due_date: '',
        panel_id: '',
        member_reference: '',
        recording_error_only: false,
    });
    let paymentKey = crypto.randomUUID();
    const clearEntry = () => {
        Object.assign(form, {
            amount: '',
            responsibility_amount: '',
            method: '',
            reference: '',
            reason: '',
            due_date: '',
            panel_id: '',
            member_reference: '',
            recording_error_only: false,
        });
        error.value = '';
        closeDialogs();
        reversalReceipt.value = null;
        paymentKey = crypto.randomUUID();
    };
    const stopStart = router.on('start', (event) => {
        if (event.detail.visit.url.pathname === '/branch-context') {
            contextCleared.value = true;
            clearEntry();
        }
    });
    const stopSuccess = router.on('success', (event) => {
        if (
            ['Billing/Show', 'Billing/Payments'].includes(
                event.detail.page.component,
            )
        ) {
            contextCleared.value = false;
        }
    });
    watch(
        [
            () => props.billing.visit.visitNumber,
            () => page.props.branchContext?.active?.id,
        ],
        clearEntry,
    );
    onUnmounted(() => {
        stopStart();
        stopSuccess();
    });
    const base = computed(
        () =>
            `/visits/${encodeURIComponent(props.billing.visit.visitNumber)}/billing`,
    );
    const invoiceBase = computed(
        () => `${base.value}/${props.billing.invoice?.publicId}`,
    );
    const isCompleted = computed(
        () => props.billing.visit.status === 'completed',
    );
    // The exact amount due now as a plain MYR figure, for the "Use amount due" shortcuts.
    const dueNowText = computed(() =>
        props.billing.invoice
            ? (props.billing.invoice.state.due_now / 100).toFixed(2)
            : '',
    );
    const methodOptions = computed(() => [
        { value: '', label: 'Select method' },
        ...props.billing.methods.map((method) => ({
            value: method.code,
            label: method.name,
        })),
    ]);
    const panelOptions = computed(() => [
        { value: '', label: 'Select Panel' },
        ...props.billing.panels.map((panel) => ({
            value: panel.id,
            label: panel.name,
        })),
    ]);
    const lines = computed(
        () =>
            props.billing.invoice?.lines.filter(
                (l) =>
                    tab.value === 'all' ||
                    (tab.value === 'items'
                        ? l.type === 'medicine'
                        : l.type !== 'medicine'),
            ) ?? [],
    );
    const post = (
        url: string,
        extra: Record<string, string | number | boolean | null> = {},
    ) => {
        if (busy.value || contextCleared.value) {
            return;
        }

        busy.value = true;
        error.value = '';
        router.post(
            url,
            {
                expected_branch_id: page.props.branchContext?.active?.id ?? 0,
                lock_version: props.billing.invoice?.lockVersion ?? null,
                return_to: props.manage ? 'payments' : 'billing',
                ...extra,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    error.value =
                        Object.values(errors)[0] ??
                        'The request could not be completed.';
                },
                onSuccess: () => {
                    clearEntry();
                },
                onFinish: () => {
                    busy.value = false;
                },
            },
        );
    };
    const payment = () => {
        const sen = toSen(form.amount);

        if (sen === null) {
            error.value =
                'Enter an exact MYR amount with at most two decimal places.';

            return;
        }

        post(`${invoiceBase.value}/payments`, {
            amount_sen: sen,
            method: form.method,
            reference: form.reference || null,
            idempotency_key: paymentKey,
        });
    };
    const propose = (kind: string) => {
        const sen = toSen(form.responsibility_amount);

        if (sen === null) {
            error.value =
                'Enter the amount the Panel or pay later will cover, in MYR with at most two decimal places.';

            return;
        }

        post(`${invoiceBase.value}/responsibility/${kind}`, {
            amount_sen: sen,
            reason: form.reason,
            due_date: form.due_date || null,
            panel_id: form.panel_id || null,
            member_reference: form.member_reference || null,
        });
    };
    const approve = (kind: string, proposal: Responsibility) =>
        post(
            `${invoiceBase.value}/responsibility/${kind}/${proposal.publicId}/approve`,
            { proposal_lock_version: proposal.lockVersion },
        );
    const reverse = (receipt: Receipt) =>
        post(`${invoiceBase.value}/payments/${receipt.publicId}/reverse`, {
            payment_lock_version: receipt.lockVersion,
            reason: form.reason,
            recording_error_only: form.recording_error_only,
        });

    return {
        responsibilityTotals,
        invoiceTabs,
        page,
        busy,
        receiptTone,
        contextCleared,
        completionOpen,
        paymentOpen,
        responsibilityOpen,
        responsibilityKind,
        reversalOpen,
        reversalReceipt,
        closeDialogs,
        updateDialog,
        openEntry,
        openReversal,
        error,
        tab,
        form,
        clearEntry,
        base,
        invoiceBase,
        isCompleted,
        dueNowText,
        methodOptions,
        panelOptions,
        lines,
        post,
        payment,
        propose,
        approve,
        reverse,
    };
}
