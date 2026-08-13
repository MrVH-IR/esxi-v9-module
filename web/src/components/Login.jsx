import { useState } from 'react';
import { api, ApiError } from '../api';
import { Button, Field, inputClass } from './ui';

export default function Login({ onLoggedIn }) {
  const [host, setHost] = useState('');
  const [username, setUsername] = useState('root');
  const [password, setPassword] = useState('');
  const [allowSelfSigned, setAllowSelfSigned] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(e) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      await api.login(host, username, password, { allowSelfSigned });
      onLoggedIn(host);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not reach the API.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-950 px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <div className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-400 ring-1 ring-cyan-500/30">
            <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="1.5">
              <rect x="3" y="4" width="18" height="12" rx="1.5" />
              <path d="M8 20h8M12 16v4" strokeLinecap="round" />
            </svg>
          </div>
          <h1 className="text-lg font-semibold text-slate-100">ESXi V9 Panel</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to your ESXi host</p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4 rounded-lg border border-slate-800 bg-slate-900/50 p-6">
          <Field label="Host / IP">
            <input
              className={inputClass}
              placeholder="192.168.1.10"
              value={host}
              onChange={(e) => setHost(e.target.value)}
              required
              autoFocus
            />
          </Field>

          <Field label="Username">
            <input className={inputClass} value={username} onChange={(e) => setUsername(e.target.value)} required />
          </Field>

          <Field label="Password">
            <input
              type="password"
              className={inputClass}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </Field>

          <label className="flex items-center gap-2 text-sm text-slate-400">
            <input
              type="checkbox"
              checked={allowSelfSigned}
              onChange={(e) => setAllowSelfSigned(e.target.checked)}
              className="h-4 w-4 rounded border-slate-700 bg-slate-800 text-cyan-500 focus:ring-cyan-500"
            />
            Allow self-signed certificate
          </label>

          {error && (
            <div className="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">
              {error}
            </div>
          )}

          <Button type="submit" loading={loading} className="w-full">
            {loading ? 'Connecting…' : 'Connect'}
          </Button>
        </form>
      </div>
    </div>
  );
}
