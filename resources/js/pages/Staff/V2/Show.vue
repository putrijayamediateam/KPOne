<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import V2StaffAssignmentDialog from '@/components/staff-v2/V2StaffAssignmentDialog.vue';
import V2StaffRolesDialog from '@/components/staff-v2/V2StaffRolesDialog.vue';
import V2StaffStatusDialog from '@/components/staff-v2/V2StaffStatusDialog.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2KeyValueList from '@/components/v2/V2KeyValueList.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { formatRoleLabel } from '@/lib/displayLabels';
import { formatDate, formatDateTime } from '@/lib/presentation';
import { describeStaffEvent } from '@/lib/v2-staff-history';
import type { StaffAssignment, StaffDetail, StaffOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff', href: '/staff' }, { title: 'Detail' }],
    },
});

const props = defineProps<{
    staff: StaffDetail;
    options: StaffOptions | null;
    today: string;
}>();

const rolesOpen = ref(false);
const statusOpen = ref(false);
const adding = ref(false);
const editing = ref<StaffAssignment | null>(null);
const primaryError = ref<string>();
const primaryAssignmentId = ref<number>();

const label = (value: string) =>
    value
        .replaceAll('_', ' ')
        .replaceAll('.', ' · ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

// DD-43: the shared KPOne formats ("1 Jan 2026", "1 Jan 2026, 9:16 AM").
const date = (value: string | null) =>
    value
        ? value.includes('T')
            ? formatDateTime(value)
            : formatDate(value)
        : '—';

const signInLabel = (configuration: StaffDetail['signInConfiguration']) =>
    ({
        password: 'Password',
        google_awaiting_first_sign_in: 'Google · Awaiting first sign-in',
        google_linked: 'Google · Linked',
    })[configuration];

// In words: "Ended 12 Nov 2026", "Starts 1 Jan 2027", "From 1 Jan 2026, no end date".
const when = (assignment: StaffAssignment) => {
    if (assignment.state === 'ended') {
        return `Ended ${date(assignment.validUntil)} (from ${date(assignment.validFrom)})`;
    }

    if (assignment.state === 'future') {
        return assignment.validUntil
            ? `Starts ${date(assignment.validFrom)}, through ${date(assignment.validUntil)} (inclusive)`
            : `Starts ${date(assignment.validFrom)}, no end date`;
    }

    return assignment.validUntil
        ? `${date(assignment.validFrom)} to ${date(assignment.validUntil)} (inclusive)`
        : `From ${date(assignment.validFrom)}, no end date`;
};

const stateTone = {
    current: 'success',
    future: 'info',
    ended: 'neutral',
} as const;
const stateLabel = { current: 'Current', future: 'Future', ended: 'Ended' };

const setPrimary = (assignmentId: number) => {
    primaryError.value = undefined;
    primaryAssignmentId.value = assignmentId;

    router.post(
        `/staff/${props.staff.id}/branch-assignments/${assignmentId}/primary`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                primaryError.value =
                    errors.assignment ??
                    Object.values(errors)[0] ??
                    'The primary branch could not be changed.';
            },
            onFinish: () => (primaryAssignmentId.value = undefined),
        },
    );
};

const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:opacity-50 lg:h-9';
</script>

<template>
    <Head :title="staff.name" />
    <PageFrame width="wide">
        <V2WorkLayout
            :title="staff.name"
            :description="`${staff.staffNumber ?? 'No staff number'} · ${staff.email}`"
            rail-label="Access and history"
            rail-from="xl"
            rail-below="stack-bottom"
        >
            <template #actions>
                <Link href="/staff" :class="button">Directory</Link>
                <Link
                    v-if="staff.can.update"
                    :href="`/staff/${staff.id}/edit`"
                    :class="button"
                    >Edit profile</Link
                >
                <button
                    v-if="staff.can.manageRoles && options"
                    type="button"
                    :class="button"
                    @click="rolesOpen = true"
                >
                    Manage roles
                </button>
                <button
                    v-if="staff.can.manageStatus"
                    type="button"
                    :class="[
                        button,
                        staff.isActive
                            ? 'border-destructive text-destructive hover:bg-destructive/10'
                            : 'border-primary text-primary hover:bg-primary/10',
                    ]"
                    @click="statusOpen = true"
                >
                    {{ staff.isActive ? 'Deactivate' : 'Activate' }}
                </button>
            </template>

            <template #main>
                <div class="flex items-center gap-2">
                    <V2Chip
                        :tone="staff.isActive ? 'success' : 'neutral'"
                        :label="staff.isActive ? 'Active' : 'Inactive'"
                    />
                </div>

                <V2FormCard title="Identity and employment">
                    <V2KeyValueList
                        :items="[
                            { label: 'Work email', value: staff.email },
                            { label: 'Staff number', value: staff.staffNumber },
                            { label: 'Department', value: staff.department },
                            { label: 'Job title', value: staff.jobTitle },
                            {
                                label: 'Sign-in configuration',
                                value: signInLabel(staff.signInConfiguration),
                            },
                            { label: 'Created', value: date(staff.createdAt) },
                        ]"
                        empty-text="Not assigned"
                    />
                </V2FormCard>

                <V2FormCard
                    title="Branch assignments"
                    description="Past, current and future-dated access. The current primary assignment drives the default branch context."
                >
                    <div
                        v-if="staff.can.manageAccess && options"
                        class="flex justify-start"
                    >
                        <button
                            type="button"
                            :class="button"
                            @click="adding = true"
                        >
                            Add assignment
                        </button>
                    </div>
                    <p
                        v-if="primaryError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ primaryError }}
                    </p>
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="assignment in staff.assignments"
                            :key="assignment.id"
                            class="flex min-w-0 flex-col gap-3 rounded-[12px] border border-border p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        assignment.branch.name
                                    }}</span>
                                    <V2Chip
                                        v-if="assignment.isPrimary"
                                        tone="success"
                                        label="Primary"
                                    />
                                    <V2Chip
                                        :tone="stateTone[assignment.state]"
                                        :label="stateLabel[assignment.state]"
                                    />
                                </div>
                                <p
                                    class="mt-1 text-[13px] text-muted-foreground"
                                >
                                    {{ label(assignment.assignmentType) }} ·
                                    {{ when(assignment) }}
                                </p>
                            </div>
                            <div
                                v-if="staff.can.manageAccess && options"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <button
                                    v-if="assignment.canBecomePrimary"
                                    type="button"
                                    :class="button"
                                    :disabled="
                                        primaryAssignmentId === assignment.id
                                    "
                                    @click="setPrimary(assignment.id)"
                                >
                                    Set primary
                                </button>
                                <span
                                    v-else-if="
                                        assignment.state === 'current' &&
                                        !assignment.isPrimary &&
                                        staff.isActive &&
                                        assignment.validUntil
                                    "
                                    class="max-w-44 text-[13px] text-muted-foreground"
                                    >Primary requires a non-expiring
                                    assignment.</span
                                >
                                <button
                                    type="button"
                                    :class="button"
                                    :aria-label="`Edit assignment at ${assignment.branch.name}`"
                                    @click="editing = assignment"
                                >
                                    Edit
                                </button>
                            </div>
                        </li>
                    </ul>
                    <p
                        v-if="staff.assignments.length === 0"
                        class="rounded-[12px] border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                    >
                        No branch assignments are visible in your current scope.
                    </p>
                </V2FormCard>
            </template>

            <template #rail>
                <V2FormCard title="Access summary">
                    <div>
                        <h3 class="text-sm font-medium text-muted-foreground">
                            Current primary branch
                        </h3>
                        <p class="mt-1 text-[15px]">
                            {{
                                staff.primaryBranch?.name ??
                                'No effective primary branch'
                            }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-2">
                        <h3 class="text-sm font-medium text-muted-foreground">
                            Roles
                        </h3>
                        <div
                            v-if="staff.roles.length"
                            class="flex flex-wrap gap-2"
                        >
                            <V2Chip
                                v-for="role in staff.roles"
                                :key="role"
                                tone="neutral"
                                plain
                                :label="formatRoleLabel(role)"
                            />
                        </div>
                        <p v-else class="text-sm text-muted-foreground">
                            No roles assigned
                        </p>
                    </div>
                    <p
                        class="rounded-[10px] bg-muted p-3 text-[13px] text-muted-foreground"
                    >
                        What this person can open depends on their roles and the
                        branches they are assigned to.
                    </p>
                </V2FormCard>

                <V2FormCard
                    v-if="staff.recentAudit.length"
                    title="Recent staff history"
                    description="Security-relevant audit entries for this identity and its assignments."
                >
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="entry in staff.recentAudit"
                            :key="entry.id"
                            class="border-l-2 border-border pl-3"
                        >
                            <p class="text-sm font-medium">
                                {{
                                    describeStaffEvent(
                                        entry.event,
                                        entry.roleNames,
                                        entry.actor,
                                    )
                                }}
                            </p>
                            <p class="text-[13px] text-muted-foreground">
                                <span class="whitespace-nowrap">{{
                                    date(entry.occurredAt)
                                }}</span>
                            </p>
                        </li>
                    </ul>
                    <Link href="/audit-logs" :class="[button, 'self-start']"
                        >Open audit logs</Link
                    >
                </V2FormCard>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <template v-if="options">
        <V2StaffRolesDialog
            v-if="staff.can.manageRoles"
            v-model:open="rolesOpen"
            :staff-id="staff.id"
            :current-roles="staff.roles"
            :roles="options.roles"
        />
        <template v-if="staff.can.manageAccess">
            <V2StaffAssignmentDialog
                v-model:open="adding"
                :staff-id="staff.id"
                :branches="options.branches"
                :today="today"
            />
            <V2StaffAssignmentDialog
                v-if="editing"
                :key="editing.id"
                :open="true"
                :staff-id="staff.id"
                :branches="options.branches"
                :today="today"
                :assignment="editing"
                @update:open="(v: boolean) => !v && (editing = null)"
            />
        </template>
    </template>
    <V2StaffStatusDialog
        v-if="staff.can.manageStatus"
        v-model:open="statusOpen"
        :staff-id="staff.id"
        :staff-name="staff.name"
        :is-active="staff.isActive"
    />
</template>
