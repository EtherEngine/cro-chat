import { createContext, useContext, type ReactNode } from 'react';
import { useCall } from './useCall';
import type { CallState, CallPhase } from './useCall';
import type { Call } from '../../types';

type CallActions = {
  startCall: (conversationId: number) => Promise<void>;
  acceptCall: () => Promise<void>;
  rejectCall: () => Promise<void>;
  cancelCall: () => Promise<void>;
  hangup: () => Promise<void>;
  toggleMute: () => void;
  refreshDevices: () => Promise<void>;
  switchAudioInput: (deviceId: string) => Promise<void>;
  switchAudioOutput: (deviceId: string) => void;
  checkMicPermission: () => Promise<PermissionState | null>;
};

type CallContextValue = {
  callState: CallState;
} & CallActions;

const CallContext = createContext<CallContextValue | null>(null);

/**
 * Provides call state and actions to the entire app.
 * Must be rendered inside AppProvider (needs userId from auth state).
 */
export function CallProvider({
  userId,
  children,
}: {
  userId: number;
  children: ReactNode;
}) {
  const value = useCall(userId);

  return <CallContext.Provider value={value}>{children}</CallContext.Provider>;
}

/**
 * Access call state and actions from any component.
 */
export function useCallContext(): CallContextValue {
  const ctx = useContext(CallContext);
  if (!ctx) throw new Error('useCallContext must be used within CallProvider');
  return ctx;
}
