<script lang="ts">
/** Must match ClockPhotoService::NOTICE_VERSION; raise both together whenever the wording below changes. */
export const CLOCK_PHOTO_NOTICE_VERSION = '2026-11-12';
</script>

<script setup lang="ts">
import { Camera } from '@lucide/vue';
import { DialogDescription, DialogTitle } from 'reka-ui';

/**
 * HR-PHOTO: the one-time "About clock-in photos" notice, shown before a person's first camera use and again when the
 * wording version changes (owner, 2026-11-12). Kavitha (DS-13) owns the final sheet design and wording; this is the
 * slot the camera flow shows it in. `info` is the same text reopened from "About clock-in photos" on My attendance,
 * with a plain Close.
 */
withDefaults(defineProps<{ mode?: 'first-use' | 'info' }>(), {
    mode: 'first-use',
});
defineEmits<{ continue: []; cancel: [] }>();
</script>

<template>
    <div
        class="flex flex-1 flex-col gap-5 overflow-y-auto px-6 pt-[max(2rem,env(safe-area-inset-top))] pb-[max(1.75rem,env(safe-area-inset-bottom))]"
        data-test="clock-photo-notice"
    >
        <div
            class="flex size-14 items-center justify-center rounded-full bg-brand/10 text-brand-strong"
            aria-hidden="true"
        >
            <Camera class="size-7" />
        </div>
        <DialogTitle class="text-2xl font-bold"
            >About clock-in photos</DialogTitle
        >
        <DialogDescription class="flex flex-col gap-3 text-base">
            <span
                >When you clock in or out, KPOne takes one photo of you with
                this device's camera. It is used only to check that you were the
                one who clocked in. KPOne does not identify faces or compare
                photos.</span
            >
            <span
                >The Director and the HR Manager can see it, and each time they
                open it is recorded. It is kept for 7 years with your attendance
                record, then deleted (to confirm in the PDPA review).</span
            >
            <span
                v-if="mode === 'first-use'"
                data-test="clock-photo-notice-not-now"
                >If you choose Not now, nothing is recorded and you are not
                clocked in or out. Only a live photo counts. If you can't use
                the camera, ask HR to add your time as a correction.</span
            >
            <span v-else
                >Only a live photo counts. If you can't use the camera, ask HR
                to add your time as a correction.</span
            >
        </DialogDescription>
        <div
            v-if="mode === 'first-use'"
            class="mt-auto flex flex-col gap-3 sm:flex-row-reverse"
        >
            <button
                type="button"
                class="min-h-12 flex-1 rounded-full bg-primary px-6 text-base font-bold text-primary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                data-test="clock-photo-notice-continue"
                @click="$emit('continue')"
            >
                Continue to the camera
            </button>
            <button
                type="button"
                class="min-h-12 flex-1 rounded-full border px-6 text-base font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="$emit('cancel')"
            >
                Not now
            </button>
        </div>
        <div v-else class="mt-auto flex">
            <button
                type="button"
                class="min-h-12 flex-1 rounded-full bg-primary px-6 text-base font-bold text-primary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                data-test="clock-photo-notice-close"
                @click="$emit('cancel')"
            >
                Close
            </button>
        </div>
    </div>
</template>
