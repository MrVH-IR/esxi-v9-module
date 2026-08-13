import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import { Button, PowerBadge, Spinner } from './ui';
import CreateVmModal from './CreateVmModal';
import VmDetailModal from './VmDetailModal';

export default function Dashboard({ host, onLoggedOut }) {
  const [vms, setVms] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showCreate, setShowCreate] = useState(false);
  const [selectedId, setSelectedId] = useState(null);
  const [busyId, setBusyId] = useState(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const data = await api.listVMs();
      setVms(data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load VMs.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  async function quickPower(id, action) {
    setBusyId(id);
    try {
      await api.powerVM(id, action);
      await load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Action failed.');
    } finally {
      setBusyId(null);
    }
  }

  async function handleLogout() {
    await api.logout().catch(() => {});
    onLoggedOut();
  }

  return (
    <div className="min-h-screen bg-slate-950">
      <header className="border-b border-slate-800">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-2.5">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-400 ring-1 ring-cyan-500/30">
              <svg viewBox="0 0 24 24" className="h-4.5 w-4.5" fill="none" stroke="currentColor" strokeWidth="1.5">
                <rect x="3" y="4" width="18" height="12" rx="1.5" />
                <path d="M8 20h8M12 16v4" strokeLinecap="round" />
              </svg>
            </div>
            <div>
              <h1 className="text-sm font-semibold text-slate-100">ESXi V9 Panel</h1>
              <p className="font-mono text-xs text-slate-500">{host}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={load} loading={loading}>
              Refresh
            </Button>
            <Button onClick={() => setShowCreate(true)}>+ Create VM</Button>
            <Button variant="subtle" onClick={handleLogout}>
              Log out
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-6 py-8">
        {error && (
          <div className="mb-4 rounded-md border border-red-500/30 bg-red-500/10 px-4 py-2.5 text-sm text-red-400">
            {error}
          </div>
        )}

        {loading ? (
          <div className="flex items-center justify-center py-24 text-slate-500">
            <Spinner className="h-6 w-6" />
          </div>
        ) : vms.length === 0 ? (
          <div className="rounded-lg border border-dashed border-slate-800 py-24 text-center">
            <p className="text-slate-400">No virtual machines yet.</p>
            <Button className="mt-4" onClick={() => setShowCreate(true)}>
              + Create your first VM
            </Button>
          </div>
        ) : (
          <div className="overflow-hidden rounded-lg border border-slate-800">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-800 bg-slate-900/50 text-left text-xs text-slate-500">
                  <th className="px-4 py-3 font-medium">Name</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">CPU</th>
                  <th className="px-4 py-3 font-medium">Memory</th>
                  <th className="px-4 py-3 font-medium">IP Address</th>
                  <th className="px-4 py-3 font-medium"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/70">
                {vms.map((vm) => (
                  <tr key={vm.id} className="cursor-pointer hover:bg-slate-900/40" onClick={() => setSelectedId(vm.id)}>
                    <td className="px-4 py-3 font-medium text-slate-100">{vm.name}</td>
                    <td className="px-4 py-3">
                      <PowerBadge state={vm.powerState} />
                    </td>
                    <td className="px-4 py-3 text-slate-400">{vm.cpu ?? '—'} vCPU</td>
                    <td className="px-4 py-3 text-slate-400">
                      {vm.memoryMB ? `${(vm.memoryMB / 1024).toFixed(1)} GB` : '—'}
                    </td>
                    <td className="px-4 py-3 font-mono text-slate-400">{vm.ip || '—'}</td>
                    <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                      {busyId === vm.id ? (
                        <Spinner className="ml-auto h-4 w-4 text-slate-500" />
                      ) : vm.powerState === 'poweredOn' ? (
                        <Button variant="ghost" onClick={() => quickPower(vm.id, 'off')}>
                          Power Off
                        </Button>
                      ) : (
                        <Button variant="ghost" onClick={() => quickPower(vm.id, 'on')}>
                          Power On
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </main>

      {showCreate && (
        <CreateVmModal
          onClose={() => setShowCreate(false)}
          onCreated={() => {
            setShowCreate(false);
            load();
          }}
        />
      )}

      {selectedId && (
        <VmDetailModal vmId={selectedId} onClose={() => setSelectedId(null)} onChanged={load} />
      )}
    </div>
  );
}
