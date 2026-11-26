<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';
import type { PatientRegistrationSummary } from '@/types';

type FamilyLink = {
    id: number;
    lockVersion: number;
    active: boolean;
    relationshipCode: string;
    relationshipLabel: string;
    suppressed: boolean;
    patientNumber: string | null;
    name: string | null;
    url: string | null;
    canUpdate: boolean;
};
type Directory = { links: FamilyLink[]; canAdd: boolean };
const props = defineProps<{ patientNumber: string; patientName: string }>();
const page = usePage();
const branchId = computed(() => page.props.branchContext?.active?.id ?? null);
const endpoint = computed(
    () => `/patients/${encodeURIComponent(props.patientNumber)}/family-links`,
);
const directory = ref<Directory | null>(null);
const loading = ref(false);
const error = ref('');
const draftError = ref('');
const editor = ref(false);
const editing = ref<FamilyLink | null>(null);
const selected = ref<{ patientNumber: string; fullName: string } | null>(null);
const query = ref('');
const results = ref<PatientRegistrationSummary[]>([]);
const searching = ref(false);
const searched = ref(false);
const relationship = ref<string>('');
const processing = ref(false);
const removeTarget = ref<FamilyLink | null>(null);
const options = [
    { value: 'parent_of', label: 'Parent of' },
    { value: 'child_of', label: 'Child of' },
    { value: 'spouse', label: 'Spouse of' },
    { value: 'sibling', label: 'Sibling of' },
    { value: 'other_relative', label: 'Other relative of' },
];
const relationshipLabel = computed(
    () =>
        options
            .find((item) => item.value === relationship.value)
            ?.label.toLowerCase() ?? 'relative of',
);
let generation = 0;
let readController: AbortController | undefined;
let searchController: AbortController | undefined;
let writeController: AbortController | undefined;
let commandKey = '';
let sentPayload = '';
let editorTrigger: HTMLElement | null = null;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrf(),
});
const focusError = async () => {
    await nextTick();
    document.getElementById('family-draft-error')?.focus();
};
const closeEditor = () => {
    editor.value = false;
    editing.value = null;
    selected.value = null;
    query.value = '';
    results.value = [];
    relationship.value = '';
    draftError.value = '';
    searched.value = false;
    commandKey = '';
    sentPayload = '';
    searchController?.abort();
    searching.value = false;
};
const load = async () => {
    readController?.abort();
    const controller = new AbortController();
    readController = controller;
    const current = generation;
    loading.value = true;
    error.value = '';

    try {
        const response = await fetch(endpoint.value, {
            headers: headers(),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
        });

        if (current !== generation || controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            if (response.status === 403 || response.status === 404) {
                closeEditor();
                removeTarget.value = null;
            }

            throw new Error('Unable to load family links. Please retry.');
        }

        const data: Directory = await response.json();

        if (current === generation && !controller.signal.aborted) {
            directory.value = data;
        }
    } catch (failure) {
        if (current === generation && !controller.signal.aborted) {
            directory.value = null;
            error.value =
                failure instanceof Error
                    ? failure.message
                    : 'Unable to load family links.';
        }
    } finally {
        if (current === generation && !controller.signal.aborted) {
            loading.value = false;
        }
    }
};
const cancelEditor = async () => {
    closeEditor();
    await nextTick();
    editorTrigger?.focus();
};
const start = async (link: FamilyLink | null = null) => {
    editorTrigger = document.activeElement as HTMLElement | null;
    closeEditor();
    editing.value = link;
    editor.value = true;

    if (link?.patientNumber && link.name) {
        selected.value = {
            patientNumber: link.patientNumber,
            fullName: link.name,
        };
        relationship.value = link.relationshipCode;
    }

    await nextTick();
    document
        .getElementById(link ? 'family-relationship' : 'family-search')
        ?.focus();
};
// Reuse Registration's explicit search classification, never recent patients or a prefilled phone.
const patientSearchType = (value: string) => {
    const compact = value.replace(/[\s-]/g, '');

    if (value.toUpperCase().startsWith('KP-')) {
        return 'patient_number';
    }

    if (/^\d{12}$/.test(compact)) {
        return 'nric';
    }

    if (/^\+?[()\d][()\d\s-]{6,}$/.test(value)) {
        return 'phone';
    }

    return 'name';
};
const search = async () => {
    searchController?.abort();
    results.value = [];
    searched.value = false;
    draftError.value = '';
    const term = query.value.trim();

    if (term.length < 3) {
        draftError.value =
            'Enter at least 3 characters to search for an existing patient.';
        await focusError();

        return;
    }

    const controller = new AbortController();
    searchController = controller;
    const current = generation;
    searching.value = true;

    try {
        const response = await fetch('/patients/search', {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            signal: controller.signal,
            body: JSON.stringify({
                query: term,
                search_type: patientSearchType(term),
            }),
        });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(
                'Patient search could not be completed. Please retry.',
            );
        }

        if (current === generation && !controller.signal.aborted) {
            results.value = (
                payload.data as PatientRegistrationSummary[]
            ).filter(
                (patient) => patient.patientNumber !== props.patientNumber,
            );
            searched.value = true;
        }
    } catch (failure) {
        if (current === generation && !controller.signal.aborted) {
            draftError.value =
                failure instanceof Error
                    ? failure.message
                    : 'Patient search could not be completed.';
            await focusError();
        }
    } finally {
        if (current === generation && !controller.signal.aborted) {
            searching.value = false;
        }
    }
};
const select = async (patient: PatientRegistrationSummary) => {
    searchController?.abort();
    searching.value = false;
    selected.value = {
        patientNumber: patient.patientNumber,
        fullName: patient.fullName,
    };
    results.value = [];
    draftError.value = '';
    await nextTick();
    document.getElementById('family-relationship')?.focus();
};
const submit = async (removal = false) => {
    if (processing.value) {
        return;
    }

    const target = removal ? removeTarget.value : editing.value;

    if (!removal && (!selected.value || !relationship.value)) {
        draftError.value = 'Select an existing patient and a relationship.';
        await focusError();

        return;
    }

    if (removal && !target) {
        return;
    }

    const body = {
        operation: removal ? 'unlink' : target?.active ? 'correct' : 'link',
        ...(target
            ? { link_id: target.id }
            : { other_patient_number: selected.value?.patientNumber }),
        relationship_code: removal
            ? target?.relationshipCode
            : relationship.value,
        lock_version: target?.lockVersion ?? 0,
        expected_branch_id: branchId.value,
    };
    const fingerprint = JSON.stringify(body);

    if (sentPayload && sentPayload !== fingerprint) {
        draftError.value =
            'An earlier attempt may have been saved. Retry the unchanged request, or cancel and reload family links before making another change.';
        await focusError();

        return;
    }

    commandKey ||= crypto.randomUUID();
    sentPayload = fingerprint;
    const current = generation;
    const controller = new AbortController();
    writeController = controller;
    processing.value = true;
    draftError.value = '';

    try {
        const response = await fetch(`${endpoint.value}/commands`, {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            signal: controller.signal,
            body: JSON.stringify({ ...body, idempotency_key: commandKey }),
        });
        const payload = await response.json();

        if (current !== generation || controller.signal.aborted) {
            return;
        }

        if (!response.ok) {
            if (response.status === 403 || response.status === 404) {
                directory.value = null;
                closeEditor();
                removeTarget.value = null;
                error.value =
                    'Family links are no longer available in this context. Reload to check your access.';

                return;
            }

            const messages = Object.values(payload.errors ?? {}).flat();

            throw new Error(
                String(
                    messages[0] ??
                        'Unable to save. Your draft is kept; retry or reload to review the current link.',
                ),
            );
        }

        directory.value = payload;
        closeEditor();
        removeTarget.value = null;
        await nextTick();
        document.getElementById('family-links-title')?.focus();
    } catch (failure) {
        if (current === generation && !controller.signal.aborted) {
            draftError.value =
                failure instanceof Error &&
                !(failure instanceof TypeError) &&
                !(failure instanceof SyntaxError)
                    ? failure.message
                    : 'We could not confirm whether this change was saved. Your draft is kept. Retry the unchanged request, or cancel and reload before changing it.';

            if (!removal) {
                await focusError();
            }
        }
    } finally {
        if (current === generation) {
            processing.value = false;
        }
    }
};
const askRemove = (link: FamilyLink) => {
    closeEditor();
    removeTarget.value = link;
};
watch(query, () => {
    searchController?.abort();
    results.value = [];
    searching.value = false;
    searched.value = false;
});
watch(
    () => [props.patientNumber, branchId.value],
    () => {
        generation++;
        readController?.abort();
        searchController?.abort();
        writeController?.abort();
        closeEditor();
        removeTarget.value = null;
        directory.value = null;
        processing.value = false;
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    generation++;
    readController?.abort();
    searchController?.abort();
    writeController?.abort();
});
</script>

<template>
    <section
        id="family-links"
        aria-labelledby="family-links-title"
        class="rounded-xl border bg-card p-4 text-sm shadow-sm sm:p-5"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="family-links-title" tabindex="-1" class="font-semibold">
                Family links
            </h2>
            <Button
                v-if="directory && !editor"
                variant="ghost"
                class="min-h-11 lg:min-h-9"
                :disabled="processing || loading"
                @click="load"
                >Reload links</Button
            >
            <Button
                v-if="directory?.canAdd && !editor"
                class="min-h-11 lg:min-h-9"
                variant="outline"
                :disabled="processing"
                @click="start()"
                >Add relative</Button
            >
        </div>
        <p class="mt-1 text-muted-foreground">
            Administrative relationships only. Guardian, emergency contact and
            billing arrangements are separate.
        </p>
        <p v-if="loading" role="status" class="mt-4 text-muted-foreground">
            Loading family links…
        </p>
        <div v-else-if="error" class="mt-4 space-y-2">
            <p role="alert" class="text-destructive">{{ error }}</p>
            <Button variant="outline" class="min-h-11 lg:min-h-9" @click="load"
                >Reload family links</Button
            >
        </div>
        <template v-else-if="directory">
            <p
                v-if="!directory.links.length"
                class="mt-4 text-muted-foreground"
            >
                No family links to show.
            </p>
            <ul v-else class="mt-3 divide-y">
                <li
                    v-for="link in directory.links"
                    :key="link.id"
                    class="flex flex-wrap items-center justify-between gap-3 py-3"
                >
                    <div class="min-w-0 flex-1 break-words">
                        <template v-if="link.suppressed">
                            <p>
                                A linked patient was merged. Review the current
                                patient record before linking again.
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Original record link. Removing it prevents it
                                from reappearing after an unmerge.
                            </p>
                        </template>
                        <template v-else>
                            <p>
                                {{ patientName }} is
                                {{ link.relationshipLabel.toLowerCase() }}
                                <strong class="font-medium">{{
                                    link.name
                                }}</strong
                                >.
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ link.patientNumber }}
                            </p>
                            <p
                                v-if="!link.active"
                                class="text-muted-foreground"
                            >
                                Link removed
                            </p>
                        </template>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="link.url"
                            as-child
                            variant="ghost"
                            class="min-h-11 lg:min-h-9"
                            ><Link
                                :href="link.url"
                                :aria-label="`Patient record for ${link.name}`"
                                >Patient record</Link
                            ></Button
                        >
                        <template v-if="link.canUpdate">
                            <Button
                                v-if="!link.suppressed"
                                variant="outline"
                                class="min-h-11 lg:min-h-9"
                                :disabled="processing"
                                @click="start(link)"
                                >{{
                                    link.active
                                        ? 'Edit relationship'
                                        : 'Link again'
                                }}</Button
                            >
                            <Button
                                v-if="link.active"
                                variant="ghost"
                                class="min-h-11 lg:min-h-9"
                                :disabled="processing"
                                @click="askRemove(link)"
                                >Remove</Button
                            >
                        </template>
                    </div>
                </li>
            </ul>
        </template>
        <div v-if="editor" class="mt-4 space-y-4 border-t pt-4">
            <h3 class="font-medium">
                {{
                    editing?.active
                        ? 'Edit relationship'
                        : 'Link an existing patient'
                }}
            </h3>
            <form v-if="!selected" class="space-y-2" @submit.prevent="search">
                <label for="family-search" class="font-medium"
                    >Find an existing patient</label
                >
                <p
                    id="family-search-help"
                    class="text-xs text-muted-foreground"
                >
                    Enter a name, patient number or identifier. Select the
                    correct record yourself; shared contact details do not
                    establish a relationship.
                </p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <Input
                        id="family-search"
                        v-model="query"
                        aria-describedby="family-search-help family-draft-error"
                        autocomplete="off"
                        class="min-h-11 lg:min-h-9"
                        :disabled="processing"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        :disabled="searching || processing"
                        >{{ searching ? 'Searching…' : 'Search' }}</Button
                    >
                </div>
                <ul v-if="results.length" class="divide-y rounded-lg border">
                    <li v-for="patient in results" :key="patient.patientNumber">
                        <button
                            type="button"
                            class="min-h-11 w-full rounded-md p-3 text-left hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="select(patient)"
                        >
                            <span class="block font-medium break-words">{{
                                patient.fullName
                            }}</span>
                            <span
                                class="block font-mono text-xs text-muted-foreground"
                                >{{ patient.patientNumber
                                }}<template v-if="patient.identifier">
                                    ·
                                    {{
                                        patient.identifier.maskedValue
                                    }}</template
                                ></span
                            >
                        </button>
                    </li>
                </ul>
                <p
                    v-else-if="searched"
                    role="status"
                    class="text-muted-foreground"
                >
                    No matching patient to select.
                </p>
            </form>
            <template v-else>
                <div class="rounded-lg border p-3">
                    <p class="font-medium break-words">
                        {{ selected.fullName }}
                    </p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ selected.patientNumber }}
                    </p>
                    <Button
                        v-if="!editing"
                        variant="ghost"
                        class="mt-1 min-h-11 lg:min-h-9"
                        :disabled="processing"
                        @click="selected = null"
                        >Change patient</Button
                    >
                </div>
                <label id="family-relationship-label" class="block font-medium"
                    >This patient's relationship to the selected patient</label
                >
                <OperationalSelect
                    id="family-relationship"
                    v-model="relationship"
                    :options="options"
                    label="Relationship"
                    labelledby="family-relationship-label"
                    describedby="family-direction family-draft-error"
                    trigger-class="min-h-11 lg:min-h-9"
                    :disabled="processing"
                />
                <p
                    id="family-direction"
                    class="break-words text-muted-foreground"
                >
                    {{ patientName }} ({{ patientNumber }}) is
                    {{ relationshipLabel }} {{ selected.fullName }} ({{
                        selected.patientNumber
                    }}).
                </p>
            </template>
            <p
                id="family-draft-error"
                tabindex="-1"
                role="alert"
                class="text-destructive"
            >
                {{ draftError }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    class="min-h-11 lg:min-h-9"
                    :disabled="processing || !selected || !relationship"
                    @click="submit()"
                    >{{ processing ? 'Saving…' : 'Save relationship' }}</Button
                >
                <Button
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    :disabled="processing"
                    @click="cancelEditor"
                    >Cancel</Button
                >
            </div>
        </div>
        <OperationalConfirmDialog
            :open="removeTarget !== null"
            title="Remove this family link?"
            description="Only the administrative relationship is removed. Patient, guardian and emergency contact records remain. An original merged-record link will not reappear after unmerge."
            confirm-label="Remove link"
            :processing="processing"
            :error="draftError"
            destructive
            @confirm="submit(true)"
            @update:open="
                (open) => {
                    if (!open && !processing) {
                        removeTarget = null;
                        closeEditor();
                    }
                }
            "
        />
    </section>
</template>
