<script setup lang="ts">
import ClockPhotoDialog from '@/components/hr/attendance/ClockPhotoDialog.vue';
</script>

<template>
    <slot />
    <!-- HR-PHOTO: the space shell draws its own chrome; the camera flow is mounted here once for it. -->
    <ClockPhotoDialog />
</template>
