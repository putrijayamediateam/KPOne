<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import { echoDate as echo } from '@/lib/v2-date-echo';
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
    endForm.patch(
        `/staff/${props.staffId}/branch-assignments/${props.assignment?.id}/end`,
        { preserveScroll: true, onSuccess: close },
    );

const input =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 md:text-[15px] lg:h-9';
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
        <V2Field
            v-slot="{ id, describedBy }"
            label="Branch"
            :error="form.errors.branch_id"
        >
            <select
                :id="id"
                v-model="form.branch_id"
                :disabled="!!assignment"
                :aria-describedby="describedBy"
                :class="[
                    input,
                    form.errors.branch_id
                        ? 'border-destructive'
                        : 'border-input',
                ]"
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
        </V2Field>
        <V2Field
            v-slot="{ id, describedBy }"
            label="Assignment type"
            :error="form.errors.assignment_type"
        >
            <select
                :id="id"
                v-model="form.assignment_type"
                :aria-describedby="describedBy"
                :class="[input, 'border-input']"
            >
                <option value="permanent">Permanent</option>
                <option value="temporary">Temporary</option>
            </select>
        </V2Field>
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
        <V2Field
            v-slot="{ id, describedBy }"
            label="Valid from"
            :hint="echo(form.valid_from, 'From')"
            :error="form.errors.valid_from"
        >
            <input
                :id="id"
                v-model="form.valid_from"
                type="date"
                :aria-describedby="describedBy"
                :class="[
                    input,
                    form.errors.valid_from
                        ? 'border-destructive'
                        : 'border-input',
                ]"
            />
        </V2Field>
        <V2Field
            v-slot="{ id, describedBy }"
            label="Valid through (inclusive)"
            :hint="
                echo(form.valid_until, 'Through', ', inclusive') ??
                'No end date'
            "
            :error="form.errors.valid_until"
        >
            <input
                :id="id"
                v-model="form.valid_until"
                type="date"
                :aria-describedby="describedBy"
                :class="[
                    input,
                    form.errors.valid_until
                        ? 'border-destructive'
                        : 'border-input',
                ]"
            />
        </V2Field>
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
            <V2Field
                v-slot="{ id, describedBy }"
                label="Access valid through (inclusive)"
                :hint="echo(endForm.valid_until, 'Through', ', inclusive')"
                :error="endForm.errors.valid_until"
            >
                <input
                    :id="id"
                    v-model="endForm.valid_until"
                    type="date"
                    :aria-describedby="describedBy"
                    :class="[
                        input,
                        endForm.errors.valid_until
                            ? 'border-destructive'
                            : 'border-input',
                    ]"
                />
            </V2Field>
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
