<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <!--
        UX-7: the message is inserted as role="alert" when an error appears, so screen readers
        announce it at once (a live region that is already hidden would often stay silent). The
        outer div still hides with v-show and keeps any id a field points to with aria-describedby,
        so the layout and existing links are unchanged.
    -->
    <div v-show="message">
        <p
            v-if="message"
            role="alert"
            class="text-sm text-red-600 dark:text-red-500"
        >
            {{ message }}
        </p>
    </div>
</template>
