<script setup lang="ts">
// HR-10c /hr/discipline: restricted discipline cases (Director, HR Manager). The list carries no allegation or note;
// those are shown only on the case page, after the view is recorded. KPOne never ends employment or changes an account.
import { Head, Link } from '@inertiajs/vue3';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
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
import { useDisciplinePage } from '@/composables/hr/useDisciplinePage';
import type { DisciplinePageProps } from '@/composables/hr/useDisciplinePage';

const props = defineProps<DisciplinePageProps>();
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
</script>

<template>
    <Head title="Discipline" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 lg:p-6">
        <PageHeader
            eyebrow="KPOne HR"
            title="Discipline"
            description="Restricted cases: the allegation, show-cause letters, the staff member's reply and one outcome. Cases never show on the staff file, the dashboard or any export. KPOne never ends employment or changes an account."
        >
            <template #actions>
                <Button
                    class="max-lg:min-h-11"
                    data-test="case-new"
                    @click="startCase"
                    >Open a case</Button
                >
            </template>
        </PageHeader>
        <SyntheticNote />
        <ErrorState v-if="failure && !form.open" :message="failure" />

        <OperationalTabs
            :model-value="discipline.tab"
            :tabs="tabs"
            label="Case sections"
            class="min-w-0 max-lg:[&>button]:h-11"
            @update:model-value="selectTab"
        />

        <EmptyState
            v-if="discipline.cases.length === 0"
            :title="
                discipline.tab === 'open'
                    ? 'No open cases.'
                    : 'No closed cases.'
            "
            description="Cases about you, or about someone only a Director may handle, are not listed here."
        />
        <ul
            v-else
            class="divide-y rounded-xl border bg-card"
            data-test="case-list"
        >
            <li
                v-for="c in discipline.cases"
                :key="c.id"
                class="flex flex-wrap items-center justify-between gap-3 p-4"
                data-test="case-row"
            >
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        <span class="break-words">{{ c.person }}</span>
                        <StatusBadge
                            :status="c.status"
                            :label="c.status === 'open' ? 'Open' : 'Closed'"
                            :tone="c.status === 'open' ? 'warning' : 'neutral'"
                        />
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Happened {{ c.incidentOn }} · opened {{ c.openedOn }} ·
                        {{
                            c.entries === 1 ? '1 entry' : `${c.entries} entries`
                        }}
                        <template v-if="c.outcome">
                            · {{ c.outcome }}, {{ c.closedOn }}</template
                        >
                    </p>
                </div>
                <Button as-child variant="outline" class="max-lg:min-h-11">
                    <Link :href="`/hr/discipline/${c.id}`"
                        >Open<span class="sr-only">
                            case about {{ c.person }}</span
                        ></Link
                    >
                </Button>
            </li>
        </ul>

        <Sheet v-model:open="form.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="case-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Open a discipline case</SheetTitle>
                    <SheetDescription
                        >The allegation is kept as written and cannot be edited.
                        Add notes, letters and the reply on the case
                        page.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-4"
                    @submit.prevent="submit"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="flex flex-col gap-2">
                        <Label id="case-worker-label">Staff file</Label>
                        <OperationalSelect
                            :model-value="form.worker"
                            :options="
                                discipline.workers.map((w) => ({
                                    value: w.id,
                                    label: w.name,
                                }))
                            "
                            label="Staff file"
                            labelledby="case-worker-label"
                            placeholder="Choose a staff file"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.worker = String(v))
                            "
                        />
                        <p class="text-sm text-muted-foreground">
                            You cannot open a case about yourself. A Director's
                            or HR Manager's case is opened by a Director.
                        </p>
                        <InputError :message="errors.worker_id" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="case-incident">Date it happened</Label>
                        <DateField
                            id="case-incident"
                            v-model="form.incidentOn"
                            :max="discipline.today"
                            :aria-invalid="!!errors.incident_on"
                        />
                        <InputError :message="errors.incident_on" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="case-allegation">Allegation</Label>
                        <textarea
                            id="case-allegation"
                            v-model="form.allegation"
                            rows="6"
                            :maxlength="discipline.maxAllegation"
                            :class="textareaClass"
                            aria-describedby="case-allegation-help"
                            :aria-invalid="
                                errors.allegation ? 'true' : undefined
                            "
                        ></textarea>
                        <p
                            id="case-allegation-help"
                            class="text-sm text-muted-foreground"
                        >
                            What is alleged, when and where. Write facts only;
                            no patient name, IC or diagnosis.
                            {{ form.allegation.length }} of
                            {{ discipline.maxAllegation }} characters.
                        </p>
                        <InputError :message="errors.allegation" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="
                                form.processing ||
                                !form.worker ||
                                form.allegation.trim().length < 10
                            "
                            data-test="case-save"
                            >{{
                                form.processing ? 'Opening…' : 'Open case'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="form.open = false"
                            >Cancel</Button
                        >
                    </div>
                    <p
                        v-if="
                            !form.worker || form.allegation.trim().length < 10
                        "
                        class="text-sm text-muted-foreground"
                    >
                        Open case: choose a staff file and write the allegation
                        first.
                    </p>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
