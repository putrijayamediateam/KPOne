<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import { formatDate } from '@/lib/presentation';
type Review = {
    public_id: string;
    order_public_id: string;
    order_number: string;
    kind: string;
    receiver_user_id: number;
    receiver_name?: string | null;
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
    received_by_name?: string | null;
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

// ---- V2 only -------------------------------------------------------------------------------------------------------
const kindLabel = (kind: string) =>
    ({ receipt: 'Delivery exception', correction: 'Reversing correction' })[
        kind
    ] ?? kind;
const outcomeLabel = (outcome: string) =>
    ({
        accepted: 'Accepted',
        quarantined: 'Quarantined',
        rejected: 'Rejected',
    })[outcome] ?? outcome;
const reviewState = (row: Review) =>
    row.consumption_id
        ? { tone: 'success' as const, label: 'Posted' }
        : row.approval_id
          ? { tone: 'info' as const, label: 'Approved, waiting to post' }
          : { tone: 'warning' as const, label: 'Waiting for approval' };
const reviewMenu = (row: Review): RowMenuGroup[] => {
    const groups: RowMenuGroup[] = [
        {
            label: 'Open',
            items: [{ id: 'view', label: 'View lots', allowed: true }],
        },
    ];

    if (!row.approval_id) {
        groups.push({
            label: 'Check',
            items: [
                {
                    id: 'approve',
                    label: 'Approve',
                    allowed: row.canApprove && !busy.value,
                    reason: row.canApprove
                        ? undefined
                        : 'Needs a different CA Supervisor, Finance Officer or Director',
                },
            ],
        });
    }

    if (row.approval_id && !row.consumption_id) {
        groups.push({
            label: 'Move',
            items: [
                {
                    id: 'post',
                    label:
                        row.kind === 'correction'
                            ? 'Post the reversal'
                            : 'Post the delivery',
                    allowed: row.canPost && !busy.value,
                    reason: row.canPost
                        ? undefined
                        : 'Only the person who received it posts it',
                },
            ],
        });
    }

    return groups;
};
const viewing = ref<Review | null>(null);
const confirming = ref<{ row: Review; approve: boolean } | null>(null);
const onReviewMenu = (row: Review, id: string) => {
    if (id === 'view') {
        viewing.value = row;
    } else if (id === 'approve' || id === 'post') {
        confirming.value = { row, approve: id === 'approve' };
    }
};
const confirmOpen = computed({
    get: () => confirming.value !== null,
    set: (open: boolean) => {
        if (!open) {
            confirming.value = null;
        }
    },
});
const confirmText = computed(() => {
    const target = confirming.value;

    if (!target) {
        return { title: '', description: '', label: 'Confirm' };
    }

    if (target.approve) {
        return {
            title: `Approve ${target.row.order_number}?`,
            description:
                'The person who received it can then post it. Approving does not change stock by itself.',
            label: 'Approve',
        };
    }

    return target.row.kind === 'correction'
        ? {
              title: `Post the reversal for ${target.row.order_number}?`,
              description:
                  'The whole receipt is reversed and its stock comes off. The original evidence is kept.',
              label: 'Post the reversal',
          }
        : {
              title: `Post the delivery for ${target.row.order_number}?`,
              description:
                  'The approved delivery is posted and its accepted and quarantined lots enter stock.',
              label: 'Post the delivery',
          };
});
const runConfirmed = () => {
    const target = confirming.value;
    confirming.value = null;

    if (target) {
        act(target.row, target.approve);
    }
};
const correctionReasonOptions = [
    'duplicate_delivery',
    'incorrect_quantity',
    'incorrect_batch',
    'other_approved',
].map((reason) => ({ value: reason, label: formatInventoryReason(reason) }));
const correcting = ref<ReceiptRow | null>(null);
const correctionError = ref('');
const receiptMenu = (row: ReceiptRow): RowMenuGroup[] =>
    row.canCorrect
        ? [
              {
                  label: 'Danger',
                  items: [
                      {
                          id: 'correct',
                          label: 'Request a reversing correction',
                          allowed: true,
                          tone: 'danger',
                      },
                  ],
              },
          ]
        : [];
const correctionOpen = computed({
    get: () => correcting.value !== null,
    set: (open: boolean) => {
        if (!open) {
            correcting.value = null;
            correctionError.value = '';
        }
    },
});
const submitCorrection = () => {
    const row = correcting.value;

    if (!row) {
        return;
    }

    if (!correctionReasons[row.public_id]) {
        correctionError.value = 'Choose why this receipt is being reversed.';

        return;
    }

    requestCorrection(row);
    correcting.value = null;
};
const receiptsPage = (number: number) =>
    router.get(
        '/inventory',
        { receiving_receipts_page: number },
        { only: ['receiving'], preserveState: true, preserveScroll: true },
    );
</script>

<template>
    <section class="space-y-3" aria-labelledby="v2-receiving-reviews-title">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3
                id="v2-receiving-reviews-title"
                class="text-[15px] font-semibold"
            >
                Receiving reviews
            </h3>
            <button
                type="button"
                class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                @click="load()"
            >
                {{ reviews ? 'Refresh' : 'Load receiving reviews' }}
            </button>
        </div>
        <p class="text-sm text-muted-foreground">
            A delivery with an exception, and any reversing correction, needs a
            different CA Supervisor, Finance Officer or Director to approve it.
            The person who received it then posts it. Quarantined stock is
            released separately, as a batch hold release.
        </p>
        <div
            v-if="errors.length"
            role="alert"
            class="rounded-[14px] border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <p v-for="error in errors" :key="error">{{ error }}</p>
        </div>

        <template v-if="reviews">
            <V2Table
                label="Receiving reviews"
                :columns="4"
                cards="xl"
                min-width="760px"
                :empty="reviews.rows.length === 0"
                empty-message="Nothing is waiting for a receiving review."
            >
                <template #head>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Received by</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="row in reviews.rows" :key="row.public_id">
                        <td data-label="Order" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    kindLabel(row.kind)
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    >{{ row.order_number }} ·
                                    {{
                                        row.payload.delivery_reference ??
                                        formatInventoryReason(
                                            row.payload.reason,
                                        )
                                    }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Received by">
                            {{ row.receiver_name ?? 'Staff member' }}
                        </td>
                        <td data-label="Status">
                            <span class="whitespace-nowrap"
                                ><V2Chip
                                    :tone="reviewState(row).tone"
                                    :label="reviewState(row).label"
                            /></span>
                        </td>
                        <td data-actions class="text-right">
                            <V2RowMenu
                                :label="`Actions for ${row.order_number}`"
                                :groups="reviewMenu(row)"
                                :busy="busy"
                                @select="(id) => onReviewMenu(row, id)"
                            />
                        </td>
                    </tr>
                </template>
            </V2Table>
            <V2Pager
                v-if="reviews.lastPage > 1"
                :current-page="reviews.page"
                :last-page="reviews.lastPage"
                :total="reviews.total"
                :per-page="25"
                :disabled="busy"
                @change="(number) => load(number)"
            />

            <h4 class="pt-2 text-[15px] font-semibold">Posted receipts</h4>
            <V2Table
                label="Posted receipts"
                :columns="3"
                dense
                cards="xl"
                min-width="640px"
                :empty="reviews.receipts.rows.length === 0"
                empty-message="No receipts have been posted at this branch yet."
            >
                <template #head>
                    <tr>
                        <th scope="col">Receipt</th>
                        <th scope="col">Received</th>
                        <th scope="col" class="text-right">Actions</th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="receipt in reviews.receipts.rows"
                        :key="receipt.public_id"
                    >
                        <td data-label="Receipt" data-primary>
                            <div class="min-w-0">
                                <span class="block font-medium break-words">{{
                                    receipt.order_number
                                }}</span>
                                <span
                                    class="block text-xs break-words text-muted-foreground"
                                    >{{ receipt.delivery_reference }} ·
                                    {{
                                        receipt.correction_id
                                            ? 'Reversed by a correction'
                                            : 'Original receipt'
                                    }}</span
                                >
                            </div>
                        </td>
                        <td data-label="Received">
                            {{ receipt.received_at_local }} ·
                            {{ receipt.received_by_name ?? 'Staff member' }}
                        </td>
                        <td data-actions class="text-right">
                            <V2RowMenu
                                v-if="receipt.canCorrect"
                                :label="`Actions for ${receipt.order_number}`"
                                :groups="receiptMenu(receipt)"
                                :busy="busy"
                                @select="correcting = receipt"
                            />
                        </td>
                    </tr>
                </template>
            </V2Table>
            <V2Pager
                v-if="reviews.receipts.lastPage > 1"
                :current-page="reviews.receipts.page"
                :last-page="reviews.receipts.lastPage"
                :total="reviews.receipts.total"
                :per-page="25"
                @change="receiptsPage"
            />
        </template>
    </section>

    <V2Dialog
        :open="viewing !== null"
        :title="
            viewing
                ? `${kindLabel(viewing.kind)} · ${viewing.order_number}`
                : ''
        "
        confirm-label="Done"
        cancel-label="Close"
        @update:open="(open) => !open && (viewing = null)"
        @confirm="viewing = null"
    >
        <template v-if="viewing">
            <p class="text-sm">
                {{ viewing.reason_codes.map(formatInventoryReason).join(', ') }}
            </p>
            <div
                v-for="(line, index) in viewing.payload.lines ?? []"
                :key="index"
                class="space-y-1 rounded-[14px] border p-3 text-sm"
            >
                <p class="font-medium">Counted {{ line.physical_quantity }}</p>
                <p
                    v-for="(entry, lotIndex) in line.lots"
                    :key="lotIndex"
                    class="wrap-anywhere"
                >
                    {{ entry.batch_number }} · Expires
                    {{ formatDate(entry.expiry_date) }} · {{ entry.quantity }} ·
                    {{ outcomeLabel(entry.outcome) }}
                    {{ formatInventoryReason(entry.reason) }}
                </p>
            </div>
        </template>
    </V2Dialog>

    <V2Dialog
        v-model:open="confirmOpen"
        :title="confirmText.title"
        :description="confirmText.description"
        :confirm-label="confirmText.label"
        cancel-label="Go back"
        :busy="busy"
        @confirm="runConfirmed"
    />

    <V2Dialog
        v-model:open="correctionOpen"
        :title="correcting ? `Reverse receipt ${correcting.order_number}?` : ''"
        description="A different CA Supervisor, Finance Officer or Director must approve the reversal before it posts. The original receipt is kept."
        confirm-label="Request reversal"
        cancel-label="Go back"
        tone="danger"
        @confirm="submitCorrection"
    >
        <V2OptionGroup
            v-if="correcting"
            :model-value="correctionReasons[correcting.public_id] ?? ''"
            label="Why is this receipt being reversed?"
            required
            :options="correctionReasonOptions"
            :error="correctionError || undefined"
            @update:model-value="
                (value) => {
                    if (correcting) {
                        correctionReasons[correcting.public_id] = value;
                        resetCorrectionKey(correcting.public_id);
                        correctionError = '';
                    }
                }
            "
        />
    </V2Dialog>
</template>
