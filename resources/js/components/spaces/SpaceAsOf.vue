<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// "Branch · as of 8:43 PM" with a manual Refresh: reloads only this space's data (no polling in this slice). If the
// reload fails, say so and keep the last good time.
const props = defineProps<{
    branchName: string;
    asOf: string | null;
    only: string;
    // The page header already names the branch (My day): show only "as of".
    hideBranch?: boolean;
}>();

const refreshing = ref(false);
const failed = ref(false);

const refresh = () => {
    refreshing.value = true;
    failed.value = false;
    let succeeded = false;
    router.reload({
        only: [props.only],
        onSuccess: () => {
            succeeded = true;
        },
        onFinish: () => {
            refreshing.value = false;
            failed.value = !succeeded;
        },
    });
};
</script>

<template>
    <p class="space-muted space-asof">
        <span
            ><template v-if="!hideBranch">{{ branchName }}</template
            ><template v-if="asOf"
                >{{ hideBranch ? 'As of' : ' · as of' }} {{ asOf }}</template
            ></span
        >
        <button
            type="button"
            class="space-refresh"
            :disabled="refreshing"
            @click="refresh"
        >
            {{ refreshing ? 'Refreshing…' : 'Refresh' }}
        </button>
        <span v-if="failed" class="space-ask-error" role="status"
            >Could not refresh. Still showing the update from {{ asOf }}.</span
        >
    </p>
</template>
