<script setup lang="ts">
/**
 * UI-RESTYLE 0.3a: the form action bar that stays at the bottom of the screen. A solid page-colour backdrop and a
 * hairline top edge, so the form never shows through behind it; the buttons are 44 px tall below 1024 px and
 * 36 px from 1024 px.
 */
</script>

<template>
    <div
        class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 border-t bg-background px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-lg:[&_button]:min-h-11 lg:px-8"
        data-test="sticky-action-bar"
    >
        <slot />
    </div>
</template>
