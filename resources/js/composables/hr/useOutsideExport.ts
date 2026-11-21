import { ref } from 'vue';

/**
 * HR-12 export for an outside HR product (HR-PLAN 15.1): the same request for the Classic card
 * (`components/hr/records/OutsideExportCard.vue`) and Version 2. One way; each download is recorded first and no file is
 * kept. Never identity numbers, bank details or documents. Moved out unchanged.
 */
export function useOutsideExport() {
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

            const disposition =
                response.headers.get('Content-Disposition') ?? '';
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

    return { month, busy, failure, monthError, download };
}
