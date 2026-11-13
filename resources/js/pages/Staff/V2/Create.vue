<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { formatRoleLabel } from '@/lib/displayLabels';
import { echoDate } from '@/lib/v2-date-echo';
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

// The same fields, names and request as Classic: POST /staff.
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

const toggleRole = (role: string, checked: boolean) => {
    form.clearErrors('roles');
    form.roles = checked
        ? [...form.roles, role]
        : form.roles.filter((item) => item !== role);
};

const makePrimary = (index: number) => {
    form.clearErrors('assignment' as never);

    return form.assignments.forEach(
        (assignment, assignmentIndex) =>
            (assignment.is_primary = index === assignmentIndex),
    );
};

const rawError = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

// V2 shows a plain line instead of a raw field name ("The department id field is required."). Real rule refusals (an
// expiring primary, a role you may not grant, an email already used) keep the server's own words.
const PLAIN: Array<[RegExp, string]> = [
    [/^name$/, 'Enter the full name.'],
    [/^email$/, 'Enter the work email.'],
    [/^department_id$/, 'Choose a department.'],
    [/^assignments\.\d+\.branch_id$/, 'Choose a branch.'],
    [/^roles$/, 'Choose at least one role.'],
];

const errorFor = (key: string): string | undefined => {
    const message = rawError(key);

    if (message === undefined) {
        return undefined;
    }

    // Plain wording for the two password lines (the server's rule is in the hint beside the field).
    if (key === 'password') {
        if (/must be at least 12 characters/.test(message)) {
            return 'The password must be at least 12 characters.';
        }

        if (/confirmation does not match/.test(message)) {
            return 'The two passwords do not match.';
        }
    }

    if (key === 'email' && /has already been taken/.test(message)) {
        return 'That email is already used by another staff member.';
    }

    const plain = PLAIN.find(([pattern]) => pattern.test(key));

    return plain && /field is required\.?$/.test(message) ? plain[1] : message;
};

// A field's error goes as soon as the person edits that field; what is still wrong stays.
const clear = (...keys: string[]) => form.clearErrors(...(keys as never[]));

// The typed password is never kept after a refusal: the two boxes are emptied and the person types it again.
const submit = () =>
    form.post('/staff', {
        onError: () => form.reset('password', 'password_confirmation'),
    });

const signInOptions = [
    { value: 'google_only', label: 'Google sign-in only' },
    { value: 'password', label: 'Temporary password' },
];

const control =
    'h-11 w-full rounded-[10px] border bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const row =
    'flex min-h-11 cursor-pointer items-center gap-3 rounded-[10px] border border-border px-3 text-sm font-medium hover:bg-muted lg:min-h-9';
const outline =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background lg:h-9';
const filled =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground outline-none hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:opacity-50 lg:h-9';
const border = (error?: string) =>
    error ? 'border-destructive' : 'border-input';
</script>

<template>
    <Head title="Create staff" />
    <PageFrame width="wide">
        <form @submit.prevent="submit">
            <V2WorkLayout
                title="Create staff account"
                description="Provision identity, employment and initial access in one controlled transaction."
                rail-label="Roles and account status"
                rail-from="xl"
                rail-below="stack-bottom"
            >
                <template #main>
                    <V2FormCard
                        :step="1"
                        title="Identity"
                        description="The person will sign in with their work email."
                    >
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Full name"
                            :error="errorFor('name')"
                        >
                            <input
                                :id="id"
                                v-model="form.name"
                                @input="clear('name')"
                                autocomplete="off"
                                :aria-describedby="describedBy"
                                :class="[control, border(errorFor('name'))]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Work email"
                            :error="errorFor('email')"
                        >
                            <input
                                :id="id"
                                v-model="form.email"
                                @input="clear('email')"
                                type="email"
                                autocomplete="off"
                                :aria-describedby="describedBy"
                                :class="[control, border(errorFor('email'))]"
                            />
                        </V2Field>
                        <V2OptionGroup
                            :model-value="form.credential_strategy"
                            label="Sign-in method"
                            :options="signInOptions"
                            @update:model-value="
                                form.credential_strategy = $event as
                                    'google_only' | 'password'
                            "
                        />
                        <p class="text-[13px] text-muted-foreground">
                            <template
                                v-if="
                                    form.credential_strategy === 'google_only'
                                "
                                >The person signs in with Google. Their Google
                                email must match the work email above.</template
                            ><template v-else
                                >You set a temporary password once. It is never
                                shown again.</template
                            >
                        </p>
                        <template
                            v-if="form.credential_strategy === 'password'"
                        >
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Temporary password"
                                hint="At least 12 characters, with capital and small letters, a number and a symbol."
                                :error="errorFor('password')"
                            >
                                <input
                                    :id="id"
                                    v-model="form.password"
                                    @input="clear('password')"
                                    type="password"
                                    autocomplete="new-password"
                                    :aria-describedby="describedBy"
                                    :class="[
                                        control,
                                        border(errorFor('password')),
                                    ]"
                                />
                            </V2Field>
                            <V2Field v-slot="{ id }" label="Confirm password">
                                <input
                                    :id="id"
                                    v-model="form.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    :class="[control, 'border-input']"
                                />
                            </V2Field>
                        </template>
                    </V2FormCard>

                    <V2FormCard :step="2" title="Employment">
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Staff number"
                            :error="errorFor('staff_number')"
                        >
                            <input
                                :id="id"
                                v-model="form.staff_number"
                                @input="clear('staff_number')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('staff_number')),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Job title"
                            :error="errorFor('job_title')"
                        >
                            <input
                                :id="id"
                                v-model="form.job_title"
                                @input="clear('job_title')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('job_title')),
                                ]"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Department"
                            :error="errorFor('department_id')"
                        >
                            <select
                                :id="id"
                                v-model="form.department_id"
                                @change="clear('department_id')"
                                :aria-describedby="describedBy"
                                :class="[
                                    control,
                                    border(errorFor('department_id')),
                                ]"
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
                                </option>
                            </select>
                        </V2Field>
                    </V2FormCard>

                    <V2FormCard
                        :step="3"
                        title="Initial branch access"
                        description="Exactly one currently effective primary branch is required. Additional assignments may start later."
                    >
                        <div
                            v-for="(assignment, index) in form.assignments"
                            :key="index"
                            class="flex min-w-0 flex-col gap-4 rounded-[12px] border border-border p-4"
                        >
                            <div
                                class="flex flex-wrap items-center justify-between gap-2"
                            >
                                <h3
                                    class="flex items-center gap-2 text-sm font-semibold"
                                >
                                    Assignment {{ index + 1 }}
                                    <V2Chip
                                        v-if="assignment.is_primary"
                                        tone="success"
                                        label="Primary"
                                    />
                                </h3>
                                <button
                                    v-if="form.assignments.length > 1"
                                    type="button"
                                    :class="outline"
                                    :aria-label="`Remove assignment ${index + 1}`"
                                    @click="form.assignments.splice(index, 1)"
                                >
                                    Remove
                                </button>
                            </div>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Branch"
                                :error="
                                    errorFor(`assignments.${index}.branch_id`)
                                "
                            >
                                <select
                                    :id="id"
                                    v-model="assignment.branch_id"
                                    @change="
                                        clear(
                                            `assignments.${index}.branch_id`,
                                            'assignment',
                                        )
                                    "
                                    :aria-describedby="describedBy"
                                    :class="[
                                        control,
                                        border(
                                            errorFor(
                                                `assignments.${index}.branch_id`,
                                            ),
                                        ),
                                    ]"
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
                                    </option>
                                </select>
                            </V2Field>
                            <V2Field v-slot="{ id }" label="Type">
                                <select
                                    :id="id"
                                    v-model="assignment.assignment_type"
                                    @change="clear('assignment')"
                                    :class="[control, 'border-input']"
                                >
                                    <option value="permanent">Permanent</option>
                                    <option value="temporary">Temporary</option>
                                </select>
                            </V2Field>
                            <label :class="row">
                                <input
                                    type="radio"
                                    name="primary-assignment"
                                    class="size-5 shrink-0 accent-primary"
                                    :checked="assignment.is_primary"
                                    @change="makePrimary(index)"
                                />
                                Primary branch
                            </label>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Valid from"
                                :hint="echoDate(assignment.valid_from, 'From')"
                                :error="
                                    errorFor(`assignments.${index}.valid_from`)
                                "
                            >
                                <input
                                    :id="id"
                                    v-model="assignment.valid_from"
                                    @input="
                                        clear(
                                            `assignments.${index}.valid_from`,
                                            'assignment',
                                        )
                                    "
                                    type="date"
                                    :aria-describedby="describedBy"
                                    :class="[
                                        control,
                                        border(
                                            errorFor(
                                                `assignments.${index}.valid_from`,
                                            ),
                                        ),
                                    ]"
                                />
                            </V2Field>
                            <V2Field
                                v-slot="{ id, describedBy }"
                                label="Valid through (inclusive)"
                                :hint="
                                    echoDate(
                                        assignment.valid_until,
                                        'Through',
                                        ', inclusive',
                                    ) ?? 'No end date'
                                "
                                :error="
                                    errorFor(`assignments.${index}.valid_until`)
                                "
                            >
                                <input
                                    :id="id"
                                    v-model="assignment.valid_until"
                                    @input="
                                        clear(
                                            `assignments.${index}.valid_until`,
                                            'assignment',
                                        )
                                    "
                                    type="date"
                                    :aria-describedby="describedBy"
                                    :class="[
                                        control,
                                        border(
                                            errorFor(
                                                `assignments.${index}.valid_until`,
                                            ),
                                        ),
                                    ]"
                                />
                            </V2Field>
                        </div>
                        <!-- The server answers a rule refusal under `assignment` (for example an expiring primary); Classic shows no line for it. -->
                        <p
                            v-if="
                                errorFor('assignment') ||
                                rawError('assignments')
                            "
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{
                                errorFor('assignment') ??
                                rawError('assignments')
                            }}
                        </p>
                        <button
                            type="button"
                            :class="[outline, 'self-start']"
                            @click="form.assignments.push(blankAssignment())"
                        >
                            Add assignment
                        </button>
                    </V2FormCard>

                    <Link
                        href="/staff"
                        :class="[outline, 'self-start xl:hidden']"
                        >Cancel</Link
                    >

                    <V2StickyBar>
                        <button
                            type="submit"
                            :class="filled"
                            :disabled="form.processing"
                        >
                            Create staff
                        </button>
                    </V2StickyBar>
                </template>

                <template #rail>
                    <V2FormCard
                        title="Roles"
                        description="Only roles within your effective authority are available."
                    >
                        <fieldset class="flex min-w-0 flex-col gap-2">
                            <legend class="sr-only">Roles</legend>
                            <label
                                v-for="role in options.roles"
                                :key="role"
                                :class="row"
                            >
                                <input
                                    type="checkbox"
                                    class="size-5 shrink-0 accent-primary"
                                    :checked="form.roles.includes(role)"
                                    @change="
                                        toggleRole(
                                            role,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                {{ formatRoleLabel(role) }}
                            </label>
                        </fieldset>
                        <p
                            v-if="errorFor('roles')"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ errorFor('roles') }}
                        </p>
                    </V2FormCard>

                    <V2FormCard title="Account status">
                        <label :class="row">
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                class="size-5 shrink-0 accent-primary"
                            />
                            Create as active
                        </label>
                        <p class="text-[13px] text-muted-foreground">
                            Active accounts require a currently effective
                            primary branch. Inactive accounts cannot
                            authenticate.
                        </p>
                    </V2FormCard>

                    <div class="hidden flex-col gap-2 xl:flex">
                        <button
                            type="submit"
                            :class="[filled, 'sm:flex-1']"
                            :disabled="form.processing"
                        >
                            Create staff
                        </button>
                        <Link href="/staff" :class="outline">Cancel</Link>
                    </div>
                </template>
            </V2WorkLayout>
        </form>
    </PageFrame>
</template>
