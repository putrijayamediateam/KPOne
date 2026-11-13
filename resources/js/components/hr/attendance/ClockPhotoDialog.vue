<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, RefreshCcw, X } from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import ClockPhotoNotice, {
    CLOCK_PHOTO_NOTICE_VERSION,
} from '@/components/hr/attendance/ClockPhotoNotice.vue';
import {
    CAMERA_REFUSED,
    clockPhotoState as state,
    markNoticeSeen,
    NoticeNeeded,
    resetClockPhoto,
    sendPress,
    takeToken,
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
let photo: Blob | null = null;
let stream: MediaStream | null = null;

const open = computed(() => state.step !== 'idle');
const title = computed(() => (state.kind === 'in' ? 'Clock in' : 'Clock out'));
const dark = computed(() =>
    ['starting', 'camera', 'review', 'sending', 'failed'].includes(state.step),
);
const notice = computed(() => state.step === 'notice');

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

watch(
    () => state.step,
    (step, before) => {
        if (step === 'starting' && before === 'idle') {
            facing.value = 'user';
            void begin();
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

    if (!el || !el.videoWidth) {
        return;
    }

    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 1280 / Math.max(el.videoWidth, el.videoHeight));
    canvas.width = Math.round(el.videoWidth * scale);
    canvas.height = Math.round(el.videoHeight * scale);
    canvas.getContext('2d')?.drawImage(el, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', 0.88),
    );

    if (!blob) {
        return;
    }

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
    state.step = 'starting';
    await begin();
};

// "Use photo": the server records the time now.
const usePhoto = async () => {
    if (!photo || state.step !== 'review') {
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
        state.step = 'review';
        state.error = e instanceof Error ? e.message : 'Try again.';
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
                    notice
                        ? 'bg-background text-foreground'
                        : dark
                          ? 'bg-[#08080a] text-white'
                          : 'bg-[color-mix(in_oklab,var(--success)_12%,var(--background))] text-success dark:bg-[color-mix(in_oklab,var(--success)_22%,var(--background))]'
                "
                @escape-key-down="
                    (e: Event) => state.step === 'sending' && e.preventDefault()
                "
                @pointer-down-outside="(e: Event) => e.preventDefault()"
            >
                <!-- The one-time "About clock-in photos" notice -->
                <ClockPhotoNotice
                    v-if="notice"
                    @continue="noticeRead"
                    @cancel="close"
                />

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
                                    ? 'Check your photo'
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
                            class="mb-3 rounded-lg bg-white/10 px-3 py-2 text-center text-sm"
                            role="alert"
                            data-test="clock-photo-error"
                        >
                            {{ state.error }}
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
                                class="min-h-14 flex-1 rounded-full border-2 border-white/70 bg-transparent text-[17px] font-bold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none disabled:opacity-50"
                                data-test="clock-photo-retake"
                                :disabled="state.step === 'sending'"
                                @click="retake"
                            >
                                Retake
                            </button>
                            <button
                                ref="usePhotoButton"
                                type="button"
                                class="min-h-14 flex-[1.4] rounded-full bg-[#6ba6ff] text-[17px] font-bold text-[#0b1a33] focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none disabled:opacity-70"
                                data-test="clock-photo-use"
                                :disabled="state.step === 'sending'"
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
