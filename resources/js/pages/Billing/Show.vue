<script setup lang="ts">
import BillingWorkspace from '@/components/billing/BillingWorkspace.vue';
import type { BillingPage } from '@/types/billing';
defineProps<{ billing: BillingPage }>();
</script>
<template><BillingWorkspace :billing="billing" /></template>
