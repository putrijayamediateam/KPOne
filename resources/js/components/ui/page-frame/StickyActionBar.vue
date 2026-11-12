<script setup lang="ts">
/**
 * UI-RESTYLE 0.3a: the form action bar that stays at the bottom of the screen. The backdrop is solid page colour
 * with a hairline top edge and runs the full width, so the form never shows through behind it. The buttons sit in
 * the same frame as the page (same `width` as the PageFrame above it), so Save lines up under the right edge of the
 * cards. Buttons are 44 px tall below 1024 px and 36 px from 1024 px.
 *
 * The bar is `sticky`, so it takes its own space at the end of the page: the last field is never hidden behind it.
 * Its bottom padding includes `env(safe-area-inset-bottom)` for phones with a home indicator.
 */
withDefaults(
    defineProps<{
        /** The same widths as PageFrame (work is Dispensary only). */
        width?: 'narrow' | 'default' | 'work' | 'wide';
    }>(),
    { width: 'default' },
);
</script>

<template>
    <div
        class="sticky bottom-0 z-10 border-t bg-background pb-[env(safe-area-inset-bottom)]"
        data-test="sticky-action-bar"
    >
        <div
            class="mx-auto flex w-full flex-wrap items-center justify-end gap-2 px-4 py-3 max-lg:[&_button]:min-h-11 lg:px-8"
            :class="{
                'max-w-4xl': width === 'narrow',
                'max-w-7xl': width === 'default',
                'max-w-[1500px]': width === 'work',
                'max-w-[1600px]': width === 'wide',
            }"
        >
            <slot />
        </div>
    </div>
</template>
