<script setup lang="ts">
// HR-11b vaccine fridge log. A record of what a person typed, not a certification. In range, Out of range and Missed are
// worked out by the server when the page opens. Alerts use the danger tone with an icon and words, never colour alone.
import { router, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { dateLabel } from '@/components/hr/records/types';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import type {
    DayView,
    FridgeCard,
    FridgeTabData,
    ReadingView,
} from './fridgeTypes';

defineProps<{ fridge: FridgeTabData; setUpHint: boolean }>();
const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const base = '/clinic-compliance';
const when = (iso: string, zone: string) =>
    new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: zone,
    }).format(new Date(iso));
const goMonth = (month: string) =>
    router.get(base, { tab: 'fridge', month }, { preserveScroll: true });
const numbers = (r: ReadingView) =>
    [
        `${r.temp} °C`,
        r.low !== null ? `lowest ${r.low}` : null,
        r.high !== null ? `highest ${r.high}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
const dayLine = (d: DayView) =>
    d.count === 0
        ? 'No reading'
        : `${d.count} reading${d.count === 1 ? '' : 's'}`;

// ---- Add a reading ----
const reading = reactive({
    open: false,
    fridge: null as FridgeCard | null,
    temp: '',
    low: '',
    high: '',
    reason: '',
    key: '',
    processing: false,
});
const openReading = (fridge: FridgeCard) =>
    Object.assign(reading, {
        open: true,
        fridge,
        temp: '',
        low: '',
        high: '',
        reason: '',
        key: crypto.randomUUID(),
        processing: false,
    });
const sendReading = () => {
    if (!reading.fridge) {
        return;
    }

    reading.processing = true;
    router.post(
        `${base}/readings`,
        {
            fridge_id: reading.fridge.id,
            temp: reading.temp,
            low: reading.low,
            high: reading.high,
            reason: reading.reason,
            idempotency_key: reading.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (reading.open = false),
            onFinish: () => (reading.processing = false),
        },
    );
};

// ---- Record the action taken ----
const review = reactive({
    open: false,
    reading: null as ReadingView | null,
    action: '',
    note: '',
    key: '',
    processing: false,
});
const openReview = (r: ReadingView) =>
    Object.assign(review, {
        open: true,
        reading: r,
        action: '',
        note: '',
        key: crypto.randomUUID(),
        processing: false,
    });
const sendReview = () => {
    if (!review.reading) {
        return;
    }

    review.processing = true;
    router.post(
        `${base}/readings/${review.reading.id}/review`,
        {
            action: review.action,
            note: review.note,
            idempotency_key: review.key,
        },
        {
            preserveScroll: true,
            onSuccess: () => (review.open = false),
            onFinish: () => (review.processing = false),
        },
    );
};

// ---- Mark a reading entered in error ----
const mistake = reactive({
    open: false,
    reading: null as ReadingView | null,
    reason: '',
    key: '',
    processing: false,
});
const openMistake = (r: ReadingView) =>
    Object.assign(mistake, {
        open: true,
        reading: r,
        reason: '',
        key: crypto.randomUUID(),
        processing: false,
    });
const sendMistake = () => {
    if (!mistake.reading) {
        return;
    }

    mistake.processing = true;
    router.post(
        `${base}/readings/${mistake.reading.id}/error`,
        { reason: mistake.reason, idempotency_key: mistake.key },
        {
            preserveScroll: true,
            onSuccess: () => (mistake.open = false),
            onFinish: () => (mistake.processing = false),
        },
    );
};
</script>

<template>
    <section class="flex flex-col gap-4" aria-label="Fridge log">
        <div
            class="flex flex-wrap items-center justify-between gap-2"
            data-test="fridge-month"
        >
            <div class="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    @click="goMonth(fridge.previousMonth)"
                    >Previous month</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    :disabled="!fridge.nextMonth"
                    @click="fridge.nextMonth && goMonth(fridge.nextMonth)"
                    >Next month</Button
                >
            </div>
            <p class="text-sm font-medium">{{ fridge.monthLabel }}</p>
        </div>

        <ErrorState
            v-if="
                errors.compliance &&
                !reading.open &&
                !review.open &&
                !mistake.open
            "
            :message="errors.compliance"
        />

        <div
            v-if="fridge.alertCount > 0"
            class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
            role="status"
            data-test="fridge-alert-banner"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                {{ fridge.alertCount }}
                {{ fridge.alertCount === 1 ? 'reading is' : 'readings are' }}
                out of range and waiting for an action.
            </p>
        </div>

        <EmptyState
            v-if="!fridge.setUp"
            title="No fridges to show."
            :description="
                setUpHint
                    ? 'Add the clinic fridges and their allowed range under Settings.'
                    : 'Ask the Director to add the fridges for your branch.'
            "
        />

        <article
            v-for="f in fridge.fridges"
            :key="f.id"
            class="rounded-lg border bg-card p-4"
            data-test="fridge-card"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold break-words">
                        {{ f.name }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ f.branchName }} · Allowed {{ f.min }} to
                        {{ f.max }} °C · Today {{ f.todayCount }} of
                        {{ f.readingsPerDay }}
                        {{ f.readingsPerDay === 1 ? 'reading' : 'readings' }}
                    </p>
                </div>
                <Button
                    v-if="f.canRecord"
                    type="button"
                    class="min-h-11 lg:min-h-9"
                    data-test="add-reading"
                    @click="openReading(f)"
                    >Add a reading<span class="sr-only">
                        for {{ f.name }}</span
                    ></Button
                >
            </div>

            <ul v-if="f.alerts.length" class="mt-3 flex flex-col gap-2">
                <li
                    v-for="a in f.alerts"
                    :key="a.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
                    data-test="fridge-alert"
                >
                    <span class="flex min-w-0 flex-wrap items-center gap-2">
                        <TriangleAlert
                            class="size-4 shrink-0 text-destructive"
                            aria-hidden="true"
                        />
                        <span class="font-semibold text-destructive"
                            >Out of range</span
                        >
                        <span class="break-words"
                            >{{ numbers(a) }} ·
                            {{ when(a.takenAt, f.timezone) }}</span
                        >
                    </span>
                    <Button
                        v-if="f.canReview"
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        @click="openReview(a)"
                        >Record action<span class="sr-only">
                            for the reading at
                            {{ when(a.takenAt, f.timezone) }}</span
                        ></Button
                    >
                </li>
            </ul>

            <h3
                class="mt-4 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Recent readings
            </h3>
            <p
                v-if="!f.recent.length"
                class="mt-1 text-sm text-muted-foreground"
            >
                No readings this month.
            </p>
            <ul v-else class="mt-1 divide-y">
                <li
                    v-for="r in f.recent"
                    :key="r.id"
                    class="flex flex-col gap-1 py-3 text-sm"
                >
                    <p class="flex flex-wrap items-center gap-2">
                        <StatusBadge
                            :status="
                                r.errorReason
                                    ? 'entered_in_error'
                                    : r.outOfRange
                                      ? 'out_of_range'
                                      : 'in_range'
                            "
                            :label="
                                r.errorReason
                                    ? 'Entered in error'
                                    : r.outOfRange
                                      ? 'Out of range'
                                      : 'In range'
                            "
                            :tone="
                                r.errorReason
                                    ? 'neutral'
                                    : r.outOfRange
                                      ? 'danger'
                                      : 'success'
                            "
                        />
                        <span class="break-words">{{ numbers(r) }}</span>
                        <span
                            v-if="r.errorReason"
                            class="text-xs text-muted-foreground"
                            >· entered in error ({{ r.errorReason }})</span
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ when(r.takenAt, f.timezone) }} · {{ r.enteredBy }}
                        <template v-if="r.reason"> · {{ r.reason }}</template>
                    </p>
                    <p v-if="r.review" class="text-xs break-words">
                        Action: {{ r.review.action
                        }}<template v-if="r.review.note">
                            ({{ r.review.note }})</template
                        >
                        · {{ r.review.by }}, {{ when(r.review.at, f.timezone) }}
                    </p>
                    <div v-if="f.canManage && !r.errorReason">
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 lg:min-h-9"
                            @click="openMistake(r)"
                            >Mark entered in error<span class="sr-only">
                                for the reading at
                                {{ when(r.takenAt, f.timezone) }}</span
                            ></Button
                        >
                    </div>
                </li>
            </ul>

            <h3
                class="mt-4 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Day by day
            </h3>
            <div class="mt-1 overflow-x-auto">
                <table class="w-full min-w-[28rem] text-sm">
                    <caption class="sr-only">
                        Daily readings for
                        {{
                            f.name
                        }},
                        {{
                            fridge.monthLabel
                        }}
                    </caption>
                    <thead>
                        <tr
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Day
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Readings
                            </th>
                            <th scope="col" class="py-2 pr-3 font-medium">
                                Lowest to highest
                            </th>
                            <th scope="col" class="py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="d in f.days"
                            :key="d.date"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-3 whitespace-nowrap">
                                {{ dateLabel(d.date) }}
                            </td>
                            <td class="py-2 pr-3">{{ dayLine(d) }}</td>
                            <td
                                class="py-2 pr-3 whitespace-nowrap tabular-nums"
                            >
                                {{
                                    d.lowest !== null
                                        ? `${d.lowest} to ${d.highest} °C`
                                        : ''
                                }}
                            </td>
                            <td class="py-2">
                                <span class="flex flex-wrap items-center gap-1">
                                    <StatusBadge
                                        v-if="d.outOfRange"
                                        status="out_of_range"
                                        label="Out of range"
                                        tone="danger"
                                    />
                                    <StatusBadge
                                        v-if="d.missed"
                                        status="missed"
                                        label="Missed"
                                        tone="warning"
                                    />
                                    <span
                                        v-if="!d.outOfRange && !d.missed"
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            d.count > 0
                                                ? 'Done'
                                                : d.isToday
                                                  ? 'Not yet'
                                                  : ''
                                        }}</span
                                    >
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>

        <!-- Add a reading -->
        <Sheet
            :open="reading.open"
            @update:open="(v: boolean) => (reading.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="reading-sheet"
            >
                <SheetHeader>
                    <SheetTitle
                        >Add a reading{{
                            reading.fridge ? ` for ${reading.fridge.name}` : ''
                        }}</SheetTitle
                    >
                    <SheetDescription>
                        The time is saved automatically. Type the numbers from
                        the fridge display.
                        <template v-if="reading.fridge"
                            >Allowed {{ reading.fridge.min }} to
                            {{ reading.fridge.max }} °C.</template
                        >
                    </SheetDescription>
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendReading"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <InputError :message="errors.fridge_id" />
                    <div class="grid gap-1.5">
                        <Label for="reading-temp">Temperature now (°C) *</Label>
                        <Input
                            id="reading-temp"
                            v-model="reading.temp"
                            inputmode="decimal"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.temp"
                        />
                        <InputError :message="errors.temp" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="reading-low"
                            >Lowest since the last reading (°C)</Label
                        >
                        <Input
                            id="reading-low"
                            v-model="reading.low"
                            inputmode="decimal"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.low"
                        />
                        <InputError :message="errors.low" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="reading-high"
                            >Highest since the last reading (°C)</Label
                        >
                        <Input
                            id="reading-high"
                            v-model="reading.high"
                            inputmode="decimal"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.high"
                        />
                        <InputError :message="errors.high" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="reading-reason"
                            ><span id="reading-reason-label"
                                >Reason, if there is one</span
                            ></Label
                        >
                        <OperationalSelect
                            id="reading-reason"
                            v-model="reading.reason"
                            labelledby="reading-reason-label"
                            trigger-class="max-lg:h-11"
                            label="Reason"
                            placeholder="None"
                            :options="fridge.reasons"
                            :invalid="!!errors.reason"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="reading.processing"
                        >Save reading</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <!-- Record the action taken -->
        <Sheet
            :open="review.open"
            @update:open="(v: boolean) => (review.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="review-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Record the action taken</SheetTitle>
                    <SheetDescription>
                        What was done about this reading. The reading itself is
                        never changed. Keep any note short and do not name a
                        person's fault.
                    </SheetDescription>
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendReview"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="review-action"
                            ><span id="review-action-label"
                                >What was done *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="review-action"
                            v-model="review.action"
                            labelledby="review-action-label"
                            trigger-class="max-lg:h-11"
                            label="What was done"
                            placeholder="Choose"
                            :options="fridge.actions"
                            :invalid="!!errors.action"
                        />
                        <InputError :message="errors.action" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="review-note"
                            >Note{{
                                review.action === 'other' ? ' *' : ' (optional)'
                            }}</Label
                        >
                        <Input
                            id="review-note"
                            v-model="review.note"
                            maxlength="200"
                            class="max-lg:h-11"
                            :aria-invalid="!!errors.note"
                        />
                        <InputError :message="errors.note" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="review.processing"
                        >Save action</Button
                    >
                </form>
            </SheetContent>
        </Sheet>

        <!-- Entered in error -->
        <Sheet
            :open="mistake.open"
            @update:open="(v: boolean) => (mistake.open = v)"
        >
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="reading-mistake-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Mark entered in error</SheetTitle>
                    <SheetDescription
                        >The reading stays in the record. It no longer counts
                        for the day or raises an alert.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="sendMistake"
                >
                    <ErrorState
                        v-if="errors.compliance"
                        :message="errors.compliance"
                    />
                    <div class="grid gap-1.5">
                        <Label for="reading-mistake-reason"
                            ><span id="reading-mistake-reason-label"
                                >Reason *</span
                            ></Label
                        >
                        <OperationalSelect
                            id="reading-mistake-reason"
                            v-model="mistake.reason"
                            labelledby="reading-mistake-reason-label"
                            trigger-class="max-lg:h-11"
                            label="Reason"
                            placeholder="Choose"
                            :options="fridge.correctionReasons"
                            :invalid="!!errors.reason"
                        />
                        <InputError :message="errors.reason" />
                    </div>
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="mistake.processing"
                        >Mark entered in error</Button
                    >
                </form>
            </SheetContent>
        </Sheet>
    </section>
</template>
