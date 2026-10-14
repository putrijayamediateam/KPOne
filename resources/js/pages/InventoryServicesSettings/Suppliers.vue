<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { getCountries, getCountryCallingCode } from 'libphonenumber-js/max';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CompactPagination } from '@/components/ui/pagination';

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
const page = usePage();
const search = ref(props.q);
const searching = ref(Boolean(props.q));
watch(
    () => props.q,
    (value) => {
        search.value = value;
    },
);
const adding = ref(false);
const busySupplier = ref<string | null>(null);
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
        code: country,
        label: `${String.fromCodePoint(...[...country].map((letter) => 127397 + letter.charCodeAt(0)))} +${getCountryCallingCode(country)} ${countryNames.of(country)}`,
    }))
    .sort((a, b) => a.label.localeCompare(b.label));
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
            },
        },
    );
};
const contactError = (index: number, field: string) =>
    (form.errors as Record<string, string>)[`contacts.${index}.${field}`];
const generalError = computed(
    () =>
        page.props.errors?.supplier ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key,
);
</script>

<template>
    <Head title="Suppliers — Inventory and services settings" />
    <main
        class="mx-auto grid w-full max-w-[1600px] gap-8 px-4 py-6 md:grid-cols-[220px_minmax(0,1fr)]"
    >
        <nav
            aria-label="Inventory and services settings"
            class="flex flex-wrap gap-2 md:flex-col"
        >
            <span
                aria-disabled="true"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground"
                >Price tier</span
            >
            <span
                aria-disabled="true"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground"
                >Medicine details</span
            >
            <span
                aria-current="page"
                class="rounded-md bg-muted px-4 py-3 text-sm font-semibold"
                >Suppliers</span
            >
        </nav>
        <section class="min-w-0">
            <p class="mb-3 text-sm text-muted-foreground">
                Clinic management <span class="mx-2">/</span> Inventory and
                services settings
            </p>
            <h1 class="border-b pb-3 text-2xl font-semibold">
                Inventory and services settings
            </h1>
            <div class="mt-5">
                <h2 class="text-lg font-medium">Suppliers</h2>
                <p class="text-sm text-muted-foreground">
                    Manage your suppliers and their contact persons.
                </p>
            </div>
            <p
                v-if="generalError"
                role="alert"
                class="mt-4 rounded border border-destructive p-3 text-sm text-destructive"
            >
                {{ generalError }}
            </p>
            <div class="mt-6 flex items-center justify-end gap-3 border-b pb-3">
                <form
                    v-if="searching"
                    class="flex min-w-0 items-center gap-2"
                    @submit.prevent="go()"
                >
                    <label class="sr-only" for="supplier-search"
                        >Search suppliers</label
                    ><input
                        id="supplier-search"
                        v-model="search"
                        type="search"
                        placeholder="Search suppliers"
                        maxlength="200"
                        class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm"
                    /><Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        aria-label="Search suppliers"
                        ><svg
                            viewBox="0 0 24 24"
                            class="size-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <circle cx="10.5" cy="10.5" r="6.5" />
                            <path d="m16 16 4 4" /></svg
                    ></Button>
                </form>
                <Button
                    v-else
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label="Search suppliers"
                    @click="searching = true"
                    ><svg
                        viewBox="0 0 24 24"
                        class="size-5"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <circle cx="10.5" cy="10.5" r="6.5" />
                        <path d="m16 16 4 4" /></svg
                ></Button>
                <Button type="button" @click="open">Add new</Button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th scope="col" class="px-4 py-4 font-medium">
                                NAME
                            </th>
                            <th scope="col" class="px-4 py-4 font-medium">
                                PIC
                            </th>
                            <th scope="col" class="px-4 py-4 font-medium">
                                PHONE
                            </th>
                            <th scope="col" class="px-4 py-4 font-medium">
                                STATUS
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-4 text-right font-medium"
                            >
                                ACTION
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="supplier in suppliers"
                            :key="supplier.publicId"
                            class="border-b"
                        >
                            <td class="px-4 py-4">{{ supplier.name }}</td>
                            <td class="px-4 py-4">
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
                            <td class="px-4 py-4 whitespace-nowrap">
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
                            <td class="px-4 py-4">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="supplier.active"
                                    :aria-label="`${supplier.active ? 'Archive' : 'Activate'} ${supplier.name}`"
                                    :disabled="busySupplier !== null"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors disabled:opacity-50"
                                    :class="
                                        supplier.active
                                            ? 'bg-emerald-500'
                                            : 'bg-muted-foreground/40'
                                    "
                                    @click="toggle(supplier)"
                                >
                                    <span
                                        class="inline-block size-5 rounded-full bg-white shadow-sm transition-transform"
                                        :class="
                                            supplier.active
                                                ? 'translate-x-5'
                                                : 'translate-x-1'
                                        "
                                    />
                                </button>
                            </td>
                            <td class="px-4 py-4 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled
                                    :aria-label="`More actions for ${supplier.name}`"
                                    title="More actions are not available yet"
                                    >…</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="!suppliers.length">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                {{
                                    q
                                        ? 'No suppliers match your search.'
                                        : 'No suppliers yet.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <CompactPagination
                v-if="pagination.total > 0"
                class="mt-4"
                :current-page="pagination.currentPage"
                :last-page="pagination.lastPage"
                :total="pagination.total"
                @change="go"
            />
        </section>
        <Dialog v-model:open="adding"
            ><DialogContent class="sm:max-w-[560px]"
                ><DialogHeader class="border-b pb-4"
                    ><DialogTitle class="text-center"
                        >Add new seller</DialogTitle
                    ><DialogDescription class="sr-only"
                        >Add a supplier company and its contact
                        persons.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="save">
                    <p class="font-semibold">Sold by</p>
                    <div>
                        <label
                            for="supplier-name"
                            class="mb-1 block text-sm font-medium"
                            >Company name</label
                        ><input
                            id="supplier-name"
                            v-model="form.name"
                            required
                            maxlength="200"
                            placeholder="Company name"
                            class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                        /><InputError :message="form.errors.name" />
                    </div>
                    <div
                        class="max-h-[45vh] space-y-4 overflow-y-auto rounded-lg border bg-muted/30 p-4"
                    >
                        <div
                            v-for="(contact, index) in form.contacts"
                            :key="index"
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <div>
                                <label
                                    :for="`pic-${index}`"
                                    class="mb-1 block text-sm font-medium"
                                    >PIC name</label
                                ><input
                                    :id="`pic-${index}`"
                                    v-model="contact.name"
                                    maxlength="200"
                                    placeholder="Name"
                                    class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                                /><InputError
                                    :message="contactError(index, 'name')"
                                />
                            </div>
                            <div>
                                <label
                                    :for="`phone-${index}`"
                                    class="mb-1 block text-sm font-medium"
                                    >Phone number</label
                                >
                                <div class="flex gap-1">
                                    <select
                                        v-model="contact.country"
                                        :aria-label="`Phone country ${index + 1}`"
                                        class="h-10 w-24 min-w-0 rounded-md border bg-background px-1 text-sm"
                                    >
                                        <option
                                            v-for="country in countries"
                                            :key="country.code"
                                            :value="country.code"
                                        >
                                            {{ country.label }}
                                        </option></select
                                    ><input
                                        :id="`phone-${index}`"
                                        v-model="contact.phone"
                                        type="tel"
                                        inputmode="tel"
                                        maxlength="40"
                                        placeholder="Phone number"
                                        class="h-10 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm"
                                    />
                                </div>
                                <InputError
                                    :message="
                                        contactError(index, 'phone') ||
                                        contactError(index, 'country')
                                    "
                                />
                            </div>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :disabled="
                                form.contacts.length >= 20 || form.processing
                            "
                            @click="
                                form.contacts.push({
                                    name: '',
                                    phone: '',
                                    country: 'MY',
                                })
                            "
                            >+ Add more</Button
                        ><InputError :message="form.errors.contacts" />
                    </div>
                    <InputError :message="generalError" /><DialogFooter
                        class="border-t pt-4"
                        ><Button
                            type="button"
                            variant="ghost"
                            :disabled="form.processing"
                            @click="adding = false"
                            >Cancel</Button
                        ><Button type="submit" :disabled="form.processing">{{
                            form.processing ? 'Saving…' : 'Save'
                        }}</Button></DialogFooter
                    >
                </form></DialogContent
            ></Dialog
        >
    </main>
</template>
