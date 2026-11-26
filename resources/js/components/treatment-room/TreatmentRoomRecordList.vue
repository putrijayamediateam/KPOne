<script setup lang="ts">
import { computed } from 'vue';
import TreatmentRoomPagination from '@/components/treatment-room/TreatmentRoomPagination.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/presentation';
import type {
    TreatmentRoomRecord,
    TreatmentRoomRecords,
} from '@/types/treatment-room';

const props = withDefaults(
    defineProps<{
        records: TreatmentRoomRecords;
        timezone: string;
        canCorrect?: boolean;
        busy?: boolean;
    }>(),
    { canCorrect: false, busy: false },
);
const emit = defineEmits<{
    correct: [record: TreatmentRoomRecord];
    void: [record: TreatmentRoomRecord];
    currentPage: [page: number];
    historyPage: [page: number];
}>();
const historical = computed(() =>
    props.records.history.filter((record) => record.status !== 'current'),
);
const supersededBy = (record: TreatmentRoomRecord) =>
    record.successorCorrectionCode;
const correctionLabel = (code: TreatmentRoomRecord['correctionCode']) =>
    code === 'wrong_procedure'
        ? 'Wrong procedure'
        : code === 'wrong_outcome'
          ? 'Wrong outcome'
          : null;
</script>

<template>
    <div class="space-y-4" data-testid="treatment-room-records">
        <p
            v-if="records.current.length === 0"
            class="text-sm text-muted-foreground"
        >
            No current treatment-room record.
        </p>
        <article
            v-for="record in records.current"
            :key="record.id"
            class="space-y-2 rounded-lg border border-border bg-card p-4"
            data-testid="treatment-room-current"
        >
            <h3 class="font-semibold">{{ record.procedureLabel }}</h3>
            <p
                class="text-sm font-medium"
                :class="
                    record.outcomeCode === 'not_successful'
                        ? 'text-warning-strong'
                        : 'text-foreground'
                "
            >
                {{ record.outcomeLabel
                }}<template v-if="record.reasonLabel"
                    >: {{ record.reasonLabel }}</template
                >
            </p>
            <p
                v-if="record.correctionCode"
                class="text-[12.5px] text-muted-foreground"
            >
                Correction: {{ correctionLabel(record.correctionCode) }}.
                Earlier evidence is retained in history.
            </p>
            <dl class="grid gap-1 text-[12.5px] text-muted-foreground">
                <div>
                    <dt class="inline">Started:</dt>
                    <dd class="inline">
                        {{ formatDateTime(record.startedAt, timezone) }}
                    </dd>
                </div>
                <div>
                    <dt class="inline">Finished:</dt>
                    <dd class="inline">
                        {{ formatDateTime(record.finishedAt, timezone) }}
                    </dd>
                </div>
                <div>
                    <dt class="inline">Recorded:</dt>
                    <dd class="inline">
                        {{ formatDateTime(record.recordedAt, timezone) }} ·
                        {{ record.recordedBy ?? 'Staff member' }}
                    </dd>
                </div>
            </dl>
            <div v-if="canCorrect" class="flex flex-wrap gap-2 pt-1">
                <Button
                    variant="outline"
                    class="h-9 max-lg:h-11"
                    :disabled="busy"
                    @click="emit('correct', record)"
                    >Correct record</Button
                >
                <Button
                    variant="outline"
                    class="h-9 max-lg:h-11"
                    :disabled="busy"
                    @click="emit('void', record)"
                    >Void: wrong patient</Button
                >
            </div>
        </article>
        <TreatmentRoomPagination
            v-if="records.currentPagination"
            :pagination="records.currentPagination"
            label="Current records"
            :busy="busy"
            @page="emit('currentPage', $event)"
        />
        <details
            v-if="historical.length || records.historyPagination?.lastPage > 1"
            class="rounded-lg border border-border bg-card p-4"
            data-testid="treatment-room-history"
        >
            <summary
                class="flex min-h-11 cursor-pointer items-center font-medium lg:min-h-9"
            >
                Earlier evidence · {{ historical.length }} on this page
            </summary>
            <p
                v-if="historical.length === 0"
                class="mt-3 text-sm text-muted-foreground"
            >
                This history page contains current records only. Check other
                pages for earlier evidence.
            </p>
            <article
                v-for="record in historical"
                :key="record.id"
                class="mt-3 space-y-1 border-t border-border pt-3 text-sm"
            >
                <p class="font-medium">
                    {{
                        record.status === 'voided'
                            ? 'Entered for the wrong patient'
                            : 'Superseded record'
                    }}
                </p>
                <p class="text-muted-foreground line-through">
                    {{ record.procedureLabel }} · {{ record.outcomeLabel
                    }}<template v-if="record.reasonLabel"
                        >: {{ record.reasonLabel }}</template
                    >
                </p>
                <p class="text-[12.5px] text-muted-foreground">
                    {{ formatDateTime(record.recordedAt, timezone) }} ·
                    {{ record.recordedBy ?? 'Staff member' }}
                </p>
                <p v-if="supersededBy(record)" class="text-[12.5px]">
                    Superseded: {{ correctionLabel(supersededBy(record)) }}
                </p>
                <p
                    v-if="record.status === 'voided' && record.voidedAt"
                    class="text-[12.5px]"
                >
                    Voided {{ formatDateTime(record.voidedAt, timezone) }} ·
                    {{ record.voidedBy ?? 'Staff member' }}. The original
                    evidence is retained.
                </p>
            </article>
            <TreatmentRoomPagination
                v-if="records.historyPagination"
                :pagination="records.historyPagination"
                label="History records"
                :busy="busy"
                @page="emit('historyPage', $event)"
            />
        </details>
    </div>
</template>
