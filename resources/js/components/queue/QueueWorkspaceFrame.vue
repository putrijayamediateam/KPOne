<script setup lang="ts">
import { computed, ref } from 'vue';

type Tab = {
    value: 'waiting' | 'serving' | 'removed' | 'held' | 'treatment' | 'checks';
    label: string;
    count?: number;
};
const props = defineProps<{
    tabs: Tab[];
    activeTab: Tab['value'];
    scope: 'own' | 'branch';
    branchName: string;
    canRegistration: boolean;
}>();
defineEmits<{ select: [value: Tab['value']] }>();
const showInfo = ref(true);
const about = computed(
    () =>
        ({
            waiting:
                'Red-zone emergencies remain in their own lane. Other patients are ordered by urgency, then arrival; today comes before carry-over.',
            serving:
                'Patients currently in consultation. Held consultations appear separately under On hold.',
            removed:
                'Consultations sent to Dispensary or Billing today, using this branch’s local date. This does not mean billing is settled.',
            held: 'Consultations currently on hold. Opening this list does not resume a patient.',
            treatment:
                'Finished treatment steps awaiting doctor review, including not-successful outcomes. This does not confirm the patient has returned. Opening this list does not acknowledge or resume care.',
            checks: 'Your patients whose prepared medicines await your check. Open their consultation to review; viewing this list does not approve medicines.',
        })[props.activeTab],
);
</script>

<template>
    <div
        class="grid min-w-0 items-start gap-5"
        :class="showInfo ? 'xl:grid-cols-[minmax(0,1fr)_280px]' : ''"
        data-testid="queue-workspace-frame"
    >
        <div class="flex min-w-0 flex-col gap-4">
            <div
                class="grid grid-cols-2 gap-3 lg:grid-cols-4"
                aria-label="Queue summary"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    :aria-pressed="activeTab === tab.value"
                    class="min-h-24 rounded-2xl border bg-card p-4 text-left shadow-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="
                        activeTab === tab.value
                            ? 'border-primary ring-1 ring-primary'
                            : 'border-transparent hover:border-border'
                    "
                    @click="$emit('select', tab.value)"
                >
                    <span class="block text-2xl font-semibold tabular-nums">{{
                        tab.count ?? '—'
                    }}</span>
                    <span class="mt-1 block text-xs text-muted-foreground">{{
                        tab.label
                    }}</span>
                </button>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-muted-foreground">
                    {{ scope === 'own' ? 'Your patients' : 'All doctors' }} ·
                    {{ branchName }} · Live, every 3 seconds
                </p>
                <button
                    type="button"
                    class="min-h-11 rounded-full bg-muted px-4 text-xs lg:min-h-9"
                    :aria-expanded="showInfo"
                    aria-controls="queue-helpful-info"
                    @click="showInfo = !showInfo"
                >
                    {{ showInfo ? 'Hide helpful info' : 'Show helpful info' }}
                </button>
            </div>
            <slot />
        </div>
        <aside
            v-if="showInfo"
            id="queue-helpful-info"
            class="grid min-w-0 gap-3"
            aria-label="Helpful queue information"
        >
            <details open class="rounded-2xl bg-card p-4 shadow-sm">
                <summary
                    class="min-h-11 cursor-pointer text-sm font-semibold lg:min-h-9"
                >
                    About this tab
                </summary>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ about }}
                </p>
                <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                    Waiting turns amber from 30 minutes and red from 60 minutes.
                </p>
            </details>
            <details open class="rounded-2xl bg-card p-4 shadow-sm">
                <summary
                    class="min-h-11 cursor-pointer text-sm font-semibold lg:min-h-9"
                >
                    Queue at a glance
                </summary>
                <dl class="text-xs">
                    <div
                        v-for="tab in tabs"
                        :key="tab.value"
                        class="flex justify-between gap-4 border-t py-3"
                    >
                        <dt>{{ tab.label }}</dt>
                        <dd class="text-muted-foreground tabular-nums">
                            {{ tab.count ?? '—' }}
                        </dd>
                    </div>
                </dl>
                <slot name="summary" />
            </details>
            <details class="rounded-2xl bg-card px-4 py-2 shadow-sm">
                <summary
                    class="min-h-11 cursor-pointer py-2 text-sm font-semibold lg:min-h-9"
                >
                    Related pages
                </summary>
                <a
                    v-if="canRegistration"
                    href="/registration"
                    class="flex min-h-11 items-center border-t text-sm text-primary lg:min-h-9"
                    >Visits today</a
                >
                <slot name="related" />
            </details>
            <details class="rounded-2xl bg-card px-4 py-2 shadow-sm">
                <summary
                    class="min-h-11 cursor-pointer py-2 text-sm font-semibold lg:min-h-9"
                >
                    Good to know
                </summary>
                <p class="pb-2 text-xs leading-relaxed text-muted-foreground">
                    Calling a patient announces their queue number and room on
                    the TV. Held consultations stay held when another patient is
                    called. Check the patient's readiness before calling.
                </p>
            </details>
        </aside>
    </div>
</template>
