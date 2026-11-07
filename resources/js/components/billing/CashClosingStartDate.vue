<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';

/** BIL-15 (owner decisions 2026-11-07): the branch's cash closing start date, on the month page. */
const props = defineProps<{
    startDate: {
        startsOn: string | null;
        revision: number;
        setBy: string | null;
        setAt: string | null;
        canSet: boolean;
    };
    month: string;
}>();

const plainDate = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
};

// BIL-15: set or change the branch's cash closing start date (Finance Officer, Director).
const page = usePage();
const startError = computed(
    () =>
        (page.props.errors as Record<string, string | undefined> | undefined)
            ?.starts_on,
);
const startInput = ref(props.startDate.startsOn ?? '');
const startKey = ref(crypto.randomUUID());
const savingStart = ref(false);
const saveStart = () => {
    savingStart.value = true;
    router.post(
        '/cash-closings/start-date',
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            starts_on: startInput.value,
            expected_revision: props.startDate.revision,
            month: props.month,
            idempotency_key: startKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                startKey.value = crypto.randomUUID();
            },
            onFinish: () => {
                savingStart.value = false;
            },
        },
    );
};
</script>

<template>
    <section
        class="space-y-2 rounded-xl border bg-card p-4 text-sm"
        aria-labelledby="start-date-heading"
        data-testid="start-date"
    >
        <h2 id="start-date-heading" class="font-medium">
            Cash closing start date
        </h2>
        <p class="text-xs text-muted-foreground">
            <template v-if="startDate.startsOn">
                {{ plainDate(startDate.startsOn) }}. Days from this date without
                a count show as Not counted. Set by {{ startDate.setBy }} on
                {{ startDate.setAt }}.
            </template>
            <template v-else>
                Not set. Days before the branch's first count are not marked as
                missed.
            </template>
        </p>
        <form
            v-if="startDate.canSet"
            class="flex flex-wrap items-end gap-2"
            data-testid="start-date-form"
            @submit.prevent="saveStart"
        >
            <label class="grid gap-1 text-xs font-medium">
                {{ startDate.startsOn ? 'Change to' : 'Start date' }}
                <input
                    v-model="startInput"
                    type="date"
                    required
                    class="h-11 rounded-md border border-input bg-background px-2 text-sm sm:h-9"
                />
            </label>
            <button
                type="submit"
                class="inline-flex min-h-11 items-center rounded-md border px-3 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 sm:min-h-9"
                :disabled="savingStart || startInput === ''"
            >
                Save start date
            </button>
        </form>
        <InputError :message="startError" />
    </section>
</template>
