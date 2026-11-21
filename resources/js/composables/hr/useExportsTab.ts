import { reactive, ref } from 'vue';
import type { ExportRun } from '@/components/hr/payroll/types';

/**
 * HR-7c Exports tab state: the password dialog and the recorded download. The request lives here, shared by the Classic
 * tab (`components/hr/payroll/ExportsTab.vue`) and V2 Payroll, so both send the same request; the password is sent once
 * and never kept. Moved unchanged.
 */
export type ExportsTabProps = { runs: ExportRun[] };

export function useExportsTab() {
    const MIN_PASSWORD = 12;
    const busy = ref<string | null>(null);
    const failure = ref('');
    const asking = ref<{ run: ExportRun; kind: 'bank' | 'accountant' } | null>(
        null,
    );
    const secret = reactive({
        password: '',
        confirm: '',
        show: false,
        // Each message sits under the field it is about (Daniel); `error` is for the server and the connection.
        passwordError: '',
        confirmError: '',
        error: '',
    });
    const forget = () => {
        secret.password = '';
        secret.confirm = '';
        secret.show = false;
        secret.passwordError = '';
        secret.confirmError = '';
        secret.error = '';
    };
    // The dialog opens from code, so focus goes back by hand to the button that opened it (Daniel). The clicked button
    // itself, as Safari does not focus a button on a mouse click (Suresh).
    let opener: HTMLElement | null = null;
    const ask = (
        run: ExportRun,
        kind: 'bank' | 'accountant',
        from: EventTarget | null,
    ) => {
        opener = from instanceof HTMLElement ? from : null;
        forget();
        failure.value = '';
        asking.value = { run, kind };
    };
    // Never closed while the file is being made: the request would carry on without its dialog.
    const dismiss = () => {
        if (!busy.value) {
            close();
        }
    };
    const returnFocus = (event: Event) => {
        // A button no longer on the page (its run gone) leaves focus to the dialog's own default.
        if (opener?.isConnected) {
            event.preventDefault();
            opener.focus();
        }

        opener = null;
    };
    // A stalled connection must not hold the dialog for minutes with Cancel off (Suresh).
    const TIMEOUT_MS = 60_000;
    const close = () => {
        asking.value = null;
        forget();
    };

    const download = async () => {
        if (busy.value || !asking.value) {
            return;
        }

        secret.passwordError = '';
        secret.confirmError = '';
        secret.error = '';

        if (secret.password.length < MIN_PASSWORD) {
            secret.passwordError = `Type a password of at least ${MIN_PASSWORD} characters for the file.`;

            return;
        }

        if (!/^[\x21-\x7E]+$/.test(secret.password)) {
            secret.passwordError =
                'Use only letters, numbers and symbols, with no spaces.';

            return;
        }

        if (secret.password !== secret.confirm) {
            secret.confirmError = 'The two passwords do not match.';

            return;
        }

        const { run, kind } = asking.value;
        busy.value = `${run.id}:${kind}`;
        failure.value = '';
        secret.error = '';

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

        try {
            const response = await fetch(
                `/hr/payroll/runs/${run.id}/export/${kind}`,
                {
                    method: 'POST',
                    signal: controller.signal,
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
                    body: JSON.stringify({
                        file_password: secret.password,
                        file_password_confirmation: secret.confirm,
                    }),
                },
            );

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                const errors: Record<string, string[]> = {
                    ...(payload?.errors ?? {}),
                };
                const first = (key: string) => {
                    const message = (errors[key] ?? []).join(' ');
                    delete errors[key];

                    return message;
                };
                secret.passwordError = first('file_password');
                secret.confirmError = first('file_password_confirmation');
                const rest = Object.values(errors).flat().join(' ');

                if (secret.passwordError || secret.confirmError) {
                    secret.error = rest;

                    return;
                }

                throw new Error(
                    rest
                        ? rest
                        : response.status === 429
                          ? 'Too many downloads. Try again in a minute.'
                          : 'Unable to prepare the file. Try again.',
                );
            }

            const disposition =
                response.headers.get('Content-Disposition') ?? '';
            const name =
                /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `${kind}.zip`;
            const blob = await response.blob();
            clearTimeout(timer);
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            close();
        } catch (error) {
            // A timed-out request may still have been recorded as a download, so this never says nothing was downloaded.
            secret.error = controller.signal.aborted
                ? 'The file is taking too long. Check the connection and try again.'
                : error instanceof Error
                  ? error.message
                  : 'Unable to prepare the file.';
        } finally {
            clearTimeout(timer);
            busy.value = null;
        }
    };

    return {
        MIN_PASSWORD,
        busy,
        failure,
        asking,
        secret,
        forget,
        ask,
        dismiss,
        returnFocus,
        close,
        download,
    };
}
