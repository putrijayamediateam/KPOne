import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { InvoiceAdjustmentSummary } from '@/types/billing';

/**
 * Version 2 Create claim page logic: a verbatim copy of the script of `pages/PanelClaims/Create.vue` (Classic stays
 * untouched), so both pages post the same form: same payload keys, lock versions, idempotency key, photo checks and
 * route. `tests/Frontend/v2-panel-create-claim.test.mjs` compares the two. Look only.
 */
export type Props = {
    branch: { id: number; today: string };
    allocation: {
        publicId: string;
        lockVersion: number;
        invoiceLockVersion: number;
        panelName: string;
        amountSen: number;
        patientName: string;
        invoiceNumber: string;
        invoiceTotalSen: number;
        invoiceBreakdown: InvoiceAdjustmentSummary;
        visitDate: string;
        visitDateTime: string;
        dueRule: string | null;
        dueBy: string | null;
        lines: {
            id: number;
            name: string;
            quantity: string;
            unitPriceSen: number;
            totalSen: number;
        }[];
    };
};

export function usePanelClaimCreate(props: Props) {
    const form = useForm({
        expected_branch_id: props.branch.id,
        allocation_lock_version: props.allocation.lockVersion,
        invoice_lock_version: props.allocation.invoiceLockVersion,
        idempotency_key: crypto.randomUUID(),
        submitted_date: props.branch.today,
        remarks: '',
        photos: [] as File[],
    });
    const page = ref(1);
    const pages = computed(() =>
        Math.max(1, Math.ceil(props.allocation.lines.length / 10)),
    );
    const lines = computed(() =>
        props.allocation.lines.slice((page.value - 1) * 10, page.value * 10),
    );
    const photoError = ref('');
    const selectPhotos = (files: FileList | null) => {
        if (!files) {
            return;
        }

        const selected = [...form.photos, ...Array.from(files)];

        if (
            selected.length > 10 ||
            selected.some(
                (file) =>
                    !['image/jpeg', 'image/png'].includes(file.type) ||
                    file.size > 5 * 1024 * 1024 ||
                    file.size === 0,
            )
        ) {
            photoError.value =
                'Select up to 10 JPG or PNG photos, at most 5 MB each.';

            return;
        }

        photoError.value = '';
        form.photos = selected;
    };
    const createClaim = () =>
        form.post(
            `/panel-claims/allocations/${encodeURIComponent(props.allocation.publicId)}`,
            { forceFormData: true },
        );
    const errors = computed(() => Object.entries(form.errors));
    // UX-7: these fields announce their own error through InputError, so the summary repeats
    // them on screen only; every other error is announced here.
    const fieldAnnouncedErrors = new Set([
        'submitted_date',
        'remarks',
        'photos',
    ]);

    return {
        createClaim,
        errors,
        fieldAnnouncedErrors,
        form,
        lines,
        page,
        pages,
        photoError,
        selectPhotos,
    };
}
