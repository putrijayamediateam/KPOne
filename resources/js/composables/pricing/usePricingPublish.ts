import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { rmToSen } from '@/lib/money';

/**
 * Version 2 Pricing: the publish-price dialog's state and rules. These are the same rules as the Classic Pricing page
 * (CAT-1b: an empty amount is refused with the same message, a typed 0.00 is allowed; the request carries the same
 * keys, `expected_version` and `expected_branch_id`). The server stays the authority.
 */
export const EMPTY_AMOUNT_MESSAGE =
    'Enter an amount. Type 0.00 if this is free.';

export type PublishableCharge = {
    publicId: string;
    displayName: string;
    currentPrice: {
        priceBookPublicId: string;
        amountSen: number | null;
        version: number;
    } | null;
};

export function usePricingPublish(activeBranchId: number | null) {
    const target = ref<PublishableCharge | null>(null);
    const form = reactive({
        priceBook: '',
        amount: '',
        busy: false,
        error: '',
    });
    const open = computed({
        get: () => target.value !== null,
        set: (value: boolean) => {
            if (!value) {
                target.value = null;
            }
        },
    });

    const begin = (charge: PublishableCharge) => {
        form.priceBook = charge.currentPrice?.priceBookPublicId ?? '';
        form.amount =
            charge.currentPrice && charge.currentPrice.amountSen !== null
                ? (charge.currentPrice.amountSen / 100).toFixed(2)
                : '';
        form.busy = false;
        form.error = '';
        target.value = charge;
    };

    const submit = () => {
        const charge = target.value;

        if (!charge) {
            return;
        }

        if (!activeBranchId) {
            form.error = 'Select an active branch before publishing a price.';

            return;
        }

        const amountSen = rmToSen(form.amount);

        if (amountSen === null) {
            form.error = EMPTY_AMOUNT_MESSAGE;

            return;
        }

        if (!form.priceBook || !Number.isFinite(amountSen) || amountSen < 0) {
            form.error = 'Select a price book and enter a valid amount.';

            return;
        }

        form.busy = true;
        form.error = '';
        router.post(
            `/pricing/charges/${charge.publicId}/publish`,
            {
                price_book_public_id: form.priceBook,
                amount_sen: amountSen,
                expected_version: charge.currentPrice?.version ?? 0,
                expected_branch_id: activeBranchId,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    target.value = null;
                },
                onError: (errors) => {
                    form.error =
                        Object.values(errors)[0] ??
                        'The price could not be published.';
                },
                onFinish: () => {
                    form.busy = false;
                },
            },
        );
    };

    return { target, form, open, begin, submit };
}
