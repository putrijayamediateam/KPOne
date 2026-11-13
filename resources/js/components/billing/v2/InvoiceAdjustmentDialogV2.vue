<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2MoneyInput from '@/components/v2/V2MoneyInput.vue';
import type { BillingPage } from '@/types/billing';
import { myr, toSen } from '@/types/billing';

/**
 * BV2-2: Version 2 adjustment dialog. The script below `defineProps` is a verbatim copy of Classic
 * `components/billing/InvoiceAdjustmentDialog.vue` (same calculation, threshold check, idempotency key, payload and
 * reset rules), pinned by `tests/Frontend/bv2-2-billing-v2-dialogs.test.mjs`; change one and you must change both.
 * Only the template differs: V2Dialog (bottom sheet on a phone, pinned footer), V2MoneyInput for a fixed amount,
 * 44 px choices instead of native selects, warning tokens. Classic is untouched.
 */
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
    <div v-if="controls?.add || controls?.reverse" class="flex flex-wrap gap-2">
        <Button
            v-if="controls.add"
            variant="outline"
            class="max-lg:min-h-11"
            @click="start(false)"
            >Add adjustment</Button
        >
        <Button
            v-if="controls.reverse"
            variant="outline"
            class="max-lg:min-h-11"
            @click="start(true)"
            >Reverse adjustment</Button
        >
    </div>
    <!-- Classic blocked closing while saving; the discard question is off while busy, so the close still goes through
         the busy guard below. Classic's native "required" check becomes the disabled Confirm and the line above it. -->
    <V2Dialog
        :open="open"
        size="tall"
        :title="reverse ? 'Reverse adjustment' : 'Add adjustment'"
        :description="
            reverse
                ? 'Independent reversal of the active adjustment. The original record is retained.'
                : 'Adjust the payable amount. Original billed items and prices stay unchanged.'
        "
        :confirm-label="
            busy
                ? 'Saving…'
                : reverse
                  ? 'Reverse adjustment'
                  : 'Apply adjustment'
        "
        :tone="reverse ? 'danger' : 'default'"
        :busy="
            busy ||
            !form.reason ||
            (!reverse && !form.value) ||
            (above && (!controls?.override || !form.confirmed))
        "
        :dirty="!busy && !!(form.value || form.reason)"
        data-testid="adjustment-dialog-v2"
        @update:open="!busy && ($event ? (open = true) : reset())"
        @confirm="submit"
    >
        <dl
            class="flex justify-between rounded-[10px] bg-muted p-3 text-[15px]"
        >
            <dt>Original subtotal</dt>
            <dd class="font-semibold tabular-nums">{{ myr(base) }}</dd>
        </dl>
        <template v-if="!reverse">
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Adjustment</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="option in [
                            ['deduct', 'Deduct (discount)'],
                            ['add', 'Add (surcharge)'],
                        ]"
                        :key="option[0]"
                        class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border px-3 text-[15px] has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring lg:min-h-9"
                        ><input
                            v-model="form.direction"
                            type="radio"
                            name="adjustment-direction-v2"
                            :value="option[0]"
                            class="size-4"
                        />{{ option[1] }}</label
                    >
                </div>
            </fieldset>
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">Calculation</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="option in [
                            ['fixed', 'Fixed amount (RM)'],
                            ['percentage', 'Percentage (%)'],
                        ]"
                        :key="option[0]"
                        class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[10px] border px-3 text-[15px] has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring lg:min-h-9"
                        ><input
                            v-model="form.type"
                            type="radio"
                            name="adjustment-type-v2"
                            :value="option[0]"
                            class="size-4"
                        />{{ option[1] }}</label
                    >
                </div>
            </fieldset>
            <V2MoneyInput
                v-if="form.type === 'fixed'"
                v-model="form.value"
                label="Amount"
                :error="errors.input_value"
                :disabled="busy"
                required
            />
            <label v-else class="block space-y-1 text-sm font-medium"
                >Percentage (%)<input
                    v-model="form.value"
                    inputmode="decimal"
                    class="block min-h-11 w-full rounded-[10px] border border-input bg-card px-3 text-right text-[15px] tabular-nums lg:min-h-9"
                    :disabled="busy"
                    :aria-invalid="!!errors.input_value"
                    :aria-describedby="
                        errors.input_value
                            ? 'adjustment-percentage-error-v2'
                            : undefined
                    "
                />
                <InputError
                    id="adjustment-percentage-error-v2"
                    :message="errors.input_value"
                />
            </label>
            <p class="text-[13px] text-muted-foreground">
                Percentage uses the original subtotal, rounded once to the
                nearest sen.
            </p>
            <dl class="space-y-2 rounded-[10px] border p-3 text-[15px]">
                <div class="flex justify-between gap-3">
                    <dt>Adjustment</dt>
                    <dd class="tabular-nums">
                        {{ form.direction === 'deduct' ? '−' : '+'
                        }}{{ myr(magnitude ?? 0) }}
                    </dd>
                </div>
                <div
                    class="flex justify-between gap-3 border-t pt-2 font-semibold"
                >
                    <dt>New payable amount</dt>
                    <dd class="tabular-nums">
                        {{ preview >= 0 ? myr(preview) : '—' }}
                    </dd>
                </div>
            </dl>
            <p class="text-[13px] text-muted-foreground">
                Branch threshold:
                {{
                    controls?.threshold.configured
                        ? myr(controls.threshold.limitSen ?? 0)
                        : 'Not configured (RM 0.00 for authority checks)'
                }}
            </p>
            <div
                v-if="above"
                class="space-y-2 rounded-[10px] border border-warning/40 bg-warning/10 p-3 text-[13px]"
                role="status"
            >
                <p class="font-semibold">Above branch adjustment limit</p>
                <label
                    v-if="controls?.override"
                    class="flex min-h-11 items-start gap-2"
                    ><input
                        v-model="form.confirmed"
                        type="checkbox"
                        class="mt-0.5 size-4"
                    />I confirm this adjustment exceeds the branch threshold and
                    uses my override authority.</label
                >
                <p v-else>An authorised override holder is required.</p>
            </div>
        </template>
        <label class="block space-y-1 text-sm font-medium"
            >Reason <span class="text-destructive" aria-hidden="true">*</span
            ><span class="sr-only">(required)</span
            ><textarea
                v-model="form.reason"
                maxlength="500"
                rows="3"
                class="block w-full rounded-[10px] border border-input bg-card p-3 text-[15px] font-normal"
                :disabled="busy"
            />
        </label>
        <p class="text-[13px] text-muted-foreground">
            The reason is retained privately and does not appear on the invoice
            or activity log.
        </p>
        <div>
            <InputError
                v-for="(message, field) in errors"
                :key="field"
                :message="message"
            />
        </div>
        <!-- Directly above the footer whenever the action is disabled: what is still missing (Daniel, BV2-2). -->
        <p
            v-if="
                !busy &&
                (!form.reason ||
                    (!reverse && !form.value) ||
                    (above && (!controls?.override || !form.confirmed)))
            "
            class="text-[13px] text-muted-foreground"
            data-testid="adjustment-missing"
        >
            {{
                above && !controls?.override
                    ? 'An authorised override holder is required for an adjustment above the branch limit.'
                    : [
                          !reverse && !form.value ? 'Enter an amount.' : '',
                          !form.reason ? 'Enter a reason.' : '',
                          above && !form.confirmed
                              ? 'Tick the box to use your override authority.'
                              : '',
                      ]
                          .filter(Boolean)
                          .join(' ')
            }}
        </p>
    </V2Dialog>
</template>
