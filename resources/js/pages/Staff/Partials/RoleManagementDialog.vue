<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { formatRoleLabel } from '@/lib/displayLabels';

const props = defineProps<{
    staffId: number;
    currentRoles: string[];
    roles: string[];
}>();
const open = ref(false);
const form = useForm({ roles: [...props.currentRoles] });
const toggle = (role: string, checked: boolean) => {
    form.roles = checked
        ? [...form.roles, role]
        : form.roles.filter((item) => item !== role);
};

const submit = () =>
    form.put(`/staff/${props.staffId}/roles`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button variant="outline"
                ><ShieldCheck class="size-4" /> Manage roles</Button
            ></DialogTrigger
        >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader
                ><DialogTitle>Manage staff roles</DialogTitle
                ><DialogDescription
                    >Roles change effective application authority. At least one
                    approved KPOne role must remain assigned.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="role in roles"
                        :key="role"
                        class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm hover:bg-muted/30"
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 accent-emerald-700"
                            :checked="form.roles.includes(role)"
                            @change="
                                toggle(
                                    role,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <span class="font-medium">{{
                            formatRoleLabel(role)
                        }}</span>
                    </label>
                </div>
                <InputError :message="form.errors.roles" />
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >Cancel</Button
                    ><Button type="submit" :disabled="form.processing"
                        >Save roles</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
