import { useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import { Button, Field, Modal, PowerBadge, inputClass } from './ui';

export default function VmDetailModal({ vmId, onClose, onChanged }) {
  const [vm, setVm] = useState(null);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(null); // which action is in flight
  const [cpu, setCpu] = useState('');
  const [memoryMB, setMemoryMB] = useState('');
  const [confirmDelete, setConfirmDelete] = useState(false);

  async function refresh() {
    try {
      const data = await api.getVM(vmId);
      setVm(data);
      setCpu(data.cpu ?? '');
      setMemoryMB(data.memoryMB ?? '');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to load VM.');
    }
  }

  useEffect(() => {
    refresh();
  }, [vmId]);

  async function runAction(name, fn) {
    setBusy(name);
    setError(null);
    try {
      await fn();
      await refresh();
      onChanged?.();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : `${name} failed.`);
    } finally {
      setBusy(null);
    }
  }

  async function handleDelete() {
    if (!confirmDelete) {
      setConfirmDelete(true);
      return;
    }
    setBusy('delete');
    setError(null);
    try {
      await api.deleteVM(vmId);
      onChanged?.();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Delete failed.');
      setBusy(null);
    }
  }

  if (!vm) {
    return (
      <Modal title="VM Details" onClose={onClose}>
        {error ? (
          <p className="text-sm text-red-400">{error}</p>
        ) : (
          <p className="text-sm text-slate-500">Loading…</p>
        )}
      </Modal>
    );
  }

  const isOn = vm.powerState === 'poweredOn';
  const resizeDirty = Number(cpu) !== vm.cpu || Number(memoryMB) !== vm.memoryMB;

  return (
    <Modal title={vm.name || vmId} onClose={onClose}>
      <div className="space-y-5">
        <div className="flex items-center justify-between">
          <PowerBadge state={vm.powerState} />
          <span className="font-mono text-xs text-slate-500">{vmId}</span>
        </div>

        <dl className="grid grid-cols-2 gap-y-2 text-sm">
          <dt className="text-slate-500">IP Address</dt>
          <dd className="text-right font-mono text-slate-200">{vm.ip || '—'}</dd>
          <dt className="text-slate-500">Hostname</dt>
          <dd className="text-right text-slate-200">{vm.hostname || '—'}</dd>
          <dt className="text-slate-500">VMware Tools</dt>
          <dd className="text-right text-slate-200">{vm.toolsStatus || '—'}</dd>
        </dl>

        <div className="flex gap-2">
          <Button
            variant="ghost"
            className="flex-1"
            disabled={isOn}
            loading={busy === 'on'}
            onClick={() => runAction('on', () => api.powerVM(vmId, 'on'))}
          >
            Power On
          </Button>
          <Button
            variant="ghost"
            className="flex-1"
            disabled={!isOn}
            loading={busy === 'off'}
            onClick={() => runAction('off', () => api.powerVM(vmId, 'off'))}
          >
            Power Off
          </Button>
          <Button
            variant="ghost"
            className="flex-1"
            disabled={!isOn}
            loading={busy === 'reset'}
            onClick={() => runAction('reset', () => api.powerVM(vmId, 'reset'))}
          >
            Restart
          </Button>
        </div>

        <div className="rounded-md border border-slate-800 p-4">
          <h3 className="mb-3 text-xs font-medium text-slate-400">
            Resources {isOn && <span className="text-amber-400">(power off to change)</span>}
          </h3>
          <div className="grid grid-cols-2 gap-3">
            <Field label="vCPUs">
              <input
                type="number"
                min={1}
                className={inputClass}
                value={cpu}
                disabled={isOn}
                onChange={(e) => setCpu(e.target.value)}
              />
            </Field>
            <Field label="Memory (MB)">
              <input
                type="number"
                min={512}
                step={512}
                className={inputClass}
                value={memoryMB}
                disabled={isOn}
                onChange={(e) => setMemoryMB(e.target.value)}
              />
            </Field>
          </div>
          <Button
            className="mt-3 w-full"
            disabled={isOn || !resizeDirty}
            loading={busy === 'resize'}
            onClick={() =>
              runAction('resize', () => api.resizeVM(vmId, { cpu: Number(cpu), memoryMB: Number(memoryMB) }))
            }
          >
            Apply Resize
          </Button>
        </div>

        {error && (
          <div className="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">
            {error}
          </div>
        )}

        <div className="flex justify-between border-t border-slate-800 pt-4">
          <Button variant="subtle" onClick={onClose}>
            Close
          </Button>
          <Button variant="danger" loading={busy === 'delete'} onClick={handleDelete}>
            {confirmDelete ? 'Confirm Delete' : 'Delete VM'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
