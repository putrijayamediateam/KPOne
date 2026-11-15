import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toSen } from '@/types/billing';

/**
 * Version 2 Panel claim page logic: a verbatim copy of the script of `pages/PanelClaims/Show.vue` (Classic stays
 * untouched), so both pages post the same requests with the same payload keys, lock versions and idempotency key.
 * `tests/Frontend/v2-panel-claim-show.test.mjs` compares the two. Look only.
 */
export type ClaimData = {
    publicId: string;
    lockVersion: number;
    status: string;
    patientName: string;
    memberReference: string | null;
    panelName: string;
    branchName: string;
    visitNumber: string;
    visitDate: string;
    invoiceNumber: string;
    invoiceTotalSen: number;
    invoiceSubtotalSen: number;
    invoiceAdjustmentSen: number;
    claimOnlyAdjustmentSen: number;
    claimAmountSen: number;
    adjustmentSen: number;
    acceptedAmountSen: number | null;
    paidAmountSen: number;
    outstandingSen: number;
    lines: {
        number: number;
        type: string;
        name: string;
        code: string;
        unit: string;
        quantity: string;
        unitPriceSen: number;
        totalSen: number;
        adjustment: boolean;
    }[];
    events: {
        type: string;
        previousAmountSen: number | null;
        amountSen: number | null;
        externalReference: string | null;
        note: string | null;
        occurredAt: string;
        actor: string;
    }[];
    payments: {
        amountSen: number;
        reference: string | null;
        receivedAt: string;
        reversed?: boolean;
        reversedOn?: string | null;
    }[];
    createdAt: string | null;
    claimNo: string;
    statusKey: string;
    remarks: string | null;
    submittedOn: string | null;
    updatedBy: string | null;
    shareUrl: string;
    message: string | null;
    invoiceUrl: string;
    dueRule: string;
    verificationUrl: string | null;
    receivedOn: string | null;
    amountDueSen: number;
    identityVisible: boolean;
    patientPhone: string | null;
    canWhatsapp: boolean;
    patientIdentifierType: string | null;
    patientIdentifier: string | null;
    documents: {
        publicId: string;
        name: string;
        mimeType: string;
        sizeBytes: number;
        uploadedAt: string | null;
        url: string;
    }[];
    canEditRemarks: boolean;
    canEditAmount: boolean;
    canSubmit: boolean;
    canRecordQuery: boolean;
    canRecordDecision: boolean;
    canRecordPayment: boolean;
};

export type Props = {
    branch: { id: number; name: string; today: string };
    claim: ClaimData;
};

export function usePanelClaimShow(props: Props) {
    const page = usePage();
    const busy = ref(false);
    const error = ref('');
    const uploadRecoveryError = computed(() => page.props.errors?.file);
    const amount = ref((props.claim.claimAmountSen / 100).toFixed(2));
    const amountReason = ref('');
    const confirmAboveInvoice = ref(false);
    const submissionReference = ref('');
    const queryReference = ref('');
    const queryNote = ref('');
    const decision = ref<'accepted' | 'partially_accepted' | 'rejected'>(
        'accepted',
    );
    const acceptedAmount = ref((props.claim.claimAmountSen / 100).toFixed(2));
    const decisionNote = ref('');
    const decisionReference = ref('');
    const remittanceAmount = ref('');
    const remittanceDate = ref(props.branch.today);
    const remittanceReference = ref('');
    let paymentKey = crypto.randomUUID();
    watch(decision, (value) => {
        if (value === 'rejected') {
            acceptedAmount.value = '0.00';
        } else if (value === 'accepted') {
            acceptedAmount.value = (props.claim.claimAmountSen / 100).toFixed(
                2,
            );
        }
    });

    const amountSen = computed(() => toSen(amount.value));
    const aboveInvoice = computed(
        () =>
            amountSen.value !== null &&
            BigInt(amountSen.value) > BigInt(props.claim.invoiceTotalSen),
    );
    const post = (
        method: 'post' | 'patch',
        path: string,
        payload: Record<string, string | number | boolean | null>,
        onSuccess?: () => void,
    ) => {
        busy.value = true;
        error.value = '';
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                error.value =
                    Object.values(errors)[0] ??
                    'The request could not be completed.';
            },
            onSuccess,
            onFinish: () => {
                busy.value = false;
            },
        };

        if (method === 'patch') {
            router.patch(path, payload, options);
        } else {
            router.post(path, payload, options);
        }
    };
    const context = () => ({
        expected_branch_id:
            page.props.branchContext?.active?.id ?? props.branch.id,
        lock_version: props.claim.lockVersion,
    });
    const adjust = () => {
        const value = toSen(amount.value);

        if (value === null || BigInt(value) < 1n) {
            error.value =
                'Enter a positive MYR claim amount with at most two decimals.';

            return;
        }

        post('patch', `/panel-claims/${props.claim.publicId}/amount`, {
            ...context(),
            amount_sen: value,
            reason: amountReason.value,
            confirm_over_invoice: confirmAboveInvoice.value,
        });
    };
    const submit = () =>
        post('post', `/panel-claims/${props.claim.publicId}/submit`, {
            ...context(),
            external_reference: submissionReference.value || null,
        });
    const recordQuery = () =>
        post('post', `/panel-claims/${props.claim.publicId}/query`, {
            ...context(),
            external_reference: queryReference.value || null,
            note: queryNote.value,
        });
    const recordDecision = () => {
        const acceptedSen = toSen(acceptedAmount.value);

        if (acceptedSen === null) {
            error.value =
                'Enter the Panel-accepted amount in MYR with at most two decimals.';

            return;
        }

        post('post', `/panel-claims/${props.claim.publicId}/decision`, {
            ...context(),
            decision: decision.value,
            accepted_amount_sen: acceptedSen,
            external_reference: decisionReference.value || null,
            note: decisionNote.value,
        });
    };
    const recordPayment = () => {
        const value = toSen(remittanceAmount.value);

        if (value === null || BigInt(value) < 1n) {
            error.value =
                'Enter a positive MYR remittance with at most two decimals.';

            return;
        }

        post(
            'post',
            `/panel-claims/${props.claim.publicId}/payments`,
            {
                expected_branch_id:
                    page.props.branchContext?.active?.id ?? props.branch.id,
                idempotency_key: paymentKey,
                amount_sen: value,
                received_date: remittanceDate.value,
                reference: remittanceReference.value || null,
            },
            () => {
                paymentKey = crypto.randomUUID();
                remittanceAmount.value = '';
                remittanceReference.value = '';
            },
        );
    };
    const printPack = () => window.print();
    // PNL-8: the printable pack for this one claim (letterhead, IC or passport, photo names; no clinical content).
    const packError = computed(
        () => (page.props.errors as Record<string, string> | undefined)?.claims,
    );
    const packUrl = computed(
        () =>
            `/panel-claims/pack?claims[]=${encodeURIComponent(props.claim.publicId)}&from=${encodeURIComponent(props.claim.publicId)}`,
    );
    const editing = ref(false);
    const copied = ref(false);
    const noteOpen = ref(false);
    const noteText = ref('');
    const remarksText = ref(props.claim.remarks ?? '');
    const fileInput = ref<HTMLInputElement | null>(null);
    const messageCopied = ref(false);
    const copyMessage = async () => {
        try {
            await navigator.clipboard.writeText(props.claim.message ?? '');
            messageCopied.value = true;
            setTimeout(() => (messageCopied.value = false), 2000);
        } catch {
            error.value = 'The message could not be copied.';
        }
    };
    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(props.claim.shareUrl);
            copied.value = true;
            setTimeout(() => (copied.value = false), 2000);
        } catch {
            error.value = 'The link could not be copied.';
        }
    };
    const branchId = () =>
        page.props.branchContext?.active?.id ?? props.branch.id;
    const addNote = () =>
        post(
            'post',
            `/panel-claims/${props.claim.publicId}/notes`,
            { expected_branch_id: branchId(), note: noteText.value },
            () => {
                noteText.value = '';
                noteOpen.value = false;
            },
        );
    const saveRemarks = () =>
        post('patch', `/panel-claims/${props.claim.publicId}/remarks`, {
            ...context(),
            remarks: remarksText.value || null,
        });
    const uploadDocument = (event: Event) => {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        busy.value = true;
        error.value = '';
        router.post(
            `/panel-claims/${props.claim.publicId}/documents`,
            { expected_branch_id: branchId(), file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => {
                    error.value =
                        Object.values(errors)[0] ??
                        'The photo could not be uploaded.';
                },
                onFinish: () => {
                    busy.value = false;
                    input.value = '';
                },
            },
        );
    };
    const sizeLabel = (bytes: number) =>
        bytes >= 1048576
            ? `${(bytes / 1048576).toFixed(1)} MB`
            : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    const eventTitle = (type: string) =>
        ({
            prepared: 'Panel claim was created',
            amount_adjusted: 'Claim amount adjusted',
            submitted: 'Submitted to Panel',
            queried: 'Panel query recorded',
            decision_accepted: 'Panel accepted claim',
            decision_partially_accepted: 'Panel partially accepted claim',
            decision_rejected: 'Panel rejected claim',
            note: 'Note added',
        })[type] ?? type;

    return {
        aboveInvoice,
        acceptedAmount,
        addNote,
        adjust,
        amount,
        amountReason,
        amountSen,
        branchId,
        busy,
        confirmAboveInvoice,
        context,
        copied,
        copyLink,
        copyMessage,
        decision,
        decisionNote,
        decisionReference,
        editing,
        error,
        eventTitle,
        fileInput,
        messageCopied,
        noteOpen,
        noteText,
        packError,
        packUrl,
        page,
        post,
        printPack,
        queryNote,
        queryReference,
        recordDecision,
        recordPayment,
        recordQuery,
        remarksText,
        remittanceAmount,
        remittanceDate,
        remittanceReference,
        saveRemarks,
        sizeLabel,
        submissionReference,
        submit,
        uploadDocument,
        uploadRecoveryError,
    };
}
