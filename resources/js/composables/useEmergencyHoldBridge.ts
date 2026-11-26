import { onBeforeUnmount, shallowRef } from 'vue';

export type EmergencyHoldBridge = {
    visitNumber: string;
    branchId: number;
    canHold: () => boolean;
    isHeld: () => boolean;
    request: (success: () => void, failure: (message: string) => void) => void;
    cancel: () => void;
};
export const emergencyHoldBridge = shallowRef<EmergencyHoldBridge | null>(null);

export function useEmergencyHoldBridge(bridge: EmergencyHoldBridge) {
    emergencyHoldBridge.value = bridge;
    onBeforeUnmount(() => {
        bridge.cancel();

        if (emergencyHoldBridge.value === bridge) {
            emergencyHoldBridge.value = null;
        }
    });
}
