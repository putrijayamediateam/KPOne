<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Medicine details settings (shown only in V2; Classic is
 * `pages/InventoryServicesSettings/MedicineDetails.vue`, untouched). Same props, same routes, same payloads and lock
 * versions. Look only.
 */
type Option = {
    id: number;
    label: string;
    active: boolean;
    lockVersion: number;
};
const props = defineProps<{
    branchId: number;
    type: string;
    tabs: Record<string, string>;
    q: string;
    options: Option[];
    pagination: { currentPage: number; lastPage: number; total: number };
}>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Medicine details' },
]);

const page = usePage();
const search = ref(props.q);
watch(
    () => props.q,
    (value) => {
        search.value = value;
    },
);
const adding = ref(false);
const renaming = ref(false);
const archiving = ref<Option | null>(null);
const selected = ref<Option | null>(null);
const batch = computed(() => ['precaution', 'indication'].includes(props.type));
const title = computed(() => props.tabs[props.type]);
// "Drug group" -> "drug groups", "Frequency" -> "frequencies": used in the empty state and the search name.
const plural = computed(() => {
    const label = (title.value ?? 'option').toLowerCase();

    return label.endsWith('y') ? `${label.slice(0, -1)}ies` : `${label}s`;
});
const emptyTitle = computed(() =>
    props.q
        ? `No ${plural.value} match "${props.q}"`
        : `No ${plural.value} yet`,
);
const emptyDescription = computed(() =>
    props.q ? 'Try another search.' : 'Add one with the Add button.',
);
const tabList = computed(() =>
    Object.entries(props.tabs).map(([value, label]) => ({ value, label })),
);
const dialogType = ref(props.type);
const addForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    labels: [''],
});
const renameForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    lock_version: 1,
    label: '',
});
const busy = ref<number | null>(null);
const statusKeys = new Map<string, string>();
const generalError = computed(
    () =>
        page.props.errors?.option ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key,
);

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:w-full lg:h-9';

const go = (number = 1) =>
    router.get(
        '/inventory-services-settings/medicine-details',
        {
            type: props.type,
            q: search.value || undefined,
            page: number > 1 ? number : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
const openTab = (type: string) => {
    if (type !== props.type) {
        router.get('/inventory-services-settings/medicine-details', { type });
    }
};
const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.expected_branch_id = props.branchId;
    addForm.idempotency_key = crypto.randomUUID();
    dialogType.value = props.type;
    adding.value = true;
};
const save = () =>
    addForm.post(
        `/inventory-services-settings/medicine-details/${dialogType.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                adding.value = false;
            },
        },
    );
const openRename = (option: Option) => {
    selected.value = option;
    dialogType.value = props.type;
    renameForm.clearErrors();
    renameForm.label = option.label;
    renameForm.lock_version = option.lockVersion;
    renameForm.expected_branch_id = props.branchId;
    renameForm.idempotency_key = crypto.randomUUID();
    renaming.value = true;
};
const rename = () => {
    if (!selected.value) {
        return;
    }

    renameForm.patch(
        `/inventory-services-settings/medicine-details/${dialogType.value}/${selected.value.id}/rename`,
        {
            preserveScroll: true,
            onSuccess: () => {
                renaming.value = false;
            },
        },
    );
};
const toggle = (option: Option) => {
    if (busy.value !== null) {
        return;
    }

    busy.value = option.id;
    const token = `${option.id}:${option.lockVersion}:${!option.active}`;
    const key = statusKeys.get(token) ?? crypto.randomUUID();
    statusKeys.set(token, key);
    router.patch(
        `/inventory-services-settings/medicine-details/${props.type}/${option.id}/active`,
        {
            expected_branch_id: props.branchId,
            lock_version: option.lockVersion,
            active: !option.active,
            idempotency_key: key,
        },
        {
            preserveScroll: true,
            onSuccess: () => statusKeys.delete(token),
            onFinish: () => {
                busy.value = null;
                archiving.value = null;
            },
        },
    );
};
const menuFor = (option: Option): RowMenuGroup[] => [
    {
        label: 'Edit',
        items: [{ id: 'rename', label: 'Rename', allowed: true }],
    },
    {
        label: 'Status',
        items: [
            option.active
                ? {
                      id: 'archive',
                      label: 'Archive',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'restore', label: 'Restore', allowed: true },
        ],
    },
];
const onMenu = (option: Option, id: string) => {
    if (id === 'rename') {
        openRename(option);
    } else if (id === 'archive') {
        archiving.value = option;
    } else if (id === 'restore') {
        toggle(option);
    }
};
const labelError = (index: number) =>
    (addForm.errors as Record<string, string>)[`labels.${index}`];
</script>

<template>
    <Head title="Medicine details — Inventory and services settings" />
    <PageFrame>
        <V2WorkLayout
            title="Medicine details"
            description="Set up your drug groups, dosage units and instructions."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-option"
                    @click="openAdd"
                >
                    Add {{ title?.toLowerCase() }}
                </button>
            </template>

            <template #main>
                <p
                    v-if="generalError"
                    role="alert"
                    class="rounded-[10px] border border-destructive px-3 py-2 text-sm text-destructive"
                >
                    {{ generalError }}
                </p>
                <V2Tabs
                    :model-value="type"
                    ariaLabel="Medicine details tabs"
                    :tabs="tabList"
                    @update:model-value="openTab"
                />
                <form @submit.prevent="go()">
                    <V2Toolbar
                        v-model="search"
                        :search-label="`Search ${plural}`"
                        :search-placeholder="`Search ${plural}`"
                        @search="go()"
                    />
                </form>
                <V2Table
                    label="Medicine details options"
                    :columns="3"
                    cards="xl"
                    min-width="560px"
                    :empty="options.length === 0"
                    :empty-message="`${emptyTitle}. ${emptyDescription}`"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="option in options"
                            :key="option.id"
                            :data-option="option.id"
                        >
                            <td data-label="Name" data-primary>
                                <span
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span
                                        class="text-sm font-medium [overflow-wrap:anywhere]"
                                        >{{ option.label }}</span
                                    >
                                    <V2Chip
                                        class="xl:hidden"
                                        :tone="
                                            option.active
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            option.active
                                                ? 'Active'
                                                : 'Archived'
                                        "
                                    />
                                </span>
                            </td>
                            <td data-label="Status" class="max-xl:hidden!">
                                <V2Chip
                                    :tone="
                                        option.active ? 'success' : 'neutral'
                                    "
                                    :label="
                                        option.active ? 'Active' : 'Archived'
                                    "
                                />
                            </td>
                            <td data-actions class="text-right">
                                <V2RowMenu
                                    :label="`Actions for ${option.label}`"
                                    :groups="menuFor(option)"
                                    :busy="busy === option.id"
                                    show-label
                                    @select="onMenu(option, $event)"
                                />
                            </td>
                        </tr>
                    </template>
                </V2Table>
                <V2Pager
                    v-if="pagination.total > 0"
                    :current-page="pagination.currentPage"
                    :last-page="pagination.lastPage"
                    :total="pagination.total"
                    :per-page="25"
                    @change="go"
                />
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="adding"
            :title="`Add ${title?.toLowerCase()}`"
            :description="
                batch
                    ? `You can add more than one ${title?.toLowerCase()} at one time`
                    : 'Add an option for future selections.'
            "
            confirm-label="Add"
            :busy="addForm.processing"
            @confirm="save()"
        >
            <V2Field
                v-for="(_, index) in addForm.labels"
                :key="index"
                v-slot="{ id, describedBy, invalid }"
                :label="`${title} name`"
                required
                :error="labelError(index)"
            >
                <input
                    :id="id"
                    v-model="addForm.labels[index]"
                    maxlength="200"
                    autocomplete="off"
                    :placeholder="`Enter ${title?.toLowerCase()} name`"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <p
                v-if="addForm.errors.labels || generalError"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ addForm.errors.labels || generalError }}
            </p>
            <button
                v-if="batch"
                type="button"
                :class="[outlineButton, 'self-start']"
                :disabled="addForm.labels.length >= 20 || addForm.processing"
                @click="addForm.labels.push('')"
            >
                Add more {{ title?.toLowerCase() }}
            </button>
        </V2Dialog>

        <V2Dialog
            v-model:open="renaming"
            :title="`Rename ${title?.toLowerCase()}`"
            description="Changes future selections only. Saved medicines and prescriptions keep their text."
            confirm-label="Save"
            :busy="renameForm.processing"
            @confirm="rename()"
        >
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                :label="`${title} name`"
                required
                :error="renameForm.errors.label"
            >
                <input
                    :id="id"
                    v-model="renameForm.label"
                    maxlength="200"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <p
                v-if="generalError"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ generalError }}
            </p>
        </V2Dialog>

        <V2Dialog
            :open="archiving !== null"
            :title="`Archive ${archiving?.label ?? title?.toLowerCase()}?`"
            description="It will no longer be offered for new selections. Saved medicines and prescriptions keep their text. You can restore it later."
            confirm-label="Archive"
            tone="danger"
            :busy="busy !== null"
            @update:open="$event || (archiving = null)"
            @confirm="archiving && toggle(archiving)"
        />
    </PageFrame>
</template>
