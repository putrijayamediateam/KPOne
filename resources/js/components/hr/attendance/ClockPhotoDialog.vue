<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertCircle, Check, RefreshCcw, X } from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import ClockLocationConsent from '@/components/hr/attendance/ClockLocationConsent.vue';
import ClockPhotoNotice, {
    CLOCK_PHOTO_NOTICE_VERSION,
} from '@/components/hr/attendance/ClockPhotoNotice.vue';
import {
    CAMERA_REFUSED,
    clockPhotoState as state,
    markNoticeSeen,
    NoticeNeeded,
    PASS_REFUSALS,
    passRefusalNote,
    readLocation,
    resetClockPhoto,
    sendPress,
    takeToken,
    WITHOUT_LOCATION,
} from '@/composables/useClockPhoto';

/**
 * HR-PHOTO camera flow (owner, 2026-11-12: option 2 "Camera app"; Daniel's board V2 and GUIDE section 18). Mounted
 * once per layout. Full screen on phones, a large dialog from 640 px; the camera and review steps are always dark.
 * Live camera only: there is no gallery or file picker, and nothing is kept in the browser after the press.
 */
const video = ref<HTMLVideoElement | null>(null);
const shutter = ref<HTMLButtonElement | null>(null);
const usePhotoButton = ref<HTMLButtonElement | null>(null);
const doneButton = ref<HTMLButtonElement | null>(null);
const facing = ref<'user' | 'environment'>('user');
const canSwitch = ref(false);
const preview = ref('');
// A line on the camera step after a photo was thrown away (the pass ran out).
const cameraNote = ref('');
// After any other refusal, "Use photo" turns off and Retake is the one filled action.
const refused = ref(false);
const shooting = ref(false);
let photo: Blob | null = null;
let stream: MediaStream | null = null;

const open = computed(() => state.step !== 'idle');
const title = computed(() => (state.kind === 'in' ? 'Clock in' : 'Clock out'));
const dark = computed(() =>
    ['starting', 'camera', 'review', 'sending', 'failed'].includes(state.step),
);
const notice = computed(() => state.step === 'notice');
// HR-16a on the Me press: the consent step and the one location read come before the camera, on a light sheet.
const consent = computed(
    () => state.step === 'consent' && state.location !== null,
);
const locating = computed(() => state.step === 'locating');
const light = computed(() => notice.value || consent.value || locating.value);

const stopStream = () => {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
};

const clearPreview = () => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = '';
    photo = null;
};

const startCamera = async () => {
    stopStream();
    state.error = '';

    if (!navigator.mediaDevices?.getUserMedia) {
        state.step = 'failed';
        state.error = CAMERA_REFUSED;

        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: facing.value },
            audio: false,
        });
        const devices = await navigator.mediaDevices
            .enumerateDevices()
            .catch(() => []);
        canSwitch.value =
            devices.filter((d) => d.kind === 'videoinput').length > 1;
        state.step = 'camera';
        await nextTick();

        if (video.value) {
            video.value.srcObject = stream;
            await video.value.play().catch(() => undefined);
        }

        shutter.value?.focus();
    } catch {
        state.step = 'failed';
        state.error = CAMERA_REFUSED;
    }
};

// The token is taken when the camera opens; a retake after it ran out takes a fresh one.
const begin = async () => {
    try {
        state.token = await takeToken(state.kind);
    } catch (e) {
        if (e instanceof NoticeNeeded) {
            // The one-time "About clock-in photos" notice comes before the first camera use.
            state.step = 'notice';

            return;
        }

        state.step = 'failed';
        state.error = 'KPOne could not start the camera. Try again.';

        return;
    }

    await startCamera();
};

// The location is read once, at the press; the result rides along with the photo. A flow closed meanwhile ignores it.
const checkLocation = async (agreed: boolean) => {
    const key = state.key;
    state.step = 'locating';
    const extra = await readLocation(agreed);

    if (state.key !== key || state.step !== 'locating') {
        return;
    }

    state.extra = { ...state.extra, ...extra };
    // Daniel: a read that fails or is refused says so in words on the camera screen; the press still goes ahead.
    cameraNote.value =
        extra.location_unavailable === 'denied'
            ? 'Location is not allowed for KPOne on this device, so this is recorded without it.'
            : extra.location_unavailable === 'failed'
              ? 'Your location could not be read in time, so this is recorded without it.'
              : '';
    state.step = 'starting';
    await begin();
};

const withoutLocation = async () => {
    state.extra = { ...state.extra, ...WITHOUT_LOCATION };
    facing.value = 'user';
    state.step = 'starting';
    await begin();
};

watch(
    () => state.step,
    (step, before) => {
        if (before !== 'idle') {
            return;
        }

        if (step === 'starting' || step === 'locating') {
            facing.value = 'user';
        }

        if (step === 'starting') {
            void begin();
        } else if (step === 'locating') {
            void checkLocation(false);
        }
    },
);

const noticeRead = async () => {
    try {
        await markNoticeSeen(CLOCK_PHOTO_NOTICE_VERSION);
    } catch {
        state.step = 'failed';
        state.error = 'This did not go through. Try again.';

        return;
    }

    state.step = 'starting';
    await begin();
};

const switchCamera = async () => {
    facing.value = facing.value === 'user' ? 'environment' : 'user';
    await startCamera();
};

const take = async () => {
    const el = video.value;

    if (!el || !el.videoWidth || shooting.value) {
        return;
    }

    shooting.value = true;

    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 1280 / Math.max(el.videoWidth, el.videoHeight));
    canvas.width = Math.round(el.videoWidth * scale);
    canvas.height = Math.round(el.videoHeight * scale);
    canvas.getContext('2d')?.drawImage(el, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', 0.88),
    );

    if (!blob) {
        shooting.value = false;

        return;
    }

    // The pass is taken at the shutter, so time on the camera screen never counts against the person.
    try {
        state.token = await takeToken(state.kind);
    } catch (e) {
        shooting.value = false;

        if (e instanceof NoticeNeeded) {
            stopStream();
            state.step = 'notice';

            return;
        }

        cameraNote.value = 'KPOne could not take the photo. Try again.';

        return;
    }

    shooting.value = false;
    cameraNote.value = '';
    refused.value = false;
    state.error = '';
    clearPreview();
    photo = blob;
    preview.value = URL.createObjectURL(blob);
    stopStream();
    state.step = 'review';
    await nextTick();
    usePhotoButton.value?.focus();
};

const retake = async () => {
    clearPreview();
    state.error = '';
    refused.value = false;
    cameraNote.value = '';
    state.step = 'starting';
    await begin();
};

// "Use photo": the server records the time now.
const usePhoto = async () => {
    if (!photo || state.step !== 'review' || refused.value) {
        return;
    }

    state.step = 'sending';
    state.error = '';

    try {
        state.result = await sendPress(photo);
        clearPreview();
        state.step = 'done';
        await nextTick();
        doneButton.value?.focus();
    } catch (e) {
        const message = e instanceof Error ? e.message : 'Try again.';

        if (PASS_REFUSALS.includes(message)) {
            // The photo is thrown away and the camera opens again, with one line saying why: no dead "Use photo".
            clearPreview();
            state.error = '';
            state.step = 'starting';
            await startCamera();
            cameraNote.value = passRefusalNote(message);

            return;
        }

        state.step = 'review';
        state.error = message;
        refused.value = true;
    }
};

const close = () => {
    if (state.step === 'sending') {
        return;
    }

    const wasDone = state.step === 'done';
    const after = state.onDone;
    stopStream();
    clearPreview();
    refused.value = false;
    cameraNote.value = '';
    resetClockPhoto();

    if (wasDone) {
        if (after) {
            after();
        } else {
            router.reload();
        }
    }
};

const chipTone = computed(() => {
    switch (state.result?.chip.code) {
        case 'on_time':
            return 'border-success/40 bg-background text-success';
        case 'late':
        case 'left_early':
            return 'border-warning/40 bg-background text-warning-strong';
        default:
            return 'border-border bg-background text-foreground';
    }
});

// Safety net (Rashid and Sofia, #670 review): a layout change unmounts the dialog, so the shared flow is reset too and
// can never leave the dialog reopening or a press refused.
onBeforeUnmount(() => {
    stopStream();
    clearPreview();
    refused.value = false;
    cameraNote.value = '';
    resetClockPhoto();
});
</script>

<template>
    <DialogRoot
        :open="open"
        @update:open="(value: boolean) => !value && close()"
    >
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/60 motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0"
            />
            <DialogContent
                data-test="clock-photo-dialog"
                class="fixed inset-0 z-50 flex flex-col overflow-hidden outline-none sm:inset-auto sm:top-1/2 sm:left-1/2 sm:h-[min(44rem,calc(100dvh-4rem))] sm:w-[min(40rem,calc(100vw-4rem))] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl sm:shadow-2xl"
                :class="
                    light
                        ? 'bg-background text-foreground'
                        : dark
                          ? 'bg-[#08080a] text-white'
                          : 'bg-[color-mix(in_oklab,var(--success)_12%,var(--background))] text-success dark:bg-[color-mix(in_oklab,var(--success)_22%,var(--background))]'
                "
                @escape-key-down="
                    (e: Event) => state.step === 'sending' && e.preventDefault()
                "
                @pointer-down-outside="(e: Event) => e.preventDefault()"
                @open-auto-focus="
                    (e: Event) => state.step === 'consent' && e.preventDefault()
                "
            >
                <!-- The one-time "About clock-in photos" notice -->
                <ClockPhotoNotice
                    v-if="notice"
                    @continue="noticeRead"
                    @cancel="close"
                />

                <!-- HR-16a: consent, once per version, then one location read -->
                <ClockLocationConsent
                    v-else-if="consent && state.location"
                    :kind="state.kind"
                    :location="state.location"
                    @agree="checkLocation(true)"
                    @without="withoutLocation"
                    @cancel="close"
                />
                <div
                    v-else-if="locating"
                    class="relative flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center"
                    data-test="clock-location-checking"
                >
                    <!-- The first screen when consent is already given: a phone has no Escape, so it needs its own way out
                         (Suresh, m2). Closing records nothing. -->
                    <button
                        type="button"
                        class="absolute top-[max(1.5rem,env(safe-area-inset-top))] right-6 flex size-11 items-center justify-center rounded-full border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Close"
                        data-test="clock-location-checking-close"
                        @click="close"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </button>
                    <DialogTitle class="text-xl font-semibold">{{
                        title
                    }}</DialogTitle>
                    <DialogDescription
                        class="flex flex-col gap-1 text-base"
                        role="status"
                    >
                        <span>Checking your location…</span>
                        <!-- Daniel: no animation, but a line so it never looks frozen. -->
                        <span class="text-sm text-muted-foreground"
                            >This can take up to 10 seconds.</span
                        >
                    </DialogDescription>
                </div>

                <!-- Camera and review -->
                <template v-else-if="dark">
                    <div
                        class="flex items-center justify-between gap-2 px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-2"
                    >
                        <button
                            type="button"
                            class="flex size-11 items-center justify-center rounded-full bg-white/15 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none disabled:opacity-50"
                            aria-label="Close"
                            data-test="clock-photo-close"
                            :disabled="state.step === 'sending'"
                            @click="close"
                        >
                            <X class="size-5" aria-hidden="true" />
                        </button>
                        <DialogTitle class="text-base font-semibold">
                            {{
                                state.step === 'review' ||
                                state.step === 'sending'
                                    ? `${title} · Check your photo`
                                    : title
                            }}
                        </DialogTitle>
                        <button
                            v-if="state.step === 'camera' && canSwitch"
                            type="button"
                            class="flex size-11 items-center justify-center rounded-full bg-white/15 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            aria-label="Switch camera"
                            data-test="clock-photo-switch"
                            @click="switchCamera"
                        >
                            <RefreshCcw class="size-5" aria-hidden="true" />
                        </button>
                        <span v-else class="size-11" aria-hidden="true" />
                    </div>
                    <DialogDescription class="sr-only">
                        Take a photo of your face to {{ title.toLowerCase() }}.
                        HR can see it. It is kept for 7 years.
                    </DialogDescription>

                    <div
                        class="relative min-h-0 flex-1"
                        :class="state.step === 'failed' ? 'hidden' : ''"
                    >
                        <video
                            v-show="state.step === 'camera'"
                            ref="video"
                            class="absolute inset-0 size-full object-cover"
                            :class="facing === 'user' ? '-scale-x-100' : ''"
                            playsinline
                            muted
                            aria-hidden="true"
                        />
                        <img
                            v-if="
                                (state.step === 'review' ||
                                    state.step === 'sending') &&
                                preview
                            "
                            :src="preview"
                            alt="Your photo"
                            class="absolute inset-0 size-full object-cover"
                            data-test="clock-photo-preview"
                        />
                        <div
                            v-if="state.step === 'camera'"
                            class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-6"
                        >
                            <div
                                class="h-[min(20rem,55%)] w-[min(15rem,60%)] rounded-[50%] border-[3px] border-white/70"
                                aria-hidden="true"
                            />
                            <p
                                class="rounded-full bg-black/65 px-4 py-1.5 text-[15px] font-medium text-white"
                            >
                                Centre your face in the oval
                            </p>
                        </div>
                        <p
                            v-if="state.step === 'starting'"
                            class="absolute inset-0 flex items-center justify-center text-sm text-white/80"
                            role="status"
                        >
                            Starting the camera…
                        </p>
                    </div>

                    <!-- Camera refused or unavailable: no clock-in without a photo -->
                    <div
                        v-if="state.step === 'failed'"
                        class="flex flex-1 flex-col items-center justify-center gap-6 px-6 text-center"
                        role="alert"
                        data-test="clock-photo-failed"
                    >
                        <p class="max-w-sm text-lg">{{ state.error }}</p>
                        <button
                            type="button"
                            class="min-h-14 rounded-full bg-white/15 px-8 text-[17px] font-bold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            @click="retake"
                        >
                            Try again
                        </button>
                    </div>

                    <div
                        class="px-4 pt-3 pb-[max(1.75rem,env(safe-area-inset-bottom))]"
                    >
                        <p
                            v-if="state.error && state.step === 'review'"
                            id="clock-photo-error"
                            class="mb-3 flex items-start justify-center gap-2 text-center text-sm text-white"
                            role="alert"
                            data-test="clock-photo-error"
                        >
                            <AlertCircle
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ state.error }}</span>
                        </p>
                        <p
                            v-if="cameraNote && state.step === 'camera'"
                            class="mb-3 flex items-start justify-center gap-2 text-center text-sm text-white"
                            role="alert"
                            data-test="clock-photo-camera-note"
                        >
                            <AlertCircle
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ cameraNote }}</span>
                        </p>
                        <div
                            v-if="state.step === 'camera'"
                            class="flex justify-center"
                        >
                            <button
                                ref="shutter"
                                type="button"
                                class="flex size-[84px] items-center justify-center rounded-full border-[5px] border-white focus-visible:ring-4 focus-visible:ring-white/60 focus-visible:outline-none sm:size-[72px] sm:border-4"
                                aria-label="Take photo"
                                data-test="clock-photo-shutter"
                                :disabled="shooting"
                                @click="take"
                            >
                                <span
                                    class="block size-[62px] rounded-full bg-white sm:size-[52px]"
                                />
                            </button>
                        </div>
                        <div
                            v-else-if="
                                state.step === 'review' ||
                                state.step === 'sending'
                            "
                            class="flex gap-3"
                        >
                            <button
                                type="button"
                                class="min-h-14 flex-1 rounded-full text-[17px] font-bold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none disabled:opacity-50"
                                :class="
                                    refused
                                        ? 'bg-[#6ba6ff] text-[#0b1a33]'
                                        : 'border-2 border-white/70 bg-transparent'
                                "
                                data-test="clock-photo-retake"
                                :disabled="state.step === 'sending'"
                                @click="retake"
                            >
                                Retake
                            </button>
                            <button
                                ref="usePhotoButton"
                                type="button"
                                class="min-h-14 flex-[1.4] rounded-full text-[17px] font-bold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none disabled:opacity-70"
                                :class="
                                    refused
                                        ? 'border-2 border-white/30 bg-transparent text-white/60'
                                        : 'bg-[#6ba6ff] text-[#0b1a33]'
                                "
                                data-test="clock-photo-use"
                                :disabled="state.step === 'sending'"
                                :aria-disabled="refused ? 'true' : undefined"
                                :aria-describedby="
                                    refused ? 'clock-photo-error' : undefined
                                "
                                @click="usePhoto"
                            >
                                {{
                                    state.step === 'sending'
                                        ? state.kind === 'in'
                                            ? 'Clocking in…'
                                            : 'Clocking out…'
                                        : 'Use photo'
                                }}
                            </button>
                        </div>
                    </div>
                </template>

                <!-- Confirmation -->
                <template v-else-if="state.step === 'done' && state.result">
                    <div
                        class="flex flex-1 flex-col items-center justify-center gap-3.5 px-6 text-center"
                        role="status"
                        data-test="clock-photo-done"
                    >
                        <div
                            class="flex size-24 items-center justify-center rounded-full bg-background"
                            aria-hidden="true"
                        >
                            <Check class="size-13" stroke-width="2.4" />
                        </div>
                        <DialogTitle class="text-[34px] font-extrabold">
                            {{ state.result.title }}
                        </DialogTitle>
                        <DialogDescription class="text-[17px]">
                            {{ state.result.time }} at {{ state.result.branch }}
                        </DialogDescription>
                        <span
                            class="inline-flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-[13px] font-semibold"
                            :class="chipTone"
                            data-test="clock-photo-chip"
                        >
                            <span
                                class="size-[7px] rounded-full bg-current"
                                aria-hidden="true"
                            />
                            {{ state.result.chip.label }}
                        </span>
                    </div>
                    <div
                        class="px-4 pt-3 pb-[max(1.75rem,env(safe-area-inset-bottom))]"
                    >
                        <button
                            ref="doneButton"
                            type="button"
                            class="min-h-14 w-full rounded-full bg-primary text-[17px] font-bold text-primary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            data-test="clock-photo-done-button"
                            @click="close"
                        >
                            Done
                        </button>
                    </div>
                </template>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
