<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { formatInventoryReason } from '@/lib/inventory-picker';
import type { InventoryDocument } from '@/types/inventory-operations';
const props = defineProps<{
    row: InventoryDocument;
    branchId: number;
    nonce: string;
}>();
const lot = () => ({
    batch_number: '',
    expiry_date: '',
    quantity: '',
    outcome: 'accepted',
    reason: null as string | null,
    unit_cost_sen: null as number | null,
    unit: null as string | null,
});
const form = useForm({
    expected_branch_id: props.branchId,
    lock_version: props.row.lockVersion,
    delivery_reference: '',
    idempotency_key: crypto.randomUUID(),
    retry_key: crypto.randomUUID(),
    receipt_session_nonce: props.nonce,
    lines: props.row.lines.map((line) => ({
        included: true,
        line_public_id: line.publicId,
        physical_quantity: '',
        short_delivery: false,
        lots: [lot()],
    })),
});
const reasons = [
    'damaged',
    'wrong_item',
    'short_expiry',
    'documentation_missing',
    'other_approved',
];
const submit = (review: boolean) => {
    form.expected_branch_id = props.branchId;
    form.lock_version = props.row.lockVersion;
    form.receipt_session_nonce = props.nonce;
    form.transform((data) => ({
        ...data,
        lines: data.lines
            .filter((line) => line.included)
            .map(({ included, ...line }) => {
                void included;

                return {
                    ...line,
                    lots: line.lots.map((lot) => ({
                        ...lot,
                        unit:
                            props.row.lines.find(
                                (source) =>
                                    source.publicId === line.line_public_id,
                            )?.stockUnit ?? null,
                    })),
                };
            }),
    })).post(
        `/inventory/purchase-orders/${props.row.publicId}/${review ? 'receiving-reviews' : 'receipts'}`,
        { preserveScroll: true },
    );
};
watch(
    () => [form.delivery_reference, JSON.stringify(form.lines)],
    () => {
        form.idempotency_key = crypto.randomUUID();
        form.retry_key = crypto.randomUUID();
    },
);
watch(
    () => [props.branchId, props.row.lockVersion],
    () => {
        form.reset();
        form.clearErrors();
    },
);
</script>
<template>
    <details class="min-w-0 rounded-lg border p-3">
        <summary class="min-h-11 cursor-pointer py-3 font-medium">
            Split lots and delivery exceptions
        </summary>
        <p class="mb-3 text-sm text-muted-foreground">
            A real future expiry and delivery reference are required. Enter SKU
            base stock units, never purchase packs; identity factor 1.000.
            Maximum 100 lots per receipt. Exceptions need a different approver
            before posting. Quarantine holds the whole batch at this branch,
            including any earlier balance. Nothing here releases a hold.
        </p>
        <form class="space-y-4" @submit.prevent="submit(false)">
            <label class="grid gap-1 text-sm"
                >Delivery note or supplier invoice reference<input
                    v-model="form.delivery_reference"
                    maxlength="120"
                    required
                    class="h-11 min-w-0 rounded border bg-background px-3"
            /></label>
            <fieldset
                v-for="(line, index) in form.lines"
                :key="line.line_public_id"
                class="min-w-0 space-y-3 rounded border p-3"
            >
                <legend class="max-w-full px-1 text-sm wrap-anywhere">
                    {{ row.lines[index].sku }}
                </legend>
                <label class="flex min-h-11 items-center gap-2"
                    ><input
                        v-model="line.included"
                        type="checkbox"
                        class="size-5"
                    />Include this order line</label
                >
                <template v-if="line.included">
                    <label class="grid gap-1 text-sm"
                        >Physical quantity counted ({{
                            row.lines[index].stockUnit
                        }})<input
                            v-model="line.physical_quantity"
                            inputmode="decimal"
                            required
                            class="h-11 min-w-0 rounded border bg-background px-3"
                    /></label>
                    <label class="flex min-h-11 items-center gap-2 text-sm"
                        ><input
                            v-model="line.short_delivery"
                            type="checkbox"
                            class="size-5"
                        />Declared short delivery (requires approval)</label
                    >
                    <div
                        v-for="(entry, number) in line.lots"
                        :key="number"
                        class="grid min-w-0 gap-2 rounded border p-3 sm:grid-cols-2"
                    >
                        <p class="font-medium sm:col-span-2">
                            Lot {{ number + 1 }}
                        </p>
                        <label class="grid gap-1 text-sm"
                            >Batch number<input
                                v-model="entry.batch_number"
                                maxlength="100"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Real expiry date<input
                                v-model="entry.expiry_date"
                                type="date"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Lot quantity<input
                                v-model="entry.quantity"
                                inputmode="decimal"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                        /></label>
                        <label class="grid gap-1 text-sm"
                            >Outcome<select
                                v-model="entry.outcome"
                                class="h-11 min-w-0 rounded border bg-background px-3"
                                @change="entry.reason = null"
                            >
                                <option>accepted</option>
                                <option>quarantined</option>
                                <option>rejected</option>
                            </select></label
                        >
                        <label
                            v-if="entry.outcome !== 'accepted'"
                            class="grid gap-1 text-sm"
                            >Controlled reason<select
                                v-model="entry.reason"
                                required
                                class="h-11 min-w-0 rounded border bg-background px-3"
                            >
                                <option :value="null" disabled>
                                    Choose a reason
                                </option>
                                <option
                                    v-for="reason in reasons"
                                    :key="reason"
                                    :value="reason"
                                >
                                    {{ formatInventoryReason(reason) }}
                                </option>
                            </select></label
                        >
                        <button
                            type="button"
                            class="min-h-11 rounded border px-3"
                            :disabled="line.lots.length === 1"
                            @click="line.lots.splice(number, 1)"
                        >
                            Remove lot
                        </button>
                    </div>
                    <button
                        type="button"
                        class="min-h-11 rounded border px-3"
                        :disabled="line.lots.length >= 20"
                        @click="line.lots.push(lot())"
                    >
                        Add lot (maximum 20)
                    </button>
                </template>
            </fieldset>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    class="min-h-11 rounded bg-primary px-4 text-primary-foreground"
                    :disabled="form.processing"
                >
                    Post receipt</button
                ><button
                    type="button"
                    class="min-h-11 rounded border px-4"
                    :disabled="form.processing"
                    @click="submit(true)"
                >
                    Request exception approval
                </button>
            </div>
        </form>
    </details>
</template>
