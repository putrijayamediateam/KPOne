<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useDisciplinePage } from '@/composables/hr/useDisciplinePage';
import type { DisciplinePageProps } from '@/composables/hr/useDisciplinePage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Discipline (shown only in V2; Classic is `pages/HR/Discipline.vue`, untouched). Same props, routes,
 * permissions and idempotency keys: every request comes from `useDisciplinePage`, which Classic uses too, so this page
 * sends none of its own. This is a restricted record: the list carries no allegation, note or outcome text (the props
 * hold none), nothing is kept in the browser, and a case's text is only on the case page after the view is recorded.
 * What is typed in the form stays in the form until it is sent. KPOne never ends employment or changes an account.
 */
const props = defineProps<DisciplinePageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Discipline' },
]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    form,
    startCase,
    submit,
    textareaClass,
} = useDisciplinePage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';

const workerOptions = computed(() =>
    props.discipline.workers.map((w) => ({ value: w.id, label: w.name })),
);
const confirmReason = computed(() =>
    !form.worker
        ? 'Choose a staff file to continue.'
        : !form.incidentOn
          ? 'Choose the date it happened to continue.'
          : form.allegation.trim().length < 10
            ? 'Write the allegation (at least 10 characters) to continue.'
            : '',
);
</script>

<template>
    <Head title="Discipline" />
    <PageFrame>
        <V2WorkLayout
            title="Discipline"
            description="Restricted cases: the allegation, show-cause letters, the staff member's reply and one outcome. Cases never show on the staff file, the dashboard or any export. KPOne never ends employment or changes an account."
        >
            <template #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="case-new"
                    @click="startCase"
                >
                    Open a case
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <p
                        v-if="failure && !form.open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <V2Tabs
                        :model-value="discipline.tab"
                        :tabs="tabs"
                        ariaLabel="Case sections"
                        @update:model-value="selectTab"
                    />
                    <div
                        v-if="discipline.cases.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center"
                    >
                        <p class="font-medium">
                            {{
                                discipline.tab === 'open'
                                    ? 'No open cases.'
                                    : 'No closed cases.'
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Cases about you, or about someone only a Director
                            may handle, are not listed here.
                        </p>
                    </div>
                    <ul
                        v-else
                        class="flex flex-col gap-3"
                        data-test="case-list"
                    >
                        <li
                            v-for="c in discipline.cases"
                            :key="c.id"
                            :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                            data-test="case-row"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium break-words">
                                        {{ c.person }}
                                    </p>
                                    <V2Chip
                                        :tone="
                                            c.status === 'open'
                                                ? 'warning'
                                                : 'neutral'
                                        "
                                        :label="
                                            c.status === 'open'
                                                ? 'Open'
                                                : 'Closed'
                                        "
                                    />
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    Happened {{ c.incidentOn }} · opened
                                    {{ c.openedOn }} ·
                                    {{
                                        c.entries === 1
                                            ? '1 entry'
                                            : `${c.entries} entries`
                                    }}
                                    <template v-if="c.outcome">
                                        · {{ c.outcome }},
                                        {{ c.closedOn }}</template
                                    >
                                </p>
                            </div>
                            <Link
                                :href="`/hr/discipline/${c.id}`"
                                :class="button"
                                >Open<span class="sr-only">
                                    case about {{ c.person }}</span
                                ></Link
                            >
                        </li>
                    </ul>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="form.open"
            title="Open a discipline case"
            description="The allegation is kept as written and cannot be edited. Add notes, letters and the reply on the case page."
            size="tall"
            :confirm-label="form.processing ? 'Opening…' : 'Open case'"
            :busy="form.processing"
            :confirm-disabled="confirmReason !== ''"
            :confirm-disabled-reason="confirmReason"
            data-test="case-sheet"
            @update:open="(open: boolean) => (form.open = open)"
            @confirm="submit"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-1.5">
                <label id="v2-case-worker-label" class="text-sm font-medium"
                    >Staff file <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="form.worker"
                    :options="workerOptions"
                    label="Staff file"
                    labelledby="v2-case-worker-label"
                    placeholder="Choose a staff file"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (form.worker = String(v))"
                />
                <p class="text-sm text-muted-foreground">
                    You cannot open a case about yourself. A Director's or HR
                    Manager's case is opened by a Director.
                </p>
                <p
                    v-if="errors.worker_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.worker_id }}
                </p>
            </div>
            <V2DateField
                v-model="form.incidentOn"
                label="Date it happened"
                required
                :max="discipline.today"
                :error="errors.incident_on"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="Allegation"
                required
                :hint="`What is alleged, when and where. Write facts only; no patient name, IC or diagnosis. ${form.allegation.length} of ${discipline.maxAllegation} characters.`"
                :error="errors.allegation"
            >
                <textarea
                    :id="id"
                    v-model="form.allegation"
                    rows="6"
                    :maxlength="discipline.maxAllegation"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
        </V2Dialog>
    </PageFrame>
</template>
