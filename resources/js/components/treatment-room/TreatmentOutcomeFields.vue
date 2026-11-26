<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { OperationalSelect } from '@/components/ui/select';
const outcome = defineModel<string>('outcome', { required: true });
const reason = defineModel<string>('reason', { required: true });
defineProps<{
    reasons: { value: string; label: string }[];
    disabled?: boolean;
    errors: Record<string, string>;
    idPrefix: string;
}>();
const outcomes = [
    { value: 'done', label: 'Done' },
    { value: 'not_successful', label: 'Not successful' },
];
</script>
<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1">
            <label :for="`${idPrefix}-outcome`" class="text-sm font-medium"
                >Outcome</label
            >
            <OperationalSelect
                :id="`${idPrefix}-outcome`"
                v-model="outcome"
                label="Outcome"
                :options="outcomes"
                :disabled="disabled"
                trigger-class="h-9 w-full max-lg:min-h-11"
                @update:model-value="reason = ''"
            />
            <InputError :message="errors.outcome_code" />
        </div>
        <div v-if="outcome === 'not_successful'" class="space-y-1">
            <label :for="`${idPrefix}-reason`" class="text-sm font-medium"
                >Reason</label
            >
            <OperationalSelect
                :id="`${idPrefix}-reason`"
                v-model="reason"
                label="Reason"
                :options="[{ value: '', label: 'Choose a reason' }, ...reasons]"
                :disabled="disabled"
                trigger-class="h-9 w-full max-lg:min-h-11"
            />
            <InputError :message="errors.reason_code" />
        </div>
    </div>
</template>
