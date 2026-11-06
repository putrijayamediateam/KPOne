<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
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
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';

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
    props.q ? 'Try another search.' : 'Add one with Add new.',
);
// DD-30 pattern (Daniel, CAT-5): on a phone the tab strip scrolls, so fade its right edge while more tabs are hidden
// and bring the current or focused tab into view.
const tabStrip = ref<HTMLElement | null>(null);
const moreTabsRight = ref(false);
const updateTabFade = () => {
    const nav = tabStrip.value;

    moreTabsRight.value =
        nav !== null &&
        nav.scrollWidth > nav.clientWidth + 1 &&
        nav.scrollLeft + nav.clientWidth < nav.scrollWidth - 1;
};
const revealTab = (tab: HTMLElement | null | undefined) => {
    const nav = tabStrip.value;

    if (!nav || !tab) {
        return;
    }

    const navBox = nav.getBoundingClientRect();
    const tabBox = tab.getBoundingClientRect();

    if (tabBox.left < navBox.left) {
        nav.scrollLeft -= navBox.left - tabBox.left;
    } else if (tabBox.right > navBox.right) {
        nav.scrollLeft += tabBox.right - navBox.right;
    }

    updateTabFade();
};
const revealFocusedTab = (event: FocusEvent) =>
    revealTab(event.target as HTMLElement | null);
let tabResizeObserver: ResizeObserver | undefined;
onMounted(() => {
    void nextTick(() => {
        revealTab(
            tabStrip.value?.querySelector<HTMLElement>('[aria-current="page"]'),
        );
        updateTabFade();
    });

    if (typeof ResizeObserver !== 'undefined' && tabStrip.value) {
        tabResizeObserver = new ResizeObserver(updateTabFade);
        tabResizeObserver.observe(tabStrip.value);
    }
});
onBeforeUnmount(() => tabResizeObserver?.disconnect());
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
                    Set up your drug groups, dosage units and instructions.
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
                    ref="tabStrip"
                    aria-label="Medicine details tabs"
                    class="flex max-w-full overflow-x-auto"
                    :class="
                        moreTabsRight
                            ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                            : ''
                    "
                    data-testid="medicine-details-tabs"
                    @scroll.passive="updateTabFade"
                    @focusin="revealFocusedTab"
                >
                    <Link
                        v-for="(label, type) in tabs"
                        :key="type"
                        :href="`/inventory-services-settings/medicine-details?type=${type}`"
                        :aria-current="props.type === type ? 'page' : undefined"
                        :class="[
                            'min-h-11 shrink-0 border-b-2 px-5 py-3 text-sm font-medium',
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
                        <label
                            for="option-search"
                            class="text-xs font-medium md:sr-only"
                            >Search {{ plural }}</label
                        ><input
                            id="option-search"
                            v-model="search"
                            type="search"
                            maxlength="200"
                            :placeholder="`Search ${plural}`"
                            class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm"
                        /><Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            :aria-label="`Search ${plural}`"
                            class="min-h-11 min-w-11 md:min-h-8 md:min-w-8"
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
                        :aria-label="`Search ${plural}`"
                        class="min-h-11 min-w-11 md:min-h-8 md:min-w-8"
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
            <section
                class="mt-4 grid grid-cols-1 gap-3 md:hidden"
                aria-label="Medicine details options"
                data-testid="settings-cards"
            >
                <EmptyState
                    v-if="options.length === 0"
                    :title="emptyTitle"
                    :description="emptyDescription"
                />
                <article
                    v-for="option in options"
                    :key="option.id"
                    class="grid min-w-0 grid-cols-1 gap-3 rounded-xl border bg-card p-4"
                    data-testid="settings-card"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p
                            class="min-w-0 text-sm font-medium [overflow-wrap:anywhere]"
                        >
                            {{ option.label }}
                        </p>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="min-h-11 min-w-11 shrink-0"
                                    :aria-label="`More actions for ${option.label}`"
                                    :disabled="busy !== null"
                                    data-action="row-menu"
                                >
                                    <Ellipsis
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @select="openRename(option)"
                                    >Rename</DropdownMenuItem
                                >
                                <DropdownMenuItem
                                    v-if="option.active"
                                    @select="toggle(option)"
                                    >Archive</DropdownMenuItem
                                >
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="option.active"
                            :aria-label="`${option.active ? 'Archive' : 'Restore'} ${option.label}`"
                            :disabled="busy !== null"
                            :class="[
                                'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full ring-1 ring-border transition-colors disabled:opacity-50',
                                option.active
                                    ? 'bg-success'
                                    : 'bg-muted-foreground/40',
                            ]"
                            @click="toggle(option)"
                        >
                            <span
                                :class="[
                                    'size-5 rounded-full bg-white shadow-sm transition-transform',
                                    option.active
                                        ? 'translate-x-6'
                                        : 'translate-x-1',
                                ]"
                            />
                        </button>
                        <span class="text-sm" data-testid="status-word">{{
                            option.active ? 'Active' : 'Archived'
                        }}</span>
                    </div>
                </article>
            </section>
            <div class="mt-4 hidden min-w-0 md:block">
                <OperationalTable
                    label="Medicine details options"
                    class="[&_table]:table-fixed"
                    :columns="3"
                    :empty="options.length === 0"
                    :empty-message="`${emptyTitle}. ${emptyDescription}`"
                    min-width="0"
                >
                    <template #head>
                        <tr>
                            <th>Name</th>
                            <th class="w-44">Status</th>
                            <th class="w-24 text-right">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="option in options" :key="option.id">
                            <td class="[overflow-wrap:anywhere]">
                                {{ option.label }}
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="option.active"
                                        :aria-label="`${option.active ? 'Archive' : 'Restore'} ${option.label}`"
                                        :disabled="busy !== null"
                                        :class="[
                                            'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full ring-1 ring-border transition-colors disabled:opacity-50',
                                            option.active
                                                ? 'bg-success'
                                                : 'bg-muted-foreground/40',
                                        ]"
                                        @click="toggle(option)"
                                    >
                                        <span
                                            :class="[
                                                'size-5 rounded-full bg-white shadow-sm transition-transform',
                                                option.active
                                                    ? 'translate-x-6'
                                                    : 'translate-x-1',
                                            ]"
                                        />
                                    </button>
                                    <span
                                        class="text-sm"
                                        data-testid="status-word"
                                        >{{
                                            option.active
                                                ? 'Active'
                                                : 'Archived'
                                        }}</span
                                    >
                                </div>
                            </td>
                            <td class="text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-9"
                                            :aria-label="`More actions for ${option.label}`"
                                            :disabled="busy !== null"
                                            data-action="row-menu"
                                        >
                                            <Ellipsis
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @select="openRename(option)"
                                            >Rename</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            v-if="option.active"
                                            @select="toggle(option)"
                                            >Archive</DropdownMenuItem
                                        >
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
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
