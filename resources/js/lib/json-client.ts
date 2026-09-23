export class JsonRequestError extends Error {
    constructor(
        public readonly status: number,
        public readonly payload: unknown,
    ) {
        super(`JSON request failed with status ${status}`);
    }
}

export const requestJson = async <T>(
    url: string,
    init: RequestInit = {},
): Promise<T> => {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    headers.delete('X-Inertia');

    const response = await fetch(url, {
        ...init,
        credentials: 'same-origin',
        cache: 'no-store',
        headers,
    });
    const payload: unknown = await response.json();

    if (!response.ok) {
        throw new JsonRequestError(response.status, payload);
    }

    return payload as T;
};
