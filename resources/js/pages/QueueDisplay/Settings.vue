<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';
import type { DisplaySettings } from '@/lib/queue-display';

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

const kindLabels: Record<RoomKind, string> = {
    consultation: 'Consultation room',
    dispensary: 'Dispensary',
    treatment: 'Treatment room',
};

const selectBranch = (event: Event) => {
    router.get(
        '/queue-display-settings',
        { branch: (event.target as HTMLSelectElement).value },
        { preserveScroll: true },
    );
};

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

const editingId = ref<number | null>(null);
const editForm = useForm({ name: '', sort_order: 0, lock_version: 1 });
const beginEdit = (room: RoomRow) => {
    editingId.value = room.id;
    editForm.name = room.name;
    editForm.sort_order = room.sortOrder;
    editForm.lock_version = room.lockVersion;
    editForm.clearErrors();
};
const saveEdit = () => {
    if (editingId.value === null) {
        return;
    }

    editForm.patch(`/queue-display-settings/rooms/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
};
const toggleRoom = (room: RoomRow) => {
    router.post(
        `/queue-display-settings/rooms/${room.id}/${room.isActive ? 'deactivate' : 'activate'}`,
        { lock_version: room.lockVersion },
        { preserveScroll: true },
    );
};

const settingsForm = useForm({
    ticker_text: props.settings.tickerText ?? '',
    youtube_url: props.youtubeUrl ?? '',
    poster_seconds: props.settings.posterSeconds,
    call_display_mode: props.settings.callDisplayMode ?? 'number',
    lock_version: props.settings.lockVersion,
});
watch(
    () => [props.settings, props.youtubeUrl] as const,
    ([settings, youtubeUrl]) => {
        if (!settingsForm.isDirty) {
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
        }
    },
);
const saveSettings = () => {
    settingsForm
        .transform((data) => ({
            ...data,
            ticker_text: data.ticker_text.trim() || null,
            youtube_url: data.youtube_url.trim() || null,
        }))
        .patch(`/queue-display-settings/branches/${props.branch.id}`, {
            preserveScroll: true,
            onSuccess: () => settingsForm.defaults(),
        });
};

const posterForm = useForm<{ poster: File | null; lock_version: number }>({
    poster: null,
    lock_version: props.settings.lockVersion,
});
const posterInput = ref<HTMLInputElement | null>(null);
const uploadPoster = () => {
    posterForm.lock_version = props.settings.lockVersion;
    posterForm.post(
        `/queue-display-settings/branches/${props.branch.id}/posters`,
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                posterForm.reset('poster');

                if (posterInput.value) {
                    posterInput.value.value = '';
                }
            },
        },
    );
};
const removePoster = (posterId: string) => {
    router.post(
        `/queue-display-settings/branches/${props.branch.id}/posters/${posterId}/remove`,
        { lock_version: props.settings.lockVersion },
        { preserveScroll: true },
    );
};
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Queue Display (TV)"
            description="Set up the waiting-room TV for each branch: rooms, posters, a YouTube video and scrolling text."
        />

        <section
            class="flex flex-wrap items-end justify-between gap-3 rounded-xl border bg-card p-4"
        >
            <label class="grid gap-1 text-xs">
                Branch
                <select
                    class="h-9 rounded-md border bg-background px-3 text-sm"
                    :value="branch.id"
                    @change="selectBranch"
                >
                    <option
                        v-for="item in branches"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </select>
            </label>
            <div class="text-xs text-muted-foreground">
                The TV signs in with a <strong>Queue Display (TV)</strong> staff
                account assigned to this branch and opens the screen
                automatically.
                <a
                    :href="screenUrl"
                    target="_blank"
                    rel="noopener"
                    class="ml-1 font-medium text-primary underline"
                    >Preview screen</a
                >
            </div>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Rooms</h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Doctors choose their consultation room for the day in the
                Consultation queue; once a branch has a consultation room, Call
                In is blocked until the doctor has chosen one. The TV shows that
                room when their patient is called and reads the number and room
                aloud: in Malay when the TV has a Malay voice, otherwise in
                English, so a name such as Consultation Room 2 also reads well.
                Rooms are deactivated, never deleted.
            </p>
            <form
                class="mt-4 grid gap-3 md:grid-cols-[1fr_2fr_120px_auto] md:items-end"
                @submit.prevent="addRoom"
            >
                <label class="grid gap-1 text-xs">
                    Type
                    <select
                        v-model="roomForm.kind"
                        class="h-9 rounded-md border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="(label, kind) in kindLabels"
                            :key="kind"
                            :value="kind"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <InputError :message="roomForm.errors.kind" />
                </label>
                <label class="grid gap-1 text-xs">
                    Name shown on the TV
                    <Input
                        v-model="roomForm.name"
                        maxlength="60"
                        autocomplete="off"
                        placeholder="e.g. Bilik Rawatan 1"
                    />
                    <InputError :message="roomForm.errors.name" />
                </label>
                <label class="grid gap-1 text-xs">
                    Order
                    <Input
                        v-model.number="roomForm.sort_order"
                        type="number"
                        min="0"
                        max="999"
                    />
                    <InputError :message="roomForm.errors.sort_order" />
                </label>
                <Button type="submit" :disabled="roomForm.processing">
                    Add room
                </Button>
            </form>

            <div
                v-if="rooms.length === 0"
                class="mt-4 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No rooms yet. Add the consultation rooms first, for example
                Bilik Rawatan 1 to 4.
            </div>
            <div v-else class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Name</th>
                            <th class="py-2 pr-3 font-medium">Type</th>
                            <th class="py-2 pr-3 font-medium">Order</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="room in rooms"
                            :key="room.id"
                            class="border-b last:border-0"
                        >
                            <template v-if="editingId === room.id">
                                <td class="py-2 pr-3">
                                    <Input
                                        v-model="editForm.name"
                                        maxlength="60"
                                        aria-label="Room name"
                                    />
                                    <InputError
                                        :message="editForm.errors.name"
                                    />
                                    <InputError
                                        :message="editForm.errors.lock_version"
                                    />
                                </td>
                                <td class="py-2 pr-3">
                                    {{ kindLabels[room.kind] }}
                                </td>
                                <td class="py-2 pr-3">
                                    <Input
                                        v-model.number="editForm.sort_order"
                                        type="number"
                                        min="0"
                                        max="999"
                                        class="w-24"
                                        aria-label="Order"
                                    />
                                </td>
                                <td class="py-2 pr-3" />
                                <td class="py-2">
                                    <div class="flex gap-2">
                                        <Button
                                            size="sm"
                                            :disabled="editForm.processing"
                                            @click="saveEdit"
                                            >Save</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="editingId = null"
                                            >Cancel</Button
                                        >
                                    </div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="py-3 pr-3 font-medium">
                                    {{ room.name }}
                                </td>
                                <td class="py-3 pr-3">
                                    {{ kindLabels[room.kind] }}
                                </td>
                                <td class="py-3 pr-3">{{ room.sortOrder }}</td>
                                <td class="py-3 pr-3">
                                    <StatusBadge
                                        :status="
                                            room.isActive
                                                ? 'Active'
                                                : 'Inactive'
                                        "
                                    />
                                </td>
                                <td class="py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            @click="beginEdit(room)"
                                            >Edit</Button
                                        >
                                        <Button
                                            size="sm"
                                            :variant="
                                                room.isActive
                                                    ? 'destructive'
                                                    : 'default'
                                            "
                                            @click="toggleRoom(room)"
                                        >
                                            {{
                                                room.isActive
                                                    ? 'Deactivate'
                                                    : 'Activate'
                                            }}
                                        </Button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Screen content</h2>
            <form class="mt-4 grid gap-3" @submit.prevent="saveSettings">
                <label class="grid max-w-md gap-1 text-xs">
                    Call patients by
                    <select
                        v-model="settingsForm.call_display_mode"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        data-testid="call-display-mode"
                    >
                        <option value="number">
                            Queue number (A-001, B-001)
                        </option>
                        <option value="name">
                            Patient full name (as registered)
                        </option>
                    </select>
                    <span class="text-muted-foreground">
                        Applies to the TV list and the spoken call. In name mode
                        the TV shows each patient's full name to everyone in the
                        waiting room.
                    </span>
                    <InputError
                        :message="settingsForm.errors.call_display_mode"
                    />
                </label>
                <label class="grid gap-1 text-xs">
                    Scrolling text (shown at the bottom of the TV)
                    <Input
                        v-model="settingsForm.ticker_text"
                        maxlength="500"
                        placeholder="e.g. Waktu operasi: 8 pagi – 10 malam setiap hari."
                    />
                    <InputError :message="settingsForm.errors.ticker_text" />
                </label>
                <label class="grid gap-1 text-xs">
                    YouTube video link (plays muted on a loop)
                    <Input
                        v-model="settingsForm.youtube_url"
                        maxlength="300"
                        inputmode="url"
                        placeholder="https://www.youtube.com/watch?v=…"
                    />
                    <InputError :message="settingsForm.errors.youtube_url" />
                </label>
                <label class="grid max-w-xs gap-1 text-xs">
                    Seconds per poster
                    <Input
                        v-model.number="settingsForm.poster_seconds"
                        type="number"
                        min="5"
                        max="120"
                    />
                    <InputError :message="settingsForm.errors.poster_seconds" />
                </label>
                <InputError :message="settingsForm.errors.lock_version" />
                <div>
                    <Button type="submit" :disabled="settingsForm.processing">
                        Save screen content
                    </Button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">
                Posters ({{ settings.posters.length }} of {{ maxPosters }})
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                JPG, PNG or WebP, up to 5 MB each. Posters rotate on the TV. Do
                not upload anything that identifies a patient.
            </p>
            <InputError :message="$page.props.errors?.poster" />
            <InputError :message="$page.props.errors?.lock_version" />
            <form
                v-if="settings.posters.length < maxPosters"
                class="mt-4 flex flex-wrap items-end gap-3"
                @submit.prevent="uploadPoster"
            >
                <label class="grid gap-1 text-xs">
                    Poster image
                    <input
                        ref="posterInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="text-sm"
                        @change="
                            posterForm.poster =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <InputError :message="posterForm.errors.poster" />
                    <InputError :message="posterForm.errors.lock_version" />
                </label>
                <Button
                    type="submit"
                    :disabled="posterForm.processing || !posterForm.poster"
                >
                    Upload poster
                </Button>
            </form>
            <div
                v-if="settings.posters.length > 0"
                class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4"
            >
                <figure
                    v-for="poster in settings.posters"
                    :key="poster.id"
                    class="overflow-hidden rounded-lg border bg-muted"
                >
                    <img
                        :src="poster.url"
                        alt="Poster"
                        class="aspect-[3/4] w-full object-contain"
                    />
                    <figcaption class="p-2">
                        <Button
                            size="sm"
                            variant="destructive"
                            class="w-full"
                            @click="removePoster(poster.id)"
                            >Remove</Button
                        >
                    </figcaption>
                </figure>
            </div>
        </section>
    </main>
</template>
