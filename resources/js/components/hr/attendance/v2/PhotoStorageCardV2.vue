<script setup lang="ts">
// HR-PHOTO-STORAGE: the read-only "Clock-in photo storage" card on the Version 2 Settings tab (Director, HR Manager). Counts
// only: no names, photos or per-person figures, and no Save. Daniel: a neutral warning chip for the draft retention, the
// earliest delete in the sentence, quantities with their units in tabular figures that never split, and a forecast only
// after three full months.
import V2Chip from '@/components/v2/V2Chip.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import type { PhotoStorageCard } from '../types';

defineProps<{ card: PhotoStorageCard }>();

const count = (n: number) => n.toLocaleString('en-GB');
const plural = (n: number, one: string, many: string) =>
    `${count(n)}\u00A0${n === 1 ? one : many}`;
// "182 MB" with a no-break space, so a figure never leaves its unit.
const glue = (size: string) => size.replace(' ', '\u00A0');
// "three full months", in words (Daniel).
const word = (n: number) =>
    ['no', 'one', 'two', 'three', 'four', 'five', 'six'][n] ?? count(n);
</script>

<template>
    <V2FormCard title="Clock-in photo storage" data-test="v2-photo-storage">
        <div class="flex flex-col gap-4 text-sm">
            <div class="flex flex-col gap-2">
                <div>
                    <V2Chip
                        tone="warning"
                        :label="`Retention: ${card.retentionYears} years${card.draft ? ' · draft, to confirm' : ''}`"
                        class="text-nowrap!"
                        data-test="photo-storage-retention"
                    />
                </div>
                <p class="text-muted-foreground">
                    Photos are kept with their attendance record, then deleted
                    with it.
                    <template v-if="card.earliestDelete">
                        The earliest delete is
                        <span class="whitespace-nowrap tabular-nums">{{
                            card.earliestDelete
                        }}</span
                        >.
                    </template>
                </p>
            </div>

            <p
                v-if="card.photos === 0"
                class="rounded-[14px] border border-dashed bg-card p-4 text-muted-foreground"
                data-test="photo-storage-empty"
            >
                No clock-in photos yet.
            </p>
            <template v-else>
                <dl
                    class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3"
                    data-test="photo-storage-figures"
                >
                    <div class="flex min-w-0 flex-col">
                        <dt class="text-[13px] text-muted-foreground">
                            Stored
                        </dt>
                        <dd class="font-medium whitespace-nowrap tabular-nums">
                            {{ plural(card.photos, 'photo', 'photos') }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col">
                        <dt class="text-[13px] text-muted-foreground">Size</dt>
                        <dd class="font-medium whitespace-nowrap tabular-nums">
                            {{ glue(card.size) }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col">
                        <dt class="text-[13px] text-muted-foreground">
                            Oldest photo
                        </dt>
                        <dd class="font-medium whitespace-nowrap tabular-nums">
                            {{ card.oldest }}
                        </dd>
                    </div>
                    <div class="flex min-w-0 flex-col">
                        <dt class="text-[13px] text-muted-foreground">
                            This month
                        </dt>
                        <dd class="font-medium whitespace-nowrap tabular-nums">
                            {{ plural(card.thisMonth, 'photo', 'photos') }}
                        </dd>
                    </div>
                    <div
                        v-if="card.perMonth !== null && card.perMonthSize"
                        class="flex min-w-0 flex-col"
                    >
                        <dt class="text-[13px] text-muted-foreground">
                            Per month
                        </dt>
                        <dd class="font-medium whitespace-nowrap tabular-nums">
                            ~{{ plural(card.perMonth, 'photo', 'photos') }} ·
                            ~{{ glue(card.perMonthSize) }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="card.forecastOneYear && card.forecastRetention"
                    data-test="photo-storage-forecast"
                >
                    <span class="font-medium"
                        >Rough forecast, from the last
                        {{ word(card.forecastMonths) }} full months:</span
                    >{{ ' '
                    }}<span class="whitespace-nowrap tabular-nums"
                        >~{{ glue(card.forecastOneYear) }}</span
                    >
                    after 1 year, and
                    <span class="whitespace-nowrap tabular-nums"
                        >~{{ glue(card.forecastRetention) }}</span
                    >
                    at {{ card.retentionYears }} years, when deletes start.
                </p>
                <p
                    v-else
                    class="text-muted-foreground"
                    data-test="photo-storage-no-forecast"
                >
                    A forecast appears after
                    {{ word(card.forecastMonths) }} full months.
                </p>
            </template>

            <p class="text-[13px] text-muted-foreground">
                Totals only. No names. Sizes are from the saved photos and
                thumbnails; the encrypted files on disk take about a third more.
            </p>
        </div>
    </V2FormCard>
</template>
