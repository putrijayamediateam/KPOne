<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2StickyBar from '@/components/v2/V2StickyBar.vue';
import V2Table from '@/components/v2/V2Table.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import type { DisplaySettings } from '@/lib/queue-display';

/**
 * Version 2 Queue Display (TV) settings (shown only in V2; Classic is `pages/QueueDisplay/Settings.vue`, untouched).
 * Same props, endpoints, payload keys and lock versions. Screen content (call mode, scrolling text, YouTube link,
 * seconds per poster) is one versioned update with one Save; rooms and posters are their own actions and take effect
 * as soon as they are pressed, and the page says so. The waiting-room TV screen itself is not part of Version 2.
 */
type RoomKind = 'consultation' | 'dispensary' | 'treatment';
type RoomRow = {
    id: number;
    kind: RoomKind;
    name: string;
    sortOrder: number;
    isActive: boolean;
    lockVersion: number;
};

const props = defineProps<{
    branches: Array<{ id: number; name: string }>;
    branch: { id: number; name: string };
    rooms: RoomRow[];
    settings: DisplaySettings;
    youtubeUrl: string | null;
    maxPosters: number;
    screenUrl: string;
}>();
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'Queue Display (TV)' },
]);

const kindLabels: Record<RoomKind, string> = {
    consultation: 'Consultation room',
    dispensary: 'Dispensary',
    treatment: 'Treatment room',
};
const kindOptions = (Object.keys(kindLabels) as RoomKind[]).map((value) => ({
    value,
    label: kindLabels[value],
}));

/* ---------- unsaved-changes guard (screen content only) ---------- */
const leaveOpen = ref(false);
let pendingUrl: string | null = null;
let allowLeave = false;
const stopBefore = router.on('before', (event) => {
    const visit = event.detail.visit;

    // Only a move to another page (or another branch) is guarded; our own saves are PATCH/POST visits.
    if (
        allowLeave ||
        !settingsForm.isDirty ||
        String(visit.method).toLowerCase() !== 'get' ||
        (visit.only?.length ?? 0) > 0 ||
        (visit.except?.length ?? 0) > 0 ||
        visit.url.pathname + visit.url.search ===
            window.location.pathname + window.location.search
    ) {
        return;
    }

    pendingUrl = visit.url.pathname + visit.url.search;
    leaveOpen.value = true;

    return false;
});
const leaveAnyway = () => {
    leaveOpen.value = false;
    // Discard the unsaved edits first, so a branch switch cannot carry them into the other branch's form.
    settingsForm.reset();
    allowLeave = true;

    if (pendingUrl) {
        router.visit(pendingUrl, {
            preserveScroll: true,
            onFinish: () => {
                allowLeave = false;
            },
        });
    } else {
        allowLeave = false;
    }
};
// "Save and leave" never skips the privacy step: it goes through the same confirm as Save, then saves and leaves.
let leaveTarget: string | null = null;
const leaveAfterSave = () => {
    const target = leaveTarget;
    leaveTarget = null;

    if (target) {
        router.visit(target, { preserveScroll: true });
    }
};
const saveAndLeave = () => {
    leaveOpen.value = false;
    leaveTarget = pendingUrl;

    if (needsPrivacyConfirm.value) {
        privacyOpen.value = true;

        return;
    }

    performSave(leaveAfterSave);
};
const warnUnload = (event: BeforeUnloadEvent) => {
    if (settingsForm.isDirty) {
        event.preventDefault();
        event.returnValue = '';
    }
};
onMounted(() => window.addEventListener('beforeunload', warnUnload));
onBeforeUnmount(() => {
    stopBefore();
    window.removeEventListener('beforeunload', warnUnload);
});

const selectBranch = (value: string | number) => {
    router.get(
        '/queue-display-settings',
        { branch: String(value) },
        { preserveScroll: true },
    );
};

/* ---------- rooms ---------- */
const roomForm = useForm({
    kind: 'consultation' as RoomKind,
    name: '',
    sort_order: 10,
});
const addRoom = () => {
    roomForm.post(`/queue-display-settings/branches/${props.branch.id}/rooms`, {
        preserveScroll: true,
        onSuccess: () => roomForm.reset('name'),
    });
};

const editing = ref<RoomRow | null>(null);
const editForm = useForm({ name: '', sort_order: 0, lock_version: 1 });
const beginEdit = (room: RoomRow) => {
    editing.value = room;
    editForm.name = room.name;
    editForm.sort_order = room.sortOrder;
    editForm.lock_version = room.lockVersion;
    editForm.clearErrors();
};
const saveEdit = () => {
    if (editing.value === null) {
        return;
    }

    editForm.patch(`/queue-display-settings/rooms/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
};
const deactivating = ref<RoomRow | null>(null);
const askToggle = (room: RoomRow) => {
    if (room.isActive) {
        deactivating.value = room;

        return;
    }

    toggleRoom(room);
};
const toggleRoom = (room: RoomRow) => {
    deactivating.value = null;
    router.post(
        `/queue-display-settings/rooms/${room.id}/${room.isActive ? 'deactivate' : 'activate'}`,
        { lock_version: room.lockVersion },
        { preserveScroll: true },
    );
};

/* ---------- screen content: one versioned update ---------- */
const settingsForm = useForm({
    ticker_text: props.settings.tickerText ?? '',
    youtube_url: props.youtubeUrl ?? '',
    poster_seconds: props.settings.posterSeconds,
    call_display_mode: props.settings.callDisplayMode ?? 'number',
    lock_version: props.settings.lockVersion,
});
const callModes = [
    { value: 'number', label: 'Queue number' },
    { value: 'short_name', label: 'Number and short name' },
    { value: 'name', label: 'Full name' },
];
const callModeHelp = computed(
    () =>
        ({
            number: 'The TV and the spoken call use the queue number only (A-001, B-001). No patient name is shown or spoken.',
            short_name:
                "The TV shows and speaks the queue number and the first two words of the patient's name (only the first word if both are long), without bin, binti, a/l or a/p. For example A-012 · Ahmad Ismail. Everyone in the waiting room sees and hears part of the name.",
            name: "The TV shows and speaks each patient's full registered name to everyone in the waiting room. This is the least private choice.",
        })[
            settingsForm.call_display_mode as 'number' | 'short_name' | 'name'
        ] ?? '',
);
let shownBranchId = props.branch.id;
watch(
    () => [props.settings, props.youtubeUrl, props.branch.id] as const,
    ([settings, youtubeUrl, branchId]) => {
        const branchChanged = branchId !== shownBranchId;
        shownBranchId = branchId;

        // Another branch always replaces the whole form; the same branch keeps what the person is typing.
        if (branchChanged || !settingsForm.isDirty) {
            settingsForm.defaults({
                ticker_text: settings.tickerText ?? '',
                youtube_url: youtubeUrl ?? '',
                poster_seconds: settings.posterSeconds,
                call_display_mode: settings.callDisplayMode ?? 'number',
                lock_version: settings.lockVersion,
            });
            settingsForm.reset();
        } else {
            settingsForm.lock_version = settings.lockVersion;
            settingsForm.defaults('lock_version', settings.lockVersion);
        }
    },
);
// Moving the saved setting to short or full names is a privacy change: Save asks first (a V2-only step).
const privacyOpen = ref(false);
const savedMode = computed(() => props.settings.callDisplayMode ?? 'number');
const needsPrivacyConfirm = computed(
    () =>
        settingsForm.call_display_mode !== savedMode.value &&
        settingsForm.call_display_mode !== 'number',
);
const privacySentence = computed(() =>
    settingsForm.call_display_mode === 'name'
        ? "Everyone in the waiting room will see and hear each patient's full registered name."
        : "Everyone in the waiting room will see and hear the queue number and the first two words of each patient's name.",
);
const saveSettings = () => {
    if (!settingsForm.isDirty || settingsForm.processing) {
        return;
    }

    if (needsPrivacyConfirm.value) {
        privacyOpen.value = true;

        return;
    }

    performSave();
};
const performSave = (after?: () => void) => {
    privacyOpen.value = false;
    settingsForm
        .transform((data) => ({
            ...data,
            ticker_text: data.ticker_text.trim() || null,
            youtube_url: data.youtube_url.trim() || null,
        }))
        .patch(`/queue-display-settings/branches/${props.branch.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                settingsForm.defaults();
                after?.();
            },
        });
};

/* ---------- posters ---------- */
const posterForm = useForm<{ poster: File | null; lock_version: number }>({
    poster: null,
    lock_version: props.settings.lockVersion,
});
const chooser = ref<InstanceType<typeof FileChooseButton> | null>(null);
const posterStatus = computed(() =>
    posterForm.poster
        ? `Chosen: ${posterForm.poster.name}. Press Upload poster to add it.`
        : 'No file chosen. JPG, PNG or WebP, up to 5 MB.',
);
const choosePoster = (event: Event) => {
    posterForm.poster = (event.target as HTMLInputElement).files?.[0] ?? null;
};
const uploadPoster = () => {
    posterForm.lock_version = props.settings.lockVersion;
    posterForm.post(
        `/queue-display-settings/branches/${props.branch.id}/posters`,
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                posterForm.reset('poster');
                chooser.value?.clear();
            },
        },
    );
};
const removing = ref<string | null>(null);
const removeBusy = ref(false);
const removePoster = () => {
    if (removing.value === null || removeBusy.value) {
        return;
    }

    removeBusy.value = true;
    router.post(
        `/queue-display-settings/branches/${props.branch.id}/posters/${removing.value}/remove`,
        { lock_version: props.settings.lockVersion },
        {
            preserveScroll: true,
            onSuccess: () => (removing.value = null),
            onFinish: () => (removeBusy.value = false),
        },
    );
};
</script>

<template>
    <Head title="Queue Display (TV)" />
    <PageFrame width="wide">
        <V2WorkLayout
            title="Queue Display (TV)"
            description="Set up the waiting-room TV for each branch: rooms, posters, a YouTube video and scrolling text."
        >
            <template #actions>
                <div v-if="branches.length > 1" class="w-full min-w-48 sm:w-56">
                    <OperationalSelect
                        :model-value="branch.id"
                        label="Branch"
                        :options="
                            branches.map((item) => ({
                                value: item.id,
                                label: item.name,
                            }))
                        "
                        @update:model-value="selectBranch"
                    />
                </div>
                <a
                    :href="screenUrl"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:h-9"
                >
                    Open TV screen
                    <ExternalLink class="size-4" aria-hidden="true" />
                    <span class="sr-only">(opens in a new tab)</span>
                </a>
            </template>
            <template #main>
                <p class="text-sm text-muted-foreground">
                    The TV signs in with a
                    <strong>Queue Display (TV)</strong> staff account assigned
                    to {{ branch.name }} and opens the screen automatically.
                    Rooms and posters take effect as soon as you use them.
                    Screen content needs Save.
                </p>

                <V2FormCard
                    :step="1"
                    title="Rooms"
                    description="Doctors choose their consultation room for the day in the Consultation queue. Once a branch has a consultation room, Call In is blocked until the doctor has chosen one. Rooms are deactivated, never deleted."
                >
                    <form
                        class="grid gap-4 lg:grid-cols-[auto_minmax(0,2fr)_7rem_auto] lg:items-end"
                        @submit.prevent="addRoom"
                    >
                        <V2OptionGroup
                            v-model="roomForm.kind"
                            label="Type"
                            :options="kindOptions"
                            :error="roomForm.errors.kind"
                        />
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Name shown on the TV"
                            :hint="`${roomForm.name.length} / 60`"
                            :error="roomForm.errors.name"
                        >
                            <Input
                                :id="id"
                                v-model="roomForm.name"
                                maxlength="60"
                                autocomplete="off"
                                placeholder="e.g. Consultation Room 1"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Order"
                            :error="roomForm.errors.sort_order"
                        >
                            <Input
                                :id="id"
                                v-model.number="roomForm.sort_order"
                                type="number"
                                min="0"
                                max="999"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="roomForm.processing"
                            class="h-11 lg:h-9"
                        >
                            Add room
                        </Button>
                    </form>

                    <V2Table
                        label="Rooms"
                        :columns="5"
                        cards="lg"
                        min-width="640px"
                        :empty="rooms.length === 0"
                        empty-message="No rooms yet. Add the consultation rooms first, for example Consultation Room 1 to 4."
                    >
                        <template #head>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Order</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </template>
                        <template #body>
                            <tr v-for="room in rooms" :key="room.id">
                                <td
                                    data-primary
                                    class="font-medium [overflow-wrap:anywhere]"
                                >
                                    {{ room.name }}
                                </td>
                                <td data-label="Type">
                                    {{ kindLabels[room.kind] }}
                                </td>
                                <td data-label="Order">{{ room.sortOrder }}</td>
                                <td data-label="Status">
                                    <V2Chip
                                        :tone="
                                            room.isActive
                                                ? 'success'
                                                : 'neutral'
                                        "
                                        :label="
                                            room.isActive
                                                ? 'Active'
                                                : 'Inactive'
                                        "
                                    />
                                </td>
                                <td data-actions class="text-right">
                                    <div
                                        class="flex flex-wrap items-center justify-end gap-2 max-lg:[&>*]:flex-1"
                                    >
                                        <Button
                                            variant="outline"
                                            class="h-11 lg:h-9"
                                            :aria-label="`Edit ${room.name}`"
                                            @click="beginEdit(room)"
                                            >Edit</Button
                                        >
                                        <Button
                                            variant="outline"
                                            class="h-11 lg:h-9"
                                            :aria-label="`${room.isActive ? 'Deactivate' : 'Activate'} ${room.name}`"
                                            @click="askToggle(room)"
                                        >
                                            {{
                                                room.isActive
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                            }}
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </V2Table>
                </V2FormCard>

                <V2FormCard
                    :step="2"
                    title="Screen content"
                    description="What the TV says and plays. One Save applies all of these together."
                >
                    <form
                        class="grid gap-4"
                        @submit.prevent="saveSettings"
                        @keydown.enter.self.prevent
                    >
                        <V2OptionGroup
                            v-model="settingsForm.call_display_mode"
                            label="Call patients by"
                            data-testid="call-display-mode"
                            aria-describedby="call-display-mode-help"
                            :options="callModes"
                            :error="settingsForm.errors.call_display_mode"
                        />
                        <p
                            id="call-display-mode-help"
                            data-testid="call-display-mode-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{ callModeHelp }}
                        </p>
                        <p
                            v-if="settingsForm.call_display_mode === 'name'"
                            role="note"
                            class="rounded-[10px] border border-warning/60 bg-warning/10 px-3 py-2 text-sm text-foreground"
                        >
                            <strong>Privacy:</strong> full names are shown to
                            the whole waiting room. Review this with the
                            clinic's privacy lead before go-live.
                        </p>

                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Scrolling text"
                            hint="Shown at the bottom of the TV."
                            :error="settingsForm.errors.ticker_text"
                        >
                            <Input
                                :id="id"
                                v-model="settingsForm.ticker_text"
                                maxlength="500"
                                placeholder="e.g. Open every day, 8 am to 10 pm."
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="YouTube video link"
                            hint="Plays muted on a loop."
                            :error="settingsForm.errors.youtube_url"
                        >
                            <Input
                                :id="id"
                                v-model="settingsForm.youtube_url"
                                maxlength="300"
                                inputmode="url"
                                placeholder="https://www.youtube.com/watch?v=…"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <V2Field
                            v-slot="{ id, describedBy, invalid }"
                            label="Seconds per poster"
                            hint="From 5 to 120."
                            :error="settingsForm.errors.poster_seconds"
                        >
                            <Input
                                :id="id"
                                v-model.number="settingsForm.poster_seconds"
                                type="number"
                                min="5"
                                max="120"
                                class="max-w-40"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </V2Field>
                        <InputError
                            :message="settingsForm.errors.lock_version"
                        />
                        <div
                            class="flex flex-wrap items-center gap-3 max-sm:hidden"
                        >
                            <Button
                                type="submit"
                                :variant="
                                    settingsForm.isDirty ? 'default' : 'outline'
                                "
                                :disabled="
                                    !settingsForm.isDirty ||
                                    settingsForm.processing
                                "
                                class="h-11 lg:h-9"
                            >
                                {{
                                    settingsForm.processing
                                        ? 'Saving…'
                                        : 'Save screen content'
                                }}
                            </Button>
                            <p
                                v-if="settingsForm.isDirty"
                                class="text-sm text-muted-foreground"
                            >
                                You have unsaved changes.
                            </p>
                        </div>
                    </form>
                </V2FormCard>

                <V2FormCard
                    :step="3"
                    :title="`Posters (${settings.posters.length} of ${maxPosters})`"
                    description="JPG, PNG or WebP, up to 5 MB each. Posters rotate on the TV in the order they were uploaded. Do not upload anything that identifies a patient."
                >
                    <InputError :message="$page.props.errors?.poster" />
                    <InputError :message="$page.props.errors?.lock_version" />
                    <form
                        v-if="settings.posters.length < maxPosters"
                        class="flex flex-wrap items-start gap-3"
                        @submit.prevent="uploadPoster"
                    >
                        <FileChooseButton
                            ref="chooser"
                            accept="image/jpeg,image/png,image/webp"
                            label="Choose poster"
                            :status="posterStatus"
                            status-id="poster-status"
                            @change="choosePoster"
                        >
                            <InputError :message="posterForm.errors.poster" />
                            <InputError
                                :message="posterForm.errors.lock_version"
                            />
                        </FileChooseButton>
                        <Button
                            type="submit"
                            variant="outline"
                            class="h-11 lg:h-9"
                            :disabled="
                                posterForm.processing || !posterForm.poster
                            "
                        >
                            Upload poster
                        </Button>
                    </form>
                    <p
                        v-if="settings.posters.length === 0"
                        class="rounded-[10px] border border-dashed p-4 text-sm text-muted-foreground"
                    >
                        No posters yet. The TV shows the video and scrolling
                        text only.
                    </p>
                    <ul v-else class="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <li
                            v-for="(poster, index) in settings.posters"
                            :key="poster.id"
                            class="overflow-hidden rounded-[14px] border bg-muted"
                        >
                            <img
                                :src="poster.url"
                                :alt="`Poster ${index + 1}`"
                                class="aspect-[3/4] w-full object-contain"
                            />
                            <div class="p-2">
                                <Button
                                    variant="outline"
                                    class="h-11 w-full lg:h-9"
                                    :aria-label="`Remove poster ${index + 1}`"
                                    @click="removing = poster.id"
                                    >Remove poster</Button
                                >
                            </div>
                        </li>
                    </ul>
                </V2FormCard>

                <V2StickyBar>
                    <Button
                        type="button"
                        :variant="settingsForm.isDirty ? 'default' : 'outline'"
                        :disabled="
                            !settingsForm.isDirty || settingsForm.processing
                        "
                        @click="saveSettings"
                    >
                        {{
                            settingsForm.processing
                                ? 'Saving…'
                                : settingsForm.isDirty
                                  ? 'Save screen content'
                                  : 'Screen content saved'
                        }}
                    </Button>
                </V2StickyBar>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        :open="editing !== null"
        title="Edit room"
        :description="
            editing
                ? `${kindLabels[editing.kind]}. The type cannot change.`
                : ''
        "
        confirm-label="Save room"
        :busy="editForm.processing"
        @update:open="(value) => !value && (editing = null)"
        @confirm="saveEdit"
    >
        <V2Field
            v-slot="{ id, describedBy, invalid }"
            label="Name shown on the TV"
            :hint="`${editForm.name.length} / 60`"
            :error="editForm.errors.name"
        >
            <Input
                :id="id"
                v-model="editForm.name"
                maxlength="60"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
            />
        </V2Field>
        <V2Field
            v-slot="{ id, describedBy, invalid }"
            label="Order"
            :error="editForm.errors.sort_order"
        >
            <Input
                :id="id"
                v-model.number="editForm.sort_order"
                type="number"
                min="0"
                max="999"
                class="max-w-40"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
            />
        </V2Field>
        <InputError :message="editForm.errors.lock_version" />
    </V2Dialog>

    <V2Dialog
        :open="removing !== null"
        title="Remove poster?"
        description="The poster stops showing on the TV. It cannot be undone; upload it again to bring it back."
        confirm-label="Remove poster"
        cancel-label="Keep poster"
        tone="danger"
        :busy="removeBusy"
        @update:open="(value) => !value && !removeBusy && (removing = null)"
        @confirm="removePoster"
    />

    <V2Dialog
        :open="leaveOpen"
        title="Leave without saving?"
        description="Your screen content changes have not been saved. If you leave now they are lost."
        confirm-label="Save and leave"
        cancel-label="Stay on this page"
        @update:open="(value) => (leaveOpen = value)"
        @confirm="saveAndLeave"
    >
        <Button
            type="button"
            variant="outline"
            class="h-11 w-full sm:w-fit lg:h-9"
            @click="leaveAnyway"
            >Leave without saving</Button
        >
    </V2Dialog>

    <V2Dialog
        :open="privacyOpen"
        title="Change who sees patient names?"
        :description="privacySentence"
        confirm-label="Save screen content"
        cancel-label="Cancel"
        :busy="settingsForm.processing"
        @update:open="
            (value) => {
                privacyOpen = value;

                if (!value) {
                    leaveTarget = null;
                }
            }
        "
        @confirm="performSave(leaveAfterSave)"
    />

    <V2Dialog
        :open="deactivating !== null"
        :title="deactivating ? `Deactivate ${deactivating.name}?` : ''"
        description="This room will no longer be offered for new calls. You can activate it again at any time."
        confirm-label="Deactivate"
        cancel-label="Keep room"
        @update:open="(value) => !value && (deactivating = null)"
        @confirm="deactivating && toggleRoom(deactivating)"
    />
</template>
