<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Power } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

const props = defineProps<{
    staffId: number;
    staffName: string;
    isActive: boolean;
}>();
const open = ref(false);
const form = useForm({ is_active: !props.isActive });
const submit = () =>
    form.patch(`/staff/${props.staffId}/status`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button :variant="isActive ? 'destructive' : 'default'"
                ><Power class="size-4" />
                {{ isActive ? 'Deactivate' : 'Activate' }}</Button
            ></DialogTrigger
        >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    isActive
                        ? 'Deactivate staff account?'
                        : 'Activate staff account?'
                }}</DialogTitle>
                <DialogDescription v-if="isActive"
                    >{{ staffName }} will be unable to sign in, and any existing
                    session will be rejected on its next request. Historical
                    records remain intact.</DialogDescription
                >
                <DialogDescription v-else
                    >{{ staffName }} will regain access according to their
                    current roles and effective branch
                    assignments.</DialogDescription
                >
            </DialogHeader>
            <DialogFooter
                ><Button variant="outline" @click="open = false">Cancel</Button
                ><Button
                    :variant="isActive ? 'destructive' : 'default'"
                    :disabled="form.processing"
                    @click="submit"
                    >Confirm
                    {{ isActive ? 'deactivation' : 'activation' }}</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>
