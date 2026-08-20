<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatRoleLabel } from '@/lib/displayLabels';
import type { StaffOptions } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Staff', href: '/staff' },
            { title: 'Create', href: '/staff/create' },
        ],
    },
});
const props = defineProps<{ options: StaffOptions; today: string }>();
type AssignmentInput = {
    branch_id: number | '';
    assignment_type: 'permanent' | 'temporary';
    is_primary: boolean;
    valid_from: string;
    valid_until: string;
};
const blankAssignment = (primary = false): AssignmentInput => ({
    branch_id: '',
    assignment_type: 'permanent',
    is_primary: primary,
    valid_from: props.today,
    valid_until: '',
});
const form = useForm<{
    name: string;
    email: string;
    staff_number: string;
    job_title: string;
    department_id: number | '';
    credential_strategy: 'google_only' | 'password';
    password: string;
    password_confirmation: string;
    is_active: boolean;
    roles: string[];
    assignments: AssignmentInput[];
}>({
    name: '',
    email: '',
    staff_number: '',
    job_title: '',
    department_id: '',
    credential_strategy: 'google_only',
    password: '',
    password_confirmation: '',
    is_active: true,
    roles: [],
    assignments: [blankAssignment(true)],
});
const toggleRole = (role: string, checked: boolean) =>
    (form.roles = checked
        ? [...form.roles, role]
        : form.roles.filter((item) => item !== role));
const makePrimary = (index: number) =>
    form.assignments.forEach(
        (assignment, assignmentIndex) =>
            (assignment.is_primary = index === assignmentIndex),
    );
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
const submit = () => form.post('/staff');
</script>

<template>
    <Head title="Create staff" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <UserPlus class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Create staff account
                </h1>
                <p class="text-sm text-muted-foreground">
                    Provision identity, employment and initial access in one
                    controlled transaction.
                </p>
            </div>
        </div>
        <form
            class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]"
            @submit.prevent="submit"
        >
            <div class="space-y-5">
                <Card
                    ><CardHeader
                        ><CardTitle class="text-base">Identity</CardTitle
                        ><CardDescription
                            >Use the staff member's KPOne work identity. Public
                            registration remains disabled.</CardDescription
                        ></CardHeader
                    ><CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <label class="text-sm font-medium">Full name</label
                            ><input
                                v-model="form.name"
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                                autocomplete="off"
                            /><InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <label class="text-sm font-medium">Work email</label
                            ><input
                                v-model="form.email"
                                type="email"
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                                autocomplete="off"
                            /><InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <label class="text-sm font-medium"
                                >Sign-in method</label
                            >
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label
                                    class="rounded-md border p-3 text-sm"
                                    :class="
                                        form.credential_strategy ===
                                            'google_only' &&
                                        'border-emerald-400 bg-emerald-50/60'
                                    "
                                    ><input
                                        v-model="form.credential_strategy"
                                        type="radio"
                                        value="google_only"
                                        class="mr-2 accent-emerald-700"
                                    />Google-only pre-provisioning<span
                                        class="mt-1 block text-xs text-muted-foreground"
                                        >No OAuth token is stored. First link
                                        still requires a verified matching
                                        Google email.</span
                                    ></label
                                ><label
                                    class="rounded-md border p-3 text-sm"
                                    :class="
                                        form.credential_strategy ===
                                            'password' &&
                                        'border-emerald-400 bg-emerald-50/60'
                                    "
                                    ><input
                                        v-model="form.credential_strategy"
                                        type="radio"
                                        value="password"
                                        class="mr-2 accent-emerald-700"
                                    />Bootstrap password<span
                                        class="mt-1 block text-xs text-muted-foreground"
                                        >Enter it once. KPOne hashes it
                                        immediately and never returns or audits
                                        it.</span
                                    ></label
                                >
                            </div>
                        </div>
                        <template v-if="form.credential_strategy === 'password'"
                            ><div class="grid gap-2">
                                <label class="text-sm font-medium"
                                    >Bootstrap password</label
                                ><input
                                    v-model="form.password"
                                    type="password"
                                    autocomplete="new-password"
                                    class="h-9 rounded-md border bg-background px-3 text-sm"
                                /><InputError :message="form.errors.password" />
                            </div>
                            <div class="grid gap-2">
                                <label class="text-sm font-medium"
                                    >Confirm password</label
                                ><input
                                    v-model="form.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    class="h-9 rounded-md border bg-background px-3 text-sm"
                                /></div
                        ></template> </CardContent
                ></Card>

                <Card
                    ><CardHeader
                        ><CardTitle class="text-base">Employment</CardTitle
                        ><CardDescription
                            >Organisation ownership is assigned from your
                            authorised KPOne context, not from this
                            form.</CardDescription
                        ></CardHeader
                    ><CardContent class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <label class="text-sm font-medium"
                                >Staff number</label
                            ><input
                                v-model="form.staff_number"
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                            /><InputError :message="form.errors.staff_number" />
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm font-medium">Job title</label
                            ><input
                                v-model="form.job_title"
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                            /><InputError :message="form.errors.job_title" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <label class="text-sm font-medium">Department</label
                            ><select
                                v-model="form.department_id"
                                class="h-9 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="" disabled>
                                    Select department
                                </option>
                                <option
                                    v-for="department in options.departments"
                                    :key="department.id"
                                    :value="department.id"
                                >
                                    {{ department.name }}
                                </option></select
                            ><InputError :message="form.errors.department_id" />
                        </div> </CardContent
                ></Card>

                <Card
                    ><CardHeader
                        ><div class="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle class="text-base"
                                    >Initial branch access</CardTitle
                                ><CardDescription class="mt-1"
                                    >Exactly one currently effective primary
                                    branch is required. Additional assignments
                                    may start later.</CardDescription
                                >
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="
                                    form.assignments.push(blankAssignment())
                                "
                                ><Plus class="size-4" /> Add</Button
                            >
                        </div></CardHeader
                    ><CardContent class="space-y-4">
                        <div
                            v-for="(assignment, index) in form.assignments"
                            :key="index"
                            class="rounded-md border p-4"
                        >
                            <div class="mb-3 flex items-center justify-between">
                                <div class="text-sm font-medium">
                                    Assignment {{ index + 1
                                    }}<span
                                        v-if="assignment.is_primary"
                                        class="ml-2 text-xs text-emerald-700"
                                        >Primary</span
                                    >
                                </div>
                                <Button
                                    v-if="form.assignments.length > 1"
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="form.assignments.splice(index, 1)"
                                    ><Trash2 class="size-4" /> Remove</Button
                                >
                            </div>
                            <div
                                class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
                            >
                                <div class="grid gap-2 xl:col-span-2">
                                    <label class="text-xs font-medium"
                                        >Branch</label
                                    ><select
                                        v-model="assignment.branch_id"
                                        class="h-9 rounded-md border bg-background px-3 text-sm"
                                    >
                                        <option value="" disabled>
                                            Select branch
                                        </option>
                                        <option
                                            v-for="branch in options.branches"
                                            :key="branch.id"
                                            :value="branch.id"
                                        >
                                            {{ branch.name }}
                                        </option></select
                                    ><InputError
                                        :message="
                                            errorFor(
                                                `assignments.${index}.branch_id`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-xs font-medium"
                                        >Type</label
                                    ><select
                                        v-model="assignment.assignment_type"
                                        class="h-9 rounded-md border bg-background px-3 text-sm"
                                    >
                                        <option value="permanent">
                                            Permanent
                                        </option>
                                        <option value="temporary">
                                            Temporary
                                        </option>
                                    </select>
                                </div>
                                <label
                                    class="mt-7 flex items-center gap-2 text-sm"
                                    ><input
                                        type="radio"
                                        name="primary-assignment"
                                        class="size-4 accent-emerald-700"
                                        :checked="assignment.is_primary"
                                        @change="makePrimary(index)"
                                    />
                                    Primary branch</label
                                >
                                <div class="grid gap-2">
                                    <label class="text-xs font-medium"
                                        >Valid from</label
                                    ><input
                                        v-model="assignment.valid_from"
                                        type="date"
                                        class="h-9 rounded-md border bg-background px-3 text-sm"
                                    /><InputError
                                        :message="
                                            errorFor(
                                                `assignments.${index}.valid_from`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <label class="text-xs font-medium"
                                        >Valid through (inclusive)</label
                                    ><input
                                        v-model="assignment.valid_until"
                                        type="date"
                                        class="h-9 rounded-md border bg-background px-3 text-sm"
                                    /><InputError
                                        :message="
                                            errorFor(
                                                `assignments.${index}.valid_until`,
                                            )
                                        "
                                    />
                                </div>
                            </div>
                        </div>
                        <InputError
                            :message="form.errors.assignments"
                        /> </CardContent
                ></Card>
            </div>

            <aside class="space-y-5">
                <Card
                    ><CardHeader
                        ><CardTitle class="text-base">Roles</CardTitle
                        ><CardDescription
                            >Only roles within your effective authority are
                            available.</CardDescription
                        ></CardHeader
                    ><CardContent class="space-y-2"
                        ><label
                            v-for="role in options.roles"
                            :key="role"
                            class="flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm hover:bg-muted/30"
                            ><input
                                type="checkbox"
                                class="size-4 accent-emerald-700"
                                :checked="form.roles.includes(role)"
                                @change="
                                    toggleRole(
                                        role,
                                        ($event.target as HTMLInputElement)
                                            .checked,
                                    )
                                "
                            /><span class="font-medium">{{
                                formatRoleLabel(role)
                            }}</span></label
                        ><InputError
                            :message="form.errors.roles" /></CardContent
                ></Card>
                <Card
                    ><CardHeader
                        ><CardTitle class="text-base"
                            >Account status</CardTitle
                        ></CardHeader
                    ><CardContent
                        ><label class="flex items-start gap-3 text-sm"
                            ><input
                                v-model="form.is_active"
                                type="checkbox"
                                class="mt-0.5 size-4 accent-emerald-700"
                            /><span
                                ><span class="font-medium"
                                    >Create as active</span
                                ><span
                                    class="mt-1 block text-xs text-muted-foreground"
                                    >Active accounts require a currently
                                    effective primary branch. Inactive accounts
                                    cannot authenticate.</span
                                ></span
                            ></label
                        ></CardContent
                    ></Card
                >
                <div class="flex gap-2">
                    <Button
                        type="submit"
                        class="flex-1"
                        :disabled="form.processing"
                        >Create staff</Button
                    ><Button type="button" variant="outline" as-child
                        ><Link href="/staff"
                            ><ArrowLeft class="size-4" /> Cancel</Link
                        ></Button
                    >
                </div>
            </aside>
        </form>
    </main>
</template>
