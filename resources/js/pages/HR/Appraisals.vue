<script setup lang="ts">
// HR-10b /hr/appraisals: your own appraisals (everyone), and appraisal cycles and criteria (Director, HR Manager).
// No ranking and no total score: each criterion is rated on its own.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';

type Tab = 'mine' | 'cycles' | 'criteria';
type Status = 'draft' | 'sent' | 'signed_off';
type Criterion = { title: string; description: string };
type Cycle = {
    id: string;
    name: string;
    period: string;
    status: 'open' | 'closed';
    lockVersion: number;
    draft: number;
    sent: number;
    signedOff: number;
};
type Person = {
    id: string;
    name: string;
    status: Status;
    statusLabel: string;
    commented: boolean;
    refusal: string | null;
};

const props = defineProps<{
    appraisals: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        mine: {
            id: string;
            cycle: string;
            period: string;
            status: Status;
            statusLabel: string;
            sentOn: string | null;
            commented: boolean;
        }[];
        criteria: { version: number; items: Criterion[] } | null;
        maxCriteria: number;
        cycles: Cycle[];
        cycle: {
            id: string;
            name: string;
            period: string;
            status: 'open' | 'closed';
            people: Person[];
        } | null;
        workers: { id: string; name: string }[];
        today: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(
    () =>
        errors.value.appraisal ??
        errors.value.records ??
        errors.value.cycle ??
        errors.value.criteria,
);
const tabLabels: Record<Tab, string> = {
    mine: 'My appraisals',
    cycles: 'Cycles',
    criteria: 'Criteria',
};
const tabs = computed(() =>
    props.appraisals.tabs.map((value) => ({ value, label: tabLabels[value] })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/appraisals', { tab: value }, { preserveScroll: true });
const tone = (status: Status) =>
    status === 'signed_off'
        ? 'success'
        : status === 'sent'
          ? 'accent'
          : 'neutral';

// ---- Criteria ----
const criteriaForm = reactive({
    items: [] as Criterion[],
    key: '',
    processing: false,
    editing: false,
});
const startCriteria = () => {
    Object.assign(criteriaForm, {
        items: (props.appraisals.criteria?.items ?? []).map((c) => ({ ...c })),
        key: crypto.randomUUID(),
        editing: true,
    });

    if (!criteriaForm.items.length) {
        criteriaForm.items.push({ title: '', description: '' });
    }
};
const addCriterion = () =>
    criteriaForm.items.push({ title: '', description: '' });
const removeCriterion = (i: number) => criteriaForm.items.splice(i, 1);
const saveCriteria = () => {
    criteriaForm.processing = true;
    router.post(
        '/hr/appraisals/criteria',
        { criteria: criteriaForm.items, idempotency_key: criteriaForm.key },
        {
            preserveScroll: true,
            onSuccess: () => (criteriaForm.editing = false),
            onFinish: () => (criteriaForm.processing = false),
        },
    );
};

// ---- Cycles ----
const cycleForm = reactive({
    open: false,
    name: '',
    start: '',
    end: '',
    workers: [] as string[],
    key: '',
    processing: false,
});
const startCycle = () =>
    Object.assign(cycleForm, {
        open: true,
        name: '',
        start: '',
        end: '',
        workers: [],
        key: crypto.randomUUID(),
    });
const allChosen = computed(
    () =>
        props.appraisals.workers.length > 0 &&
        cycleForm.workers.length === props.appraisals.workers.length,
);
const toggleAll = () =>
    (cycleForm.workers = allChosen.value
        ? []
        : props.appraisals.workers.map((w) => w.id));
const saveCycle = () => {
    cycleForm.processing = true;
    router.post(
        '/hr/appraisals/cycles',
        {
            name: cycleForm.name,
            period_start: cycleForm.start,
            period_end: cycleForm.end,
            worker_ids: cycleForm.workers,
            idempotency_key: cycleForm.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (cycleForm.open = false),
            onFinish: () => (cycleForm.processing = false),
        },
    );
};
const openCycle = (id: string) =>
    router.get(
        '/hr/appraisals',
        { tab: 'cycles', cycle: id },
        { preserveScroll: true },
    );
const closing = ref<Cycle | null>(null);
const closeKey = ref('');
const closeBusy = ref(false);
const askClose = (cycle: Cycle) => {
    closing.value = cycle;
    closeKey.value = crypto.randomUUID();
};
const sendClose = () => {
    const cycle = closing.value;

    if (!cycle) {
        return;
    }

    closeBusy.value = true;
    router.post(
        `/hr/appraisals/cycles/${cycle.id}/close`,
        {
            expected_lock_version: cycle.lockVersion,
            idempotency_key: closeKey.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => (closing.value = null),
            onFinish: () => (closeBusy.value = false),
        },
    );
};
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
</script>

<template>
    <Head title="Appraisals" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Appraisals"
                :description="
                    appraisals.canManage
                        ? 'Enter the clinic\'s criteria, open a cycle for chosen staff, and rate each criterion from 1 to 5. A Director signs each appraisal off. There is no ranking and no total score.'
                        : 'Your appraisals from HR. Read each one and add your own comment.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="appraisals.tab"
            :tabs="tabs"
            label="Appraisal sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="
                failure && !cycleForm.open && !criteriaForm.editing && !closing
            "
            :message="failure"
        />

        <!-- My appraisals -->
        <section
            v-if="appraisals.tab === 'mine'"
            aria-labelledby="my-appraisals-title"
            class="flex flex-col gap-3"
            data-test="my-appraisals"
        >
            <h2 id="my-appraisals-title" class="sr-only">My appraisals</h2>
            <EmptyState
                v-if="!appraisals.mine.length"
                title="No appraisals for you yet."
                description="When HR sends you an appraisal, it appears here."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="a in appraisals.mine"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    data-test="my-appraisal"
                >
                    <div class="min-w-0">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <p class="font-medium break-words">{{ a.cycle }}</p>
                            <StatusBadge
                                :status="a.status"
                                :label="a.statusLabel"
                                :tone="tone(a.status)"
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
                    <Button as-child variant="outline" class="max-lg:min-h-11">
                        <Link
                            :href="`/hr/appraisals/${a.id}`"
                            :aria-label="`Open ${a.cycle}`"
                            >Open</Link
                        >
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Cycles -->
        <section
            v-if="appraisals.tab === 'cycles'"
            aria-labelledby="cycles-title"
            class="flex flex-col gap-3"
            data-test="appraisal-cycles"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="cycles-title" class="text-base font-semibold">
                    Cycles
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        v-if="!appraisals.criteria"
                        id="cycle-hint"
                        class="text-sm text-muted-foreground"
                        >Enter the criteria first.</span
                    >
                    <Button
                        class="max-lg:min-h-11"
                        :disabled="!appraisals.criteria"
                        :aria-describedby="
                            appraisals.criteria ? undefined : 'cycle-hint'
                        "
                        data-test="cycle-new"
                        @click="startCycle"
                        >Open a cycle</Button
                    >
                </div>
            </div>
            <EmptyState
                v-if="!appraisals.cycles.length"
                title="No cycles yet."
                description="Open a cycle with its dates and the staff to appraise."
            />
            <ul v-else class="flex flex-col divide-y rounded-xl border bg-card">
                <li
                    v-for="c in appraisals.cycles"
                    :key="c.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    data-test="appraisal-cycle"
                >
                    <div class="min-w-0">
                        <div
                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        >
                            <p class="font-medium break-words">{{ c.name }}</p>
                            <StatusBadge
                                :status="c.status"
                                :label="c.status === 'open' ? 'Open' : 'Closed'"
                                :tone="
                                    c.status === 'open' ? 'accent' : 'neutral'
                                "
                            />
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ c.period }} · {{ c.draft }} not sent ·
                            {{ c.sent }} sent · {{ c.signedOff }} signed off
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Show people in ${c.name}`"
                            @click="openCycle(c.id)"
                            >People</Button
                        >
                        <Button
                            v-if="c.status === 'open'"
                            variant="outline"
                            class="max-lg:min-h-11"
                            :aria-label="`Close ${c.name}`"
                            @click="askClose(c)"
                            >Close cycle</Button
                        >
                    </div>
                </li>
            </ul>

            <section
                v-if="appraisals.cycle"
                aria-labelledby="cycle-people-title"
                class="flex flex-col gap-2"
                data-test="cycle-people"
            >
                <h3 id="cycle-people-title" class="text-sm font-semibold">
                    {{ appraisals.cycle.name }} · {{ appraisals.cycle.period }}
                </h3>
                <ul class="flex flex-col divide-y rounded-xl border bg-card">
                    <li
                        v-for="p in appraisals.cycle.people"
                        :key="p.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                        data-test="cycle-person"
                    >
                        <div class="min-w-0">
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <p class="font-medium break-words">
                                    {{ p.name }}
                                </p>
                                <StatusBadge
                                    :status="p.status"
                                    :label="p.statusLabel"
                                    :tone="tone(p.status)"
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
                        <Button
                            v-if="!p.refusal"
                            as-child
                            variant="outline"
                            class="max-lg:min-h-11"
                        >
                            <Link
                                :href="`/hr/appraisals/${p.id}`"
                                :aria-label="`Open the appraisal of ${p.name}`"
                                >{{
                                    p.status === 'draft' &&
                                    appraisals.cycle.status === 'open'
                                        ? 'Rate'
                                        : 'Open'
                                }}</Link
                            >
                        </Button>
                    </li>
                </ul>
            </section>
        </section>

        <!-- Criteria -->
        <section
            v-if="appraisals.tab === 'criteria'"
            aria-labelledby="criteria-title"
            class="flex flex-col gap-3"
            data-test="appraisal-criteria"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="criteria-title" class="text-base font-semibold">
                    Criteria<template v-if="appraisals.criteria">
                        · version {{ appraisals.criteria.version }}</template
                    >
                </h2>
                <Button
                    v-if="!criteriaForm.editing"
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="criteria-edit"
                    @click="startCriteria"
                    >{{
                        appraisals.criteria
                            ? 'Change criteria'
                            : 'Enter criteria'
                    }}</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                KPOne ships no criteria. Each save is a new version; a cycle
                keeps the version it was opened with.
            </p>
            <template v-if="!criteriaForm.editing">
                <EmptyState
                    v-if="!appraisals.criteria"
                    title="No criteria yet."
                    :description="`Enter up to ${appraisals.maxCriteria} criteria, for example teamwork or punctuality.`"
                />
                <ol
                    v-else
                    class="flex list-decimal flex-col gap-2 rounded-xl border bg-card py-3 pr-4 pl-9"
                >
                    <li v-for="(c, i) in appraisals.criteria.items" :key="i">
                        <p class="font-medium">{{ c.title }}</p>
                        <p
                            v-if="c.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ c.description }}
                        </p>
                    </li>
                </ol>
            </template>
            <form
                v-else
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                data-test="criteria-form"
                @submit.prevent="saveCriteria"
            >
                <ErrorState v-if="failure" :message="failure" />
                <fieldset
                    v-for="(c, i) in criteriaForm.items"
                    :key="i"
                    class="flex flex-col gap-2 rounded-lg border p-3"
                >
                    <legend class="px-1 text-sm font-medium">
                        Criterion {{ i + 1 }}
                    </legend>
                    <div class="grid gap-1.5">
                        <Label :for="`crit-title-${i}`">Title *</Label>
                        <Input
                            :id="`crit-title-${i}`"
                            v-model="c.title"
                            maxlength="80"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors[`criteria.${i}.title`]"
                        />
                        <InputError :message="errors[`criteria.${i}.title`]" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`crit-desc-${i}`"
                            >What it means (optional)</Label
                        >
                        <textarea
                            :id="`crit-desc-${i}`"
                            v-model="c.description"
                            rows="2"
                            maxlength="300"
                            :class="textareaClass"
                            :aria-invalid="
                                errors[`criteria.${i}.description`]
                                    ? 'true'
                                    : undefined
                            "
                        ></textarea>
                        <InputError
                            :message="errors[`criteria.${i}.description`]"
                        />
                    </div>
                    <Button
                        v-if="criteriaForm.items.length > 1"
                        type="button"
                        variant="outline"
                        class="self-start max-lg:min-h-11"
                        :aria-label="`Remove criterion ${i + 1}`"
                        @click="removeCriterion(i)"
                        >Remove</Button
                    >
                </fieldset>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="
                            criteriaForm.items.length >= appraisals.maxCriteria
                        "
                        @click="addCriterion"
                        >Add a criterion</Button
                    >
                    <span class="text-sm text-muted-foreground"
                        >{{ criteriaForm.items.length }} of
                        {{ appraisals.maxCriteria }}</span
                    >
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="criteriaForm.processing"
                        data-test="criteria-save"
                        >{{
                            criteriaForm.processing
                                ? 'Saving…'
                                : 'Save criteria'
                        }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="criteriaForm.editing = false"
                        >Cancel</Button
                    >
                </div>
            </form>
        </section>

        <!-- Open a cycle -->
        <Sheet v-model:open="cycleForm.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="cycle-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Open a cycle</SheetTitle>
                    <SheetDescription
                        >It uses the current criteria (version
                        {{ appraisals.criteria?.version }}). Its name, dates and
                        criteria never change.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="saveCycle"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="cycle-name">Name *</Label>
                        <Input
                            id="cycle-name"
                            v-model="cycleForm.name"
                            maxlength="80"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.name"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="cycle-start">First day *</Label>
                        <DateField
                            id="cycle-start"
                            v-model="cycleForm.start"
                            :aria-invalid="!!errors.period_start"
                        />
                        <InputError :message="errors.period_start" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="cycle-end">Last day *</Label>
                        <DateField
                            id="cycle-end"
                            v-model="cycleForm.end"
                            :min="cycleForm.start || undefined"
                            :aria-invalid="!!errors.period_end"
                        />
                        <InputError :message="errors.period_end" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Staff to appraise *
                        </legend>
                        <label
                            class="flex min-h-11 items-center gap-2 text-sm font-medium lg:min-h-0"
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
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
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
                            Staff with an open staff file and an active account.
                            Locums are not appraised in KPOne.
                        </p>
                        <InputError :message="errors.worker_ids" />
                    </fieldset>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="cycleForm.processing"
                        data-test="cycle-save"
                    >
                        {{ cycleForm.processing ? 'Opening…' : 'Open cycle' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="closing !== null"
            title="Close this cycle?"
            :description="
                closing
                    ? `${closing.name}. ${closing.draft === 1 ? '1 appraisal was' : `${closing.draft} appraisals were`} never sent and will stay unsent; they can no longer be rated. Sent appraisals can still take comments and be signed off.`
                    : ''
            "
            confirm-label="Close cycle"
            cancel-label="Keep open"
            processing-label="Closing…"
            :processing="closeBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (closing = null)"
            @confirm="sendClose"
        />
    </div>
</template>
