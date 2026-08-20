<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building2,
    History,
    KeyRound,
    Pencil,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatAuditEventLabel, formatRoleLabel } from '@/lib/displayLabels';
import type { StaffDetail, StaffOptions } from '@/types';
import AssignmentStateBadge from './Partials/AssignmentStateBadge.vue';
import BranchAssignmentDialog from './Partials/BranchAssignmentDialog.vue';
import RoleManagementDialog from './Partials/RoleManagementDialog.vue';
import StatusConfirmationDialog from './Partials/StatusConfirmationDialog.vue';

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
const primaryError = ref<string>();
const primaryAssignmentId = ref<number>();
const label = (value: string) =>
    value
        .replaceAll('_', ' ')
        .replaceAll('.', ' · ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
const date = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-MY', {
              dateStyle: 'medium',
              timeStyle: value.includes('T') ? 'short' : undefined,
          }).format(new Date(value))
        : '—';
const signInLabel = (configuration: StaffDetail['signInConfiguration']) =>
    ({
        password: 'Password',
        google_awaiting_first_sign_in: 'Google · Awaiting first sign-in',
        google_linked: 'Google · Linked',
    })[configuration];
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
</script>

<template>
    <Head :title="staff.name" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div
            class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start"
        >
            <div class="flex items-start gap-3">
                <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                    <UserRound class="size-5" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-semibold tracking-tight">
                            {{ staff.name }}
                        </h1>
                        <Badge
                            :variant="
                                staff.isActive ? 'secondary' : 'destructive'
                            "
                            :class="
                                staff.isActive &&
                                'bg-emerald-100 text-emerald-800 hover:bg-emerald-100'
                            "
                            >{{ staff.isActive ? 'Active' : 'Inactive' }}</Badge
                        >
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ staff.staffNumber ?? 'No staff number' }} ·
                        {{ staff.email }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child
                    ><Link href="/staff"
                        ><ArrowLeft class="size-4" /> Directory</Link
                    ></Button
                >
                <Button v-if="staff.can.update" variant="outline" as-child
                    ><Link :href="`/staff/${staff.id}/edit`"
                        ><Pencil class="size-4" /> Edit profile</Link
                    ></Button
                >
                <RoleManagementDialog
                    v-if="staff.can.manageRoles && options"
                    :staff-id="staff.id"
                    :current-roles="staff.roles"
                    :roles="options.roles"
                />
                <StatusConfirmationDialog
                    v-if="staff.can.manageStatus"
                    :staff-id="staff.id"
                    :staff-name="staff.name"
                    :is-active="staff.isActive"
                />
            </div>
        </div>

        <div
            class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]"
        >
            <div class="space-y-5">
                <Card
                    ><CardHeader
                        ><CardTitle class="flex items-center gap-2 text-base"
                            ><UserRound class="size-4" /> Identity &
                            employment</CardTitle
                        ></CardHeader
                    ><CardContent
                        class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Work email
                            </div>
                            <div class="mt-1 text-sm">{{ staff.email }}</div>
                        </div>
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Staff number
                            </div>
                            <div class="mt-1 text-sm">
                                {{ staff.staffNumber ?? 'Not assigned' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Department
                            </div>
                            <div class="mt-1 text-sm">
                                {{ staff.department ?? 'Not assigned' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Job title
                            </div>
                            <div class="mt-1 text-sm">
                                {{ staff.jobTitle ?? 'Not assigned' }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Sign-in configuration
                            </div>
                            <div class="mt-1 text-sm">
                                {{ signInLabel(staff.signInConfiguration) }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Created
                            </div>
                            <div class="mt-1 text-sm">
                                {{ date(staff.createdAt) }}
                            </div>
                        </div>
                    </CardContent></Card
                >

                <Card
                    ><CardHeader
                        ><div class="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                    ><Building2 class="size-4" /> Branch
                                    assignments</CardTitle
                                ><CardDescription class="mt-1"
                                    >Past, current and future-dated access.
                                    Current primary assignment drives the
                                    default branch context.</CardDescription
                                >
                            </div>
                            <BranchAssignmentDialog
                                v-if="staff.can.manageAccess && options"
                                :staff-id="staff.id"
                                :branches="options.branches"
                                :today="today"
                            /></div></CardHeader
                    ><CardContent class="space-y-3">
                        <InputError :message="primaryError" />
                        <div
                            v-for="assignment in staff.assignments"
                            :key="assignment.id"
                            class="flex flex-col justify-between gap-4 rounded-md border p-4 md:flex-row md:items-center"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        assignment.branch.name
                                    }}</span
                                    ><Badge
                                        v-if="assignment.isPrimary"
                                        class="border-emerald-300 bg-emerald-50 text-emerald-800"
                                        variant="outline"
                                        >Primary</Badge
                                    ><AssignmentStateBadge
                                        :state="assignment.state"
                                    />
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    {{ label(assignment.assignmentType) }} ·
                                    {{ assignment.validFrom }} through
                                    {{ assignment.validUntil ?? 'No end date' }}
                                    <span v-if="assignment.validUntil">
                                        (inclusive)</span
                                    >
                                </div>
                            </div>
                            <div
                                v-if="staff.can.manageAccess && options"
                                class="flex gap-2"
                            >
                                <Button
                                    v-if="assignment.canBecomePrimary"
                                    size="sm"
                                    variant="outline"
                                    :disabled="
                                        primaryAssignmentId === assignment.id
                                    "
                                    @click="setPrimary(assignment.id)"
                                    >Set primary</Button
                                ><span
                                    v-else-if="
                                        assignment.state === 'current' &&
                                        !assignment.isPrimary &&
                                        staff.isActive &&
                                        assignment.validUntil
                                    "
                                    class="max-w-44 text-right text-xs text-muted-foreground"
                                    >Primary requires a non-expiring
                                    assignment.</span
                                ><BranchAssignmentDialog
                                    :staff-id="staff.id"
                                    :branches="options.branches"
                                    :today="today"
                                    :assignment="assignment"
                                />
                            </div>
                        </div>
                        <div
                            v-if="staff.assignments.length === 0"
                            class="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground"
                        >
                            No branch assignments are visible in your current
                            scope.
                        </div>
                    </CardContent></Card
                >
            </div>

            <div class="space-y-5">
                <Card
                    ><CardHeader
                        ><CardTitle class="flex items-center gap-2 text-base"
                            ><ShieldCheck class="size-4" /> Access
                            summary</CardTitle
                        ></CardHeader
                    ><CardContent class="space-y-5">
                        <div>
                            <div
                                class="text-xs font-medium text-muted-foreground uppercase"
                            >
                                Current primary branch
                            </div>
                            <div class="mt-1 font-medium">
                                {{
                                    staff.primaryBranch?.name ??
                                    'No effective primary branch'
                                }}
                            </div>
                        </div>
                        <div>
                            <div
                                class="mb-2 text-xs font-medium text-muted-foreground uppercase"
                            >
                                Roles
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in staff.roles"
                                    :key="role"
                                    variant="secondary"
                                    >{{ formatRoleLabel(role) }}</Badge
                                >
                            </div>
                        </div>
                        <div
                            class="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground"
                        >
                            <KeyRound
                                class="mb-2 size-4 text-foreground"
                            />Actual access is evaluated server-side from
                            identity, role permissions, organisation scope and
                            effective branch assignments.
                        </div>
                    </CardContent></Card
                >

                <Card v-if="staff.recentAudit.length"
                    ><CardHeader
                        ><CardTitle class="flex items-center gap-2 text-base"
                            ><History class="size-4" /> Recent staff
                            history</CardTitle
                        ><CardDescription
                            >Security-relevant audit entries for this identity
                            and its assignments.</CardDescription
                        ></CardHeader
                    ><CardContent class="space-y-3"
                        ><div
                            v-for="entry in staff.recentAudit"
                            :key="entry.id"
                            class="border-l-2 border-emerald-200 pl-3"
                        >
                            <div class="text-sm font-medium">
                                {{
                                    formatAuditEventLabel(
                                        entry.event,
                                        entry.roleNames,
                                    )
                                }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ entry.actor ?? 'System' }} ·
                                {{ date(entry.occurredAt) }}
                            </div>
                        </div>
                        <Button variant="outline" size="sm" as-child
                            ><Link href="/audit-logs"
                                >Open audit logs</Link
                            ></Button
                        ></CardContent
                    ></Card
                >
            </div>
        </div>
    </main>
</template>
