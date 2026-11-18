<script setup lang="ts">
import BillingShowV2 from '@/pages/Billing/V2/Show.vue';
import type { BillingPage } from '@/types/billing';

/**
 * BV2-4: Version 2 Manage payments (shown only in Version 2; Classic is `pages/Billing/Payments.vue`, untouched). As
 * Classic renders BillingWorkspace with `manage`, this renders the V2 Patient Billing page with `manage`: same prop,
 * routes, permission flags, lock versions, payloads and money rules, and every action returns here (return_to=payments).
 */
defineProps<{ billing: BillingPage }>();
</script>

<template><BillingShowV2 :billing="billing" manage /></template>
