import { useState, type FormEvent } from 'react';
import { useApp } from '../store';
import { api } from '../api/client';

export function LoginPage() {
  const { dispatch } = useApp();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    try {
      const { user } = await api.auth.login(email, password);
      dispatch({ type: 'SET_USER', user });
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Login fehlgeschlagen');
    }
  };

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={handleSubmit}>
        <h1>cr\u00f8</h1>
        <p>Melde dich an, um zu chatten</p>
        {error && <div className="login-error">{error}</div>}
        <input
          type="email"
          placeholder="E-Mail-Adresse"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoFocus
        />
        <input
          type="password"
          placeholder="Passwort"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
        <button type="submit">Anmelden</button>
      </form>
    </div>
  );
}

