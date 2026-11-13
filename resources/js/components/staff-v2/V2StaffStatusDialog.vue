<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import V2Dialog from '@/components/v2/V2Dialog.vue';

/** Version 2 activate or deactivate question. Same request as Classic: PATCH /staff/{id}/status. */
const props = defineProps<{
    open: boolean;
    staffId: number;
    staffName: string;
    isActive: boolean;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({ is_active: !props.isActive });

// The form is made once, but the page's state changes after each save: always send the opposite of what the page shows now.
const confirm = () => {
    form.is_active = !props.isActive;

    return form.patch(`/staff/${props.staffId}/status`, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
};
</script>

<template>
    <V2Dialog
        :open="open"
        :title="
            isActive ? 'Deactivate staff account?' : 'Activate staff account?'
        "
        :description="
            isActive
                ? `${staffName} will be unable to sign in, and any existing session will be rejected on its next request. Historical records remain intact.`
                : `${staffName} will regain access according to their current roles and effective branch assignments.`
        "
        :confirm-label="
            isActive ? 'Confirm deactivation' : 'Confirm activation'
        "
        :tone="isActive ? 'danger' : 'default'"
        :busy="form.processing"
        @update:open="emit('update:open', $event)"
        @confirm="confirm"
    />
</template>
