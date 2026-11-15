<script setup lang="ts">
// HR-12 export for an outside HR product (HR-PLAN 15.1): a generic CSV of the staff list, approved leave or approved
// overtime, for the Director or the HR Manager to load into another HR product. One way; nothing comes back into
// KPOne. Each download is recorded first and no file is kept. Never identity numbers, bank details or documents.
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { MonthField } from '@/components/ui/date-field';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/state';

type Kind = 'staff' | 'leave' | 'overtime';

const now = new Date();
const month = ref(
    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`,
);
const busy = ref<Kind | null>(null);
const failure = ref('');
const monthError = ref('');

const download = async (kind: Kind) => {
    if (busy.value) {
        return;
    }

    busy.value = kind;
    failure.value = '';
    monthError.value = '';

    try {
        const response = await fetch(`/hr/records/outside-export/${kind}`, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify(
                kind === 'staff' ? {} : { month: month.value },
            ),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => null);

            if (payload?.errors?.month) {
                monthError.value = String(payload.errors.month[0] ?? '');

                return;
            }

            throw new Error(
                payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : response.status === 429
                      ? 'Too many downloads. Try again in a minute.'
                      : 'Unable to prepare the file. Try again.',
            );
        }

        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name =
            /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `${kind}.csv`;
        const url = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = url;
        link.download = name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
        failure.value =
            error instanceof Error
                ? error.message
                : 'Unable to prepare the file.';
    } finally {
        busy.value = null;
    }
};
</script>

<template>
    <details class="rounded-xl border bg-card p-4" data-test="outside-export">
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
            <ErrorState v-if="failure" :message="failure" />
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="!!busy"
                        data-test="outside-export-staff"
                        @click="download('staff')"
                    >
                        {{
                            busy === 'staff'
                                ? 'Preparing…'
                                : 'Download staff list'
                        }}
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        Everyone with a staff file, as of today: position,
                        employment type and dates, primary branch.
                    </p>
                </div>
                <div class="flex flex-col gap-2 border-t pt-3">
                    <div class="grid gap-1.5">
                        <Label for="outside-export-month">Month</Label>
                        <MonthField
                            id="outside-export-month"
                            v-model="month"
                            aria-describedby="outside-export-month-help"
                            :aria-invalid="!!monthError"
                        />
                        <p
                            id="outside-export-month-help"
                            class="text-xs text-muted-foreground"
                        >
                            For approved leave and approved overtime.
                        </p>
                        <InputError :message="monthError" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="!!busy"
                            data-test="outside-export-leave"
                            @click="download('leave')"
                        >
                            {{
                                busy === 'leave'
                                    ? 'Preparing…'
                                    : 'Download approved leave'
                            }}
                        </Button>
                        <Button
                            variant="outline"
                            class="max-lg:min-h-11"
                            :disabled="!!busy"
                            data-test="outside-export-overtime"
                            @click="download('overtime')"
                        >
                            {{
                                busy === 'overtime'
                                    ? 'Preparing…'
                                    : 'Download approved overtime'
                            }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Leave lists every approved request that touches the
                        month, with all of its days. Overtime lists approved
                        minutes only, never pay.
                    </p>
                </div>
            </div>
        </div>
    </details>
</template>
