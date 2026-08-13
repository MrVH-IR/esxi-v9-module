import { useEffect, useState } from 'react';
import { api } from './api';
import Login from './components/Login';
import Dashboard from './components/Dashboard';
import { Spinner } from './components/ui';

export default function App() {
  const [checking, setChecking] = useState(true);
  const [host, setHost] = useState(null);

  useEffect(() => {
    api
      .session()
      .then((s) => setHost(s.authenticated ? s.host : null))
      .catch(() => setHost(null))
      .finally(() => setChecking(false));
  }, []);

  if (checking) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-950 text-slate-500">
        <Spinner className="h-6 w-6" />
      </div>
    );
  }

  if (!host) {
    return <Login onLoggedIn={setHost} />;
  }

  return <Dashboard host={host} onLoggedOut={() => setHost(null)} />;
}
