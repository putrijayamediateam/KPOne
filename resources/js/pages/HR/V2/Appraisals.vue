<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useAppraisalsPage } from '@/composables/hr/useAppraisalsPage';
import type { AppraisalsPageProps } from '@/composables/hr/useAppraisalsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Appraisals (shown only in V2; Classic is `pages/HR/Appraisals.vue`, untouched). Same props, routes,
 * permissions, lock versions and idempotency keys: every request comes from `useAppraisalsPage`, which Classic uses
 * too, so this page sends none of its own. No ranking and no total score; ratings live on the single appraisal page.
 */
const props = defineProps<AppraisalsPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Appraisals' },
]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    tone,
    criteriaForm,
    startCriteria,
    addCriterion,
    removeCriterion,
    saveCriteria,
    cycleForm,
    startCycle,
    allChosen,
    toggleAll,
    saveCycle,
    openCycle,
    closing,
    closeBusy,
    askClose,
    sendClose,
    textareaClass,
} = useAppraisalsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const chipTone = (s: 'draft' | 'sent' | 'signed_off') =>
    tone(s) === 'success'
        ? 'success'
        : tone(s) === 'accent'
          ? 'info'
          : 'neutral';

const description = computed(() =>
    props.appraisals.canManage
        ? "Enter the clinic's criteria, open a cycle for chosen staff, and rate each criterion from 1 to 5. A Director signs each appraisal off. There is no ranking and no total score."
        : 'Your appraisals from HR. Read each one and add your own comment.',
);
const cycleMissing = computed(() => {
    if (cycleForm.name.trim() === '') {
        return 'Enter a name to continue.';
    }

    if (!cycleForm.start || !cycleForm.end) {
        return 'Choose the first and last day to continue.';
    }

    return cycleForm.workers.length
        ? ''
        : 'Choose the staff to appraise to continue.';
});
const criteriaMissing = computed(() =>
    criteriaForm.items.some((c) => c.title.trim() === '')
        ? 'Enter a title for every criterion to continue.'
        : '',
);
</script>

<template>
    <Head title="Appraisals" />
    <PageFrame>
        <V2WorkLayout title="Appraisals" :description="description">
            <template v-if="appraisals.tab === 'cycles'" #actions>
                <span
                    v-if="!appraisals.criteria"
                    id="v2-cycle-hint"
                    class="self-center text-sm text-muted-foreground"
                    >Enter the criteria first.</span
                >
                <button
                    type="button"
                    :class="primaryButton"
                    :disabled="!appraisals.criteria"
                    :aria-describedby="
                        appraisals.criteria ? undefined : 'v2-cycle-hint'
                    "
                    data-test="cycle-new"
                    @click="startCycle"
                >
                    Open a cycle
                </button>
            </template>
            <template v-else-if="appraisals.tab === 'criteria'" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="criteria-edit"
                    @click="startCriteria"
                >
                    {{
                        appraisals.criteria
                            ? 'Change criteria'
                            : 'Enter criteria'
                    }}
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="appraisals.tab"
                        :tabs="tabs"
                        ariaLabel="Appraisal sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="
                            failure &&
                            !cycleForm.open &&
                            !criteriaForm.editing &&
                            !closing
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- My appraisals -->
                    <section
                        v-if="appraisals.tab === 'mine'"
                        aria-labelledby="v2-my-appraisals-title"
                        class="flex flex-col gap-3"
                        data-test="my-appraisals"
                    >
                        <h2 id="v2-my-appraisals-title" class="sr-only">
                            My appraisals
                        </h2>
                        <div
                            v-if="!appraisals.mine.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                No appraisals for you yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                When HR sends you an appraisal, it appears here.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="a in appraisals.mine"
                                :key="a.id"
                                :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                data-test="my-appraisal"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p class="font-medium break-words">
                                            {{ a.cycle }}
                                        </p>
                                        <V2Chip
                                            :tone="chipTone(a.status)"
                                            :label="a.statusLabel"
                                        />
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ a.period
                                        }}<template v-if="a.sentOn">
                                            · sent {{ a.sentOn }}</template
                                        ><template v-if="a.commented">
                                            · you commented</template
                                        >
                                    </p>
                                </div>
                                <Link
                                    :href="`/hr/appraisals/${a.id}`"
                                    :class="button"
                                    :aria-label="`Open ${a.cycle}`"
                                    >Open</Link
                                >
                            </li>
                        </ul>
                    </section>

                    <!-- Cycles -->
                    <section
                        v-if="appraisals.tab === 'cycles'"
                        aria-labelledby="v2-cycles-title"
                        class="flex flex-col gap-3"
                        data-test="appraisal-cycles"
                    >
                        <h2
                            id="v2-cycles-title"
                            class="text-base font-semibold"
                        >
                            Cycles
                        </h2>
                        <div
                            v-if="!appraisals.cycles.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No cycles yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Open a cycle with its dates and the staff to
                                appraise.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="c in appraisals.cycles"
                                :key="c.id"
                                :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                data-test="appraisal-cycle"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p class="font-medium break-words">
                                            {{ c.name }}
                                        </p>
                                        <V2Chip
                                            :tone="
                                                c.status === 'open'
                                                    ? 'info'
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
                                        {{ c.period }} · {{ c.draft }} not sent
                                        · {{ c.sent }} sent · {{ c.signedOff }}
                                        signed off
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        :class="button"
                                        :aria-label="`Show people in ${c.name}`"
                                        @click="openCycle(c.id)"
                                    >
                                        People
                                    </button>
                                    <button
                                        v-if="c.status === 'open'"
                                        type="button"
                                        :class="dangerButton"
                                        :aria-label="`Close ${c.name}`"
                                        @click="askClose(c)"
                                    >
                                        Close cycle
                                    </button>
                                </div>
                            </li>
                        </ul>

                        <section
                            v-if="appraisals.cycle"
                            aria-labelledby="v2-cycle-people-title"
                            class="flex flex-col gap-2"
                            data-test="cycle-people"
                        >
                            <h3
                                id="v2-cycle-people-title"
                                class="text-sm font-semibold"
                            >
                                {{ appraisals.cycle.name }} ·
                                {{ appraisals.cycle.period }}
                            </h3>
                            <ul class="flex flex-col gap-3">
                                <li
                                    v-for="p in appraisals.cycle.people"
                                    :key="p.id"
                                    :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                    data-test="cycle-person"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <p class="font-medium break-words">
                                                {{ p.name }}
                                            </p>
                                            <V2Chip
                                                :tone="chipTone(p.status)"
                                                :label="p.statusLabel"
                                            />
                                        </div>
                                        <p
                                            v-if="p.refusal"
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ p.refusal }}
                                        </p>
                                        <p
                                            v-else-if="p.commented"
                                            class="text-sm text-muted-foreground"
                                        >
                                            The staff member added a comment.
                                        </p>
                                    </div>
                                    <Link
                                        v-if="!p.refusal"
                                        :href="`/hr/appraisals/${p.id}`"
                                        :class="button"
                                        :aria-label="`Open the appraisal of ${p.name}`"
                                        >{{
                                            p.status === 'draft' &&
                                            appraisals.cycle.status === 'open'
                                                ? 'Rate'
                                                : 'Open'
                                        }}</Link
                                    >
                                </li>
                            </ul>
                        </section>
                    </section>

                    <!-- Criteria -->
                    <section
                        v-if="appraisals.tab === 'criteria'"
                        aria-labelledby="v2-criteria-title"
                        class="flex flex-col gap-3"
                        data-test="appraisal-criteria"
                    >
                        <h2
                            id="v2-criteria-title"
                            class="text-base font-semibold"
                        >
                            Criteria<template v-if="appraisals.criteria">
                                · version
                                {{ appraisals.criteria.version }}</template
                            >
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            KPOne ships no criteria. Each save is a new version;
                            a cycle keeps the version it was opened with.
                        </p>
                        <div
                            v-if="!appraisals.criteria"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No criteria yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Enter up to
                                {{ appraisals.maxCriteria }} criteria, for
                                example teamwork or punctuality.
                            </p>
                        </div>
                        <ol
                            v-else
                            class="flex list-decimal flex-col gap-2 rounded-[14px] border bg-card py-3 pr-4 pl-9"
                        >
                            <li
                                v-for="(c, i) in appraisals.criteria.items"
                                :key="i"
                            >
                                <p class="font-medium">{{ c.title }}</p>
                                <p
                                    v-if="c.description"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ c.description }}
                                </p>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Criteria form -->
        <V2Dialog
            :open="criteriaForm.editing"
            title="Criteria"
            :description="`Up to ${appraisals.maxCriteria} criteria. Each save is a new version.`"
            size="tall"
            :confirm-label="
                criteriaForm.processing ? 'Saving…' : 'Save criteria'
            "
            :busy="criteriaForm.processing"
            :confirm-disabled="criteriaMissing !== ''"
            :confirm-disabled-reason="criteriaMissing"
            data-test="criteria-form"
            @update:open="(open: boolean) => (criteriaForm.editing = open)"
            @confirm="saveCriteria"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <fieldset
                v-for="(c, i) in criteriaForm.items"
                :key="i"
                class="flex flex-col gap-3 rounded-[10px] border p-3"
            >
                <legend class="px-1 text-sm font-medium">
                    Criterion {{ i + 1 }}
                </legend>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="Title"
                    required
                    :error="errors[`criteria.${i}.title`]"
                >
                    <input
                        :id="id"
                        v-model="c.title"
                        maxlength="80"
                        :class="inputClass"
                        :aria-describedby="describedBy"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy }"
                    label="What it means (optional)"
                    :error="errors[`criteria.${i}.description`]"
                >
                    <textarea
                        :id="id"
                        v-model="c.description"
                        rows="2"
                        maxlength="300"
                        :class="textareaClass"
                        :aria-describedby="describedBy"
                    ></textarea>
                </V2Field>
                <button
                    v-if="criteriaForm.items.length > 1"
                    type="button"
                    :class="`${dangerButton} self-start`"
                    :aria-label="`Remove criterion ${i + 1}`"
                    @click="removeCriterion(i)"
                >
                    Remove
                </button>
            </fieldset>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    :class="button"
                    :disabled="
                        criteriaForm.items.length >= appraisals.maxCriteria
                    "
                    @click="addCriterion"
                >
                    Add a criterion
                </button>
                <span class="text-sm text-muted-foreground"
                    >{{ criteriaForm.items.length }} of
                    {{ appraisals.maxCriteria }}</span
                >
            </div>
        </V2Dialog>

        <!-- Open a cycle -->
        <V2Dialog
            :open="cycleForm.open"
            title="Open a cycle"
            :description="`It uses the current criteria (version ${appraisals.criteria?.version}). Its name, dates and criteria never change.`"
            size="tall"
            :confirm-label="cycleForm.processing ? 'Opening…' : 'Open cycle'"
            :busy="cycleForm.processing"
            :confirm-disabled="cycleMissing !== ''"
            :confirm-disabled-reason="cycleMissing"
            data-test="cycle-sheet"
            @update:open="(open: boolean) => (cycleForm.open = open)"
            @confirm="saveCycle"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Name"
                required
                :error="errors.name"
            >
                <input
                    :id="id"
                    v-model="cycleForm.name"
                    maxlength="80"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2DateField
                v-model="cycleForm.start"
                label="First day"
                required
                :error="errors.period_start"
            />
            <V2DateField
                v-model="cycleForm.end"
                label="Last day"
                required
                :min="cycleForm.start || undefined"
                :error="errors.period_end"
            />
            <fieldset class="flex flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Staff to appraise <span aria-hidden="true">*</span>
                </legend>
                <label
                    class="flex min-h-11 items-center gap-2 text-sm font-medium"
                >
                    <input
                        type="checkbox"
                        class="size-4"
                        :checked="allChosen"
                        @change="toggleAll"
                    />
                    Everyone ({{ appraisals.workers.length }})
                </label>
                <label
                    v-for="w in appraisals.workers"
                    :key="w.id"
                    class="flex min-h-11 items-center gap-2 text-sm"
                >
                    <input
                        v-model="cycleForm.workers"
                        type="checkbox"
                        :value="w.id"
                        class="size-4"
                    />
                    {{ w.name }}
                </label>
                <p class="text-xs text-muted-foreground">
                    Staff with an open staff file and an active account. Locums
                    are not appraised in KPOne.
                </p>
                <p
                    v-if="errors.worker_ids"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.worker_ids }}
                </p>
            </fieldset>
        </V2Dialog>

        <!-- Close a cycle -->
        <V2Dialog
            :open="closing !== null"
            title="Close this cycle?"
            :description="
                closing
                    ? `${closing.name}. ${closing.draft === 1 ? '1 appraisal was' : `${closing.draft} appraisals were`} never sent and will stay unsent; they can no longer be rated. Sent appraisals can still take comments and be signed off. This cannot be undone.`
                    : ''
            "
            confirm-label="Close cycle"
            cancel-label="Keep open"
            tone="danger"
            :busy="closeBusy"
            @update:open="(open: boolean) => !open && (closing = null)"
            @confirm="sendClose"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
