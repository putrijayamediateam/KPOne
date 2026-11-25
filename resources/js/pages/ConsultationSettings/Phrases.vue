<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';

type Phrase = {
    id: number;
    kind: string;
    kindLabel: string;
    labelEn: string;
    labelMs: string;
    status: 'draft' | 'approved';
    active: boolean;
    lockVersion: number;
};
const props = defineProps<{
    branchId: number;
    status: string;
    kind: string;
    q: string;
    kinds: { value: string; label: string }[];
    phrases: Phrase[];
    pagination: { currentPage: number; lastPage: number; total: number };
}>();
const page = usePage();
const search = ref(props.q);
const adding = ref(false);
const editing = ref<Phrase | null>(null);
const busy = ref<number | null>(null);
const statusKeys = new Map<string, string>();
const MAX = 60;
const generalError = computed(
    () =>
        page.props.errors?.phrase ||
        page.props.errors?.expected_branch_id ||
        page.props.errors?.idempotency_key ||
        page.props.errors?.status ||
        page.props.errors?.kind ||
        page.props.errors?.q,
);
const go = (number = 1, overrides: Record<string, string> = {}) =>
    router.get(
        '/consultation-settings/phrases',
        {
            status: props.status,
            kind: props.kind,
            q: search.value || undefined,
            ...overrides,
            page: number > 1 ? number : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
const mutationUrl = (path: string) =>
    `${path}?${new URLSearchParams({ status: props.status, kind: props.kind, q: props.q }).toString()}`;
const addForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    kind: 'frequency',
    label_en: '',
    label_ms: '',
});
const editForm = useForm({
    expected_branch_id: props.branchId,
    idempotency_key: crypto.randomUUID(),
    lock_version: 1,
    label_en: '',
    label_ms: '',
});
const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.expected_branch_id = props.branchId;
    addForm.idempotency_key = crypto.randomUUID();
    adding.value = true;
};
const save = () =>
    addForm.post(mutationUrl('/consultation-settings/phrases'), {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false;
        },
    });
const openEdit = (phrase: Phrase) => {
    editing.value = phrase;
    editForm.clearErrors();
    editForm.label_en = phrase.labelEn;
    editForm.label_ms = phrase.labelMs;
    editForm.lock_version = phrase.lockVersion;
    editForm.expected_branch_id = props.branchId;
    editForm.idempotency_key = crypto.randomUUID();
};
const saveEdit = () => {
    if (!editing.value) {
        return;
    }

    editForm.patch(
        mutationUrl(`/consultation-settings/phrases/${editing.value.id}/edit`),
        {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = null;
            },
        },
    );
};
const toggle = (phrase: Phrase) => {
    if (busy.value !== null) {
        return;
    }

    busy.value = phrase.id;
    const token = `${phrase.id}:${phrase.lockVersion}:${!phrase.active}`;
    const key = statusKeys.get(token) ?? crypto.randomUUID();
    statusKeys.set(token, key);
    router.patch(
        mutationUrl(`/consultation-settings/phrases/${phrase.id}/active`),
        {
            expected_branch_id: props.branchId,
            lock_version: phrase.lockVersion,
            active: !phrase.active,
            idempotency_key: key,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
};
const kindFilterOptions = computed(() => [
    { value: 'all', label: 'All kinds' },
    ...props.kinds,
]);
const statusOptions = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
];
const addIncomplete = computed(
    () => !addForm.label_en.trim() || !addForm.label_ms.trim(),
);
const editIncomplete = computed(
    () => !editForm.label_en.trim() || !editForm.label_ms.trim(),
);
const emptyTitle = computed(() =>
    props.q || props.kind !== 'all' || props.status !== 'all'
        ? 'No phrases match.'
        : 'No phrases yet.',
);
</script>

<template>
    <Head title="Prescribing phrases" />
    <main
        class="mx-auto grid w-full max-w-[1600px] gap-8 px-4 py-6 md:grid-cols-[220px_minmax(0,1fr)]"
    >
        <nav
            aria-label="Clinic settings"
            class="flex flex-wrap gap-2 md:flex-col"
        >
            <Link
                href="/consultation-settings"
                class="rounded-md px-4 py-3 text-sm font-medium text-muted-foreground hover:bg-muted"
                >Consultation settings</Link
            >
            <span
                aria-current="page"
                class="rounded-md bg-muted px-4 py-3 text-sm font-semibold"
                >Prescribing phrases</span
            >
        </nav>
        <section class="min-w-0">
            <p class="mb-3 text-sm text-muted-foreground">
                Clinic management <span class="mx-2">/</span> Consultation
                settings <span class="mx-2">/</span> Prescribing phrases
            </p>
            <h1 class="border-b pb-3 text-2xl font-semibold">
                Prescribing phrases
            </h1>
            <p class="mt-3 max-w-3xl text-sm text-muted-foreground">
                Standard wording for frequency, instruction, precaution and
                duration, in English and Bahasa Melayu. A doctor reviews each
                phrase before it is offered while typing. Saved orders keep
                their own text.
            </p>
            <form
                class="mt-5 flex flex-wrap items-end gap-3"
                @submit.prevent="go(1)"
            >
                <div class="grid gap-1">
                    <label for="phrase-filter-kind" class="text-sm font-medium"
                        >Kind</label
                    >
                    <OperationalSelect
                        id="phrase-filter-kind"
                        :model-value="kind"
                        label="Kind"
                        :options="kindFilterOptions"
                        trigger-class="max-lg:min-h-11 min-w-36"
                        @update:model-value="go(1, { kind: String($event) })"
                    />
                </div>
                <div class="grid gap-1">
                    <label
                        for="phrase-filter-status"
                        class="text-sm font-medium"
                        >Status</label
                    >
                    <OperationalSelect
                        id="phrase-filter-status"
                        :model-value="status"
                        label="Status"
                        :options="statusOptions"
                        trigger-class="max-lg:min-h-11 min-w-28"
                        @update:model-value="go(1, { status: String($event) })"
                    />
                </div>
                <label class="grid min-w-0 gap-1 text-sm font-medium"
                    >Search<input
                        v-model="search"
                        type="search"
                        maxlength="200"
                        placeholder="English or Bahasa Melayu"
                        class="h-11 min-w-0 rounded-md border bg-background px-3 font-normal md:h-9"
                /></label>
                <Button type="submit" variant="outline" class="max-sm:min-h-11"
                    >Search</Button
                >
                <Button class="ml-auto max-sm:min-h-11" @click.prevent="openAdd"
                    >Add phrase</Button
                >
            </form>
            <p
                v-if="generalError"
                role="alert"
                class="mt-3 text-sm text-destructive"
            >
                {{ generalError }}
            </p>
            <EmptyState
                v-if="phrases.length === 0"
                class="mt-4"
                :title="emptyTitle"
            />
            <ul v-else class="mt-4 space-y-2" aria-label="Prescribing phrases">
                <li
                    v-for="phrase in phrases"
                    :key="phrase.id"
                    class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border bg-card p-3"
                    data-testid="phrase-row"
                >
                    <div class="min-w-0">
                        <p class="text-[13px] text-muted-foreground">
                            {{ phrase.kindLabel }}
                        </p>
                        <p class="text-sm font-medium break-words">
                            {{ phrase.labelEn }}
                        </p>
                        <p class="text-sm break-words">{{ phrase.labelMs }}</p>
                        <p
                            v-if="phrase.status === 'draft'"
                            class="mt-1 inline-flex items-center gap-1.5 text-[13px] text-muted-foreground"
                        >
                            <span
                                class="size-1.5 rounded-full bg-muted-foreground"
                                aria-hidden="true"
                            />Waiting for doctor review
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            variant="outline"
                            size="sm"
                            class="max-sm:min-h-11"
                            :disabled="busy !== null"
                            :aria-label="`Edit ${phrase.labelEn}`"
                            @click="openEdit(phrase)"
                            >Edit</Button
                        >
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="phrase.active"
                            :aria-label="`${phrase.active ? 'Archive' : 'Restore'} ${phrase.labelEn}`"
                            :disabled="busy !== null"
                            :class="[
                                'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full ring-1 ring-border transition-colors disabled:opacity-50',
                                phrase.active
                                    ? 'bg-success'
                                    : 'bg-muted-foreground/40',
                            ]"
                            @click="toggle(phrase)"
                        >
                            <span
                                :class="[
                                    'size-5 rounded-full bg-white shadow-sm transition-transform',
                                    phrase.active
                                        ? 'translate-x-6'
                                        : 'translate-x-1',
                                ]"
                            />
                        </button>
                        <span class="w-16 text-sm" data-testid="status-word">{{
                            phrase.active ? 'Active' : 'Archived'
                        }}</span>
                    </div>
                </li>
            </ul>
            <div class="mt-4">
                <CompactPagination
                    :current-page="pagination.currentPage"
                    :last-page="pagination.lastPage"
                    :total="pagination.total"
                    @change="go"
                />
            </div>
        </section>
    </main>
    <Dialog v-model:open="adding"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader class="border-b pb-4"
                ><DialogTitle class="text-center">Add phrase</DialogTitle
                ><DialogDescription
                    >Keep each wording to {{ MAX }} characters so it fits the
                    drug label.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <label for="phrase-kind" class="text-sm font-medium"
                        >Kind<span class="text-destructive" aria-hidden="true">
                            *</span
                        ></label
                    >
                    <OperationalSelect
                        id="phrase-kind"
                        v-model="addForm.kind"
                        label="Kind"
                        :options="kinds"
                        trigger-class="h-11"
                    />
                    <InputError :message="addForm.errors.kind" />
                </div>
                <label class="grid gap-2 text-sm font-medium"
                    ><span
                        >English<span
                            class="text-destructive"
                            aria-hidden="true"
                        >
                            *</span
                        ></span
                    ><input
                        v-model="addForm.label_en"
                        required
                        :maxlength="MAX"
                        placeholder="For example After food"
                        class="h-11 rounded-md border bg-background px-3 font-normal" /><span
                        class="text-[13px] font-normal text-muted-foreground"
                        >{{ addForm.label_en.length }} / {{ MAX }}</span
                    ><InputError :message="addForm.errors.label_en"
                /></label>
                <label class="grid gap-2 text-sm font-medium"
                    ><span
                        >Bahasa Melayu<span
                            class="text-destructive"
                            aria-hidden="true"
                        >
                            *</span
                        ></span
                    ><input
                        v-model="addForm.label_ms"
                        required
                        :maxlength="MAX"
                        placeholder="Contoh Selepas makan"
                        class="h-11 rounded-md border bg-background px-3 font-normal" /><span
                        class="text-[13px] font-normal text-muted-foreground"
                        >{{ addForm.label_ms.length }} / {{ MAX }}</span
                    ><InputError :message="addForm.errors.label_ms"
                /></label>
                <InputError :message="generalError" />
                <p
                    v-if="addIncomplete"
                    class="text-[13px] text-muted-foreground"
                >
                    Enter both English and Bahasa Melayu to continue.
                </p>
                <DialogFooter class="sm:justify-between"
                    ><Button
                        type="button"
                        variant="ghost"
                        :disabled="addForm.processing"
                        @click="adding = false"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        :disabled="addForm.processing || addIncomplete"
                        >Confirm</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent></Dialog
    >
    <Dialog
        :open="editing !== null"
        @update:open="(value) => !value && (editing = null)"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader
                ><DialogTitle>Edit phrase</DialogTitle
                ><DialogDescription
                    >A changed wording goes back to a doctor for review before
                    it is offered while typing. Saved orders keep their
                    text.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="saveEdit">
                <label class="grid gap-2 text-sm font-medium"
                    ><span
                        >English<span
                            class="text-destructive"
                            aria-hidden="true"
                        >
                            *</span
                        ></span
                    ><input
                        v-model="editForm.label_en"
                        required
                        :maxlength="MAX"
                        class="h-11 rounded-md border bg-background px-3 font-normal" /><span
                        class="text-[13px] font-normal text-muted-foreground"
                        >{{ editForm.label_en.length }} / {{ MAX }}</span
                    ><InputError :message="editForm.errors.label_en"
                /></label>
                <label class="grid gap-2 text-sm font-medium"
                    ><span
                        >Bahasa Melayu<span
                            class="text-destructive"
                            aria-hidden="true"
                        >
                            *</span
                        ></span
                    ><input
                        v-model="editForm.label_ms"
                        required
                        :maxlength="MAX"
                        class="h-11 rounded-md border bg-background px-3 font-normal" /><span
                        class="text-[13px] font-normal text-muted-foreground"
                        >{{ editForm.label_ms.length }} / {{ MAX }}</span
                    ><InputError :message="editForm.errors.label_ms"
                /></label>
                <InputError :message="generalError" />
                <p
                    v-if="editIncomplete"
                    class="text-[13px] text-muted-foreground"
                >
                    Enter both English and Bahasa Melayu to continue.
                </p>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="ghost"
                        :disabled="editForm.processing"
                        @click="editing = null"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        :disabled="editForm.processing || editIncomplete"
                        >Save</Button
                    ></DialogFooter
                >
            </form></DialogContent
        ></Dialog
    >
</template>
