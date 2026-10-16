<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { myr, toSen } from '@/types/billing';
const props = defineProps<{
    branchId: number;
    threshold: {
        configured: boolean;
        limitSen: number | null;
        lockVersion: number;
    };
}>();
const amount = ref(
    props.threshold.limitSen === null
        ? ''
        : (props.threshold.limitSen / 100).toFixed(2),
);
const busy = ref(false);
const errors = ref<Record<string, string>>({});
const key = ref(crypto.randomUUID());
watch(
    () => [props.branchId, props.threshold.lockVersion],
    () => {
        amount.value =
            props.threshold.limitSen === null
                ? ''
                : (props.threshold.limitSen / 100).toFixed(2);
        key.value = crypto.randomUUID();
        errors.value = {};
    },
);
const save = () => {
    const sen = amount.value.trim() === '' ? null : toSen(amount.value);

    if (amount.value.trim() !== '' && sen === null) {
        errors.value = {
            limit_sen:
                'Enter a bounded amount with at most two decimal places.',
        };

        return;
    }

    busy.value = true;
    router.post(
        '/billing-approval-limits/adjustment-threshold',
        {
            expected_branch_id: props.branchId,
            lock_version: props.threshold.lockVersion,
            limit_sen: sen,
            idempotency_key: key.value,
        },
        {
            preserveScroll: true,
            onError: (e) => {
                errors.value = e;
            },
            onSuccess: () => {
                key.value = crypto.randomUUID();
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
};
</script>
<template>
    <section class="space-y-3 rounded-xl border bg-card p-4 text-sm">
        <h2 class="font-semibold">Invoice adjustment threshold</h2>
        <p class="text-xs text-muted-foreground">
            One branch-wide threshold, separate from individual Panel and
            pay-later approval limits. A blank value means not configured. Both
            blank and RM 0.00 require override authority for any positive
            adjustment.
        </p>
        <p class="text-xs">
            Current:
            {{
                threshold.configured
                    ? myr(threshold.limitSen ?? 0)
                    : 'Not configured'
            }}
        </p>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="save">
            <label class="space-y-1"
                >Threshold (RM)<input
                    v-model="amount"
                    inputmode="decimal"
                    class="block h-10 w-44 rounded-md border bg-background px-3"
                    :disabled="busy" /></label
            ><Button type="submit" size="sm" :disabled="busy"
                >Save threshold</Button
            >
        </form>
        <div role="alert">
            <InputError
                v-for="(message, field) in errors"
                :key="field"
                :message="message"
            />
        </div>
    </section>
</template>
