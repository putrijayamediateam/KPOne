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

export const STALE_PRICE_MESSAGE =
    'Someone published a newer price. Reload to see it.';

export type PublishableCharge = {
    publicId: string;
    displayName: string;
    currentPrice: {
        priceBookPublicId: string;
        amountSen: number | null;
        version: number;
    } | null;
};

export function usePricingPublish(
    activeBranchId: number | null,
    findCharge: (publicId: string) => PublishableCharge | undefined = () =>
        undefined,
) {
    const target = ref<PublishableCharge | null>(null);
    const form = reactive({
        priceBook: '',
        amount: '',
        busy: false,
        error: '',
        stale: false,
        reloaded: false,
    });
    const open = computed({
        get: () => target.value !== null,
        set: (value: boolean) => {
            if (!value) {
                target.value = null;
            }
        },
    });

    // Money safety (Daniel): the price book is never guessed when there is more than one, and the amount starts empty
    // so pressing Publish unchanged cannot record a duplicate version.
    const begin = (charge: PublishableCharge, defaultPriceBook = '') => {
        form.priceBook = defaultPriceBook;
        form.amount = '';
        form.busy = false;
        form.error = '';
        form.stale = false;
        form.reloaded = false;
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
        form.stale = false;
        form.reloaded = false;
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
                    // The server refuses with a `price` error when the price changed since this page loaded.
                    form.stale = 'price' in errors;
                    form.error = form.stale
                        ? STALE_PRICE_MESSAGE
                        : (Object.values(errors)[0] ??
                          'The price could not be published.');
                },
                onFinish: () => {
                    form.busy = false;
                },
            },
        );
    };

    // After a stale refusal: fetch the newest prices, keep the typed book and amount, show the new current price, and
    // wait. Nothing is published for the person; they press Publish again.
    const reload = () => {
        const id = target.value?.publicId;

        router.reload({
            only: ['charges', 'priceBooks'],
            onSuccess: () => {
                const fresh = id ? findCharge(id) : undefined;

                if (fresh) {
                    target.value = fresh;
                    form.error = '';
                    form.stale = false;
                    // Said in words in the dialog, so nobody wonders whether the reload ran.
                    form.reloaded = true;
                } else {
                    target.value = null;
                }
            },
        });
    };

    return { target, form, open, begin, submit, reload };
}
