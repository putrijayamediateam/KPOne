<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CompactPagination } from '@/components/ui/pagination';

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
const renaming = ref(false);
const selected = ref<Option | null>(null);
const batch = computed(() => ['precaution', 'indication'].includes(props.type));
const title = computed(() => props.tabs[props.type]);
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
            },
        },
    );
};
const labelError = (index: number) =>
    (addForm.errors as Record<string, string>)[`labels.${index}`];
</script>

<template>
    <Head title="Medicine details — Inventory and services settings" />
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
                aria-current="page"
                class="rounded-md bg-muted px-4 py-3 text-sm font-semibold"
                >Medicine details</span
            >
            <Link
                href="/inventory-services-settings/suppliers"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground hover:bg-muted"
                >Suppliers</Link
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
                <h2 class="text-lg font-medium">Medicine details</h2>
                <p class="text-sm text-muted-foreground">
                    Setup your drug group, medicine dosage and instructions.
                </p>
            </div>
            <p
                v-if="generalError"
                role="alert"
                class="mt-4 rounded border border-destructive p-3 text-sm text-destructive"
            >
                {{ generalError }}
            </p>
            <div
                class="mt-6 flex flex-wrap items-center justify-between gap-3 border-b"
            >
                <nav
                    aria-label="Medicine details tabs"
                    class="flex max-w-full overflow-x-auto"
                >
                    <Link
                        v-for="(label, type) in tabs"
                        :key="type"
                        :href="`/inventory-services-settings/medicine-details?type=${type}`"
                        :aria-current="props.type === type ? 'page' : undefined"
                        :class="[
                            'shrink-0 border-b-2 px-5 py-3 text-sm font-medium',
                            props.type === type
                                ? 'border-foreground'
                                : 'border-transparent text-muted-foreground',
                        ]"
                        >{{ label }}</Link
                    >
                </nav>
                <div class="mb-2 ml-auto flex max-w-full items-center gap-2">
                    <form
                        v-if="searching"
                        class="flex min-w-0 items-center gap-2"
                        @submit.prevent="go()"
                    >
                        <label for="option-search" class="sr-only"
                            >Search options</label
                        ><input
                            id="option-search"
                            v-model="search"
                            type="search"
                            maxlength="200"
                            placeholder="Search options"
                            class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm"
                        /><Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            aria-label="Search options"
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
                        variant="ghost"
                        size="sm"
                        aria-label="Search options"
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
                    <Button size="sm" @click="openAdd">Add new</Button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-4 font-medium">NAME</th>
                            <th class="w-28 px-4 py-4 font-medium">STATUS</th>
                            <th class="w-24 px-4 py-4 font-medium">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="option in options"
                            :key="option.id"
                            class="border-b"
                        >
                            <td class="px-4 py-4">{{ option.label }}</td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="option.active"
                                    :aria-label="`${option.active ? 'Archive' : 'Restore'} ${option.label}`"
                                    :disabled="busy !== null"
                                    :class="[
                                        'relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-50',
                                        option.active
                                            ? 'bg-emerald-500'
                                            : 'bg-muted-foreground/40',
                                    ]"
                                    @click="toggle(option)"
                                >
                                    <span
                                        :class="[
                                            'size-5 rounded-full bg-white transition-transform',
                                            option.active
                                                ? 'translate-x-6'
                                                : 'translate-x-1',
                                        ]"
                                    />
                                </button>
                            </td>
                            <td class="px-4 py-3">
                                <DropdownMenu
                                    ><DropdownMenuTrigger as-child
                                        ><Button
                                            variant="ghost"
                                            size="sm"
                                            :aria-label="`Actions for ${option.label}`"
                                            :disabled="busy !== null"
                                            >•••</Button
                                        ></DropdownMenuTrigger
                                    ><DropdownMenuContent align="end"
                                        ><DropdownMenuItem
                                            @select="openRename(option)"
                                            >Rename</DropdownMenuItem
                                        ><DropdownMenuItem
                                            v-if="option.active"
                                            @select="toggle(option)"
                                            >Archive</DropdownMenuItem
                                        ></DropdownMenuContent
                                    ></DropdownMenu
                                >
                            </td>
                        </tr>
                        <tr v-if="options.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No options found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <CompactPagination
                class="mt-4"
                :current-page="pagination.currentPage"
                :last-page="pagination.lastPage"
                :total="pagination.total"
                @change="go"
            />
        </section>
    </main>
    <Dialog v-model:open="adding"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader
                ><DialogTitle>Add {{ title?.toLowerCase() }}</DialogTitle
                ><DialogDescription>{{
                    batch
                        ? `You can add more than one ${title?.toLowerCase()} at one time`
                        : 'Add an option for future selections.'
                }}</DialogDescription></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="save">
                <div class="max-h-[50vh] overflow-y-auto">
                    <label
                        v-for="(_, index) in addForm.labels"
                        :key="index"
                        class="mb-3 grid gap-2 text-sm"
                        >{{ title }} name<input
                            v-model="addForm.labels[index]"
                            required
                            maxlength="200"
                            :placeholder="`Enter ${title?.toLowerCase()} name`"
                            class="h-11 rounded-md border bg-background px-3" /><InputError
                            :message="labelError(index)"
                    /></label>
                </div>
                <InputError :message="addForm.errors.labels" /><InputError
                    :message="generalError"
                /><Button
                    v-if="batch"
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="
                        addForm.labels.length >= 20 || addForm.processing
                    "
                    @click="addForm.labels.push('')"
                    >Add more {{ title?.toLowerCase() }}</Button
                ><DialogFooter
                    ><Button
                        type="button"
                        variant="ghost"
                        :disabled="addForm.processing"
                        @click="adding = false"
                        >Cancel</Button
                    ><Button type="submit" :disabled="addForm.processing"
                        >Confirm</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent></Dialog
    >
    <Dialog v-model:open="renaming"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader
                ><DialogTitle>Rename {{ title?.toLowerCase() }}</DialogTitle
                ><DialogDescription
                    >Changes future selections only. Saved medicines and
                    prescriptions keep their text.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="rename">
                <label class="grid gap-2 text-sm"
                    >{{ title }} name<input
                        v-model="renameForm.label"
                        required
                        maxlength="200"
                        class="h-11 rounded-md border bg-background px-3" /></label
                ><InputError :message="renameForm.errors.label" /><InputError
                    :message="generalError"
                /><DialogFooter
                    ><Button
                        type="button"
                        variant="ghost"
                        :disabled="renameForm.processing"
                        @click="renaming = false"
                        >Cancel</Button
                    ><Button type="submit" :disabled="renameForm.processing"
                        >Save</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
</template>
