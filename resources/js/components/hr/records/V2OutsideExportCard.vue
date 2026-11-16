<script setup lang="ts">
// Version 2 export for an outside HR product (HR-PLAN 15.1): the same recorded POST as Classic, from
// `useOutsideExport`. A generic CSV one way; each download is recorded first and no file is kept. Never identity
// numbers, bank details or documents.
import { MonthField } from '@/components/ui/date-field';
import { useOutsideExport } from '@/composables/hr/useOutsideExport';

const { month, busy, failure, monthError, download } = useOutsideExport();
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
</script>

<template>
    <details
        class="rounded-[14px] border bg-card p-4"
        data-test="outside-export"
    >
        <summary
            class="min-h-11 cursor-pointer content-center text-sm font-semibold lg:min-h-0"
        >
            Export for an outside HR product
        </summary>
        <div class="mt-4 flex flex-col gap-4">
            <p class="max-w-prose text-sm text-muted-foreground">
                A CSV file to load into another HR product. It goes one way:
                nothing comes back into KPOne. Each download is recorded and no
                file is kept in KPOne. It never holds identity numbers, bank
                details or documents.
            </p>
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    :class="button"
                    :disabled="!!busy"
                    data-test="outside-export-staff"
                    @click="download('staff')"
                >
                    {{
                        busy === 'staff' ? 'Preparing…' : 'Download staff list'
                    }}
                </button>
                <p class="text-sm text-muted-foreground">
                    Everyone with a staff file, as of today: position,
                    employment type and dates, primary branch.
                </p>
            </div>
            <div class="flex flex-col gap-2 border-t pt-3">
                <div class="grid gap-1.5">
                    <label
                        for="v2-outside-export-month"
                        class="text-sm font-medium"
                        >Month</label
                    >
                    <MonthField
                        id="v2-outside-export-month"
                        v-model="month"
                        aria-describedby="v2-outside-export-month-help"
                        :aria-invalid="!!monthError"
                    />
                    <p
                        id="v2-outside-export-month-help"
                        class="text-xs text-muted-foreground"
                    >
                        For approved leave and approved overtime.
                    </p>
                    <p
                        v-if="monthError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ monthError }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        :class="button"
                        :disabled="!!busy"
                        data-test="outside-export-leave"
                        @click="download('leave')"
                    >
                        {{
                            busy === 'leave'
                                ? 'Preparing…'
                                : 'Download approved leave'
                        }}
                    </button>
                    <button
                        type="button"
                        :class="button"
                        :disabled="!!busy"
                        data-test="outside-export-overtime"
                        @click="download('overtime')"
                    >
                        {{
                            busy === 'overtime'
                                ? 'Preparing…'
                                : 'Download approved overtime'
                        }}
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Leave lists every approved request that touches the month,
                    with all of its days. Overtime lists approved minutes only,
                    never pay.
                </p>
            </div>
        </div>
    </details>
</template>
