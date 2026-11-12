export type InventoryOperationPermissions = Record<string, boolean>;

export const purchaseOrderActionAllowed = (
    status: string,
    action: 'submit' | 'approve' | 'receive' | 'close' | 'cancel',
    permissions: InventoryOperationPermissions,
): boolean => {
    if (action === 'submit') {
        return status === 'draft' && permissions.createPurchaseOrders === true;
    }

    if (action === 'approve') {
        return (
            status === 'submitted' && permissions.approvePurchaseOrders === true
        );
    }

    if (action === 'receive') {
        return (
            ['approved', 'partially_received'].includes(status) &&
            permissions.receiveGoods === true
        );
    }

    if (action === 'cancel') {
        return (
            (['draft', 'submitted'].includes(status) &&
                permissions.createPurchaseOrders === true) ||
            (status === 'approved' &&
                permissions.approvePurchaseOrders === true)
        );
    }

    return (
        status === 'fully_received' &&
        permissions.approvePurchaseOrders === true
    );
};

/**
 * STK-31: a request can now go to another branch, so each row says which end the viewer is at. The requesting branch
 * decides, the source branch dispatches and only the destination branch receives. Without flags (older callers) only
 * the status and permission count; the server always decides.
 */
export type StockRequestSides = {
    atRequesting?: boolean;
    atSource?: boolean;
    atDestination?: boolean;
};

export const stockRequestActionAllowed = (
    status: string,
    action: 'decide' | 'dispatch' | 'receive',
    permissions: InventoryOperationPermissions,
    sides: StockRequestSides = {},
): boolean => {
    if (action === 'decide') {
        return (
            status === 'requested' &&
            permissions.approveRequests === true &&
            sides.atRequesting !== false &&
            sides.atSource !== false
        );
    }

    if (action === 'dispatch') {
        return (
            status === 'approved' &&
            permissions.dispatchTransfers === true &&
            sides.atSource !== false
        );
    }

    return (
        status === 'dispatched' &&
        permissions.receiveTransfers === true &&
        sides.atDestination !== false
    );
};

export const stocktakeActionAllowed = (
    status: string,
    action: 'start' | 'count' | 'post' | 'cancel',
): boolean =>
    (action === 'start' && status === 'draft') ||
    (action === 'count' && status === 'counting') ||
    (action === 'post' && status === 'review') ||
    (action === 'cancel' && ['draft', 'counting', 'review'].includes(status));
