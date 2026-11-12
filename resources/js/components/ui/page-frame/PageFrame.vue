<script setup lang="ts">
/**
 * UI-RESTYLE 0.3a: the one page frame (GUIDE section 19). It centres the page content, sets the side padding
 * (16 px on a phone, 32 px from 1024 px) and the 24 px gap between cards. A page adopts it in its own restyle PR;
 * nothing changes until then.
 */
withDefaults(
    defineProps<{
        /** narrow 896 px, default 1280 px, work 1500 px (Dispensary), wide 1600 px (Queue board). */
        width?: 'narrow' | 'default' | 'work' | 'wide';
    }>(),
    { width: 'default' },
);
</script>

<template>
    <div
        class="mx-auto flex min-w-0 flex-1 flex-col gap-6 px-4 py-6 lg:px-8"
        :class="{
            'w-full max-w-4xl': width === 'narrow',
            'w-full max-w-7xl': width === 'default',
            'w-full max-w-[1500px]': width === 'work',
            'w-full max-w-[1600px]': width === 'wide',
        }"
        data-test="page-frame"
    >
        <slot />
        <!-- A page that already has activity data passes it here; the strip sits at the bottom of the frame. -->
        <section
            v-if="$slots.activity"
            class="rounded-[14px] border bg-card p-4"
            data-test="page-frame-activity"
        >
            <slot name="activity" />
        </section>
    </div>
</template>
