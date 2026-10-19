<script setup lang="ts">
import { computed } from 'vue';
import type { BillToRecipientData } from '@/types/billTo';
const props = defineProps<{ recipient: BillToRecipientData }>();
const lines = computed(() =>
    [
        props.recipient.company_name,
        props.recipient.address_line_1,
        props.recipient.address_line_2,
        [props.recipient.postcode, props.recipient.city]
            .filter(Boolean)
            .join(' '),
        [props.recipient.state, props.recipient.country_code]
            .filter(Boolean)
            .join(', '),
    ].filter(Boolean),
);
</script>
<template>
    <div class="min-w-0 break-words" aria-label="Bill to recipient">
        <p class="font-medium">{{ recipient.display_name }}</p>
        <p class="text-xs capitalize">{{ recipient.party_type }}</p>
        <p v-for="(line, index) in lines" :key="index">{{ line }}</p>
    </div>
</template>
