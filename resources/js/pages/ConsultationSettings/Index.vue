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
const page = usePage();
const search = ref(props.q);
const searching = ref(Boolean(props.q));
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
const adding = ref(false);
const renaming = ref(false);
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
            },
        },
    );
};
const nameError = (index: number) =>
    (addForm.errors as Record<string, string>)[`names.${index}`];
</script>

<template>
    <Head title="Consultation settings" />
    <main
        class="mx-auto grid w-full max-w-[1600px] gap-8 px-4 py-6 md:grid-cols-[220px_minmax(0,1fr)]"
    >
        <nav
            aria-label="Clinic settings"
            class="flex flex-wrap gap-2 md:flex-col"
        >
            <span
                aria-disabled="true"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground"
                >Registration settings</span
            >
            <span
                aria-current="page"
                class="rounded-md bg-muted px-4 py-3 text-sm font-semibold"
                >Consultation settings</span
            >
            <Link
                v-if="canCheckoutSettings"
                href="/checkout-settings"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground hover:bg-muted"
                >Checkout settings</Link
            >
            <span
                v-else
                aria-disabled="true"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground"
                >Checkout settings</span
            >
        </nav>
        <section class="min-w-0">
            <p class="mb-3 text-sm text-muted-foreground">
                Clinic management <span class="mx-2">/</span> Consultation
                settings
            </p>
            <h1 class="border-b pb-3 text-2xl font-semibold">
                Consultation settings
            </h1>
            <div class="mt-5">
                <h2 class="text-lg font-medium">Clinical modules</h2>
                <p class="text-sm text-muted-foreground">
                    Turn on the record types your practice uses. They stay off
                    for general practice.
                </p>
            </div>
            <div class="mt-4 overflow-hidden rounded-lg border">
                <div
                    v-for="module in modules"
                    :key="module.name"
                    class="flex items-center justify-between gap-4 border-b px-4 py-4 last:border-b-0"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ module.name }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ module.description }}
                        </p>
                        <p
                            class="mt-1 text-xs font-medium text-muted-foreground"
                        >
                            Not available yet
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-label="module.name"
                        :aria-checked="false"
                        disabled
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-muted-foreground/30"
                    >
                        <span
                            class="size-4 translate-x-1 rounded-full bg-white"
                        />
                    </button>
                </div>
            </div>
            <div class="mt-8">
                <h2 class="text-lg font-medium">Diagnosis list</h2>
                <p class="text-sm text-muted-foreground">
                    Add, rename and archive your preset diagnoses.
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
                <nav aria-label="Diagnosis status tabs" class="flex">
                    <button
                        v-for="tab in ['all', 'active', 'archived']"
                        :key="tab"
                        type="button"
                        :aria-current="status === tab ? 'page' : undefined"
                        :class="[
                            'border-b-2 px-5 py-3 text-sm font-medium capitalize',
                            status === tab
                                ? 'border-foreground'
                                : 'border-transparent text-muted-foreground',
                        ]"
                        @click="go(1, tab)"
                    >
                        {{ tab }}
                    </button>
                </nav>
                <div class="mb-2 ml-auto flex max-w-full items-center gap-2">
                    <form
                        v-if="searching"
                        class="flex min-w-0 items-center gap-2"
                        @submit.prevent="go()"
                    >
                        <label for="preset-search" class="sr-only"
                            >Search diagnoses</label
                        ><input
                            id="preset-search"
                            v-model="search"
                            type="search"
                            maxlength="200"
                            placeholder="Search diagnoses"
                            class="h-9 min-w-0 rounded-md border bg-background px-3 text-sm"
                        /><Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            aria-label="Search diagnoses"
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
                        aria-label="Search diagnoses"
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
                    <Button size="sm" @click="openAdd">Add diagnosis</Button>
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
                            v-for="preset in presets"
                            :key="preset.id"
                            class="border-b"
                        >
                            <td
                                class="px-4 py-4 [overflow-wrap:anywhere] break-words"
                            >
                                {{ preset.name }}
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="preset.active"
                                    :aria-label="`${preset.active ? 'Archive' : 'Restore'} ${preset.name}`"
                                    :disabled="busy !== null"
                                    :class="[
                                        'relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-50',
                                        preset.active
                                            ? 'bg-emerald-500'
                                            : 'bg-muted-foreground/40',
                                    ]"
                                    @click="toggle(preset)"
                                >
                                    <span
                                        :class="[
                                            'size-5 rounded-full bg-white transition-transform',
                                            preset.active
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
                                            :aria-label="`Actions for ${preset.name}`"
                                            :disabled="busy !== null"
                                            >•••</Button
                                        ></DropdownMenuTrigger
                                    ><DropdownMenuContent align="end"
                                        ><DropdownMenuItem
                                            @select="openRename(preset)"
                                            >Rename</DropdownMenuItem
                                        ><DropdownMenuItem
                                            v-if="preset.active"
                                            @select="toggle(preset)"
                                            >Archive</DropdownMenuItem
                                        ></DropdownMenuContent
                                    ></DropdownMenu
                                >
                            </td>
                        </tr>
                        <tr v-if="presets.length === 0">
                            <td
                                colspan="3"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No preset diagnoses found.
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
    </main>
    <Dialog v-model:open="adding"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader class="border-b pb-4"
                ><DialogTitle class="text-center">Add diagnosis</DialogTitle
                ><DialogDescription
                    >You can add more than one diagnosis at one
                    time</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="save">
                <div class="max-h-[50vh] overflow-y-auto">
                    <label
                        v-for="(_, index) in addForm.names"
                        :key="index"
                        class="mb-3 grid gap-2 text-sm"
                        >Diagnosis<input
                            v-model="addForm.names[index]"
                            required
                            maxlength="500"
                            placeholder="Enter diagnosis name"
                            class="h-11 rounded-md border bg-background px-3" /><InputError
                            :message="nameError(index)"
                    /></label>
                </div>
                <InputError :message="addForm.errors.names" /><InputError
                    :message="generalError"
                /><Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    :disabled="addForm.names.length >= 20 || addForm.processing"
                    @click="addForm.names.push('')"
                    >Add more diagnosis</Button
                ><DialogFooter class="sm:justify-between"
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
                ><DialogTitle>Rename diagnosis</DialogTitle
                ><DialogDescription
                    >Changes the preset name only. Saved encounter diagnoses
                    keep their text.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="rename">
                <label class="grid gap-2 text-sm"
                    >Diagnosis<input
                        v-model="renameForm.name"
                        required
                        maxlength="500"
                        class="h-11 rounded-md border bg-background px-3" /></label
                ><InputError :message="renameForm.errors.name" /><InputError
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
