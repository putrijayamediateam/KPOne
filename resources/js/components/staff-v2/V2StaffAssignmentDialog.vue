<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Picker from '@/components/v2/V2Picker.vue';
import type { StaffAssignment, StaffBranch } from '@/types';

/**
 * Version 2 branch assignment dialog: add (no `assignment`) or update (with one), plus "Set assignment end date" for an
 * assignment that has not ended. Same requests as Classic: POST /staff/{id}/branch-assignments, PATCH .../{assignment}
 * and PATCH .../{assignment}/end.
 */
const props = defineProps<{
    open: boolean;
    staffId: number;
    branches: StaffBranch[];
    today: string;
    assignment?: StaffAssignment;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm({
    branch_id: props.assignment?.branch.id ?? '',
    assignment_type: props.assignment?.assignmentType ?? 'permanent',
    is_primary: props.assignment?.isPrimary ?? false,
    valid_from: props.assignment?.validFrom ?? props.today,
    valid_until: props.assignment?.validUntil ?? '',
});
const endForm = useForm({ valid_until: props.today });
// Text a date box could not read: Save is blocked, so an unreadable end date is never saved as "no end date".
const badFrom = ref(false);
const badUntil = ref(false);
const badEnd = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            endForm.reset();
            endForm.clearErrors();
        }
    },
);

const errorOf = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

const endError = (key: string) =>
    (endForm.errors as Record<string, string | undefined>)[key];

const close = () => emit('update:open', false);

const save = () => {
    if (badFrom.value || badUntil.value) {
        return;
    }

    const options = { preserveScroll: true, onSuccess: close };

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
    !badEnd.value &&
    endForm.patch(
        `/staff/${props.staffId}/branch-assignments/${props.assignment?.id}/end`,
        { preserveScroll: true, onSuccess: close },
    );
</script>

<template>
    <V2Dialog
        :open="open"
        :title="
            assignment ? 'Update branch assignment' : 'Add branch assignment'
        "
        description="Effective dates control current branch access. Temporary assignments require an inclusive end date."
        :confirm-label="assignment ? 'Save assignment' : 'Add assignment'"
        :busy="form.processing"
        size="tall"
        @update:open="emit('update:open', $event)"
        @confirm="save"
    >
        <V2Picker
            :model-value="form.branch_id === '' ? null : String(form.branch_id)"
            label="Branch"
            placeholder="Choose a branch"
            :selected-label="
                branches.find((b) => String(b.id) === String(form.branch_id))
                    ?.name
            "
            :options="
                branches.map((b) => ({ id: String(b.id), label: b.name }))
            "
            :disabled="!!assignment"
            required
            :error="form.errors.branch_id"
            @update:model-value="
                form.branch_id =
                    branches.find((b) => String(b.id) === $event)?.id ?? ''
            "
        />
        <V2Picker
            :model-value="form.assignment_type"
            label="Assignment type"
            :selected-label="
                form.assignment_type === 'temporary' ? 'Temporary' : 'Permanent'
            "
            :options="[
                { id: 'permanent', label: 'Permanent' },
                { id: 'temporary', label: 'Temporary' },
            ]"
            required
            :error="form.errors.assignment_type"
            @update:model-value="form.assignment_type = $event ?? 'permanent'"
        />
        <label
            v-if="!assignment"
            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-[10px] border border-border px-3 text-sm font-medium hover:bg-muted lg:min-h-9"
        >
            <input
                v-model="form.is_primary"
                type="checkbox"
                class="size-5 shrink-0 accent-primary"
            />
            Set as primary branch
        </label>
        <V2DateField
            v-model="form.valid_from"
            label="Valid from"
            weekday
            required
            :error="form.errors.valid_from"
            @update:invalid="badFrom = $event"
        />
        <V2DateField
            v-model="form.valid_until"
            label="Valid through (inclusive)"
            weekday
            :hint="
                form.errors.valid_until
                    ? undefined
                    : form.valid_until
                      ? 'The last day counts (inclusive).'
                      : 'No end date.'
            "
            :error="form.errors.valid_until"
            @update:invalid="badUntil = $event"
        />
        <p
            v-if="errorOf('assignment')"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ errorOf('assignment') }}
        </p>

        <section
            v-if="assignment && assignment.state !== 'ended'"
            class="mt-2 flex flex-col gap-3 border-t pt-4"
            aria-labelledby="v2-end-assignment"
        >
            <div>
                <h3 id="v2-end-assignment" class="text-sm font-semibold">
                    Set assignment end date
                </h3>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    Access remains valid through the selected date and becomes
                    ineffective the following day. Immediate same-day revocation
                    is not supported. A current primary assignment must be
                    changed first.
                </p>
            </div>
            <V2DateField
                v-model="endForm.valid_until"
                label="Access valid through (inclusive)"
                weekday
                required
                :error="endForm.errors.valid_until"
                @update:invalid="badEnd = $event"
            />
            <p
                v-if="endError('assignment')"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ endError('assignment') }}
            </p>
            <button
                type="button"
                :disabled="endForm.processing"
                class="inline-flex h-11 items-center justify-center self-start rounded-[10px] bg-destructive px-4 text-sm font-medium text-destructive-foreground outline-none hover:bg-destructive/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:opacity-50 lg:h-9"
                @click="endAssignment"
            >
                Set end date
            </button>
        </section>
    </V2Dialog>
</template>
