<script setup lang="ts">
// HR-22f "Free times" tab on /hr/open-shifts. A linked locum offers times they are free; a roster planner uses one as a
// draft roster shift inside the offered time, or declines it. Nothing here reaches the roster until the week is approved.
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useFreeTimes } from '@/composables/hr/useFreeTimes';
import type { FreeTimesProps, Option } from '@/composables/hr/useFreeTimes';

const props = defineProps<{
    freeTimes: FreeTimesProps;
    sessions: Option[];
    today: string;
}>();

const {
    errors,
    busy,
    tone,
    offer,
    atCap,
    startOffer,
    offerMissing,
    sendOffer,
    withdrawing,
    withdraw,
    using,
    startUse,
    useRooms,
    sendUse,
    declining,
    decline,
} = useFreeTimes(props);
</script>

<template>
    <div class="flex flex-col gap-4" data-test="free-times">
        <ErrorState
            v-if="
                errors.availability &&
                !offer.open &&
                !using.item &&
                !declining.item &&
                !withdrawing.item
            "
            :message="errors.availability"
        />

        <!-- A locum: offer a free time, and their own offers -->
        <section
            v-if="freeTimes.canPropose"
            class="flex flex-col gap-3"
            aria-labelledby="free-times-mine-heading"
        >
            <div
                class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="min-w-0">
                    <h2 id="free-times-mine-heading" class="font-semibold">
                        My free times
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Tell HR when you are free to work. HR may use part of
                        the time; you see it on My roster once the week is
                        approved.
                    </p>
                </div>
                <Button
                    class="max-lg:min-h-11"
                    :disabled="
                        !freeTimes.linked ||
                        atCap ||
                        freeTimes.branchOptions.length === 0
                    "
                    data-test="free-time-new"
                    @click="startOffer"
                    >Offer a free time</Button
                >
            </div>
            <p
                v-if="atCap"
                class="text-sm text-muted-foreground"
                data-test="free-time-cap"
            >
                You have {{ freeTimes.maxWaiting }} free times waiting. Withdraw
                one to offer another.
            </p>
            <p
                v-else-if="
                    freeTimes.linked && freeTimes.branchOptions.length === 0
                "
                class="text-sm text-muted-foreground"
            >
                You are not assigned to a branch yet. Ask HR.
            </p>
            <EmptyState
                v-if="freeTimes.mine.length === 0"
                title="You have not offered a free time."
                description="Use Offer a free time when you can take a shift."
            />
            <ul
                v-else
                class="divide-y rounded-xl border bg-card"
                data-test="free-time-mine"
            >
                <li
                    v-for="f in freeTimes.mine"
                    :key="f.id"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="min-w-0">
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            <span>{{ f.when }}</span>
                            <StatusBadge
                                :status="f.status"
                                :label="
                                    f.status === 'used' ? 'Used' : f.statusLabel
                                "
                                :tone="tone(f.status)"
                            />
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ f.branch }}
                        </p>
                        <p
                            v-if="f.status === 'used'"
                            class="text-sm text-muted-foreground"
                        >
                            {{ f.statusLabel }}
                        </p>
                        <p
                            v-if="f.note"
                            class="text-sm break-words text-muted-foreground"
                        >
                            Note: {{ f.note }}
                        </p>
                    </div>
                    <Button
                        v-if="f.status === 'waiting'"
                        variant="outline"
                        class="max-lg:min-h-11"
                        :aria-label="`Withdraw your free time on ${f.when}`"
                        data-test="free-time-withdraw"
                        @click="withdrawing.item = f"
                        >Withdraw</Button
                    >
                </li>
            </ul>
        </section>

        <!-- HR: free times waiting for a decision, then the latest answered -->
        <template v-if="freeTimes.canDecide">
            <section
                class="flex flex-col gap-3"
                aria-labelledby="free-times-waiting-heading"
            >
                <div class="min-w-0">
                    <h2 id="free-times-waiting-heading" class="font-semibold">
                        Waiting for a decision
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Use a free time to add a draft shift inside it, or
                        decline it. Approve the roster week as usual.
                    </p>
                </div>
                <EmptyState
                    v-if="freeTimes.waiting.length === 0"
                    title="No free times are waiting."
                    description="When a locum offers one, it shows here."
                />
                <ul
                    v-else
                    class="divide-y rounded-xl border bg-card"
                    data-test="free-time-waiting"
                >
                    <li
                        v-for="f in freeTimes.waiting"
                        :key="f.id"
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">{{ f.locum }}</p>
                            <p class="text-sm">{{ f.when }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ f.branch }}
                            </p>
                            <p
                                v-if="f.note"
                                class="text-sm break-words text-muted-foreground"
                            >
                                Note: {{ f.note }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                class="max-lg:min-h-11"
                                :aria-label="`Use ${f.locum}'s free time on ${f.when}`"
                                data-test="free-time-use"
                                @click="startUse(f)"
                                >Use</Button
                            >
                            <Button
                                variant="outline"
                                class="max-lg:min-h-11"
                                :aria-label="`Decline ${f.locum}'s free time on ${f.when}`"
                                data-test="free-time-decline"
                                @click="
                                    declining.item = f;
                                    declining.reason = '';
                                "
                                >Decline</Button
                            >
                        </div>
                    </li>
                </ul>
            </section>
            <section
                v-if="freeTimes.answered.length > 0"
                class="flex flex-col gap-3"
                aria-labelledby="free-times-answered-heading"
            >
                <h2 id="free-times-answered-heading" class="font-semibold">
                    Answered
                </h2>
                <ul
                    class="divide-y rounded-xl border bg-card"
                    data-test="free-time-answered"
                >
                    <li
                        v-for="f in freeTimes.answered"
                        :key="f.id"
                        class="flex flex-col gap-1 p-4"
                    >
                        <p
                            class="flex flex-wrap items-center gap-2 font-medium"
                        >
                            <span>{{ f.locum }}</span>
                            <StatusBadge
                                :status="f.status"
                                :label="f.statusLabel"
                                :tone="tone(f.status)"
                            />
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ f.when }} · {{ f.branch }}
                        </p>
                    </li>
                </ul>
            </section>
        </template>

        <!-- Withdraw -->
        <OperationalConfirmDialog
            :open="withdrawing.item !== null"
            title="Withdraw this free time?"
            :description="`HR will no longer see your free time on ${withdrawing.item?.when ?? ''}.`"
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="busy.id === withdrawing.item?.id"
            :error="errors.availability"
            @update:open="(v: boolean) => !v && (withdrawing.item = null)"
            @confirm="withdraw"
        />

        <!-- Decline with a reason -->
        <OperationalConfirmDialog
            :open="declining.item !== null"
            :title="`Decline ${declining.item?.locum ?? ''}'s free time?`"
            description="The locum sees that it was not used, with the reason."
            confirm-label="Decline"
            processing-label="Declining…"
            :processing="busy.id === declining.item?.id"
            :error="errors.reason ?? errors.availability"
            @update:open="(v: boolean) => !v && (declining.item = null)"
            @confirm="decline"
        >
            <div class="flex flex-col gap-2">
                <Label id="free-time-decline-label">Reason</Label>
                <OperationalSelect
                    :model-value="declining.reason"
                    :options="freeTimes.declineReasons"
                    label="Reason"
                    labelledby="free-time-decline-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (declining.reason = String(v))"
                />
            </div>
        </OperationalConfirmDialog>

        <!-- Offer a free time -->
        <Sheet v-model:open="offer.open">
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="free-time-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Offer a free time</SheetTitle>
                    <SheetDescription
                        >From today up to {{ freeTimes.maxDaysAhead }} days
                        ahead. HR may use all or part of it.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-4"
                    @submit.prevent="sendOffer"
                >
                    <ErrorState
                        v-if="errors.availability"
                        :message="errors.availability"
                    />
                    <div class="flex flex-col gap-2">
                        <Label id="free-time-branch-label">Branch</Label>
                        <OperationalSelect
                            :model-value="offer.branch"
                            :options="freeTimes.branchOptions"
                            label="Branch"
                            labelledby="free-time-branch-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (offer.branch = String(v))
                            "
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="free-time-date">Date</Label>
                        <DateField
                            id="free-time-date"
                            v-model="offer.date"
                            :min="today"
                            :aria-invalid="errors.date ? 'true' : undefined"
                        />
                        <InputError :message="errors.date" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="free-time-start">From</Label>
                            <Input
                                id="free-time-start"
                                v-model="offer.start_time"
                                type="time"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.start_time ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.start_time" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="free-time-end">Until</Label>
                            <Input
                                id="free-time-end"
                                v-model="offer.end_time"
                                type="time"
                                class="max-lg:min-h-11"
                                aria-describedby="free-time-end-help"
                                :aria-invalid="
                                    errors.end_time ? 'true' : undefined
                                "
                            />
                            <p
                                id="free-time-end-help"
                                class="text-xs text-muted-foreground"
                            >
                                If the end is earlier than the start, it ends
                                the next day.
                            </p>
                            <InputError :message="errors.end_time" />
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="free-time-note"
                            >Note for HR (optional)</Label
                        >
                        <Textarea
                            id="free-time-note"
                            v-model="offer.note"
                            maxlength="200"
                            rows="2"
                            aria-describedby="free-time-note-help"
                            :aria-invalid="errors.note ? 'true' : undefined"
                        />
                        <p
                            id="free-time-note-help"
                            class="text-xs text-muted-foreground"
                        >
                            Up to 200 characters. Only you and HR see it. Do not
                            write patient details.
                        </p>
                        <InputError :message="errors.note" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="
                                offer.processing || offerMissing !== null
                            "
                            data-test="free-time-save"
                            >{{
                                offer.processing ? 'Sending…' : 'Send to HR'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="offer.open = false"
                            >Cancel</Button
                        >
                    </div>
                    <p
                        v-if="offerMissing"
                        class="text-sm text-muted-foreground"
                    >
                        {{ offerMissing }}
                    </p>
                </form>
            </SheetContent>
        </Sheet>

        <!-- HR uses a free time -->
        <Sheet
            :open="using.item !== null"
            @update:open="(v: boolean) => !v && (using.item = null)"
        >
            <SheetContent
                side="right"
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="free-time-use-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Use this free time</SheetTitle>
                    <SheetDescription
                        >{{ using.item?.locum }} is free
                        {{ using.item?.when }} at {{ using.item?.branch }}. The
                        shift must sit inside that time and joins the branch's
                        next draft roster week.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-4"
                    @submit.prevent="sendUse"
                >
                    <ErrorState
                        v-if="errors.availability"
                        :message="errors.availability"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="free-time-use-start">Start time</Label>
                            <Input
                                id="free-time-use-start"
                                v-model="using.start_time"
                                type="time"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.start_time ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.start_time" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="free-time-use-end">End time</Label>
                            <Input
                                id="free-time-use-end"
                                v-model="using.end_time"
                                type="time"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.end_time ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.end_time" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label id="free-time-use-session-label"
                                >Session</Label
                            >
                            <OperationalSelect
                                :model-value="using.session_type"
                                :options="sessions"
                                label="Session"
                                labelledby="free-time-use-session-label"
                                trigger-class="max-lg:min-h-11"
                                @update:model-value="
                                    (v) => (using.session_type = String(v))
                                "
                            />
                            <InputError :message="errors.session_type" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="free-time-use-break"
                                >Break (minutes)</Label
                            >
                            <Input
                                id="free-time-use-break"
                                v-model="using.break_minutes"
                                type="number"
                                min="0"
                                max="180"
                                inputmode="numeric"
                                class="max-lg:min-h-11"
                                :aria-invalid="
                                    errors.break_minutes ? 'true' : undefined
                                "
                            />
                            <InputError :message="errors.break_minutes" />
                        </div>
                    </div>
                    <div v-if="useRooms.length > 0" class="flex flex-col gap-2">
                        <Label id="free-time-use-room-label"
                            >Room (optional)</Label
                        >
                        <OperationalSelect
                            :model-value="using.room"
                            :options="[
                                { value: '', label: 'No room' },
                                ...useRooms,
                            ]"
                            label="Room"
                            labelledby="free-time-use-room-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (using.room = String(v))
                            "
                        />
                        <InputError :message="errors.branch_room_id" />
                    </div>
                    <div
                        v-if="using.item?.takesStation"
                        class="flex flex-col gap-2"
                    >
                        <Label id="free-time-use-station-label"
                            >Station (optional)</Label
                        >
                        <OperationalSelect
                            :model-value="using.station"
                            :options="[
                                { value: '', label: 'No station' },
                                ...freeTimes.stations,
                            ]"
                            label="Station"
                            labelledby="free-time-use-station-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (using.station = String(v))
                            "
                        />
                        <InputError :message="errors.station" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="using.processing"
                            data-test="free-time-use-save"
                            >{{
                                using.processing
                                    ? 'Adding…'
                                    : 'Add to the draft roster'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="using.item = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
