<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { DayCloseContext } from '@/types/billing';
import { myr } from '@/types/billing';

/**
 * BIL-32: while a day's cash count waits for approval, money actions of that day are paused; once approved, the day
 * is locked and its receipts can only be refunded. A locked day's receipt is reversed and refunded in one action
 * (owner Q5 = A), so the locked day's cash figure does not change. The server decides; this only explains.
 */
const props = defineProps<{
    dayClose: DayCloseContext;
    base: string;
    invoiceLockVersion: number | null;
    manage?: boolean;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const open = ref<string | null>(null);
const busy = ref(false);
const form = reactive({ reason: '', method: '', reasonCode: '', key: '' });
const errorMessage = computed(
    () =>
        errors.value.correction ??
        errors.value.refund ??
        errors.value.reason ??
        errors.value.reason_code ??
        errors.value.payment_method_id,
);

const start = (publicId: string) => {
    open.value = publicId;
    Object.assign(form, {
        reason: '',
        method: '',
        reasonCode: '',
        key: crypto.randomUUID(),
    });
};

const submit = (receipt: DayCloseContext['lockedReceipts'][number]) => {
    busy.value = true;
    router.post(
        `${props.base}/payments/${receipt.publicId}/refund-with-reversal`,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            return_to: props.manage ? 'payments' : 'billing',
            lock_version: props.invoiceLockVersion,
            payment_lock_version: receipt.lockVersion,
            reason: form.reason,
            payment_method_id: form.method,
            reason_code: form.reasonCode,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                open.value = null;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>

<template>
    <section
        class="space-y-3 rounded-xl border p-4 text-sm"
        :class="
            dayClose.paused
                ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100'
                : 'border-border bg-muted/40'
        "
        aria-label="Daily cash close"
        data-testid="day-close-notice"
    >
        <p class="font-medium" role="status">{{ dayClose.message }}</p>
        <ul v-if="dayClose.lockedReceipts.length" class="space-y-3">
            <li
                v-for="receipt in dayClose.lockedReceipts"
                :key="receipt.publicId"
                class="space-y-2"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="tabular-nums"
                        >{{ receipt.number }} · {{ myr(receipt.amountSen) }} ·
                        day locked</span
                    >
                    <Button
                        v-if="
                            dayClose.canRefundLocked &&
                            open !== receipt.publicId
                        "
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :data-testid="`refund-locked-${receipt.publicId}`"
                        @click="start(receipt.publicId)"
                        >Reverse and refund</Button
                    >
                </div>
                <form
                    v-if="open === receipt.publicId"
                    class="grid gap-2 sm:grid-cols-2"
                    @submit.prevent="submit(receipt)"
                >
                    <p class="text-muted-foreground sm:col-span-2">
                        The receipt is reversed and the money given back in one
                        step. Someone else must then approve the refund. This
                        day's cash figure does not change.
                    </p>
                    <label class="grid gap-1 sm:col-span-2"
                        >Why is it reversed?<textarea
                            v-model="form.reason"
                            maxlength="500"
                            class="min-h-16 rounded-md border border-input bg-background px-2 py-1 text-sm"
                            required
                        />
                    </label>
                    <label class="grid gap-1"
                        >Given back by<select
                            v-model="form.method"
                            class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                            required
                        >
                            <option value="" disabled>Choose</option>
                            <option
                                v-for="m in dayClose.methods"
                                :key="m.id"
                                :value="String(m.id)"
                            >
                                {{ m.name }}
                            </option>
                        </select></label
                    >
                    <label class="grid gap-1"
                        >Refund reason<select
                            v-model="form.reasonCode"
                            class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                            required
                        >
                            <option value="" disabled>Choose</option>
                            <option
                                v-for="(label, code) in dayClose.reasons"
                                :key="code"
                                :value="code"
                            >
                                {{ label }}
                            </option>
                        </select></label
                    >
                    <InputError class="sm:col-span-2" :message="errorMessage" />
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="busy"
                            @click="open = null"
                            >Cancel</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            class="max-lg:min-h-11"
                            :disabled="
                                busy ||
                                !form.reason ||
                                !form.method ||
                                !form.reasonCode
                            "
                            >Reverse and record refund</Button
                        >
                    </div>
                </form>
            </li>
        </ul>
    </section>
</template>
