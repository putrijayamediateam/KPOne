import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const read = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const transitions = read('resources/js/lib/inventory-operation-transitions.ts');
const javascript = ts.transpileModule(transitions, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const module = { exports: {} };
runInNewContext(javascript, { exports: module.exports, module });
const intentSource = read('resources/js/types/inventory-operations.ts');
const intentJavascript = ts.transpileModule(intentSource, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const intentModule = { exports: {} };
runInNewContext(intentJavascript, {
    exports: intentModule.exports,
    module: intentModule,
});
const boundarySource = read(
    'resources/js/lib/inertia-auth-history-boundary.ts',
);
const boundaryJavascript = ts.transpileModule(boundarySource, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const boundaryModule = { exports: {} };
runInNewContext(boundaryJavascript, {
    exports: boundaryModule.exports,
    module: boundaryModule,
});

const opaqueIdentity = (character) => character.repeat(64);
const receiptMemoryScope = (overrides = {}) => ({
    version: 2,
    organisationPublicId: opaqueIdentity('a'),
    actorPublicId: opaqueIdentity('b'),
    sessionNonce: '00000000-0000-4000-8000-000000000901',
    branchPublicId: opaqueIdentity('c'),
    ...overrides,
});
const receiptMemoryContext = (overrides = {}) => {
    const context = intentModule.exports.inventoryReceiptRememberContext(
        receiptMemoryScope(overrides),
    );
    assert.notEqual(context, null);

    return context;
};

const productionLogoutHandler = (path, router) => {
    const source = read(path);
    const declaration = source.match(
        /const handleLogout = \(\) => \{[\s\S]*?\n\};/,
    )?.[0];

    assert.ok(declaration, `${path} must define handleLogout.`);

    const compiled = ts.transpileModule(
        `${declaration}\nmodule.exports = { handleLogout };`,
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText;
    const handlerModule = { exports: {} };
    runInNewContext(compiled, {
        exports: handlerModule.exports,
        module: handlerModule,
        router,
        retireAuthenticationHistoryForLogout: (candidate) =>
            candidate.clearHistory(),
    });

    return { source, handleLogout: handlerModule.exports.handleLogout };
};

test('Purchase Order controls follow the governed lifecycle and permissions', () => {
    const allowed = module.exports.purchaseOrderActionAllowed;
    const creator = { createPurchaseOrders: true };
    const approver = { approvePurchaseOrders: true };
    const receiver = { receiveGoods: true };

    assert.equal(allowed('draft', 'submit', creator), true);
    assert.equal(allowed('submitted', 'approve', approver), true);
    assert.equal(allowed('approved', 'receive', receiver), true);
    assert.equal(allowed('partially_received', 'receive', receiver), true);
    assert.equal(allowed('fully_received', 'close', approver), true);
    assert.equal(allowed('draft', 'cancel', creator), true);
    assert.equal(allowed('approved', 'cancel', approver), true);
    assert.equal(allowed('approved', 'cancel', creator), false);
    assert.equal(allowed('draft', 'approve', approver), false);
    assert.equal(allowed('approved', 'receive', creator), false);
    assert.equal(allowed('cancelled', 'receive', receiver), false);
});

test('two-stage transfer controls never expose destination receipt before dispatch', () => {
    const allowed = module.exports.stockRequestActionAllowed;
    const permissions = {
        approveRequests: true,
        dispatchTransfers: true,
        receiveTransfers: true,
    };

    assert.equal(allowed('requested', 'decide', permissions), true);
    assert.equal(allowed('requested', 'dispatch', permissions), false);
    assert.equal(allowed('approved', 'dispatch', permissions), true);
    assert.equal(allowed('approved', 'receive', permissions), false);
    assert.equal(allowed('dispatched', 'receive', permissions), true);
    assert.equal(allowed('received', 'receive', permissions), false);
});

test('stocktake controls require count and review before posting', () => {
    const allowed = module.exports.stocktakeActionAllowed;
    assert.equal(allowed('draft', 'start'), true);
    assert.equal(allowed('draft', 'post'), false);
    assert.equal(allowed('counting', 'count'), true);
    assert.equal(allowed('review', 'post'), true);
    assert.equal(allowed('review', 'cancel'), true);
    assert.equal(allowed('posted', 'cancel'), false);
    assert.equal(allowed('posted', 'post'), false);
    assert.equal(allowed('cancelled', 'start'), false);
});

test('Inventory Operations uses KPOne controls and server-backed idempotent endpoints', () => {
    const component = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );

    for (const label of [
        'Supplier Management',
        'Purchase Orders & Receiving',
        'Stock Requests & Transfers',
        'Stocktake',
        'Manual Adjustment',
        'Reorder Visibility',
    ]) {
        assert.match(component, new RegExp(label));
    }

    assert.match(component, /<OperationalSelect/g);
    assert.doesNotMatch(component, /<select\b/i);
    assert.match(component, /crypto\.randomUUID\(\)/);
    assert.match(component, /useRemember<InventoryReceiptRememberedVault>/);
    assert.match(component, /router\.remember\(vault, receiptRememberKey\)/);
    assert.match(component, /router\.restore\(receiptRememberKey\)/);
    assert.match(component, /receiptRememberContext = \(\) =>/);
    assert.match(component, /inventoryReceiptRememberContext/);
    assert.match(component, /InventoryOperations:goods-receipts:v2/);
    assert.doesNotMatch(component, /InventoryOperations:goods-receipts:v1/);
    assert.match(component, /context !== activeReceiptContext/);
    assert.match(component, /delete receipt\[linePublicId\]/);
    assert.match(component, /persistRememberedReceipts\(intent\.state\)/);
    assert.match(component, /idempotency_key: submitted\.idempotencyKey/);
    assert.match(
        component,
        /receipt_session_nonce:\s*props\.receiptMemoryContext\.sessionNonce/,
    );
    assert.match(component, /consumeInventoryReceiptIntent/);
    assert.match(component, /adjustmentIdentity\.value = undefined/);
    assert.match(component, /errorBag: 'inventoryOperations'/);
    assert.match(component, /onError: \(errors\)/);
    assert.match(component, /role="alert"/);
    assert.match(component, /<OperationalConfirmDialog/);
    assert.match(component, /confirmPurchaseOrderReceipt/);
    assert.match(component, /confirmStockRequestDispatch/);
    assert.match(component, /confirmStockRequestReceipt/);
    assert.match(component, /confirmStocktakeTransition/);
    assert.match(component, /confirmAdjustment/);
    assert.match(component, /updatePurchaseOrder/);
    assert.match(component, />Update draft</);
    assert.match(component, /addPurchaseOrderLine/);
    assert.match(component, /addStockRequestLine/);
    assert.match(component, />Add PO line</);
    assert.match(component, />Add request line</);
    assert.match(component, /\/receipts/);
    assert.match(component, /\/dispatch/);
    assert.match(component, /\/receive/);
    assert.doesNotMatch(
        component,
        /window\.confirm|localStorage|sessionStorage/,
    );
});

test('both production logout surfaces retire encrypted history before their single Fortify visit', async () => {
    const inertiaCore = read('node_modules/@inertiajs/core/dist/index.js');
    const inertiaVue = read('node_modules/@inertiajs/vue3/dist/index.js');
    const routeSource = read('resources/js/routes/index.ts');
    const inertiaConfig = read('config/inertia.php');

    for (const [index, path] of [
        'resources/js/components/UserMenuContent.vue',
        'resources/js/pages/auth/VerifyEmail.vue',
    ].entries()) {
        const storage = new Map([['unrelated', JSON.stringify('retained')]]);
        const sessionStorage = {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
            removeItem: (key) => storage.delete(key),
            clear: () => storage.clear(),
        };
        const browserHistory = {
            state: null,
            scrollRestoration: 'auto',
            replaceState(data) {
                this.state = data;
            },
            pushState(data) {
                this.state = data;
            },
        };
        globalThis.window = {
            sessionStorage,
            history: browserHistory,
            navigator: { userAgent: 'Node.js security test' },
            location: new URL('https://clinic.test/inventory'),
            crypto: globalThis.crypto,
            addEventListener: () => {},
            removeEventListener: () => {},
            scrollTo: () => {},
            requestAnimationFrame: (callback) => {
                callback();

                return 1;
            },
        };
        globalThis.document = {
            addEventListener: () => {},
            removeEventListener: () => {},
            dispatchEvent: () => true,
            querySelector: () => null,
            querySelectorAll: () => [],
            visibilityState: 'visible',
        };
        globalThis.CustomEvent = class {
            constructor(type, options = {}) {
                this.type = type;
                Object.assign(this, options);
            }
        };

        const { router: inertiaRouter } = await import(
            `../../node_modules/@inertiajs/core/dist/index.js?encrypted-logout-${index}`
        );
        const rememberedReceipt = {
            version: 2,
            contexts: {
                'user-a-context': {
                    entries: {
                        'purchase-order-a': {
                            idempotencyKey:
                                '00000000-0000-4000-8000-0000000000aa',
                            pending: true,
                        },
                    },
                },
            },
        };
        inertiaRouter.init({
            initialPage: {
                component: 'Inventory/Index',
                props: { authenticatedActor: 'user-a' },
                rememberedState: {
                    'InventoryOperations:goods-receipts:v2': rememberedReceipt,
                },
                url: '/inventory',
                version: 'test-version',
                encryptHistory: true,
                clearHistory: false,
            },
            resolveComponent: async () => ({}),
            swapComponent: async () => {},
        });

        for (
            let attempt = 0;
            attempt < 40 && !browserHistory.state;
            attempt++
        ) {
            await new Promise((resolve) => setTimeout(resolve, 5));
        }

        assert.equal(browserHistory.state.page instanceof ArrayBuffer, true);
        assert.equal(storage.has('historyKey'), true);
        assert.equal(storage.has('historyIv'), true);
        const decryptedForUserA = await inertiaRouter.decryptHistory();
        assert.equal(decryptedForUserA.props.authenticatedActor, 'user-a');
        assert.deepEqual(
            decryptedForUserA.rememberedState[
                'InventoryOperations:goods-receipts:v2'
            ],
            rememberedReceipt,
        );

        const calls = [];
        const production = productionLogoutHandler(path, {
            clearHistory: () => {
                calls.push('clearHistory');
                inertiaRouter.clearHistory();
            },
            flushAll: () => calls.push('flushAll'),
        });

        production.handleLogout();

        assert.deepEqual(calls, ['clearHistory', 'flushAll']);
        assert.equal(storage.has('historyKey'), false);
        assert.equal(storage.has('historyIv'), false);
        assert.equal(JSON.parse(storage.get('unrelated')), 'retained');
        await assert.rejects(
            inertiaRouter.decryptHistory(),
            /Unable to decrypt history/,
        );
        assert.match(production.source, /@start="handleLogout"/);
        assert.doesNotMatch(production.source, /@click="handleLogout"/);
        assert.doesNotMatch(production.source, /router\.post\(/);
        assert.equal(production.source.match(/:href="logout\(\)"/g)?.length, 1);
        assert.ok(
            production.source.indexOf(
                'retireAuthenticationHistoryForLogout(router)',
            ) < production.source.indexOf('router.flushAll()'),
        );
    }

    Reflect.deleteProperty(globalThis, 'window');
    Reflect.deleteProperty(globalThis, 'document');
    Reflect.deleteProperty(globalThis, 'CustomEvent');

    assert.match(inertiaConfig, /'history'\s*=>\s*\[/);
    assert.match(inertiaConfig, /'encrypt'\s*=>\s*true/);
    assert.match(
        routeSource,
        /export const logout[\s\S]*?method: 'post'[\s\S]*?url: '\/logout'/,
    );
    assert.match(
        inertiaCore,
        /clear\(\) \{\s*SessionStorage\.remove\(historySessionStorageKeys\.key\);\s*SessionStorage\.remove\(historySessionStorageKeys\.iv\);/,
    );
    const onStartIndex = inertiaCore.indexOf('this.requestParams.onStart();');
    const requestIndex = inertiaCore.indexOf(
        'http.getClient().request(processedConfig)',
        onStartIndex,
    );
    assert.ok(onStartIndex >= 0);
    assert.ok(requestIndex > onStartIndex);
    assert.match(
        inertiaCore,
        /history\.decrypt\(state\.page\)[\s\S]*?\.catch\(\(\) => \{\s*this\.onMissingHistoryItem\(\);/,
    );
    assert.match(
        inertiaVue,
        /onStart: \(visit\) => \{[\s\S]*?props\.onStart\?\.\(visit\);/,
    );
});

test('production authentication-history coordinator retires active, dormant, and stale tabs without leaking receipt state', () => {
    const create = boundaryModule.exports.createAuthenticationHistoryBoundary;
    const sharedValues = new Map([['unrelated', 'retained']]);
    const channels = [];
    const storage = (values) => ({
        get length() {
            return values.size;
        },
        getItem: (key) => values.get(key) ?? null,
        key: (index) => [...values.keys()][index] ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    });
    const localStorage = storage(sharedValues);
    const makeTab = (
        withBroadcast = true,
        selectedLocalStorage = localStorage,
        selectedChannels = channels,
        selectedSessionValues = null,
    ) => {
        const sessionValues =
            selectedSessionValues ??
            new Map([
                ['historyKey', 'synthetic-key'],
                ['historyIv', 'synthetic-iv'],
                ['unrelated-tab', 'retained'],
            ]);
        const listeners = new Map();
        const calls = [];
        const router = {
            clearHistory: () => {
                calls.push('clear');
                sessionValues.delete('historyKey');
                sessionValues.delete('historyIv');
            },
        };
        const runtime = {
            addEventListener: (type, listener) => listeners.set(type, listener),
            createBroadcastChannel: withBroadcast
                ? () => {
                      const channel = {
                          close: () => {},
                          onmessage: null,
                          postMessage: (message) => {
                              for (const peer of selectedChannels) {
                                  if (peer !== channel) {
                                      peer.onmessage?.({ data: message });
                                  }
                              }
                          },
                      };
                      selectedChannels.push(channel);

                      return channel;
                  }
                : null,
            createEpoch: () => '00000000-0000-4000-8000-0000000000ff',
            localStorage: selectedLocalStorage,
            reload: () => calls.push('reload'),
            removeEventListener: (type) => listeners.delete(type),
            sessionStorage: storage(sessionValues),
            visibilityState: () => 'visible',
        };

        return {
            boundary: create(router, runtime),
            calls,
            listeners,
            sessionValues,
        };
    };
    const epochA = '018f0000-0000-7000-8000-0000000000a1';
    const epochB = '018f0000-0001-7000-8000-0000000000b2';
    const page = (epoch, clearHistory = false) => ({
        clearHistory,
        props: {
            authHistoryBoundary: { version: 2, epoch },
            receiptMemoryContext: {
                sessionNonce: 'must-never-enter-local-storage',
            },
        },
    });
    const tabA = makeTab();
    assert.equal(tabA.boundary.acceptServerPage(page(epochA, true)), true);
    const tabB = makeTab();
    assert.equal(tabB.boundary.acceptServerPage(page(epochA)), true);
    tabA.calls.length = 0;
    tabB.calls.length = 0;
    tabA.sessionValues.set('historyKey', 'key-a');
    tabA.sessionValues.set('historyIv', 'iv-a');
    tabB.sessionValues.set('historyKey', 'key-b');
    tabB.sessionValues.set('historyIv', 'iv-b');

    tabA.boundary.retireForLogout();

    assert.deepEqual(tabA.calls, ['clear']);
    assert.deepEqual(tabB.calls, ['clear', 'reload']);
    assert.equal(tabA.sessionValues.has('historyKey'), false);
    assert.equal(tabB.sessionValues.has('historyIv'), false);
    assert.equal(tabA.sessionValues.get('unrelated-tab'), 'retained');
    assert.equal(sharedValues.get('unrelated'), 'retained');
    const durableAfterLogout = sharedValues.get(
        boundaryModule.exports.AUTH_HISTORY_SHARED_STORAGE_KEY,
    );
    assert.doesNotMatch(durableAfterLogout, /receipt|purchase|actor|quantity/i);
    assert.doesNotMatch(durableAfterLogout, /must-never-enter-local-storage/);

    const staleTab = makeTab(false);
    staleTab.calls.length = 0;
    assert.equal(staleTab.boundary.acceptServerPage(page(epochA)), false);
    assert.deepEqual(staleTab.calls, ['clear', 'reload']);

    assert.equal(tabA.boundary.acceptServerPage(page(epochB, true)), true);
    const durableEpochB = sharedValues.get(
        boundaryModule.exports.AUTH_HISTORY_SHARED_STORAGE_KEY,
    );
    const retiredTab = makeTab(false);
    retiredTab.calls.length = 0;
    assert.equal(retiredTab.boundary.acceptServerPage(page(epochA)), false);
    assert.deepEqual(retiredTab.calls, ['clear', 'reload']);
    assert.equal(
        sharedValues.get(
            boundaryModule.exports.AUTH_HISTORY_SHARED_STORAGE_KEY,
        ),
        durableEpochB,
    );

    for (let transition = 1; transition <= 12; transition++) {
        const epoch = `018f0000-${String(transition + 1).padStart(4, '0')}-7000-8000-${String(transition).padStart(12, '0')}`;
        assert.equal(tabA.boundary.acceptServerPage(page(epoch, true)), true);
    }

    const veryStaleTab = makeTab(false);
    veryStaleTab.calls.length = 0;
    assert.equal(
        veryStaleTab.boundary.acceptServerPage(page(epochA, true)),
        false,
    );
    assert.deepEqual(veryStaleTab.calls, ['clear', 'reload']);

    const delayedValues = new Map();
    const delayedStorage = storage(delayedValues);
    const currentTab = makeTab(false, delayedStorage, []);
    assert.equal(
        currentTab.boundary.acceptServerPage(page(epochB, true)),
        true,
    );
    const delayedOldTab = makeTab(false, delayedStorage, []);
    delayedOldTab.calls.length = 0;
    assert.equal(
        delayedOldTab.boundary.acceptServerPage(page(epochA, true)),
        false,
    );
    assert.deepEqual(delayedOldTab.calls, ['clear', 'reload']);
    assert.equal(
        JSON.parse(
            delayedValues.get(
                boundaryModule.exports.AUTH_HISTORY_SHARED_STORAGE_KEY,
            ),
        ).serverEpoch,
        epochB,
    );

    const writeFailureValues = new Map();
    const writableStorage = storage(writeFailureValues);
    const writeFailureChannels = [];
    const writableTab = makeTab(true, writableStorage, writeFailureChannels);
    assert.equal(
        writableTab.boundary.acceptServerPage(page(epochA, true)),
        true,
    );
    const readOnlyStorage = {
        get length() {
            return writeFailureValues.size;
        },
        getItem: writableStorage.getItem,
        key: writableStorage.key,
        removeItem: writableStorage.removeItem,
        setItem: () => {
            throw new Error('synthetic quota failure');
        },
    };
    const readOnlyInitiator = makeTab(
        true,
        readOnlyStorage,
        writeFailureChannels,
    );
    const readOnlyPeer = makeTab(true, readOnlyStorage, writeFailureChannels);
    assert.equal(
        readOnlyInitiator.boundary.acceptServerPage(page(epochA)),
        true,
    );
    assert.equal(readOnlyPeer.boundary.acceptServerPage(page(epochA)), true);
    readOnlyInitiator.calls.length = 0;
    readOnlyPeer.calls.length = 0;
    readOnlyInitiator.sessionValues.set('historyKey', 'read-only-a');
    readOnlyPeer.sessionValues.set('historyKey', 'read-only-b');
    readOnlyInitiator.boundary.retireForLogout();
    assert.deepEqual(readOnlyInitiator.calls, ['clear']);
    assert.deepEqual(readOnlyPeer.calls, ['clear', 'reload']);
    assert.equal(readOnlyPeer.sessionValues.has('historyKey'), false);

    const noChannelValues = new Map();
    const noChannelWritable = storage(noChannelValues);
    const noChannelBootstrap = makeTab(false, noChannelWritable, []);
    assert.equal(
        noChannelBootstrap.boundary.acceptServerPage(page(epochA, true)),
        true,
    );
    const noChannelReadOnly = {
        get length() {
            return noChannelValues.size;
        },
        getItem: noChannelWritable.getItem,
        key: noChannelWritable.key,
        removeItem: noChannelWritable.removeItem,
        setItem: () => {
            throw new Error('synthetic quota failure without broadcast');
        },
    };
    const noChannelInitiator = makeTab(false, noChannelReadOnly, []);
    const noChannelPeer = makeTab(false, noChannelReadOnly, []);
    assert.equal(
        noChannelInitiator.boundary.acceptServerPage(page(epochA)),
        true,
    );
    assert.equal(noChannelPeer.boundary.acceptServerPage(page(epochA)), true);
    noChannelInitiator.calls.length = 0;
    noChannelPeer.calls.length = 0;
    noChannelPeer.sessionValues.set('historyKey', 'no-channel-peer');
    noChannelInitiator.boundary.retireForLogout();
    assert.equal(
        noChannelValues.has(
            boundaryModule.exports.AUTH_HISTORY_SHARED_STORAGE_KEY,
        ),
        false,
    );
    noChannelPeer.listeners.get('focus')();
    assert.deepEqual(noChannelPeer.calls, ['clear', 'reload']);
    assert.equal(noChannelPeer.sessionValues.has('historyKey'), false);

    const failedPublicationValues = new Map();
    const failedPublicationWritable = storage(failedPublicationValues);
    const failedPublicationReadOnly = {
        get length() {
            return failedPublicationValues.size;
        },
        getItem: failedPublicationWritable.getItem,
        key: failedPublicationWritable.key,
        removeItem: failedPublicationWritable.removeItem,
        setItem: () => {
            throw new Error('synthetic persistent write failure');
        },
    };
    const sameTabSession = new Map();
    const failedPublicationTab = makeTab(
        false,
        failedPublicationReadOnly,
        [],
        sameTabSession,
    );
    assert.equal(
        failedPublicationTab.boundary.acceptServerPage(page(epochB, true)),
        true,
    );
    failedPublicationTab.boundary.dispose();
    const delayedOldPage = makeTab(
        false,
        failedPublicationReadOnly,
        [],
        sameTabSession,
    );
    delayedOldPage.calls.length = 0;
    assert.equal(
        delayedOldPage.boundary.acceptServerPage(page(epochA, true)),
        false,
    );
    assert.deepEqual(delayedOldPage.calls, ['clear', 'reload']);

    channels.splice(0);
    sharedValues.clear();
    const initiator = makeTab(false);
    const dormant = makeTab(false);
    assert.equal(initiator.boundary.acceptServerPage(page(epochA, true)), true);
    assert.equal(dormant.boundary.acceptServerPage(page(epochA)), true);
    initiator.calls.length = 0;
    dormant.calls.length = 0;
    initiator.boundary.retireForLogout();
    assert.deepEqual(dormant.calls, []);
    dormant.listeners.get('pageshow')({ persisted: true });
    assert.deepEqual(dormant.calls, ['clear', 'reload']);
    assert.equal(dormant.sessionValues.has('historyKey'), false);

    const unavailableCalls = [];
    const unavailable = create(
        { clearHistory: () => unavailableCalls.push('clear') },
        {
            addEventListener: () => {},
            createBroadcastChannel: null,
            createEpoch: () => null,
            localStorage: null,
            reload: () => unavailableCalls.push('reload'),
            removeEventListener: () => {},
            sessionStorage: null,
            visibilityState: () => 'visible',
        },
    );
    assert.doesNotThrow(() => unavailable.acceptServerPage(page(epochB, true)));
    assert.doesNotThrow(() => unavailable.retireForLogout());
    assert.ok(unavailableCalls.includes('clear'));

    const appSource = read('resources/js/app.ts');
    assert.ok(
        appSource.indexOf('installAuthenticationHistoryBoundary(') <
            appSource.indexOf('createInertiaApp('),
    );
    assert.match(appSource, /page:\s*initialPage/);
    assert.match(appSource, /router\.on\('navigate'/);
});

test('receipt quantities use the backend decimal grammar without numeric coercion or partial submission', () => {
    const normalize = intentModule.exports.normalizeInventoryReceiptQuantity;
    const validate = intentModule.exports.validateInventoryReceiptPayload;
    const prepare = intentModule.exports.prepareInventoryReceiptSubmission;
    const empty = intentModule.exports.emptyInventoryReceiptRememberedState;
    const context = receiptMemoryContext();
    const po = '00000000-0000-4000-8000-000000000091';
    const lineA = '00000000-0000-4000-8000-000000000092';
    const lineB = '00000000-0000-4000-8000-000000000093';
    const rawLine = (line_public_id, quantity) => ({
        line_public_id,
        quantity,
        batch_number: null,
        expiry_date: null,
    });

    for (const [raw, canonical] of [
        ['1', '1.000'],
        ['1.0', '1.000'],
        ['1.00', '1.000'],
        ['1.000', '1.000'],
        ['0001.000', '1.000'],
        ['000000000001.2', '1.200'],
        ['999999999999.999', '999999999999.999'],
    ]) {
        assert.equal(normalize(raw), canonical, raw);
    }

    for (const raw of [
        '',
        ' ',
        '   ',
        ' 1',
        '1 ',
        '+1',
        '-1',
        '.5',
        '1.',
        '1e3',
        '0x10',
        '1,5',
        'NaN',
        'Infinity',
        '1.2345',
        '0.0004',
        '1000000000000',
        '0',
        '0.0',
        '0.00',
        '0.000',
        '000000000000',
        1,
        1.2,
        null,
        undefined,
    ]) {
        assert.equal(normalize(raw), null, String(raw));
    }

    const emptyPayload = validate([rawLine(lineA, '')]);
    assert.equal(emptyPayload.valid, false);
    assert.equal(
        emptyPayload.error,
        'Enter at least one positive receipt quantity.',
    );
    assert.equal(
        validate([rawLine(lineA, '1.000'), rawLine(lineB, '1e3')]).valid,
        false,
    );
    assert.equal(validate([rawLine(lineA, 1.2)]).valid, false);

    let keyCalls = 0;
    const invalid = prepare(
        empty(context),
        context,
        po,
        [rawLine(lineA, '1.000'), rawLine(lineB, '1.2345')],
        () => {
            keyCalls++;

            return '00000000-0000-4000-8000-000000000094';
        },
    );
    assert.equal(invalid.valid, false);
    assert.equal(keyCalls, 0);

    const keyA = '00000000-0000-4000-8000-000000000095';
    const keyB = '00000000-0000-4000-8000-000000000096';
    const first = prepare(
        empty(context),
        context,
        po,
        [rawLine(lineA, '0001.0')],
        () => {
            keyCalls++;

            return keyA;
        },
    );
    assert.equal(first.valid, true);
    assert.equal(first.entry.payload[0].quantity, '1.000');
    assert.equal(first.entry.signature, JSON.stringify(first.entry.payload));

    const invalidEdit = prepare(
        first.state,
        context,
        po,
        [rawLine(lineA, '1e3')],
        () => {
            keyCalls++;

            return keyB;
        },
    );
    assert.equal(invalidEdit.valid, false);

    const correctedSame = prepare(
        first.state,
        context,
        po,
        [rawLine(lineA, '1.000')],
        () => {
            keyCalls++;

            return keyB;
        },
    );
    assert.equal(correctedSame.valid, true);
    assert.equal(correctedSame.entry.idempotencyKey, keyA);

    const correctedDifferent = prepare(
        first.state,
        context,
        po,
        [rawLine(lineA, '2.000')],
        () => {
            keyCalls++;

            return keyB;
        },
    );
    assert.equal(correctedDifferent.valid, true);
    assert.equal(correctedDifferent.entry.idempotencyKey, keyB);
    assert.notEqual(correctedDifferent.entry.idempotencyKey, keyA);
    assert.equal(keyCalls, 2);

    const component = read(
        'resources/js/components/inventory/InventoryOperationsPanel.vue',
    );
    const receive = component.slice(
        component.indexOf('const receivePurchaseOrder'),
        component.indexOf('const confirmPurchaseOrderReceipt'),
    );
    assert.ok(
        receive.indexOf('prepareInventoryReceiptSubmission(') <
            receive.indexOf('if (!intent.valid)'),
    );
    assert.ok(
        receive.indexOf('if (!intent.valid)') <
            receive.indexOf('persistRememberedReceipts(intent.state)'),
    );
    assert.ok(
        receive.indexOf('persistRememberedReceipts(intent.state)') <
            receive.indexOf("send(\n        'post'"),
    );
    assert.match(receive, /lines: submitted\.payload/);
    assert.doesNotMatch(receive, /Number\(|parseFloat|parseInt|toFixed/);
});

test('receipt remembered state survives serialization and remount without rotating its retry key', () => {
    const empty = intentModule.exports.emptyInventoryReceiptRememberedState;
    const remember = intentModule.exports.rememberInventoryReceiptIntent;
    const restore = intentModule.exports.restoreInventoryReceiptRememberedState;
    const emptyVault =
        intentModule.exports.emptyInventoryReceiptRememberedVault;
    const restoreVault =
        intentModule.exports.restoreInventoryReceiptRememberedVault;
    const store = intentModule.exports.storeInventoryReceiptRememberedState;
    const consume = intentModule.exports.consumeInventoryReceiptIntent;
    const drafts = intentModule.exports.inventoryReceiptDrafts;
    const context = receiptMemoryContext();
    const poA = '00000000-0000-4000-8000-000000000101';
    const poB = '00000000-0000-4000-8000-000000000102';
    const lineA = '00000000-0000-4000-8000-000000000201';
    const lineB = '00000000-0000-4000-8000-000000000202';
    const keyA = '00000000-0000-4000-8000-00000000000a';
    const keyB = '00000000-0000-4000-8000-00000000000b';
    const keyC = '00000000-0000-4000-8000-00000000000c';
    const keys = [keyA, keyB, keyC];
    const createKey = () => keys.shift();
    const allowed = { [poA]: [lineA], [poB]: [lineB] };
    const payloadA = [
        {
            line_public_id: lineA,
            quantity: '3',
            batch_number: ' BATCH-A ',
            expiry_date: '2028-12-31',
        },
    ];

    const first = remember(empty(context), context, poA, payloadA, createKey);
    assert.equal(first.entry.idempotencyKey, keyA);
    assert.equal(first.entry.payload[0].quantity, '3.000');
    assert.equal(first.entry.payload[0].batch_number, 'BATCH-A');

    // The request became ambiguous: no success consumption occurred. Simulate
    // destruction, JSON history serialization, and a fresh component owner.
    const persisted = store(emptyVault(), first.state);
    const remountedVault = restoreVault(JSON.parse(JSON.stringify(persisted)));
    const remounted = restore(
        remountedVault.contexts[context],
        context,
        allowed,
    );
    assert.equal(remounted.entries[poA].idempotencyKey, keyA);
    assert.equal(drafts(remounted)[poA][lineA].quantity, '3.000');

    const ambiguousRetry = remember(
        remounted,
        context,
        poA,
        payloadA,
        createKey,
    );
    const validationRetry = remember(
        ambiguousRetry.state,
        context,
        poA,
        payloadA,
        createKey,
    );
    assert.equal(ambiguousRetry.entry.idempotencyKey, keyA);
    assert.equal(validationRetry.entry.idempotencyKey, keyA);

    const changed = remember(
        validationRetry.state,
        context,
        poA,
        [{ ...payloadA[0], quantity: '4.000' }],
        createKey,
    );
    assert.equal(changed.entry.idempotencyKey, keyB);
    assert.notEqual(changed.entry.idempotencyKey, keyA);

    const delayedFirstSuccess = consume(
        changed.state,
        context,
        poA,
        keyA,
        first.entry.signature,
    );
    assert.equal(delayedFirstSuccess.consumed, false);
    assert.equal(delayedFirstSuccess.state.entries[poA].idempotencyKey, keyB);

    const secondOrder = remember(
        changed.state,
        context,
        poB,
        [
            {
                line_public_id: lineB,
                quantity: '2.000',
                batch_number: null,
                expiry_date: null,
            },
        ],
        createKey,
    );
    assert.equal(secondOrder.entry.idempotencyKey, keyC);
    assert.equal(secondOrder.state.entries[poA].idempotencyKey, keyB);

    const success = consume(
        secondOrder.state,
        context,
        poA,
        keyB,
        changed.entry.signature,
    );
    assert.equal(success.consumed, true);
    assert.equal(success.state.entries[poA], undefined);
    assert.equal(success.state.entries[poB].idempotencyKey, keyC);
    assert.equal(drafts(success.state)[poA], undefined);

    const afterSuccessRemount = restore(
        JSON.parse(JSON.stringify(success.state)),
        context,
        allowed,
    );
    assert.equal(afterSuccessRemount.entries[poA], undefined);
    assert.equal(afterSuccessRemount.entries[poB].idempotencyKey, keyC);

    const nextKey = '00000000-0000-4000-8000-00000000000d';
    const nextReceipt = remember(
        afterSuccessRemount,
        context,
        poA,
        payloadA,
        () => nextKey,
    );
    assert.equal(nextReceipt.entry.idempotencyKey, nextKey);
    assert.notEqual(nextReceipt.entry.idempotencyKey, keyA);
});

test('malformed, obsolete, cross-context, and duplicate receipt memories fail safely', () => {
    const empty = intentModule.exports.emptyInventoryReceiptRememberedState;
    const remember = intentModule.exports.rememberInventoryReceiptIntent;
    const restore = intentModule.exports.restoreInventoryReceiptRememberedState;
    const context = receiptMemoryContext({
        sessionNonce: '00000000-0000-4000-8000-000000000902',
    });
    const poA = '00000000-0000-4000-8000-000000000301';
    const poB = '00000000-0000-4000-8000-000000000302';
    const lineA = '00000000-0000-4000-8000-000000000401';
    const lineB = '00000000-0000-4000-8000-000000000402';
    const sharedKey = '00000000-0000-4000-8000-00000000000e';
    const payload = (line) => [
        {
            line_public_id: line,
            quantity: '1.000',
            batch_number: null,
            expiry_date: null,
        },
    ];
    const first = remember(
        empty(context),
        context,
        poA,
        payload(lineA),
        () => sharedKey,
    );
    const duplicate = remember(first.state, context, poB, payload(lineB), () =>
        sharedKey.toUpperCase(),
    );
    const allowed = { [poA]: [lineA], [poB]: [lineB] };

    assert.equal(
        Object.keys(restore(null, context, allowed).entries).length,
        0,
    );
    assert.equal(
        Object.keys(
            restore(
                first.state,
                receiptMemoryContext({ actorPublicId: opaqueIdentity('d') }),
                allowed,
            ).entries,
        ).length,
        0,
    );
    assert.equal(
        Object.keys(restore(first.state, context, { [poB]: [lineB] }).entries)
            .length,
        0,
    );
    assert.equal(
        Object.keys(restore(duplicate.state, context, allowed).entries).length,
        1,
    );

    const malformed = JSON.parse(JSON.stringify(first.state));
    malformed.entries[poA].idempotencyKey = 'not-a-uuid';
    assert.equal(
        Object.keys(restore(malformed, context, allowed).entries).length,
        0,
    );

    const prototypeEntry = JSON.stringify(first.state.entries[poA]);
    const prototypePo = JSON.parse(
        `{"version":2,"context":"${context}","entries":{"__proto__":${prototypeEntry}}}`,
    );
    assert.doesNotThrow(() => restore(prototypePo, context, allowed));
    assert.equal(
        Object.keys(restore(prototypePo, context, allowed).entries).length,
        0,
    );
});

test('receipt remembered vault isolates branches and delayed success only consumes its submitted context', () => {
    const empty = intentModule.exports.emptyInventoryReceiptRememberedState;
    const emptyVault =
        intentModule.exports.emptyInventoryReceiptRememberedVault;
    const restoreVault =
        intentModule.exports.restoreInventoryReceiptRememberedVault;
    const store = intentModule.exports.storeInventoryReceiptRememberedState;
    const remember = intentModule.exports.rememberInventoryReceiptIntent;
    const consume = intentModule.exports.consumeInventoryReceiptIntent;
    const contextA = receiptMemoryContext();
    const contextB = receiptMemoryContext({
        branchPublicId: opaqueIdentity('d'),
    });
    const poA = '00000000-0000-4000-8000-000000000501';
    const poB = '00000000-0000-4000-8000-000000000502';
    const lineA = '00000000-0000-4000-8000-000000000601';
    const lineB = '00000000-0000-4000-8000-000000000602';
    const keyA = '00000000-0000-4000-8000-00000000001a';
    const keyB = '00000000-0000-4000-8000-00000000001b';
    const payload = (line, quantity) => [
        {
            line_public_id: line,
            quantity,
            batch_number: null,
            expiry_date: null,
        },
    ];
    const receiptA = remember(
        empty(contextA),
        contextA,
        poA,
        payload(lineA, '1'),
        () => keyA,
    );
    const receiptB = remember(
        empty(contextB),
        contextB,
        poB,
        payload(lineB, '2'),
        () => keyB,
    );
    const serialized = JSON.parse(
        JSON.stringify(
            store(store(emptyVault(), receiptA.state), receiptB.state),
        ),
    );
    const remountedVault = restoreVault(serialized);

    assert.equal(
        remountedVault.contexts[contextA].entries[poA].idempotencyKey,
        keyA,
    );
    assert.equal(
        remountedVault.contexts[contextB].entries[poB].idempotencyKey,
        keyB,
    );

    const delayedSuccessA = consume(
        remountedVault.contexts[contextA],
        contextA,
        poA,
        keyA,
        receiptA.entry.signature,
    );
    const afterDelayedSuccess = store(remountedVault, delayedSuccessA.state);

    assert.equal(delayedSuccessA.consumed, true);
    assert.equal(
        afterDelayedSuccess.contexts[contextA].entries[poA],
        undefined,
    );
    assert.equal(
        afterDelayedSuccess.contexts[contextB].entries[poB].idempotencyKey,
        keyB,
    );

    const malformedVault = JSON.parse(
        '{"version":2,"contexts":{"__proto__":{"version":2,"context":"__proto__","entries":{}}}}',
    );
    assert.deepEqual(restoreVault(malformedVault), emptyVault());
});

test('receipt remembered state is isolated by organisation, actor, session, branch, and legacy version', () => {
    const contextFor = intentModule.exports.inventoryReceiptRememberContext;
    const empty = intentModule.exports.emptyInventoryReceiptRememberedState;
    const emptyVault =
        intentModule.exports.emptyInventoryReceiptRememberedVault;
    const restore = intentModule.exports.restoreInventoryReceiptRememberedState;
    const restoreVault =
        intentModule.exports.restoreInventoryReceiptRememberedVault;
    const store = intentModule.exports.storeInventoryReceiptRememberedState;
    const remember = intentModule.exports.rememberInventoryReceiptIntent;
    const consume = intentModule.exports.consumeInventoryReceiptIntent;
    const contextA = receiptMemoryContext();
    const contextActorB = receiptMemoryContext({
        actorPublicId: opaqueIdentity('d'),
    });
    const contextSessionB = receiptMemoryContext({
        sessionNonce: '00000000-0000-4000-8000-000000000902',
    });
    const contextOrganisationB = receiptMemoryContext({
        organisationPublicId: opaqueIdentity('e'),
    });
    const contextBranchB = receiptMemoryContext({
        branchPublicId: opaqueIdentity('f'),
    });
    const po = '00000000-0000-4000-8000-000000000701';
    const line = '00000000-0000-4000-8000-000000000702';
    const keyA = '00000000-0000-4000-8000-00000000002a';
    const keyB = '00000000-0000-4000-8000-00000000002b';
    const allowed = { [po]: [line] };
    const payload = [
        {
            line_public_id: line,
            quantity: '1.000',
            batch_number: 'BATCH-SECURE',
            expiry_date: '2028-12-31',
        },
    ];
    const receiptA = remember(
        empty(contextA),
        contextA,
        po,
        payload,
        () => keyA,
    );
    const serializedA = JSON.parse(
        JSON.stringify(store(emptyVault(), receiptA.state)),
    );

    assert.equal(
        restoreVault(serializedA, contextA).contexts[contextA].entries[po]
            .idempotencyKey,
        keyA,
    );

    for (const isolatedContext of [
        contextActorB,
        contextSessionB,
        contextOrganisationB,
        contextBranchB,
    ]) {
        assert.equal(
            Object.keys(
                restore(receiptA.state, isolatedContext, allowed).entries,
            ).length,
            0,
        );
        assert.equal(
            Object.keys(restoreVault(serializedA, isolatedContext).contexts)
                .length,
            0,
        );
    }

    const receiptB = remember(
        empty(contextActorB),
        contextActorB,
        po,
        payload,
        () => keyB,
    );
    const bothActors = store(
        store(emptyVault(), receiptA.state),
        receiptB.state,
    );
    assert.equal(
        bothActors.contexts[contextA].entries[po].idempotencyKey,
        keyA,
    );
    assert.equal(
        bothActors.contexts[contextActorB].entries[po].idempotencyKey,
        keyB,
    );

    const delayedSuccessA = consume(
        bothActors.contexts[contextA],
        contextA,
        po,
        keyA,
        receiptA.entry.signature,
    );
    const afterSuccessA = store(bothActors, delayedSuccessA.state);
    assert.equal(afterSuccessA.contexts[contextA].entries[po], undefined);
    assert.equal(
        afterSuccessA.contexts[contextActorB].entries[po].idempotencyKey,
        keyB,
    );

    assert.deepEqual(
        restoreVault({
            version: 1,
            contexts: {
                'branch:17': {
                    version: 1,
                    context: 'branch:17',
                    entries: {},
                },
            },
        }),
        emptyVault(),
    );
    assert.equal(
        contextFor({
            ...receiptMemoryScope(),
            actorPublicId: '__proto__',
        }),
        null,
    );
    assert.equal(
        contextFor({
            ...receiptMemoryScope(),
            sessionNonce: 'not-a-session-nonce',
        }),
        null,
    );
    assert.equal(contextFor({ ...receiptMemoryScope(), version: 1 }), null);

    let bounded = emptyVault();

    for (const [index, character] of [
        '0',
        '1',
        '2',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
    ].entries()) {
        const context = receiptMemoryContext({
            actorPublicId: opaqueIdentity(character),
            sessionNonce: `00000000-0000-4000-8000-${String(index + 910).padStart(12, '0')}`,
        });
        bounded = store(bounded, empty(context));
    }

    assert.equal(Object.keys(bounded.contexts).length, 8);
});

test('adjustment intent identity survives retry and rotates only for new intent', () => {
    const identify = intentModule.exports.inventoryIntentIdentity;
    let sequence = 0;
    const createKey = () => `synthetic-key-${++sequence}`;
    const first = identify(undefined, 'canonical-receipt-a', createKey);
    const duplicateClick = identify(first, 'canonical-receipt-a', createKey);
    const ambiguousRetry = identify(first, 'canonical-receipt-a', createKey);
    const validationRetry = identify(first, 'canonical-receipt-a', createKey);
    const editedPayload = identify(first, 'canonical-receipt-b', createKey);
    const afterSuccess = identify(undefined, 'canonical-receipt-b', createKey);

    assert.equal(first.key, 'synthetic-key-1');
    assert.equal(duplicateClick.key, first.key);
    assert.equal(ambiguousRetry.key, first.key);
    assert.equal(validationRetry.key, first.key);
    assert.notEqual(editedPayload.key, first.key);
    assert.notEqual(afterSuccess.key, editedPayload.key);
});

test('filter and operation validation errors remain in separate accessible surfaces', () => {
    const filterError = intentModule.exports.inventoryFilterError;
    const operationError = intentModule.exports.firstInventoryOperationError;
    const context = intentModule.exports.inventoryOperationErrorContext;

    assert.equal(
        filterError({ status: 'Invalid stock status.' }),
        'Invalid stock status.',
    );
    assert.equal(filterError({ page: 'Invalid page.' }), 'Invalid page.');
    assert.equal(
        filterError({ inventoryOperations: { quantity: 'Too much stock.' } }),
        null,
    );
    assert.equal(
        operationError({ quantity: 'Receipt exceeds the remainder.' }),
        'Receipt exceeds the remainder.',
    );
    assert.equal(
        context('/inventory/purchase-orders/po/receipts'),
        'Goods receipt',
    );
    assert.equal(
        context('/inventory/stock-requests/request/dispatch'),
        'Transfer dispatch',
    );
    assert.equal(context('/inventory/stocktakes/stocktake/post'), 'Stocktake');
    assert.equal(context('/inventory/adjustments'), 'Manual adjustment');
    assert.equal(
        operationError({ lines: 'Dispatch quantities changed.' }),
        'Dispatch quantities changed.',
    );
    assert.equal(
        operationError({ stock: 'Stocktake is stale.' }),
        'Stocktake is stale.',
    );
    assert.equal(
        operationError({ idempotency_key: 'Adjustment intent changed.' }),
        'Adjustment intent changed.',
    );

    const page = read('resources/js/pages/Inventory/Index.vue');
    assert.match(page, /inventoryFilterError\(props\.errors \?\? \{\}\)/);
    assert.doesNotMatch(page, /Object\.values\(props\.errors/);
});
