<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';

/** BIL-15 (owner decisions 2026-11-07): the branch's cash closing start date, on the month page. */
const props = defineProps<{
    startDate: {
        startsOn: string | null;
        revision: number;
        setBy: string | null;
        movedLaterFrom: string | null;
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
// Show every refusal of this form, including a branch change in another tab or a reused key (BIL-15 review).
const startError = computed(() => {
    const errors = page.props.errors as
        Record<string, string | undefined> | undefined;

    return (
        errors?.starts_on ??
        errors?.expected_branch_id ??
        errors?.expected_revision ??
        errors?.idempotency_key
    );
});
const startInput = ref(props.startDate.startsOn ?? '');
// Daniel: the date in words under the field, so a browser showing mm/dd cannot mislead.
const inputInWords = computed(() =>
    /^\d{4}-\d{2}-\d{2}$/.test(startInput.value)
        ? plainDate(startInput.value)
        : '',
);
// Closed by default; a refusal opens it again so the error is next to the field.
const changeOpen = ref(false);
watch(startError, (error) => {
    if (error) {
        changeOpen.value = true;
    }
});
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
        class="space-y-2 rounded-xl border bg-card p-3 text-sm"
        aria-labelledby="start-date-heading"
        data-testid="start-date"
    >
        <h2 id="start-date-heading" class="font-semibold">
            Cash closing start date
        </h2>
        <template v-if="startDate.startsOn">
            <p data-testid="start-date-value">
                Starts on <strong>{{ plainDate(startDate.startsOn) }}</strong
                >. Days from this date without a count show as Not counted.
            </p>
            <p class="text-xs text-muted-foreground">
                Set by {{ startDate.setBy }} on {{ startDate.setAt }}.
            </p>
        </template>
        <p v-else data-testid="start-date-value">
            Not set. Cash closing starts from the branch's first count; days
            before it are not marked as Not counted.
        </p>
        <p
            v-if="startDate.movedLaterFrom && startDate.startsOn"
            class="flex gap-2 rounded-md border border-warning/25 bg-warning/10 px-3 py-2 text-sm text-warning-strong"
            data-testid="start-date-moved-later"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
                Start date changed on {{ startDate.setAt }} (was
                {{ plainDate(startDate.movedLaterFrom) }}). Days before
                {{ plainDate(startDate.startsOn) }} are no longer marked as Not
                counted.
            </span>
        </p>
        <p
            v-if="!startDate.canSet"
            class="text-xs text-muted-foreground"
            data-testid="start-date-who-can-change"
        >
            A Director or Finance Officer can change this date.
        </p>
        <!-- Daniel: the change form stays closed by default, so the card stays short above the month table. -->
        <details
            v-if="startDate.canSet"
            :open="changeOpen"
            data-testid="start-date-change"
            class="group"
            @toggle="changeOpen = ($event.target as HTMLDetailsElement).open"
        >
            <summary
                class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1 text-sm font-medium sm:min-h-9 [&::-webkit-details-marker]:hidden"
            >
                <ChevronRight
                    class="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none"
                    aria-hidden="true"
                />
                Change start date
            </summary>
            <form
                class="mt-2 flex flex-wrap items-end gap-2"
                data-testid="start-date-form"
                @submit.prevent="saveStart"
            >
                <label class="grid gap-1 text-xs font-medium">
                    {{ startDate.startsOn ? 'Change to' : 'Start date' }}
                    <input
                        v-model="startInput"
                        type="date"
                        required
                        aria-describedby="start-date-hint start-date-echo start-date-error"
                        :aria-invalid="startError ? 'true' : undefined"
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
            <p
                id="start-date-echo"
                class="mt-1 text-xs font-medium"
                data-testid="start-date-echo"
                aria-live="polite"
            >
                {{ inputInWords ? `= ${inputInWords}` : '' }}
            </p>
            <p id="start-date-hint" class="text-xs text-muted-foreground">
                Today or earlier, or up to one year ahead.
            </p>
        </details>
        <InputError id="start-date-error" :message="startError" />
    </section>
</template>
