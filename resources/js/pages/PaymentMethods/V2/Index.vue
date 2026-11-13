<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2DisclosureBar from '@/components/v2/V2DisclosureBar.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Payment Methods (shown only in V2; Classic is `pages/PaymentMethods/Index.vue`, untouched). Same props,
 * same routes (`/payment-methods`, `/payment-methods/{id}`, `/classification`, `/publish`, `/deactivate`), same
 * permission and `lock_version` on a classification change. Look only.
 */
type PaymentMethodRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    requiresReference: boolean;
    sortOrder: number;
    isActive: boolean;
    classification: 'cash' | 'non_cash' | null;
    lockVersion: number;
};

defineProps<{ methods: PaymentMethodRow[] }>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Payment methods' },
]);

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:w-full lg:h-9';

const classificationLabel = (method: PaymentMethodRow) =>
    method.classification === 'cash'
        ? 'Cash'
        : method.classification === 'non_cash'
          ? 'Non-cash'
          : 'Not classified';

const menuFor = (method: PaymentMethodRow): RowMenuGroup[] => [
    {
        label: 'Open',
        items: [
            { id: 'edit', label: 'Edit details', allowed: true },
            { id: 'classify', label: 'Change classification', allowed: true },
        ],
    },
    {
        label: 'Status',
        items: [
            method.isActive
                ? {
                      id: 'deactivate',
                      label: 'Deactivate method',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'publish', label: 'Publish method', allowed: true },
        ],
    },
];

// --- add
const addOpen = ref(false);
const createForm = useForm({
    code: '',
    name: '',
    description: '',
    requires_reference: false,
    sort_order: 100,
});
const create = () => {
    createForm
        .transform((data) => ({
            ...data,
            description: data.description.trim() || null,
        }))
        .post('/payment-methods', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                addOpen.value = false;
            },
        });
};

// --- classification
const classifying = ref<PaymentMethodRow | null>(null);
const classificationForm = useForm({
    classification: null as 'cash' | 'non_cash' | null,
    lock_version: 0,
});
const classificationChoice = {
    get: () => classificationForm.classification ?? 'none',
    set: (value: string) => {
        classificationForm.classification =
            value === 'cash' || value === 'non_cash' ? value : null;
    },
};
const saveClassification = () => {
    if (!classifying.value) {
        return;
    }

    classificationForm.patch(
        `/payment-methods/${classifying.value.id}/classification`,
        {
            preserveScroll: true,
            onSuccess: () => {
                classifying.value = null;
                classificationForm.reset();
            },
        },
    );
};

// --- edit
const editingId = ref<number | null>(null);
const editForm = useForm({
    name: '',
    description: '',
    requires_reference: false,
    sort_order: 100,
});
const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
};
const saveEdit = () => {
    if (editingId.value === null) {
        return;
    }

    editForm
        .transform((data) => ({
            ...data,
            description: data.description.trim() || null,
        }))
        .patch(`/payment-methods/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: cancelEdit,
        });
};

// --- status
const toDeactivate = ref<PaymentMethodRow | null>(null);
const busyId = ref<number | null>(null);
const changeStatus = (method: PaymentMethodRow) => {
    busyId.value = method.id;
    router.post(
        `/payment-methods/${method.id}/${method.isActive ? 'deactivate' : 'publish'}`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyId.value = null;
                toDeactivate.value = null;
            },
        },
    );
};

const onMenu = (method: PaymentMethodRow, id: string) => {
    if (id === 'edit') {
        editingId.value = method.id;
        editForm.name = method.name;
        editForm.description = method.description ?? '';
        editForm.requires_reference = method.requiresReference;
        editForm.sort_order = method.sortOrder;
        editForm.clearErrors();
    } else if (id === 'classify') {
        classifying.value = method;
        classificationForm.classification = method.classification;
        classificationForm.lock_version = method.lockVersion;
        classificationForm.clearErrors();
    } else if (id === 'deactivate') {
        toDeactivate.value = method;
    } else if (id === 'publish') {
        changeStatus(method);
    }
};
</script>

<template>
    <Head title="Payment methods" />
    <PageFrame>
        <V2WorkLayout
            title="Payment methods"
            description="Configure the payment methods available at checkout."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-method"
                    @click="addOpen = true"
                >
                    Add payment method
                </button>
            </template>

            <template #main>
                <V2DisclosureBar title="How classification affects reports">
                    <p class="text-sm">
                        Classification changes apply to reports generated from
                        now on, including past periods. Stored payments and
                        receipts never change. Not classified is counted as
                        Non-cash in reports.
                    </p>
                </V2DisclosureBar>
                <V2Table
                    label="Payment methods"
                    :columns="6"
                    cards="xl"
                    min-width="760px"
                    :empty="methods.length === 0"
                    empty-message="No payment methods are set up. Add and publish one before recording payments."
                >
                    <template #head>
                        <tr>
                            <th scope="col">Method</th>
                            <th scope="col" class="min-w-40 whitespace-nowrap">
                                Classification
                            </th>
                            <th scope="col">Reference</th>
                            <th scope="col" class="text-right">Order</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="method in methods"
                            :key="method.id"
                            :data-method="method.code"
                        >
                            <td data-label="Method" data-primary>
                                <span class="block text-sm font-medium">{{
                                    method.name
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ method.code }}</span
                                >
                                <span
                                    v-if="method.description"
                                    class="block text-xs text-muted-foreground"
                                    >{{ method.description }}</span
                                >
                            </td>
                            <td data-label="Classification" class="min-w-40">
                                <V2Chip
                                    :tone="
                                        method.classification
                                            ? 'info'
                                            : 'neutral'
                                    "
                                    :label="classificationLabel(method)"
                                />
                            </td>
                            <td data-label="Reference">
                                {{
                                    method.requiresReference ? 'Required' : 'No'
                                }}
                            </td>
                            <td data-label="Order" class="text-right">
                                {{ method.sortOrder }}
                            </td>
                            <td data-label="Status">
                                <V2Chip
                                    :tone="
                                        method.isActive ? 'success' : 'neutral'
                                    "
                                    :label="
                                        method.isActive ? 'Active' : 'Inactive'
                                    "
                                />
                            </td>
                            <td data-actions class="text-right">
                                <V2RowMenu
                                    :label="`Actions for ${method.name}`"
                                    :groups="menuFor(method)"
                                    :busy="busyId === method.id"
                                    show-label
                                    @select="onMenu(method, $event)"
                                />
                            </td>
                        </tr>
                    </template>
                </V2Table>
            </template>
        </V2WorkLayout>

        <V2Dialog
            v-model:open="addOpen"
            title="Add payment method"
            description="New methods are inactive until published. The code cannot be changed after it is created."
            confirm-label="Add payment method"
            :busy="createForm.processing"
            @confirm="create()"
        >
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Code"
                required
                hint="For example CASH or BANK_TRANSFER."
                :error="createForm.errors.code"
            >
                <input
                    :id="id"
                    v-model="createForm.code"
                    maxlength="40"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Name"
                required
                :error="createForm.errors.name"
            >
                <input
                    :id="id"
                    v-model="createForm.name"
                    maxlength="100"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Description (optional)"
                :error="createForm.errors.description"
            >
                <input
                    :id="id"
                    v-model="createForm.description"
                    maxlength="500"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <label class="flex min-h-11 items-center gap-3 text-sm lg:min-h-9">
                <Checkbox v-model:checked="createForm.requires_reference" />
                Requires a transaction reference
            </label>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Display order"
                :error="createForm.errors.sort_order"
            >
                <input
                    :id="id"
                    v-model.number="createForm.sort_order"
                    type="number"
                    min="0"
                    max="32767"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
        </V2Dialog>

        <V2Dialog
            :open="editingId !== null"
            title="Edit payment method"
            description="The code stays as the permanent identifier of every transaction."
            confirm-label="Save changes"
            :busy="editForm.processing"
            @update:open="$event || cancelEdit()"
            @confirm="saveEdit()"
        >
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Name"
                required
                :error="editForm.errors.name"
            >
                <input
                    :id="id"
                    v-model="editForm.name"
                    maxlength="100"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Description (optional)"
                :error="editForm.errors.description"
            >
                <input
                    :id="id"
                    v-model="editForm.description"
                    maxlength="500"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <label class="flex min-h-11 items-center gap-3 text-sm lg:min-h-9">
                <Checkbox v-model:checked="editForm.requires_reference" />
                Requires a transaction reference
            </label>
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Display order"
                :error="editForm.errors.sort_order"
            >
                <input
                    :id="id"
                    v-model.number="editForm.sort_order"
                    type="number"
                    min="0"
                    max="32767"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
        </V2Dialog>

        <V2Dialog
            :open="classifying !== null"
            :title="
                classifying
                    ? `Change classification for ${classifying.name}`
                    : 'Change classification'
            "
            description="Not classified counts as Non-cash. Reports use the current classification; stored receipts are unchanged."
            confirm-label="Save classification"
            :busy="classificationForm.processing"
            @update:open="$event || (classifying = null)"
            @confirm="saveClassification()"
        >
            <V2OptionGroup
                :model-value="classificationChoice.get()"
                label="Classification"
                :options="[
                    { value: 'cash', label: 'Cash' },
                    { value: 'non_cash', label: 'Non-cash' },
                    { value: 'none', label: 'Not classified' },
                ]"
                :error="
                    classificationForm.errors.classification ||
                    classificationForm.errors.lock_version
                "
                @update:model-value="classificationChoice.set"
            />
        </V2Dialog>

        <V2Dialog
            :open="toDeactivate !== null"
            :title="
                toDeactivate
                    ? `Deactivate ${toDeactivate.name}?`
                    : 'Deactivate method?'
            "
            description="It will not be offered at checkout. Payments already recorded are not changed. You can publish it again."
            confirm-label="Deactivate method"
            tone="danger"
            :busy="busyId !== null"
            @update:open="toDeactivate = $event ? toDeactivate : null"
            @confirm="toDeactivate && changeStatus(toDeactivate)"
        />
    </PageFrame>
</template>
