type StorageLike = Pick<
    Storage,
    'getItem' | 'setItem' | 'removeItem' | 'key' | 'length'
>;

type BroadcastChannelLike = {
    close: () => void;
    postMessage: (message: unknown) => void;
    onmessage: ((event: MessageEvent) => void) | null;
};

type HistoryRouter = {
    clearHistory: () => void;
};

type BoundaryPage = {
    clearHistory?: unknown;
    props?: Record<string, unknown>;
};

type BoundaryRuntime = {
    addEventListener: (type: string, listener: EventListener) => void;
    createBroadcastChannel: ((name: string) => BroadcastChannelLike) | null;
    createEpoch: () => string | null;
    localStorage: StorageLike | null;
    reload: () => void;
    removeEventListener: (type: string, listener: EventListener) => void;
    sessionStorage: StorageLike | null;
    visibilityState: () => DocumentVisibilityState;
};

type SharedBoundaryState = {
    version: 2;
    boundaryEpoch: string;
    serverEpoch: string;
};

type TabBoundaryState = {
    version: 2;
    boundaryEpoch: string;
    serverEpoch: string;
};

type BoundarySignal = {
    version: 2;
    boundaryEpoch: string | null;
    serverEpoch: string | null;
};

type StoredValue<T> =
    | { status: 'valid'; value: T }
    | { status: 'missing' | 'invalid' | 'unavailable' };

export type AuthenticationHistoryBoundary = {
    acceptServerPage: (page: unknown) => boolean;
    dispose: () => void;
    retireForLogout: () => void;
};

const VERSION = 2;
const MAX_DURABLE_SERVER_EPOCHS = 16;
export const AUTH_HISTORY_SHARED_STORAGE_KEY =
    'kpone:authentication-history:shared:v2';
const AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX =
    'kpone:authentication-history:server-epoch:v2:';
export const AUTH_HISTORY_TAB_STORAGE_KEY =
    'kpone:authentication-history:tab:v2';
export const AUTH_HISTORY_BROADCAST_CHANNEL = 'kpone:authentication-history:v2';
const GLOBAL_COORDINATOR_KEY = '__kponeAuthenticationHistoryBoundaryV2';
const LEGACY_GLOBAL_COORDINATOR_KEY = '__kponeAuthenticationHistoryBoundaryV1';
const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

const normalizeEpoch = (value: unknown): string | null =>
    typeof value === 'string' && UUID_PATTERN.test(value)
        ? value.toLowerCase()
        : null;

const normalizeServerEpoch = (value: unknown): string | null => {
    const epoch = normalizeEpoch(value);

    return epoch?.[14] === '7' ? epoch : null;
};

const compareServerEpochs = (left: string, right: string): number =>
    left === right ? 0 : left < right ? -1 : 1;

const readStored = <T>(
    storage: StorageLike | null,
    key: string,
    validate: (value: unknown) => T | null,
): StoredValue<T> => {
    if (!storage) {
        return { status: 'unavailable' };
    }

    try {
        const raw = storage.getItem(key);

        if (raw === null) {
            return { status: 'missing' };
        }

        const value = validate(JSON.parse(raw));

        return value === null
            ? { status: 'invalid' }
            : { status: 'valid', value };
    } catch {
        return { status: 'unavailable' };
    }
};

const sharedBoundaryState = (value: unknown): SharedBoundaryState | null => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const candidate = value as Partial<SharedBoundaryState>;
    const boundaryEpoch = normalizeEpoch(candidate.boundaryEpoch);
    const serverEpoch = normalizeServerEpoch(candidate.serverEpoch);

    if (candidate.version !== VERSION || !boundaryEpoch || !serverEpoch) {
        return null;
    }

    return {
        version: VERSION,
        boundaryEpoch,
        serverEpoch,
    };
};

const tabBoundaryState = (value: unknown): TabBoundaryState | null => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const candidate = value as Partial<TabBoundaryState>;
    const boundaryEpoch = normalizeEpoch(candidate.boundaryEpoch);
    const serverEpoch = normalizeServerEpoch(candidate.serverEpoch);

    return candidate.version === VERSION && boundaryEpoch && serverEpoch
        ? { version: VERSION, boundaryEpoch, serverEpoch }
        : null;
};

const boundarySignal = (value: unknown): BoundarySignal | null => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    const candidate = value as Partial<BoundarySignal>;
    const boundaryEpoch =
        candidate.boundaryEpoch === null
            ? null
            : normalizeEpoch(candidate.boundaryEpoch);
    const serverEpoch =
        candidate.serverEpoch === null
            ? null
            : normalizeServerEpoch(candidate.serverEpoch);

    return candidate.version === VERSION &&
        (candidate.boundaryEpoch === null || boundaryEpoch !== null) &&
        (candidate.serverEpoch === null || serverEpoch !== null)
        ? { version: VERSION, boundaryEpoch, serverEpoch }
        : null;
};

const serverBoundary = (
    page: unknown,
): { clearHistory: boolean; epoch: string } | null => {
    if (!page || typeof page !== 'object' || Array.isArray(page)) {
        return null;
    }

    const candidate = page as BoundaryPage;
    const boundary = candidate.props?.authHistoryBoundary;

    if (!boundary || typeof boundary !== 'object' || Array.isArray(boundary)) {
        return null;
    }

    const value = boundary as { version?: unknown; epoch?: unknown };
    const epoch = normalizeServerEpoch(value.epoch);

    return value.version === VERSION && epoch
        ? { clearHistory: candidate.clearHistory === true, epoch }
        : null;
};

const writeStored = (
    storage: StorageLike | null,
    key: string,
    value: unknown,
): boolean => {
    if (!storage) {
        return false;
    }

    try {
        storage.setItem(key, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
};

const removeStored = (storage: StorageLike | null, key: string): void => {
    try {
        storage?.removeItem(key);
    } catch {
        // A blocked storage API must not prevent local history retirement.
    }
};

export const createAuthenticationHistoryBoundary = (
    router: HistoryRouter,
    runtime: BoundaryRuntime,
): AuthenticationHistoryBoundary => {
    let disposed = false;
    let reloadScheduled = false;
    let currentServerEpoch: string | null = null;
    let channel: BroadcastChannelLike | null = null;

    try {
        channel = runtime.createBroadcastChannel
            ? runtime.createBroadcastChannel(AUTH_HISTORY_BROADCAST_CHANNEL)
            : null;
    } catch {
        channel = null;
    }

    const readShared = (): StoredValue<SharedBoundaryState> => {
        const pointer = readStored(
            runtime.localStorage,
            AUTH_HISTORY_SHARED_STORAGE_KEY,
            sharedBoundaryState,
        );
        const durable: SharedBoundaryState[] = [];

        if (runtime.localStorage) {
            try {
                for (
                    let index = 0;
                    index < runtime.localStorage.length;
                    index++
                ) {
                    const key = runtime.localStorage.key(index);

                    if (
                        !key?.startsWith(
                            AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX,
                        )
                    ) {
                        continue;
                    }

                    const stored = readStored(
                        runtime.localStorage,
                        key,
                        sharedBoundaryState,
                    );

                    if (
                        stored.status === 'valid' &&
                        key ===
                            AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX +
                                stored.value.serverEpoch
                    ) {
                        durable.push(stored.value);
                    }
                }
            } catch {
                return pointer.status === 'valid'
                    ? pointer
                    : { status: 'unavailable' };
            }
        }

        durable.sort((left, right) =>
            compareServerEpochs(right.serverEpoch, left.serverEpoch),
        );

        return durable[0] ? { status: 'valid', value: durable[0] } : pointer;
    };
    const writeShared = (state: SharedBoundaryState): boolean => {
        const storage = runtime.localStorage;

        if (!storage) {
            return false;
        }

        const durableKey =
            AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX + state.serverEpoch;

        if (!writeStored(storage, durableKey, state)) {
            return false;
        }

        writeStored(storage, AUTH_HISTORY_SHARED_STORAGE_KEY, state);

        try {
            const durableKeys: string[] = [];

            for (let index = 0; index < storage.length; index++) {
                const key = storage.key(index);

                if (key?.startsWith(AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX)) {
                    durableKeys.push(key);
                }
            }

            durableKeys
                .sort()
                .slice(0, -MAX_DURABLE_SERVER_EPOCHS)
                .forEach((key) => removeStored(storage, key));
        } catch {
            // Retention pruning must not prevent publication of the new epoch.
        }

        return true;
    };
    const clearShared = (): void => {
        const storage = runtime.localStorage;

        if (!storage) {
            return;
        }

        const keys = [AUTH_HISTORY_SHARED_STORAGE_KEY];

        try {
            for (let index = 0; index < storage.length; index++) {
                const key = storage.key(index);

                if (key?.startsWith(AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX)) {
                    keys.push(key);
                }
            }
        } catch {
            // The well-known pointer can still be retired below.
        }

        keys.forEach((key) => removeStored(storage, key));
    };
    const readTab = () =>
        readStored(
            runtime.sessionStorage,
            AUTH_HISTORY_TAB_STORAGE_KEY,
            tabBoundaryState,
        );
    const observe = (boundaryEpoch: string, serverEpoch: string): void => {
        writeStored(runtime.sessionStorage, AUTH_HISTORY_TAB_STORAGE_KEY, {
            version: VERSION,
            boundaryEpoch,
            serverEpoch,
        });
    };
    const notify = (
        boundaryEpoch: string | null,
        serverEpoch: string | null,
    ): void => {
        try {
            channel?.postMessage({
                version: VERSION,
                boundaryEpoch,
                serverEpoch,
            });
        } catch {
            // Durable storage and lifecycle checks remain the fallback.
        }
    };
    const retireAndReload = (): false => {
        if (reloadScheduled || disposed) {
            return false;
        }

        reloadScheduled = true;
        router.clearHistory();
        removeStored(runtime.sessionStorage, AUTH_HISTORY_TAB_STORAGE_KEY);
        runtime.reload();

        return false;
    };
    const verifyObservedBoundary = (): void => {
        if (disposed || reloadScheduled) {
            return;
        }

        const shared = readShared();
        const tab = readTab();

        if (
            shared.status !== 'valid' ||
            tab.status !== 'valid' ||
            tab.value.boundaryEpoch !== shared.value.boundaryEpoch ||
            tab.value.serverEpoch !== shared.value.serverEpoch
        ) {
            retireAndReload();
        }
    };

    const onStorage = ((event: StorageEvent) => {
        if (
            event.key === AUTH_HISTORY_SHARED_STORAGE_KEY ||
            event.key?.startsWith(AUTH_HISTORY_SERVER_EPOCH_STORAGE_PREFIX)
        ) {
            verifyObservedBoundary();
        }
    }) as EventListener;
    const onPageShow = ((event: PageTransitionEvent) => {
        if (event.persisted) {
            verifyObservedBoundary();
        }
    }) as EventListener;
    const onVisibility = (() => {
        if (runtime.visibilityState() === 'visible') {
            verifyObservedBoundary();
        }
    }) as EventListener;
    const onFocus = (() => verifyObservedBoundary()) as EventListener;

    runtime.addEventListener('storage', onStorage);
    runtime.addEventListener('pageshow', onPageShow);
    runtime.addEventListener('visibilitychange', onVisibility);
    runtime.addEventListener('focus', onFocus);

    if (channel) {
        channel.onmessage = (event) => {
            if (disposed || reloadScheduled) {
                return;
            }

            const signal = boundarySignal(event.data);

            if (!signal) {
                return;
            }

            const tab = readTab();
            const shared = readShared();
            const signalIsOlder =
                shared.status === 'valid' &&
                signal.serverEpoch !== null &&
                compareServerEpochs(
                    signal.serverEpoch,
                    shared.value.serverEpoch,
                ) < 0;

            if (
                signalIsOlder ||
                (signal.boundaryEpoch !== null &&
                    tab.status === 'valid' &&
                    tab.value.boundaryEpoch === signal.boundaryEpoch)
            ) {
                return;
            }

            retireAndReload();
        };
    }

    return {
        acceptServerPage(page: unknown): boolean {
            if (disposed || reloadScheduled) {
                return false;
            }

            const server = serverBoundary(page);

            if (!server) {
                return retireAndReload();
            }

            const shared = readShared();
            const tab = readTab();

            if (shared.status !== 'valid') {
                if (tab.status === 'valid') {
                    if (
                        currentServerEpoch === null ||
                        compareServerEpochs(
                            server.epoch,
                            tab.value.serverEpoch,
                        ) <= 0
                    ) {
                        return retireAndReload();
                    }
                }

                router.clearHistory();
                const initial: SharedBoundaryState = {
                    version: VERSION,
                    boundaryEpoch: server.epoch,
                    serverEpoch: server.epoch,
                };

                if (!writeShared(initial)) {
                    clearShared();
                }

                observe(initial.boundaryEpoch, initial.serverEpoch);
                notify(initial.boundaryEpoch, initial.serverEpoch);
                currentServerEpoch = server.epoch;

                return true;
            }

            if (
                compareServerEpochs(server.epoch, shared.value.serverEpoch) < 0
            ) {
                return retireAndReload();
            }

            if (shared.value.serverEpoch === server.epoch) {
                const logoutBoundaryPending =
                    shared.value.boundaryEpoch !== shared.value.serverEpoch;
                const tabOwnsCurrentBoundary =
                    tab.status === 'valid' &&
                    tab.value.boundaryEpoch === shared.value.boundaryEpoch;

                if (logoutBoundaryPending && !tabOwnsCurrentBoundary) {
                    return retireAndReload();
                }

                if (
                    server.clearHistory ||
                    tab.status !== 'valid' ||
                    tab.value.boundaryEpoch !== shared.value.boundaryEpoch
                ) {
                    router.clearHistory();
                }

                observe(shared.value.boundaryEpoch, shared.value.serverEpoch);
                currentServerEpoch = server.epoch;

                return true;
            }

            const tabOwnsCurrentBoundary =
                tab.status === 'valid' &&
                tab.value.boundaryEpoch === shared.value.boundaryEpoch;

            if (!server.clearHistory && !tabOwnsCurrentBoundary) {
                return retireAndReload();
            }

            const next: SharedBoundaryState = {
                version: VERSION,
                boundaryEpoch: server.epoch,
                serverEpoch: server.epoch,
            };
            router.clearHistory();

            if (!writeShared(next)) {
                clearShared();
            }

            observe(next.boundaryEpoch, next.serverEpoch);
            notify(next.boundaryEpoch, next.serverEpoch);
            currentServerEpoch = server.epoch;

            return true;
        },
        dispose(): void {
            if (disposed) {
                return;
            }

            disposed = true;
            runtime.removeEventListener('storage', onStorage);
            runtime.removeEventListener('pageshow', onPageShow);
            runtime.removeEventListener('visibilitychange', onVisibility);
            runtime.removeEventListener('focus', onFocus);
            channel?.close();
        },
        retireForLogout(): void {
            if (disposed || reloadScheduled) {
                return;
            }

            const shared = readShared();
            const boundaryEpoch = runtime.createEpoch();

            if (
                boundaryEpoch &&
                currentServerEpoch &&
                (shared.status !== 'valid' ||
                    shared.value.serverEpoch === currentServerEpoch)
            ) {
                const published = writeShared({
                    version: VERSION,
                    boundaryEpoch,
                    serverEpoch: currentServerEpoch,
                });

                if (!published) {
                    clearShared();
                }

                observe(boundaryEpoch, currentServerEpoch);
            } else {
                clearShared();
                removeStored(
                    runtime.sessionStorage,
                    AUTH_HISTORY_TAB_STORAGE_KEY,
                );
            }

            router.clearHistory();
            notify(boundaryEpoch, currentServerEpoch);
        },
    };
};

const productionRuntime = (): BoundaryRuntime | null => {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return null;
    }

    let localStorage: StorageLike | null = null;
    let sessionStorage: StorageLike | null = null;

    try {
        localStorage = window.localStorage;
    } catch {
        // Lifecycle signals and the server receipt nonce remain fail-closed fallbacks.
    }

    try {
        sessionStorage = window.sessionStorage;
    } catch {
        // Inertia will also reject old encrypted history when its storage is blocked.
    }

    return {
        addEventListener: (type, listener) =>
            window.addEventListener(type, listener),
        createBroadcastChannel:
            typeof window.BroadcastChannel === 'function'
                ? (name) => new window.BroadcastChannel(name)
                : null,
        createEpoch: () => {
            try {
                if (typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID().toLowerCase();
                }

                const bytes = window.crypto.getRandomValues(new Uint8Array(16));
                bytes[6] = (bytes[6] & 0x0f) | 0x40;
                bytes[8] = (bytes[8] & 0x3f) | 0x80;
                const hexadecimal = Array.from(bytes, (byte) =>
                    byte.toString(16).padStart(2, '0'),
                ).join('');

                return `${hexadecimal.slice(0, 8)}-${hexadecimal.slice(8, 12)}-${hexadecimal.slice(12, 16)}-${hexadecimal.slice(16, 20)}-${hexadecimal.slice(20)}`;
            } catch {
                return null;
            }
        },
        localStorage,
        reload: () => window.location.reload(),
        removeEventListener: (type, listener) =>
            window.removeEventListener(type, listener),
        sessionStorage,
        visibilityState: () => document.visibilityState,
    };
};

export const installAuthenticationHistoryBoundary = (
    router: HistoryRouter,
    initialPage: unknown,
): { boundary: AuthenticationHistoryBoundary | null; ready: boolean } => {
    const runtime = productionRuntime();

    if (!runtime) {
        return { boundary: null, ready: true };
    }

    const host = window as unknown as Record<string, unknown>;
    const legacy = host[LEGACY_GLOBAL_COORDINATOR_KEY] as
        AuthenticationHistoryBoundary | undefined;
    legacy?.dispose();
    delete host[LEGACY_GLOBAL_COORDINATOR_KEY];
    const previous = host[GLOBAL_COORDINATOR_KEY] as
        AuthenticationHistoryBoundary | undefined;
    previous?.dispose();

    const boundary = createAuthenticationHistoryBoundary(router, runtime);
    host[GLOBAL_COORDINATOR_KEY] = boundary;

    return { boundary, ready: boundary.acceptServerPage(initialPage) };
};

export const retireAuthenticationHistoryForLogout = (
    router: HistoryRouter,
): void => {
    if (typeof window === 'undefined') {
        return;
    }

    const boundary = (window as unknown as Record<string, unknown>)[
        GLOBAL_COORDINATOR_KEY
    ] as AuthenticationHistoryBoundary | undefined;

    if (boundary) {
        boundary.retireForLogout();
    } else {
        router.clearHistory();
    }
};
