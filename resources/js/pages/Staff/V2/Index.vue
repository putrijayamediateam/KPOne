<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { formatRoleLabel } from '@/lib/displayLabels';
import type { PaginatedStaff, StaffOptions } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Staff', href: '/staff' }] },
});

const props = defineProps<{
    staff: PaginatedStaff;
    filters: {
        search?: string;
        branch?: string;
        department?: string;
        role?: string;
        status?: string;
    };
    filterOptions: StaffOptions;
    canCreate: boolean;
    // HR-17e: HR's staff-account requests; null for anyone who may not create staff accounts.
    accountRequests?: AccountRequest[] | null;
    // HR-15a: locum sign-ins HR asked for, and the locum accounts that can be linked; same audience.
    locumSignIns?: { requests: LocumSignIn[] } | null;
}>();

type LocumSignIn = {
    id: string;
    name: string;
    email: string;
    requestedOn: string;
    requestedBy: string;
    lockVersion: number;
    // The account with the email HR asked for, holding the Locum role only; null until it is created.
    account: { id: string; name: string } | null;
};
const page = usePage();
const linkError = computed(() => {
    const errors = (page.props.errors ?? {}) as Record<
        string,
        string | undefined
    >;

    return errors.user_id ?? errors.request ?? errors.records ?? null;
});
const linking = ref<string | null>(null);
const linkLocum = (request: LocumSignIn) => {
    linking.value = request.id;
    router.post(
        `/staff/locum-sign-ins/${request.id}/link`,
        {
            user_id: request.account?.id ?? '',
            expected_lock_version: request.lockVersion,
            idempotency_key: crypto.randomUUID(),
        },
        { preserveScroll: true, onFinish: () => (linking.value = null) },
    );
};

type AccountRequest = {
    id: string;
    name: string;
    email: string;
    position: string;
    branch: string;
    requestedOn: string;
    requestedBy: string;
    lockVersion: number;
};
const marking = ref<AccountRequest | null>(null);
const markKey = ref(crypto.randomUUID());
const markProcessing = ref(false);
const markDone = () => {
    const request = marking.value;

    if (!request) {
        return;
    }

    markProcessing.value = true;
    router.post(
        `/staff/account-requests/${request.id}/done`,
        {
            expected_lock_version: request.lockVersion,
            idempotency_key: markKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                marking.value = null;
                markKey.value = crypto.randomUUID();
            },
            onFinish: () => (markProcessing.value = false),
        },
    );
};

const form = reactive({
    search: props.filters.search ?? '',
    branch: props.filters.branch ?? '',
    department: props.filters.department ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});
const branchOptions = computed(() => [
    { value: '', label: 'All branches' },
    ...props.filterOptions.branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    })),
]);
const departmentOptions = computed(() => [
    { value: '', label: 'All departments' },
    ...props.filterOptions.departments.map((department) => ({
        value: String(department.id),
        label: department.name,
    })),
]);
const roleOptions = computed(() => [
    { value: '', label: 'All roles' },
    ...props.filterOptions.roles.map((role) => ({
        value: role,
        label: formatRoleLabel(role),
    })),
]);
const statusOptions = [
    { value: '', label: 'Any status' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const applyFilters = (page?: number) =>
    router.get('/staff', page ? { ...form, page } : form, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const clearFilters = () => {
    Object.assign(form, {
        search: '',
        branch: '',
        department: '',
        role: '',
        status: '',
    });
    applyFilters();
};

// Below 640px the filters live in a sheet opened by one "Filters (n)" button.
const phone = ref(false);
const sheetOpen = ref(false);
let query: MediaQueryList | null = null;
const syncPhone = () => (phone.value = !!query && !query.matches);

onMounted(() => {
    query = window.matchMedia('(min-width: 640px)');
    syncPhone();
    query.addEventListener('change', syncPhone);
});
onBeforeUnmount(() => query?.removeEventListener('change', syncPhone));

const filterCount = computed(
    () =>
        [form.branch, form.department, form.role, form.status].filter(Boolean)
            .length,
);
const resetFilters = () =>
    Object.assign(form, { branch: '', department: '', role: '', status: '' });

const btn =
    'inline-flex h-11 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:h-9';
</script>
<template>
    <Head title="Staff directory" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Staff directory"
            description="Operational identities and access within your authorised scope."
        >
            <template v-if="canCreate" #actions>
                <Link
                    href="/staff/create"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none lg:h-9"
                    >Create staff</Link
                >
            </template>
            <template #main>
                <V2FormCard
                    v-if="accountRequests && accountRequests.length"
                    title="Staff accounts HR asked for"
                    description="Candidates who passed and accepted. Create each account the usual way with Create staff, then mark the request done. Nothing is created from this list."
                    data-test="staff-account-requests"
                >
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="request in accountRequests"
                            :key="request.id"
                            class="flex flex-col gap-3 rounded-[10px] border p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 flex-col">
                                <span class="font-medium break-words">{{
                                    request.name
                                }}</span>
                                <span class="break-all text-muted-foreground">{{
                                    request.email
                                }}</span>
                                <span class="text-muted-foreground"
                                    >{{ request.position }} ·
                                    {{ request.branch }} · asked on
                                    {{ request.requestedOn }} by
                                    {{ request.requestedBy }}</span
                                >
                            </div>
                            <button
                                type="button"
                                :class="btn"
                                :aria-label="`Mark done: ${request.name}`"
                                data-test="staff-account-request-done"
                                @click="marking = request"
                            >
                                Mark done
                            </button>
                        </li>
                    </ul>
                </V2FormCard>

                <V2FormCard
                    v-if="locumSignIns && locumSignIns.requests.length"
                    title="Locum sign-ins HR asked for"
                    description="Create each account the usual way with Create staff, with the Locum role only, then link it here. A locum sees only their own approved shifts and the open shifts at their branches."
                    data-test="staff-locum-sign-ins"
                >
                    <p
                        class="text-sm text-muted-foreground"
                        data-test="locum-director-only"
                    >
                        Only the Director can create a Locum account.
                    </p>
                    <p
                        v-if="linkError"
                        role="alert"
                        class="text-sm text-destructive"
                        data-test="staff-locum-link-error"
                    >
                        {{ linkError }}
                    </p>
                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="request in locumSignIns.requests"
                            :key="request.id"
                            class="flex flex-col gap-3 rounded-[10px] border p-3 text-sm lg:flex-row lg:items-center lg:justify-between"
                        >
                            <div class="flex min-w-0 flex-col">
                                <span class="font-medium break-words">{{
                                    request.name
                                }}</span>
                                <span class="break-all text-muted-foreground">{{
                                    request.email
                                }}</span>
                                <span class="text-muted-foreground"
                                    >Locum · asked on
                                    {{ request.requestedOn }} by
                                    {{ request.requestedBy }}</span
                                >
                            </div>
                            <button
                                v-if="request.account"
                                type="button"
                                :class="btn"
                                :disabled="linking === request.id"
                                :aria-label="`Link ${request.account.name} to ${request.name}`"
                                data-test="staff-locum-link"
                                @click="linkLocum(request)"
                            >
                                {{
                                    linking === request.id
                                        ? 'Linking…'
                                        : `Link ${request.account.name}`
                                }}
                            </button>
                            <p
                                v-else
                                class="text-muted-foreground lg:max-w-xs"
                                data-test="staff-locum-none"
                            >
                                No account with this email yet. Create it with
                                Create staff, using this email and the Locum
                                role only.
                            </p>
                        </li>
                    </ul>
                </V2FormCard>

                <V2Dialog
                    :open="marking !== null"
                    :title="`Mark done: ${marking?.name ?? ''}?`"
                    description="Only once you have created their staff account with Create staff. HR sees that the account exists."
                    confirm-label="Mark done"
                    cancel-label="Not yet"
                    :busy="markProcessing"
                    @update:open="(open) => !open && (marking = null)"
                    @confirm="markDone"
                />

                <form @submit.prevent="applyFilters()">
                    <V2Toolbar
                        v-model="form.search"
                        search-label="Search staff"
                        search-placeholder="Name, email, staff number"
                        :more-filters="!phone"
                        @search="applyFilters()"
                        @reset="clearFilters"
                    >
                        <template #filters>
                            <div class="hidden w-44 sm:block">
                                <OperationalSelect
                                    id="staff-filter-branch"
                                    v-model="form.branch"
                                    label="Branch"
                                    :options="branchOptions"
                                />
                            </div>
                            <div class="hidden w-44 sm:block">
                                <OperationalSelect
                                    id="staff-filter-role"
                                    v-model="form.role"
                                    label="Role"
                                    :options="roleOptions"
                                />
                            </div>
                        </template>
                        <template #primary>
                            <div class="flex gap-2 max-sm:w-full">
                                <button
                                    v-if="phone"
                                    type="button"
                                    :class="`${btn} flex-1`"
                                    @click="sheetOpen = true"
                                >
                                    Filters{{
                                        filterCount ? ` (${filterCount})` : ''
                                    }}
                                </button>
                                <button
                                    type="submit"
                                    :class="`${btn} max-sm:flex-1`"
                                >
                                    Apply
                                </button>
                            </div>
                        </template>
                        <template #more>
                            <div class="w-full sm:w-44">
                                <OperationalSelect
                                    id="staff-filter-department"
                                    v-model="form.department"
                                    label="Department"
                                    :options="departmentOptions"
                                />
                            </div>
                            <div class="w-full sm:w-44">
                                <OperationalSelect
                                    id="staff-filter-status"
                                    v-model="form.status"
                                    label="Status"
                                    :options="statusOptions"
                                />
                            </div>
                        </template>
                    </V2Toolbar>
                </form>

                <V2Dialog
                    v-model:open="sheetOpen"
                    title="Filters"
                    confirm-label="Apply"
                    cancel-label="Close"
                    @confirm="
                        sheetOpen = false;
                        applyFilters();
                    "
                >
                    <div class="grid gap-3">
                        <label class="grid gap-1 text-sm font-medium"
                            >Branch
                            <OperationalSelect
                                id="staff-sheet-branch"
                                v-model="form.branch"
                                label="Branch"
                                :options="branchOptions"
                            />
                        </label>
                        <label class="grid gap-1 text-sm font-medium"
                            >Role
                            <OperationalSelect
                                id="staff-sheet-role"
                                v-model="form.role"
                                label="Role"
                                :options="roleOptions"
                            />
                        </label>
                        <label class="grid gap-1 text-sm font-medium"
                            >Department
                            <OperationalSelect
                                id="staff-sheet-department"
                                v-model="form.department"
                                label="Department"
                                :options="departmentOptions"
                            />
                        </label>
                        <label class="grid gap-1 text-sm font-medium"
                            >Status
                            <OperationalSelect
                                id="staff-sheet-status"
                                v-model="form.status"
                                label="Status"
                                :options="statusOptions"
                            />
                        </label>
                        <button
                            type="button"
                            class="inline-flex h-11 items-center justify-center justify-self-start rounded-[10px] px-2 text-sm font-medium text-primary hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </V2Dialog>

                <p class="text-sm text-muted-foreground">
                    {{ staff.total }} staff account{{
                        staff.total === 1 ? '' : 's'
                    }}
                    · Current effective assignments shown
                </p>

                <V2Table
                    label="Staff accounts"
                    :columns="5"
                    cards="xl"
                    min-width="880px"
                    :empty="staff.data.length === 0"
                    empty-message="No staff accounts match the current filters."
                >
                    <template #head>
                        <tr>
                            <th scope="col">Staff member</th>
                            <th scope="col">Employment</th>
                            <th scope="col">Roles</th>
                            <th scope="col">Current access</th>
                            <th scope="col">Status</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="member in staff.data"
                            :key="member.id"
                            class="max-xl:relative max-xl:has-[a:focus-visible]:ring-2 max-xl:has-[a:focus-visible]:ring-ring max-xl:has-[a:focus-visible]:ring-offset-2 max-xl:has-[a:focus-visible]:ring-offset-background"
                        >
                            <td data-primary data-label="Staff member">
                                <Link
                                    :href="`/staff/${member.id}`"
                                    :aria-label="`Open ${member.name}`"
                                    class="font-medium text-primary underline-offset-2 outline-none hover:underline focus-visible:underline max-xl:pr-8 max-xl:after:absolute max-xl:after:inset-0 max-xl:after:content-[''] xl:focus-visible:rounded-sm xl:focus-visible:ring-2 xl:focus-visible:ring-ring xl:focus-visible:ring-offset-2 xl:focus-visible:ring-offset-background"
                                    >{{ member.name }}</Link
                                >
                                <svg
                                    class="pointer-events-none size-5 text-muted-foreground max-xl:absolute max-xl:top-4 max-xl:right-3 xl:hidden"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="m9 6 6 6-6 6" />
                                </svg>
                                <div
                                    class="mt-0.5 text-sm font-normal [overflow-wrap:anywhere] text-muted-foreground"
                                >
                                    {{ member.email }}
                                </div>
                                <div
                                    v-if="member.staffNumber"
                                    class="mt-0.5 text-sm font-normal text-muted-foreground"
                                >
                                    {{ member.staffNumber }}
                                </div>
                            </td>
                            <td data-label="Employment">
                                <div class="min-w-0 max-xl:text-right">
                                    <div>
                                        {{
                                            member.department ?? 'Not assigned'
                                        }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-sm text-muted-foreground"
                                    >
                                        {{ member.jobTitle ?? 'No job title' }}
                                    </div>
                                </div>
                            </td>
                            <td data-label="Roles">
                                <div class="flex max-w-56 flex-wrap gap-1.5">
                                    <V2Chip
                                        v-for="role in member.roles"
                                        :key="role"
                                        tone="neutral"
                                        plain
                                        :label="formatRoleLabel(role)"
                                    />
                                </div>
                                <span
                                    v-if="!member.roles.length"
                                    class="text-sm text-muted-foreground"
                                    >No role</span
                                >
                            </td>
                            <td data-label="Current access">
                                <div
                                    v-if="member.currentAssignments.length"
                                    class="flex max-w-72 flex-wrap gap-1.5"
                                >
                                    <V2Chip
                                        v-for="assignment in member.currentAssignments"
                                        :key="assignment.id"
                                        :tone="
                                            assignment.isPrimary
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            assignment.branch.name +
                                            (assignment.isPrimary
                                                ? ' · Primary'
                                                : '')
                                        "
                                    />
                                </div>
                                <V2Chip
                                    v-else
                                    tone="warning"
                                    label="No current assignment"
                                />
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="
                                        member.isActive ? 'success' : 'neutral'
                                    "
                                    :label="
                                        member.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </td>
                        </tr>
                    </template>
                </V2Table>

                <V2Pager
                    v-if="staff.last_page > 1"
                    :current-page="staff.current_page"
                    :last-page="staff.last_page"
                    :total="staff.total"
                    :per-page="20"
                    @change="applyFilters($event)"
                />
            </template>
        </V2WorkLayout>
    </PageFrame>
</template>
