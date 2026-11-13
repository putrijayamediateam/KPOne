<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2SwitchRow from '@/components/v2/V2SwitchRow.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Consultation settings (shown only in V2; Classic is `pages/ConsultationSettings/Index.vue`, untouched).
 * Same props, same routes, same payloads and lock versions. Look only.
 */
type Preset = {
    id: number;
    name: string;
    active: boolean;
    lockVersion: number;
};
const props = defineProps<{
    branchId: number;
    status: string;
    q: string;
    canCheckoutSettings: boolean;
    presets: Preset[];
    pagination: { currentPage: number; lastPage: number; total: number };
}>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Consultation settings' },
]);

const page = usePage();
const search = ref(props.q);
watch(
    () => props.q,
    (value) => {
        search.value = value;
    },
);
const modules = [
    {
        name: 'Antenatal record (Buku Pink)',
        description:
            'Record pregnancy details, antenatal readings, scans and the next visit, per pregnancy.',
    },
    {
        name: 'Dental chart',
        description:
            'Chart tooth and surface findings during a consultation, and keep a running chart per patient.',
    },
    {
        name: 'NCD follow-up',
        description:
            'Follow up patients with diabetes, hypertension and other chronic conditions.',
    },
];
const statusTabs = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
];
const adding = ref(false);
const renaming = ref(false);
const archiving = ref<Preset | null>(null);
const selected = ref<Preset | null>(null);
const addForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    names: [''],
});
const renameForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    lock_version: 1,
    name: '',
});
const busy = ref<number | null>(null);
const statusKeys = new Map<string, string>();
const generalError = computed(
    () =>
        page.props.errors?.preset ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key ||
        page.props.errors?.status ||
        page.props.errors?.q,
);

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:w-full lg:h-9';

const go = (number = 1, status = props.status) =>
    router.get(
        '/consultation-settings',
        {
            status,
            q: search.value || undefined,
            page: number > 1 ? number : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
const mutationUrl = (path: string) =>
    `${path}?${new URLSearchParams({ status: props.status, q: props.q }).toString()}`;
const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.expected_branch_id = props.branchId;
    addForm.idempotency_key = crypto.randomUUID();
    adding.value = true;
};
const save = () =>
    addForm.post(mutationUrl('/consultation-settings/diagnoses'), {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false;
        },
    });
const openRename = (preset: Preset) => {
    selected.value = preset;
    renameForm.clearErrors();
    renameForm.name = preset.name;
    renameForm.lock_version = preset.lockVersion;
    renameForm.expected_branch_id = props.branchId;
    renameForm.idempotency_key = crypto.randomUUID();
    renaming.value = true;
};
const rename = () => {
    if (!selected.value) {
        return;
    }

    renameForm.patch(
        mutationUrl(
            `/consultation-settings/diagnoses/${selected.value.id}/rename`,
        ),
        {
            preserveScroll: true,
            onSuccess: () => {
                renaming.value = false;
            },
        },
    );
};
const toggle = (preset: Preset) => {
    if (busy.value !== null) {
        return;
    }

    busy.value = preset.id;
    const token = `${preset.id}:${preset.lockVersion}:${!preset.active}`;
    const key = statusKeys.get(token) ?? crypto.randomUUID();
    statusKeys.set(token, key);
    router.patch(
        mutationUrl(`/consultation-settings/diagnoses/${preset.id}/active`),
        {
            expected_branch_id: props.branchId,
            lock_version: preset.lockVersion,
            active: !preset.active,
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
const menuFor = (preset: Preset): RowMenuGroup[] => [
    {
        label: 'Edit',
        items: [{ id: 'rename', label: 'Rename', allowed: true }],
    },
    {
        label: 'Status',
        items: [
            preset.active
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
const onMenu = (preset: Preset, id: string) => {
    if (id === 'rename') {
        openRename(preset);
    } else if (id === 'archive') {
        archiving.value = preset;
    } else if (id === 'restore') {
        toggle(preset);
    }
};
const nameError = (index: number) =>
    (addForm.errors as Record<string, string>)[`names.${index}`];
const presetEmptyTitle = computed(() =>
    props.q
        ? `No preset diagnoses match "${props.q}"`
        : 'No preset diagnoses yet',
);
const presetEmptyDescription = computed(() =>
    props.q ? 'Try another search.' : 'Add one with Add diagnosis.',
);
</script>

<template>
    <Head title="Consultation settings" />
    <PageFrame>
        <V2WorkLayout
            title="Consultation settings"
            description="Clinical modules and the preset diagnosis list used in consultations."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-diagnosis"
                    @click="openAdd"
                >
                    Add diagnosis
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

                <V2FormCard
                    title="Clinical modules"
                    description="Turn on the record types your practice uses. They stay off for general practice."
                >
                    <div class="flex flex-col divide-y">
                        <V2SwitchRow
                            v-for="module in modules"
                            :key="module.name"
                            :model-value="false"
                            :label="module.name"
                            disabled
                            :disabled-reason="`${module.description} Not available yet.`"
                        />
                    </div>
                </V2FormCard>

                <V2FormCard
                    title="Diagnosis list"
                    description="Add, rename and archive your preset diagnoses."
                >
                    <V2Tabs
                        :model-value="status"
                        ariaLabel="Diagnosis status tabs"
                        :tabs="statusTabs"
                        @update:model-value="go(1, $event)"
                    />
                    <form @submit.prevent="go()">
                        <V2Toolbar
                            v-model="search"
                            search-label="Search diagnoses"
                            search-placeholder="Search diagnoses"
                            @search="go()"
                        />
                    </form>
                    <V2Table
                        label="Preset diagnoses"
                        :columns="3"
                        cards="xl"
                        min-width="560px"
                        :empty="presets.length === 0"
                        :empty-message="`${presetEmptyTitle}. ${presetEmptyDescription}`"
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
                                v-for="preset in presets"
                                :key="preset.id"
                                :data-preset="preset.id"
                            >
                                <td data-label="Name" data-primary>
                                    <span
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                    >
                                        <span
                                            class="text-sm font-medium [overflow-wrap:anywhere]"
                                            >{{ preset.name }}</span
                                        >
                                        <V2Chip
                                            class="xl:hidden"
                                            :tone="
                                                preset.active
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                            :label="
                                                preset.active
                                                    ? 'Active'
                                                    : 'Archived'
                                            "
                                        />
                                    </span>
                                </td>
                                <td data-label="Status" class="max-xl:hidden!">
                                    <V2Chip
                                        :tone="
                                            preset.active
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            preset.active
                                                ? 'Active'
                                                : 'Archived'
                                        "
                                    />
                                </td>
                                <td data-actions class="text-right">
                                    <V2RowMenu
                                        :label="`Actions for ${preset.name}`"
                                        :groups="menuFor(preset)"
                                        :busy="busy === preset.id"
                                        show-label
                                        @select="onMenu(preset, $event)"
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
                </V2FormCard>
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="adding"
            title="Add diagnosis"
            description="You can add more than one diagnosis at one time"
            confirm-label="Add"
            :busy="addForm.processing"
            @confirm="save()"
        >
            <V2Field
                v-for="(_, index) in addForm.names"
                :key="index"
                v-slot="{ id, describedBy, invalid }"
                label="Diagnosis"
                required
                :error="nameError(index)"
            >
                <input
                    :id="id"
                    v-model="addForm.names[index]"
                    maxlength="500"
                    autocomplete="off"
                    placeholder="Enter diagnosis name"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <p
                v-if="addForm.errors.names || generalError"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ addForm.errors.names || generalError }}
            </p>
            <button
                type="button"
                :class="[outlineButton, 'self-start']"
                :disabled="addForm.names.length >= 20 || addForm.processing"
                @click="addForm.names.push('')"
            >
                Add more diagnosis
            </button>
        </V2Dialog>

        <V2Dialog
            v-model:open="renaming"
            title="Rename diagnosis"
            description="Changes the preset name only. Saved encounter diagnoses keep their text."
            confirm-label="Save"
            :busy="renameForm.processing"
            @confirm="rename()"
        >
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Diagnosis"
                required
                :error="renameForm.errors.name"
            >
                <input
                    :id="id"
                    v-model="renameForm.name"
                    maxlength="500"
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
            :title="`Archive ${archiving?.name ?? 'diagnosis'}?`"
            description="It will no longer be offered as a preset. Saved encounter diagnoses keep their text. You can restore it later."
            confirm-label="Archive"
            tone="danger"
            :busy="busy !== null"
            @update:open="$event || (archiving = null)"
            @confirm="archiving && toggle(archiving)"
        />
    </PageFrame>
</template>
