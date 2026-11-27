<script setup lang="ts">
// FAM-1: link relatives by hand into one family from Registration. Nothing is suggested from a shared phone number; the
// relative is found with the normal patient search and chosen explicitly. The server re-checks every permission and the
// family version on save; this panel never stores family data in the browser.
import { LoaderCircle, Search, Users } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { OperationalSelect } from '@/components/ui/select';

type FamilyMember = {
    patientNumber: string;
    name: string;
    role: string;
    roleLabel: string;
    isCurrent: boolean;
    merged: boolean;
    url: string;
    canChange: boolean;
};
type FamilyDirectory = {
    familyVersion: number;
    members: FamilyMember[];
    canManage: boolean;
    roles: Array<{ value: string; label: string }>;
};
type SearchResult = { patientNumber: string; fullName: string };

const props = defineProps<{
    patientNumber: string;
    patientName: string;
    branchId: number;
}>();

const family = ref<FamilyDirectory | null>(null);
const loading = ref(false);
const loadError = ref('');
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const adding = ref(false);
const query = ref('');
const results = ref<SearchResult[]>([]);
const searching = ref(false);
const searchError = ref('');
const relative = ref<SearchResult | null>(null);
const relativeRole = ref('');
const patientRole = ref('');
const removeTarget = ref<FamilyMember | null>(null);
let loadController: AbortController | undefined;
let searchController: AbortController | undefined;
let saveController: AbortController | undefined;
// One retry key per command: a retry after a lost connection reuses it, so the server can recognise the same change.
let pendingKey: string | null = null;
let pendingFor = '';

const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const newKey = () => crypto.randomUUID();
const inFamily = computed(() => (family.value?.members.length ?? 0) > 0);
const others = computed(
    () => family.value?.members.filter((member) => !member.isCurrent) ?? [],
);
const roleOptions = computed(() => [
    { value: '', label: 'Choose a place' },
    ...(family.value?.roles ?? []),
]);

const load = async () => {
    loadController?.abort();
    const controller = new AbortController();
    loadController = controller;
    loading.value = true;
    loadError.value = '';

    try {
        const response = await fetch(
            `/patients/${encodeURIComponent(props.patientNumber)}/family`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );

        if (controller !== loadController) {
            return;
        }

        if (!response.ok) {
            family.value = null;
            loadError.value =
                response.status === 403
                    ? ''
                    : 'The family could not be loaded. Try again.';

            return;
        }

        family.value = await response.json();
    } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
            loadError.value = 'The family could not be loaded. Try again.';
        }
    } finally {
        if (controller === loadController) {
            loading.value = false;
        }
    }
};

const resetAdd = () => {
    adding.value = false;
    query.value = '';
    results.value = [];
    relative.value = null;
    relativeRole.value = '';
    patientRole.value = '';
    searchError.value = '';
    errors.value = {};
    pendingKey = null;
    pendingFor = '';
};

watch(
    () => props.patientNumber,
    () => {
        saveController?.abort();
        family.value = null;
        resetAdd();
        void load();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    loadController?.abort();
    searchController?.abort();
    saveController?.abort();
});

const search = async () => {
    const text = query.value.trim();

    if (text.length < 3) {
        searchError.value = 'Enter at least 3 characters.';
        results.value = [];

        return;
    }

    searchController?.abort();
    const controller = new AbortController();
    searchController = controller;
    searching.value = true;
    searchError.value = '';

    try {
        const response = await fetch('/patients/search', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            signal: controller.signal,
            body: JSON.stringify({
                query: text,
                search_type: text.toUpperCase().startsWith('KP-')
                    ? 'patient_number'
                    : 'name',
            }),
        });
        const payload = await response.json();

        if (controller !== searchController) {
            return;
        }

        if (!response.ok) {
            searchError.value =
                payload.message ?? 'Patient search could not be completed.';

            return;
        }

        results.value = (payload.data as SearchResult[]).filter(
            (row) => row.patientNumber !== props.patientNumber,
        );

        if (results.value.length === 0) {
            searchError.value = 'No matching patient found.';
        }
    } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
            searchError.value = 'Patient search could not be completed.';
        }
    } finally {
        if (controller === searchController) {
            searching.value = false;
        }
    }
};

const chooseRelative = (row: SearchResult) => {
    relative.value = row;
    errors.value = {};
};

const send = async (body: Record<string, unknown>): Promise<boolean> => {
    const patientNumber = props.patientNumber;
    const command = {
        ...body,
        expected_family_version: family.value?.familyVersion ?? 0,
        expected_branch_id: props.branchId,
    };
    const signature = JSON.stringify([patientNumber, command]);

    if (signature !== pendingFor || pendingKey === null) {
        pendingKey = newKey();
        pendingFor = signature;
    }

    saveController?.abort();
    const controller = new AbortController();
    saveController = controller;
    saving.value = true;
    errors.value = {};

    try {
        const response = await fetch(
            `/patients/${encodeURIComponent(patientNumber)}/family/commands`,
            {
                method: 'POST',
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    ...command,
                    idempotency_key: pendingKey,
                }),
            },
        );
        const payload = await response.json().catch(() => ({}));

        // A late answer for a patient no longer shown is dropped.
        if (
            controller !== saveController ||
            patientNumber !== props.patientNumber
        ) {
            return false;
        }

        // Answered: the key is spent either way.
        pendingKey = null;
        pendingFor = '';

        if (!response.ok) {
            const fieldErrors: Record<string, string> = {};

            for (const [field, messages] of Object.entries(
                (payload.errors ?? {}) as Record<string, string[]>,
            )) {
                fieldErrors[field] = messages[0];
            }

            if (Object.keys(fieldErrors).length === 0) {
                fieldErrors.family =
                    response.status === 403
                        ? 'You may not change this family.'
                        : (payload.message ?? 'The family could not be saved.');
            }

            errors.value = fieldErrors;

            // A stale family or a used key needs a fresh screen; the typed choice is kept.
            if (fieldErrors.family) {
                await load();
            }

            return false;
        }

        family.value = payload;

        return true;
    } catch (error) {
        if (
            (error instanceof DOMException && error.name === 'AbortError') ||
            patientNumber !== props.patientNumber
        ) {
            return false;
        }

        // The key is kept, so pressing Save again repeats the same change safely.
        errors.value = {
            family: 'The family could not be saved. Check the connection and try again.',
        };

        return false;
    } finally {
        if (controller === saveController) {
            saving.value = false;
        }
    }
};

const saveRelative = async () => {
    if (!relative.value) {
        errors.value = { relative_patient_number: 'Select the relative.' };

        return;
    }

    const saved = await send({
        operation: 'add',
        relative_patient_number: relative.value.patientNumber,
        relative_role: relativeRole.value || null,
        patient_role: inFamily.value ? null : patientRole.value || null,
    });

    if (saved) {
        resetAdd();
    }
};

const changeRole = async (member: FamilyMember, role: string) => {
    if (role === member.role) {
        return;
    }

    await send({
        operation: 'set_role',
        relative_patient_number: member.patientNumber,
        relative_role: role,
    });
};

const confirmRemove = async () => {
    if (!removeTarget.value) {
        return;
    }

    const saved = await send({
        operation: 'remove',
        relative_patient_number: removeTarget.value.patientNumber,
    });

    if (saved) {
        removeTarget.value = null;
    }
};
</script>

<template>
    <section
        v-if="family || loading || loadError"
        aria-labelledby="family-panel-heading"
        class="mx-4 mb-4 rounded-md border px-3 py-3"
        data-test="family-panel"
    >
        <div class="flex items-center justify-between gap-3">
            <h3
                id="family-panel-heading"
                class="inline-flex items-center gap-1.5 text-sm font-semibold"
            >
                <Users class="size-4 text-muted-foreground" /> Family
            </h3>
            <Button
                v-if="family?.canManage && !adding"
                type="button"
                size="sm"
                variant="outline"
                class="max-lg:h-11"
                @click="adding = true"
                >Link a relative</Button
            >
        </div>
        <p v-if="loading && !family" class="mt-2 text-xs text-muted-foreground">
            Loading family…
        </p>
        <p v-else-if="loadError" class="mt-2 text-xs text-destructive">
            {{ loadError }}
        </p>
        <template v-else-if="family">
            <p
                v-if="others.length === 0"
                class="mt-2 text-xs text-muted-foreground"
            >
                No relatives linked. Link relatives by hand; nothing is
                suggested from a shared phone number.
            </p>
            <ul v-else class="mt-2 divide-y divide-border/60 text-sm">
                <li
                    v-for="member in family.members"
                    :key="member.patientNumber"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span class="min-w-0">
                        <a
                            v-if="!member.isCurrent"
                            :href="member.url"
                            class="font-medium underline-offset-2 hover:underline"
                            >{{ member.name }}</a
                        >
                        <span v-else class="font-medium"
                            >{{ member.name }} (this patient)</span
                        >
                        <span
                            class="block font-mono text-xs text-muted-foreground"
                            >{{ member.patientNumber }} · {{ member.roleLabel
                            }}{{
                                member.merged ? ' · merged record' : ''
                            }}</span
                        >
                    </span>
                    <span
                        v-if="member.canChange"
                        class="flex items-center gap-2"
                    >
                        <OperationalSelect
                            :model-value="member.role"
                            :options="family.roles"
                            :label="`Place in family for ${member.name}`"
                            :disabled="saving"
                            trigger-class="h-8 w-40 max-lg:h-11"
                            @update:model-value="
                                (value) => changeRole(member, String(value))
                            "
                        />
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            class="max-lg:h-11"
                            :disabled="saving"
                            @click="removeTarget = member"
                            >Remove</Button
                        >
                    </span>
                </li>
            </ul>
            <InputError :message="errors.family" />

            <div
                v-if="adding"
                class="mt-3 space-y-3 rounded-md bg-muted/35 p-3"
                data-test="family-add"
            >
                <form class="relative" @submit.prevent="search">
                    <label class="relative block">
                        <span class="sr-only">Search the relative</span>
                        <Search
                            class="absolute top-2.5 left-3 size-4 text-muted-foreground"
                        />
                        <input
                            v-model="query"
                            autocomplete="off"
                            class="h-9 w-full rounded-[10px] border border-input bg-field-fill pr-10 pl-9 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none max-lg:h-11"
                            placeholder="Relative's name or Patient No."
                        />
                    </label>
                    <LoaderCircle
                        v-if="searching"
                        class="absolute top-2.5 right-3 size-4 animate-spin text-muted-foreground"
                    />
                </form>
                <InputError
                    :message="searchError || errors.relative_patient_number"
                />
                <ul
                    v-if="results.length && !relative"
                    class="divide-y divide-border/60 rounded-md border bg-card text-sm"
                >
                    <li v-for="row in results" :key="row.patientNumber">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-muted/35 max-lg:min-h-11"
                            @click="chooseRelative(row)"
                        >
                            <span class="truncate font-medium">{{
                                row.fullName
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ row.patientNumber }}</span
                            >
                        </button>
                    </li>
                </ul>
                <div v-if="relative" class="space-y-3">
                    <p class="text-sm">
                        Relative:
                        <span class="font-medium">{{ relative.fullName }}</span>
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ relative.patientNumber }}</span
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="relative = null"
                            >Change</Button
                        >
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <OperationalSelect
                                v-model="relativeRole"
                                :options="roleOptions"
                                :label="`${relative.fullName}'s place in the family`"
                                :invalid="!!errors.relative_role"
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Not needed if they are already in a family.
                            </p>
                            <InputError :message="errors.relative_role" />
                        </div>
                        <div v-if="!inFamily">
                            <OperationalSelect
                                v-model="patientRole"
                                :options="roleOptions"
                                :label="`${patientName}'s place in the family`"
                                :invalid="!!errors.patient_role"
                            />
                            <InputError :message="errors.patient_role" />
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="saving"
                        @click="resetAdd"
                        >Cancel</Button
                    >
                    <Button
                        type="button"
                        :disabled="saving || !relative"
                        @click="saveRelative"
                        >{{ saving ? 'Saving…' : 'Save link' }}</Button
                    >
                </div>
            </div>
        </template>

        <OperationalConfirmDialog
            :open="removeTarget !== null"
            title="Remove from this family?"
            :description="
                removeTarget
                    ? `${removeTarget.name} leaves this family. Their patient record, guardian contact and today's visits stay as they are. If only one member is left, the family closes.`
                    : ''
            "
            confirm-label="Remove"
            processing-label="Removing…"
            :processing="saving"
            :error="errors.family"
            destructive
            @update:open="
                (open) => {
                    if (!open) removeTarget = null;
                }
            "
            @confirm="confirmRemove"
        />
    </section>
</template>
