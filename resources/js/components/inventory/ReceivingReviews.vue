<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
type Review = {
    public_id: string;
    order_public_id: string;
    order_number: string;
    kind: string;
    receiver_user_id: number;
    order_lock_version: number;
    version: number;
    approval_id: number | null;
    consumption_id: number | null;
    canApprove: boolean;
    canPost: boolean;
    reason_codes: string[];
    payload: {
        delivery_reference?: string;
        reason?: string;
        lines?: {
            physical_quantity: string;
            lots: {
                batch_number: string;
                expiry_date: string;
                quantity: string;
                outcome: string;
                reason: string | null;
            }[];
        }[];
    };
};
type ReceiptRow = {
    public_id: string;
    delivery_reference: string;
    received_at_local: string;
    received_by_user_id: number;
    order_public_id: string;
    order_number: string;
    order_version: number;
    canCorrect: boolean;
    correction_id: number | null;
};
type ReviewPage = {
    branchId: number;
    timezone: string;
    receipts: {
        page: number;
        lastPage: number;
        total: number;
        rows: ReceiptRow[];
    };
    page: number;
    lastPage: number;
    total: number;
    rows: Review[];
};
const props = defineProps<{ branchId: number; nonce: string }>();
const page = usePage<{ receiving?: ReviewPage }>();
const loadedForBranch = ref<number | null>(null);
const reviews = computed(() =>
    loadedForBranch.value === props.branchId &&
    page.props.receiving?.branchId === props.branchId
        ? page.props.receiving
        : null,
);
const keys = reactive<Record<string, string>>({});
const errors = ref<string[]>([]);
const correctionReasons = reactive<Record<string, string>>({});
const resetCorrectionKey = (id: string) => {
    keys['correction-' + id] = crypto.randomUUID();
};
const requestCorrection = (row: ReceiptRow) => {
    keys['correction-' + row.public_id] ??= crypto.randomUUID();
    router.post(
        `/inventory/purchase-orders/${row.order_public_id}/receiving-reviews`,
        {
            expected_branch_id: props.branchId,
            lock_version: row.order_version,
            retry_key: keys['correction-' + row.public_id],
            kind: 'correction',
            receipt_public_id: row.public_id,
            reason: correctionReasons[row.public_id] ?? '',
            receipt_session_nonce: props.nonce,
        },
        {
            preserveScroll: true,
            onError: (bag) => {
                errors.value = Object.values(bag);
            },
            onSuccess: () => load(),
        },
    );
};
const busy = ref(false);
const load = (number = 1) =>
    router.get(
        '/inventory',
        {
            ...Object.fromEntries(new URLSearchParams(location.search)),
            receiving_page: number,
        },
        {
            only: ['receiving'],
            onSuccess: () => {
                loadedForBranch.value = props.branchId;
            },
            preserveState: true,
            preserveScroll: true,
        },
    );
const act = (row: Review, approve: boolean) => {
    keys[row.public_id] ??= crypto.randomUUID();
    busy.value = true;
    errors.value = [];
    const path = approve
        ? `/inventory/receiving-reviews/${row.public_id}/approve`
        : row.kind === 'correction'
          ? `/inventory/receiving-reviews/${row.public_id}/reverse`
          : `/inventory/purchase-orders/${row.order_public_id}/receipts`;
    router.post(
        path,
        {
            ...row.payload,
            expected_branch_id: props.branchId,
            lock_version: approve ? 1 : row.order_lock_version,
            review_lock_version: 2,
            review_public_id: row.public_id,
            idempotency_key: row.public_id,
            retry_key: keys[row.public_id],
            receipt_session_nonce: props.nonce,
        },
        {
            preserveScroll: true,
            onError: (bag) => {
                errors.value = Object.values(bag);
            },
            onSuccess: () => load(reviews.value?.page ?? 1),
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
watch(
    () => props.branchId,
    () => {
        errors.value = [];
        loadedForBranch.value = null;

        for (const key of Object.keys(correctionReasons)) {
            delete correctionReasons[key];
        }

        for (const key of Object.keys(keys)) {
            delete keys[key];
        }
    },
);
</script>
<template>
    <section
        class="min-w-0 space-y-3 rounded-lg border p-3"
        aria-label="Receiving exception reviews"
    >
        <h4 class="font-semibold">Delivery exceptions and corrections</h4>
        <p class="text-sm text-muted-foreground">
            A different CA Supervisor, Finance Officer or Director must approve.
            The original receiver posts the unchanged delivery after approval.
            Release of quarantine remains a separate STK-2 action by Finance
            Officer or Director.
        </p>
        <button class="min-h-11 rounded border px-3" @click="load()">
            Load receiving reviews (25 per page)
        </button>
        <p
            v-for="error in errors"
            :key="error"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>
        <template v-if="reviews">
            <h5 class="font-semibold">Posted receiving evidence</h5>
            <p class="text-sm text-muted-foreground">
                {{ reviews.receipts.total }}
                {{ reviews.receipts.total === 1 ? 'receipt' : 'receipts' }} · 25
                per page · Times use {{ reviews.timezone }}. Corrections reverse
                a whole untouched receipt; they never edit its evidence. Stock
                that has moved out requires owner review.
            </p>
            <article
                v-for="receipt in reviews.receipts.rows"
                :key="receipt.public_id"
                class="min-w-0 space-y-2 rounded border p-3"
            >
                <p class="font-medium wrap-anywhere">
                    {{ receipt.order_number }} ·
                    {{ receipt.delivery_reference }}
                </p>
                <p class="text-sm">
                    {{ receipt.received_at_local }} · Receiver: staff #{{
                        receipt.received_by_user_id
                    }}
                    ·
                    {{
                        receipt.correction_id
                            ? 'Reversing correction retained'
                            : 'Original receipt retained'
                    }}
                </p>
                <template v-if="receipt.canCorrect">
                    <label class="grid gap-1 text-sm"
                        >Correction reason<select
                            v-model="correctionReasons[receipt.public_id]"
                            class="h-11 min-w-0 rounded border bg-background px-3"
                            @change="resetCorrectionKey(receipt.public_id)"
                        >
                            <option value="" disabled>Choose a reason</option>
                            <option
                                v-for="reason in [
                                    'duplicate_delivery',
                                    'incorrect_quantity',
                                    'incorrect_batch',
                                    'other_approved',
                                ]"
                                :key="reason"
                                :value="reason"
                            >
                                {{ reason.replaceAll('_', ' ') }}
                            </option>
                        </select></label
                    >
                    <button
                        class="min-h-11 rounded border px-3"
                        :disabled="!correctionReasons[receipt.public_id]"
                        @click="requestCorrection(receipt)"
                    >
                        Request reversing correction
                    </button>
                </template>
            </article>
            <div class="flex flex-wrap gap-3">
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="reviews.receipts.page <= 1"
                    @click="
                        router.get(
                            '/inventory',
                            {
                                receiving_receipts_page:
                                    reviews.receipts.page - 1,
                            },
                            {
                                only: ['receiving'],
                                preserveState: true,
                                preserveScroll: true,
                            },
                        )
                    "
                >
                    Previous receipts</button
                ><button
                    class="min-h-11 rounded border px-3"
                    :disabled="
                        reviews.receipts.page >= reviews.receipts.lastPage
                    "
                    @click="
                        router.get(
                            '/inventory',
                            {
                                receiving_receipts_page:
                                    reviews.receipts.page + 1,
                            },
                            {
                                only: ['receiving'],
                                preserveState: true,
                                preserveScroll: true,
                            },
                        )
                    "
                >
                    Next receipts
                </button>
            </div>

            <p class="text-sm text-muted-foreground">
                {{ reviews.total }}
                {{ reviews.total === 1 ? 'review' : 'reviews' }} · Page
                {{ reviews.page }} / {{ reviews.lastPage }} · 25 per page
            </p>
            <article
                v-for="row in reviews.rows"
                :key="row.public_id"
                class="min-w-0 space-y-2 rounded border p-3"
            >
                <h5 class="font-semibold wrap-anywhere">
                    {{ row.order_number }} · {{ row.kind }}
                </h5>
                <p class="text-sm">
                    Receiver: staff #{{ row.receiver_user_id }} ·
                    {{
                        row.consumption_id
                            ? 'Posted'
                            : row.approval_id
                              ? 'Approved'
                              : 'Awaiting independent approval'
                    }}
                </p>
                <p class="text-sm wrap-anywhere">
                    {{ row.payload.delivery_reference ?? row.payload.reason }} ·
                    {{ row.reason_codes.join(', ') }}
                </p>
                <div
                    v-for="(line, index) in row.payload.lines ?? []"
                    :key="index"
                    class="space-y-1 text-sm"
                >
                    <p>Physical quantity: {{ line.physical_quantity }}</p>
                    <p
                        v-for="(lot, lotIndex) in line.lots"
                        :key="lotIndex"
                        class="wrap-anywhere"
                    >
                        {{ lot.batch_number }} · {{ lot.expiry_date }} ·
                        {{ lot.quantity }} · {{ lot.outcome }}
                        {{ lot.reason ?? '' }}
                    </p>
                </div>
                <button
                    v-if="row.canApprove"
                    class="min-h-11 rounded border px-3"
                    :disabled="busy"
                    @click="act(row, true)"
                >
                    Approve delivery exception
                </button>
                <button
                    v-if="row.canPost"
                    class="min-h-11 rounded bg-primary px-3 text-primary-foreground"
                    :disabled="busy"
                    @click="act(row, false)"
                >
                    {{
                        row.kind === 'correction'
                            ? 'Post reversing correction'
                            : 'Post approved delivery'
                    }}
                </button>
            </article>
            <div class="flex flex-wrap gap-3">
                <button
                    class="min-h-11 rounded border px-3"
                    :disabled="reviews.page <= 1"
                    @click="load(reviews.page - 1)"
                >
                    Previous reviews</button
                ><button
                    class="min-h-11 rounded border px-3"
                    :disabled="reviews.page >= reviews.lastPage"
                    @click="load(reviews.page + 1)"
                >
                    Next reviews
                </button>
            </div>
        </template>
    </section>
</template>
