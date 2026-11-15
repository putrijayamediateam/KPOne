<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { getCountries, getCountryCallingCode } from 'libphonenumber-js/max';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Pager from '@/components/v2/V2Pager.vue';
import V2RowMenu from '@/components/v2/V2RowMenu.vue';
import type { RowMenuGroup } from '@/components/v2/V2RowMenu.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2Toolbar from '@/components/v2/V2Toolbar.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Suppliers settings (shown only in V2; Classic is `pages/InventoryServicesSettings/Suppliers.vue`,
 * untouched). Same props, same routes, same payloads and lock versions. Look only.
 */
type Supplier = {
    publicId: string;
    name: string;
    contacts: { name: string | null; phone: string | null }[];
    active: boolean;
    lockVersion: number;
};
const props = defineProps<{
    branchId: number;
    q: string;
    suppliers: Supplier[];
    pagination: { currentPage: number; lastPage: number; total: number };
}>();

usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Suppliers' },
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
const busySupplier = ref<string | null>(null);
const archiving = ref<Supplier | null>(null);
const statusKeys = new Map<string, string>();
const form = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    name: '',
    contacts: [{ name: '', phone: '', country: 'MY' }],
});
const countryNames = new Intl.DisplayNames(['en'], { type: 'region' });
const countries = getCountries()
    .map((country) => ({
        value: country,
        label: `${String.fromCodePoint(...[...country].map((letter) => 127397 + letter.charCodeAt(0)))} +${getCountryCallingCode(country)} ${countryNames.of(country)}`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label));

const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const outlineButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const filledButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none max-sm:w-full lg:h-9';

const go = (number = 1) =>
    router.get(
        '/inventory-services-settings/suppliers',
        { q: search.value || undefined, page: number > 1 ? number : undefined },
        { preserveState: true, preserveScroll: true },
    );
const open = () => {
    form.reset();
    form.clearErrors();
    form.idempotency_key = crypto.randomUUID();
    form.expected_branch_id = props.branchId;
    adding.value = true;
};
const save = () =>
    form.post('/inventory-services-settings/suppliers', {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false;
            form.reset();
        },
    });
const toggle = (supplier: Supplier) => {
    if (busySupplier.value) {
        return;
    }

    busySupplier.value = supplier.publicId;
    const token = `${supplier.publicId}:${supplier.lockVersion}:${!supplier.active}`;
    const key = statusKeys.get(token) ?? crypto.randomUUID();
    statusKeys.set(token, key);
    router.patch(
        `/inventory-services-settings/suppliers/${encodeURIComponent(supplier.publicId)}/active`,
        {
            expected_branch_id: props.branchId,
            lock_version: supplier.lockVersion,
            active: !supplier.active,
            idempotency_key: key,
        },
        {
            preserveScroll: true,
            onSuccess: () => statusKeys.delete(token),
            onFinish: () => {
                busySupplier.value = null;
                archiving.value = null;
            },
        },
    );
};
const menuFor = (supplier: Supplier): RowMenuGroup[] => [
    {
        label: 'Status',
        items: [
            supplier.active
                ? {
                      id: 'archive',
                      label: 'Archive supplier',
                      allowed: true,
                      tone: 'danger',
                  }
                : { id: 'activate', label: 'Activate supplier', allowed: true },
        ],
    },
];
const onMenu = (supplier: Supplier, id: string) => {
    if (id === 'archive') {
        archiving.value = supplier;
    } else if (id === 'activate') {
        toggle(supplier);
    }
};
const contactError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`contacts.${index}.${field}`];
const generalError = computed(
    () =>
        page.props.errors?.supplier ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key,
);
const addContact = () =>
    form.contacts.push({ name: '', phone: '', country: 'MY' });
</script>

<template>
    <Head title="Suppliers — Inventory and services settings" />
    <PageFrame>
        <V2WorkLayout
            title="Suppliers"
            description="Manage your suppliers and their contact persons."
        >
            <template #actions>
                <button
                    type="button"
                    :class="filledButton"
                    data-action="add-supplier"
                    @click="open"
                >
                    Add supplier
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
                <form @submit.prevent="go()">
                    <V2Toolbar
                        v-model="search"
                        search-label="Search suppliers"
                        search-placeholder="Name, contact person or phone"
                        @search="go()"
                    />
                </form>
                <V2Table
                    label="Suppliers"
                    :columns="5"
                    cards="xl"
                    min-width="720px"
                    :empty="suppliers.length === 0"
                    :empty-message="
                        q
                            ? 'No suppliers match your search.'
                            : 'No suppliers yet.'
                    "
                >
                    <template #head>
                        <tr>
                            <th scope="col">Supplier</th>
                            <th scope="col">Contact person</th>
                            <th scope="col" class="whitespace-nowrap">Phone</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr
                            v-for="supplier in suppliers"
                            :key="supplier.publicId"
                            :data-supplier="supplier.publicId"
                        >
                            <td data-label="Supplier" data-primary>
                                <span
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span class="text-sm font-medium">{{
                                        supplier.name
                                    }}</span>
                                    <V2Chip
                                        class="xl:hidden"
                                        :tone="
                                            supplier.active
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            supplier.active
                                                ? 'Active'
                                                : 'Archived'
                                        "
                                    />
                                </span>
                            </td>
                            <td data-label="Contact person">
                                <p
                                    v-for="(
                                        contact, index
                                    ) in supplier.contacts"
                                    :key="index"
                                    class="min-h-6"
                                >
                                    {{ contact.name || '—' }}
                                </p>
                                <span v-if="!supplier.contacts.length">—</span>
                            </td>
                            <td data-label="Phone" class="whitespace-nowrap">
                                <p
                                    v-for="(
                                        contact, index
                                    ) in supplier.contacts"
                                    :key="index"
                                    class="min-h-6"
                                >
                                    {{ contact.phone || '—' }}
                                </p>
                                <span v-if="!supplier.contacts.length">—</span>
                            </td>
                            <td data-label="Status" class="max-xl:hidden!">
                                <V2Chip
                                    :tone="
                                        supplier.active ? 'success' : 'neutral'
                                    "
                                    :label="
                                        supplier.active ? 'Active' : 'Archived'
                                    "
                                />
                            </td>
                            <td data-actions class="text-right">
                                <V2RowMenu
                                    :label="`Actions for ${supplier.name}`"
                                    :groups="menuFor(supplier)"
                                    :busy="busySupplier === supplier.publicId"
                                    show-label
                                    @select="onMenu(supplier, $event)"
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
            title="Add supplier"
            description="Add a supplier company and its contact persons."
            confirm-label="Add supplier"
            :busy="form.processing"
            @confirm="save()"
        >
            <V2Field
                v-slot="{ id, describedBy, invalid }"
                label="Company name"
                required
                :error="form.errors.name"
            >
                <input
                    :id="id"
                    v-model="form.name"
                    maxlength="200"
                    autocomplete="off"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    :class="inputClass"
                />
            </V2Field>
            <div
                v-for="(contact, index) in form.contacts"
                :key="index"
                class="flex flex-col gap-3 rounded-[10px] border p-3"
            >
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    :label="`Contact person ${index + 1} (optional)`"
                    :error="contactError(index, 'name')"
                >
                    <input
                        :id="id"
                        v-model="contact.name"
                        maxlength="200"
                        autocomplete="off"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="inputClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    :label="`Phone country ${index + 1}`"
                    :error="contactError(index, 'country')"
                >
                    <OperationalSelect
                        :id="id"
                        v-model="contact.country"
                        :label="`Phone country ${index + 1}`"
                        :describedby="describedBy"
                        :invalid="invalid"
                        :options="countries"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    :label="`Phone number ${index + 1}`"
                    :error="contactError(index, 'phone')"
                >
                    <input
                        :id="id"
                        v-model="contact.phone"
                        type="tel"
                        inputmode="tel"
                        maxlength="40"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="inputClass"
                    />
                </V2Field>
            </div>
            <p
                v-if="form.errors.contacts"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ form.errors.contacts }}
            </p>
            <button
                type="button"
                :class="[outlineButton, 'self-start']"
                :disabled="form.contacts.length >= 20 || form.processing"
                @click="addContact"
            >
                + Add another contact
            </button>
        </V2Dialog>

        <V2Dialog
            :open="archiving !== null"
            :title="`Archive ${archiving?.name ?? 'supplier'}?`"
            description="An archived supplier cannot be chosen on new purchase orders or purchase requests, and a draft purchase order for it cannot be submitted. Orders already submitted are not changed, and nothing is deleted. You can activate it again."
            confirm-label="Archive supplier"
            tone="danger"
            :busy="busySupplier !== null"
            @update:open="$event || (archiving = null)"
            @confirm="archiving && toggle(archiving)"
        />
    </PageFrame>
</template>
