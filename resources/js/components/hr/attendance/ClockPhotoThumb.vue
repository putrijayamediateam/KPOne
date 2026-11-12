<script setup lang="ts">
import { Eye } from '@lucide/vue';
import { ref } from 'vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

export type ClockPhotoEntry = {
    id: string | null;
    own: boolean;
    needsShow: boolean;
    thumbUrl: string | null;
    fullUrl: string | null;
    label: string | null;
};

/**
 * HR-PHOTO (owner decision B, 2026-11-12): your own photo shows straight away; anyone else's stays hidden until Show,
 * and the server records that open before it sends the picture. A time added by a correction has no photo.
 */
const props = defineProps<{ photo: ClockPhotoEntry | null; name: string }>();

const shown = ref(false);
const large = ref(false);
</script>

<template>
    <span
        v-if="photo && !photo.id"
        class="text-xs text-muted-foreground"
        data-test="clock-photo-none"
    >
        {{ photo.label }}
    </span>
    <span v-else-if="photo && photo.id" class="inline-flex items-center gap-2">
        <button
            v-if="photo.needsShow && !shown"
            type="button"
            class="inline-flex min-h-11 items-center gap-1.5 rounded-md border px-2.5 text-xs font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-8"
            data-test="clock-photo-show"
            @click="shown = true"
        >
            <Eye class="size-4" aria-hidden="true" />
            Show photo<span class="sr-only">: {{ name }}</span>
        </button>
        <button
            v-else
            type="button"
            class="block size-11 overflow-hidden rounded-md border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:size-10"
            :aria-label="`Open ${name}`"
            data-test="clock-photo-thumb"
            @click="large = true"
        >
            <img
                :src="props.photo!.thumbUrl ?? ''"
                alt=""
                class="size-full object-cover"
            />
        </button>
        <span v-if="photo.label" class="text-xs text-muted-foreground">{{
            photo.label
        }}</span>
    </span>
    <Dialog v-if="photo?.id" v-model:open="large">
        <DialogContent class="max-w-[min(36rem,calc(100%-2rem))] p-3">
            <DialogTitle class="pr-8 text-sm">{{ name }}</DialogTitle>
            <img
                :src="photo.fullUrl ?? ''"
                :alt="name"
                class="max-h-[70vh] w-full rounded-md object-contain"
            />
        </DialogContent>
    </Dialog>
</template>
