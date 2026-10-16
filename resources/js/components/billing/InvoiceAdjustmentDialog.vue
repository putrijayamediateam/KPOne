<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BillingPage } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

const props = defineProps<{ billing: BillingPage }>();
const open = ref(false);
const reverse = ref(false);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const key = ref(crypto.randomUUID());
const form = reactive({
    direction: 'deduct',
    type: 'fixed',
    value: '',
    reason: '',
    confirmed: false,
});
const controls = computed(() => props.billing.invoice?.adjustmentControls);
const base = computed(() => props.billing.invoice?.adjustment.subtotalSen ?? 0);
const magnitude = computed(() => {
    const input = toSen(form.value);

    if (input === null) {
        return null;
    }

    const n = BigInt(input);

    if (form.type === 'percentage') {
        return n > 10000n
            ? null
            : Number((BigInt(base.value) * n + 5000n) / 10000n);
    }

    return Number(n);
});
const above = computed(
    () =>
        !reverse.value &&
        (magnitude.value ?? 0) > (controls.value?.threshold.limitSen ?? 0),
);
const preview = computed(
    () =>
        base.value +
        (form.direction === 'add' ? 1 : -1) * (magnitude.value ?? 0),
);
const reset = () => {
    open.value = false;
    key.value = crypto.randomUUID();
    Object.assign(form, {
        direction: 'deduct',
        type: 'fixed',
        value: '',
        reason: '',
        confirmed: false,
    });
    errors.value = {};
};
watch(
    () => [
        props.billing.activity.branchId,
        props.billing.invoice?.publicId,
        props.billing.invoice?.lockVersion,
    ],
    reset,
);
watch(
    () => [form.direction, form.type, form.value],
    () => {
        form.confirmed = false;
    },
);
const start = (isReverse: boolean) => {
    reset();
    reverse.value = isReverse;
    open.value = true;
};
const submit = () => {
    const invoice = props.billing.invoice;

    if (!invoice || busy.value) {
        return;
    }

    errors.value = {};

    if (
        !reverse.value &&
        (magnitude.value === null ||
            magnitude.value < 1 ||
            magnitude.value > base.value)
    ) {
        errors.value.input_value =
            'Enter a positive amount up to the original subtotal, or a percentage up to 100%.';

        return;
    }

    busy.value = true;
    const url = controls.value?.url;

    if (!url) {
        busy.value = false;

        return;
    }

    router.post(
        reverse.value ? `${url}/${invoice.adjustment.activeId}/reverse` : url,
        {
            expected_branch_id: props.billing.activity.branchId,
            lock_version: invoice.lockVersion,
            idempotency_key: key.value,
            reason: form.reason,
            ...(reverse.value
                ? {}
                : {
                      direction: form.direction,
                      input_type: form.type,
                      input_value: toSen(form.value),
                      threshold_version: controls.value?.threshold.lockVersion,
                      confirm_above_limit: form.confirmed,
                  }),
        },
        {
            preserveScroll: true,
            onError: (e) => {
                errors.value = e;
            },
            onSuccess: reset,
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>

<template>
    <div v-if="controls?.add || controls?.reverse" class="mt-3 flex gap-2">
        <Button
            v-if="controls.add"
            size="sm"
            variant="outline"
            @click="start(false)"
            >Add Adjustment</Button
        >
        <Button
            v-if="controls.reverse"
            size="sm"
            variant="outline"
            @click="start(true)"
            >Reverse adjustment</Button
        >
    </div>
    <Dialog
        :open="open"
        @update:open="!busy && ($event ? (open = true) : reset())"
    >
        <DialogContent
            class="flex max-h-[90dvh] flex-col overflow-hidden sm:max-w-lg"
            :show-close-button="!busy"
            @interact-outside="busy && $event.preventDefault()"
            @escape-key-down="busy && $event.preventDefault()"
        >
            <DialogHeader class="shrink-0">
                <DialogTitle>{{
                    reverse ? 'Reverse adjustment' : 'Add Adjustment'
                }}</DialogTitle>
                <DialogDescription>{{
                    reverse
                        ? 'Independent reversal of the active adjustment. The original record is retained.'
                        : 'Adjust the payable amount. Original billed items and prices stay unchanged.'
                }}</DialogDescription>
            </DialogHeader>
            <form
                class="min-h-0 space-y-4 overflow-y-auto text-sm"
                @submit.prevent="submit"
            >
                <dl class="flex justify-between rounded-lg bg-muted p-3">
                    <dt>Original subtotal</dt>
                    <dd class="font-semibold tabular-nums">{{ myr(base) }}</dd>
                </dl>
                <template v-if="!reverse">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="space-y-1"
                            >Adjustment<select
                                v-model="form.direction"
                                class="h-10 w-full rounded-md border bg-background px-2"
                            >
                                <option value="deduct">
                                    Deduct (discount)
                                </option>
                                <option value="add">Add (surcharge)</option>
                            </select></label
                        >
                        <label class="space-y-1"
                            >Calculation<select
                                v-model="form.type"
                                class="h-10 w-full rounded-md border bg-background px-2"
                            >
                                <option value="fixed">Fixed amount (RM)</option>
                                <option value="percentage">
                                    Percentage (%)
                                </option>
                            </select></label
                        >
                    </div>
                    <label class="block space-y-1"
                        >{{
                            form.type === 'fixed'
                                ? 'Amount (RM)'
                                : 'Percentage (%)'
                        }}<input
                            v-model="form.value"
                            inputmode="decimal"
                            class="h-10 w-full rounded-md border bg-background px-3"
                            required
                            :disabled="busy"
                    /></label>
                    <InputError :message="errors.input_value" />
                    <p class="text-xs text-muted-foreground">
                        Percentage uses the original subtotal, rounded once to
                        the nearest sen.
                    </p>
                    <dl class="space-y-2 rounded-lg border p-3">
                        <div class="flex justify-between">
                            <dt>Adjustment</dt>
                            <dd>
                                {{ form.direction === 'deduct' ? '−' : '+'
                                }}{{ myr(magnitude ?? 0) }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t pt-2 font-semibold"
                        >
                            <dt>New payable amount</dt>
                            <dd>{{ preview >= 0 ? myr(preview) : '—' }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-muted-foreground">
                        Branch threshold:
                        {{
                            controls?.threshold.configured
                                ? myr(controls.threshold.limitSen ?? 0)
                                : 'Not configured (RM 0.00 for authority checks)'
                        }}
                    </p>
                    <div
                        v-if="above"
                        class="space-y-2 rounded-lg border border-amber-500/60 bg-amber-50 p-3 text-xs dark:bg-amber-950/30"
                        role="status"
                    >
                        <p class="font-semibold">
                            Above branch adjustment limit
                        </p>
                        <label
                            v-if="controls?.override"
                            class="flex items-start gap-2"
                            ><input
                                v-model="form.confirmed"
                                type="checkbox"
                                class="mt-0.5"
                            />I confirm this adjustment exceeds the branch
                            threshold and uses my override authority.</label
                        >
                        <p v-else>An authorised override holder is required.</p>
                    </div>
                </template>
                <label class="block space-y-1"
                    >Reason (required)<textarea
                        v-model="form.reason"
                        required
                        maxlength="500"
                        rows="3"
                        class="w-full rounded-md border bg-background p-3"
                        :disabled="busy"
                    />
                </label>
                <p class="text-xs text-muted-foreground">
                    The reason is retained privately and does not appear on the
                    invoice or activity log.
                </p>
                <div role="alert">
                    <InputError
                        v-for="(message, field) in errors"
                        :key="field"
                        :message="message"
                    />
                </div>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="reset"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        :disabled="
                            busy ||
                            (above && (!controls?.override || !form.confirmed))
                        "
                        >{{
                            busy
                                ? 'Saving…'
                                : reverse
                                  ? 'Reverse adjustment'
                                  : 'Apply adjustment'
                        }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
