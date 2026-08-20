<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, Plus } from '@lucide/vue';
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
import type { StaffAssignment, StaffBranch } from '@/types';

const props = defineProps<{
    staffId: number;
    branches: StaffBranch[];
    today: string;
    assignment?: StaffAssignment;
}>();
const open = ref(false);
const form = useForm({
    branch_id: props.assignment?.branch.id ?? '',
    assignment_type: props.assignment?.assignmentType ?? 'permanent',
    is_primary: props.assignment?.isPrimary ?? false,
    valid_from: props.assignment?.validFrom ?? props.today,
    valid_until: props.assignment?.validUntil ?? '',
});
const endForm = useForm({ valid_until: props.today });
const formError = (key: string) => (form.errors as Record<string, string>)[key];
const endError = (key: string) =>
    (endForm.errors as Record<string, string>)[key];

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    if (props.assignment) {
        form.patch(
            `/staff/${props.staffId}/branch-assignments/${props.assignment.id}`,
            options,
        );
    } else {
        form.post(`/staff/${props.staffId}/branch-assignments`, options);
    }
};

const endAssignment = () =>
    endForm.patch(
        `/staff/${props.staffId}/branch-assignments/${props.assignment?.id}/end`,
        { preserveScroll: true, onSuccess: () => (open.value = false) },
    );
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button :variant="assignment ? 'outline' : 'default'" size="sm"
                ><component
                    :is="assignment ? CalendarClock : Plus"
                    class="size-4"
                />
                {{ assignment ? 'Edit' : 'Add assignment' }}</Button
            >
        </DialogTrigger>
        <DialogContent class="sm:max-w-lg">
            <DialogHeader
                ><DialogTitle>{{
                    assignment
                        ? 'Update branch assignment'
                        : 'Add branch assignment'
                }}</DialogTitle
                ><DialogDescription
                    >Effective dates control current branch access. Temporary
                    assignments require an inclusive end
                    date.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <label class="text-sm font-medium" for="assignment-branch"
                        >Branch</label
                    >
                    <select
                        id="assignment-branch"
                        v-model="form.branch_id"
                        :disabled="!!assignment"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option value="" disabled>Select branch</option>
                        <option
                            v-for="branch in branches"
                            :key="branch.id"
                            :value="branch.id"
                        >
                            {{ branch.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.branch_id" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium"
                            >Assignment type</label
                        ><select
                            v-model="form.assignment_type"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="permanent">Permanent</option>
                            <option value="temporary">Temporary</option></select
                        ><InputError :message="form.errors.assignment_type" />
                    </div>
                    <label
                        v-if="!assignment"
                        class="mt-7 flex items-center gap-2 text-sm"
                        ><input
                            v-model="form.is_primary"
                            type="checkbox"
                            class="size-4 accent-emerald-700"
                        />
                        Set as primary branch</label
                    >
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <label class="text-sm font-medium">Valid from</label
                        ><input
                            v-model="form.valid_from"
                            type="date"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.valid_from" />
                    </div>
                    <div class="grid gap-2">
                        <label class="text-sm font-medium"
                            >Valid through (inclusive)</label
                        ><input
                            v-model="form.valid_until"
                            type="date"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.valid_until" />
                    </div>
                </div>
                <InputError :message="formError('assignment')" />
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >Cancel</Button
                    ><Button type="submit" :disabled="form.processing">{{
                        assignment ? 'Save assignment' : 'Add assignment'
                    }}</Button></DialogFooter
                >
            </form>
            <div
                v-if="assignment && assignment.state !== 'ended'"
                class="border-t pt-4"
            >
                <div class="mb-3">
                    <div class="text-sm font-medium">
                        Set assignment end date
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Access remains valid through the selected date and
                        becomes ineffective the following day. Immediate
                        same-day revocation is not supported. A current primary
                        assignment must be changed first.
                    </p>
                </div>
                <div class="flex items-end gap-2">
                    <label class="grid flex-1 gap-1 text-xs font-medium"
                        >Access valid through (inclusive)<input
                            v-model="endForm.valid_until"
                            type="date"
                            class="h-9 rounded-md border bg-background px-3 text-sm" /></label
                    ><Button
                        variant="destructive"
                        size="sm"
                        :disabled="endForm.processing"
                        @click="endAssignment"
                        >Set end date</Button
                    >
                </div>
                <InputError
                    class="mt-2"
                    :message="
                        endError('assignment') ?? endForm.errors.valid_until
                    "
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
