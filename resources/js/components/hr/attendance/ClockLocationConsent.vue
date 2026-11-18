<script setup lang="ts">
import { MapPin, X } from '@lucide/vue';
import { DialogDescription, DialogTitle } from 'reka-ui';
import { computed, nextTick, onMounted, ref } from 'vue';
import type { ClockKind, ClockLocation } from '@/composables/useClockPhoto';

/**
 * HR-16a consent, inside the camera dialog (one Clock in, owner 18 Nov 2026): the Me press asks here instead of sending
 * people to the Attendance page. Once per consent version, before the first press at a branch that checks location.
 * Two equal outline choices, neither preselected; closing records nothing and gives no consent. The wording is the
 * branch's own EN and BM text, word for word.
 */
const props = defineProps<{ kind: ClockKind; location: ClockLocation }>();
defineEmits<{ agree: []; without: []; cancel: [] }>();

const word = computed(() => (props.kind === 'out' ? 'out' : 'in'));
// Daniel: neither choice takes focus when the sheet opens; the title does, so a screen reader reads it first and no
// choice looks like the default.
const heading = ref<{ $el?: HTMLElement } | null>(null);
onMounted(async () => {
    await nextTick();
    heading.value?.$el?.focus();
});
const choice =
    'min-h-12 flex-1 rounded-full border px-6 text-base font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <div
        class="flex flex-1 flex-col gap-5 overflow-y-auto px-6 pt-[max(1.5rem,env(safe-area-inset-top))] pb-[max(1.75rem,env(safe-area-inset-bottom))]"
        data-test="clock-location-consent"
    >
        <div class="flex items-start justify-between gap-3">
            <div
                class="flex size-14 items-center justify-center rounded-full bg-brand/10 text-brand-strong"
                aria-hidden="true"
            >
                <MapPin class="size-7" />
            </div>
            <button
                type="button"
                class="flex size-11 items-center justify-center rounded-full border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Close"
                data-test="clock-location-close"
                @click="$emit('cancel')"
            >
                <X class="size-5" aria-hidden="true" />
            </button>
        </div>
        <DialogTitle
            ref="heading"
            tabindex="-1"
            class="text-2xl font-bold outline-none"
            >Location check at this branch</DialogTitle
        >
        <DialogDescription class="flex flex-col gap-1 text-base">
            <span>Read this once. You can clock {{ word }} without it.</span>
            <span
                class="text-sm text-muted-foreground"
                data-test="clock-location-close-note"
                >Closing does not clock you {{ word }}.</span
            >
        </DialogDescription>
        <p
            class="text-[13px] text-muted-foreground"
            data-test="clock-location-draft"
        >
            Draft wording until the clinic's PDPA review. Synthetic data only.
        </p>
        <section class="flex flex-col gap-1 text-sm">
            <h3 class="font-medium">English</h3>
            <p class="whitespace-pre-line">{{ location.consentEn }}</p>
        </section>
        <section class="flex flex-col gap-1 text-sm" lang="ms">
            <h3 class="font-medium">Bahasa Melayu</h3>
            <p class="whitespace-pre-line">{{ location.consentBm }}</p>
        </section>
        <div class="mt-auto flex flex-col gap-3 sm:flex-row">
            <button
                type="button"
                :class="choice"
                data-test="clock-location-agree"
                @click="$emit('agree')"
            >
                I understand, check my location
            </button>
            <button
                type="button"
                :class="choice"
                data-test="clock-location-without"
                @click="$emit('without')"
            >
                Clock {{ word }} without location
            </button>
        </div>
    </div>
</template>
