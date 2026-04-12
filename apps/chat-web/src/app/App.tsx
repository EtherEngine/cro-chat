import { useEffect, useState } from 'react';
import { AppProvider, useApp } from '../store';
import { LoginPage } from '../pages/LoginPage';
import { ChatPage } from '../pages/ChatPage';
import { api } from '../api/client';
import { applyVisualSettings } from '../components/settings/SettingsModal';
import { usePWA, useDeepLinks } from '../hooks/usePWA';
import { CallProvider } from '../features/calls/CallProvider';
import { CallOverlay } from '../components/calls/CallOverlay';
import { CallDebugPanel } from '../components/calls/CallDebugPanel';
import { CallSimulatorPanel } from '../features/calls/dev/CallSimulatorPanel';
import './App.css';

// Apply saved visual settings on startup (before first render)
applyVisualSettings();

function AppInner() {
  const { state, dispatch } = useApp();
  const [checking, setChecking] = useState(true);

  // Initialize PWA (service worker, network monitoring)
  const { online, outboxCount } = usePWA();

  // Handle deep links from push notifications and URLs
  useDeepLinks();

  useEffect(() => {
    api.auth
      .me()
      .then(({ user }) => dispatch({ type: 'SET_USER', user }))
      .catch(() => {})
      .finally(() => setChecking(false));
  }, [dispatch]);

  if (checking) return null;
  if (!state.user) return <LoginPage />;
  return (
    <>
      {!online && (
        <div className="offline-banner">
          Offline – Nachrichten werden bei Verbindung gesendet
          {outboxCount > 0 && <span> ({outboxCount} ausstehend)</span>}
        </div>
      )}
      <CallProvider userId={state.user!.id}>
        <CallOverlay />
        <CallDebugPanel />
        <CallSimulatorPanel />
        <ChatPage />
      </CallProvider>
    </>
  );
}

export function App() {
  return (
    <AppProvider>
      <AppInner />
    </AppProvider>
  );
}
