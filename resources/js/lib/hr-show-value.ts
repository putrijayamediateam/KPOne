import { csrf } from '@/components/hr/records/types';

/**
 * The audited Show request for a masked HR value (HR-PLAN 11.3 rule 7): the server records the view first and only then
 * returns the value. Used by `MaskedValue`. Nothing is cached or prefetched; the caller decides when to ask and drops
 * the value again on Hide.
 */
export type ShowValueResult = {
    ok: boolean;
    data: {
        value?: string;
        shownAt?: string;
        errors?: Record<string, string>;
    };
};

export const requestShowValue = async (
    url: string,
    field: string,
    extra: Record<string, string> = {},
): Promise<ShowValueResult> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ field, ...extra }),
    });
    const data = (await response
        .json()
        .catch(() => ({}))) as ShowValueResult['data'];

    return { ok: response.ok, data };
};
