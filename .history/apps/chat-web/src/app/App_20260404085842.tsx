import { useEffect, useState } from 'react';
import { AppProvider, useApp } from '../store';
import { LoginPage } from '../pages/LoginPage';
import { ChatPage } from '../pages/ChatPage';
import { api } from '../api/client';
import './App.css';

function AppInner() {
  const { state, dispatch } = useApp();
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    api.auth
      .me()
      .then(({ user }) => dispatch({ type: 'SET_USER', user }))
      .catch(() => {})
      .finally(() => setChecking(false));
  }, [dispatch]);

  if (checking) return null;
  if (!state.user) return <LoginPage />;
  return <ChatPage />;
}

export function App() {
  return (
    <AppProvider>
      <AppInner />
    </AppProvider>
  );
}
