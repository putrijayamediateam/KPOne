import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { CategoryOption, ClaimRow } from '@/components/hr/claims/types';

/**
 * HR-6 New claim form state: category, date spent, amount, purpose and receipts for the active branch, with the
 * request and key, for `components/hr/claims/ClaimSheet.vue`.
 * "Send a new claim" after a Wrong amount rejection copies the purpose in, and the server copies that claim's receipts.
 */
export type ClaimSheetProps = {
    open: boolean;
    categories: CategoryOption[];
    branch: string | null;
    today: string;
    maxReceipts: number;
    /** The claim rejected for Wrong amount that is being sent again, if any. */
    from: ClaimRow | null;
};

export function useClaimSheet(props: ClaimSheetProps, close: () => void) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const form = reactive({
        categoryId: '',
        spentOn: '',
        amount: '',
        purpose: '',
        files: [] as File[],
        key: '',
        processing: false,
    });
    watch(
        () => props.open,
        (open) => {
            if (open) {
                Object.assign(form, {
                    categoryId:
                        props.categories.find(
                            (c) => c.label === props.from?.category,
                        )?.value ?? '',
                    spentOn: '',
                    amount: '',
                    purpose: props.from?.purpose ?? '',
                    files: [],
                    key: crypto.randomUUID(),
                });
            }
        },
    );
    const category = computed(() =>
        props.categories.find((c) => c.value === form.categoryId),
    );
    const copied = computed(() => props.from?.receipts.length ?? 0);
    const room = computed(() => props.maxReceipts - copied.value);
    const receiptError = computed(() => {
        const keyed = Object.entries(errors.value).find(
            ([k, v]) => k.startsWith('receipts.') && v,
        );

        return errors.value.receipts ?? keyed?.[1];
    });
    const onFiles = (event: Event) => {
        const input = event.target as HTMLInputElement;
        form.files = [...form.files, ...Array.from(input.files ?? [])].slice(
            0,
            room.value,
        );
        input.value = '';
    };
    const removeFile = (index: number) => {
        form.files = form.files.filter((_, i) => i !== index);
    };
    const send = () => {
        form.processing = true;
        router.post(
            '/hr/claims',
            {
                category_id: form.categoryId,
                spent_on: form.spentOn,
                amount: form.amount,
                purpose: form.purpose,
                ...(props.from ? { copy_from: props.from.id } : {}),
                ...(form.files.length ? { receipts: form.files } : {}),
                idempotency_key: form.key,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => (form.processing = false),
            },
        );
    };

    return {
        errors,
        form,
        category,
        copied,
        room,
        receiptError,
        onFiles,
        removeFile,
        send,
    };
}
