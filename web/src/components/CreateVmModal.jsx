import { useEffect, useState } from 'react';
import { api, ApiError } from '../api';
import { Button, Field, Modal, inputClass } from './ui';

const GUEST_OPTIONS = [
  { value: 'otherGuest64Bit', label: 'Other (64-bit)' },
  { value: 'ubuntu64Guest', label: 'Ubuntu (64-bit)' },
  { value: 'centos8_64Guest', label: 'CentOS 8 (64-bit)' },
  { value: 'debian12_64Guest', label: 'Debian 12 (64-bit)' },
  { value: 'windows9Server64Guest', label: 'Windows Server (64-bit)' },
];

export default function CreateVmModal({ onClose, onCreated }) {
  const [datastores, setDatastores] = useState([]);
  const [networks, setNetworks] = useState([]);
  const [loadingInventory, setLoadingInventory] = useState(true);
  const [isoFiles, setIsoFiles] = useState([]);
  const [loadingIsos, setLoadingIsos] = useState(false);
  const [manualIso, setManualIso] = useState(false);

  const [form, setForm] = useState({
    name: '',
    cpu: 2,
    memoryMB: 2048,
    storageGB: 20,
    datastore: '',
    network: '',
    guestId: 'ubuntu64Guest',
    iso: '',
  });

  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    Promise.all([api.datastores(), api.networks()])
        .then(([ds, nets]) => {
          setDatastores(ds);
          setNetworks(nets);
          setForm((f) => ({
            ...f,
            datastore: ds.find((d) => d.accessible)?.name || ds[0]?.name || '',
            network: nets.find((n) => n.accessible)?.name || nets[0]?.name || '',
          }));
        })
        .catch((err) => setError(err instanceof ApiError ? err.message : 'Failed to load inventory.'))
        .finally(() => setLoadingInventory(false));
  }, []);

  // Re-scan for .iso files whenever the selected datastore changes.
  useEffect(() => {
    if (!form.datastore) return;

    setLoadingIsos(true);
    setIsoFiles([]);

    api
        .isoFiles(form.datastore)
        .then((files) => setIsoFiles(files))
        .catch(() => setIsoFiles([])) // non-fatal — user can still type a path manually
        .finally(() => setLoadingIsos(false));
  }, [form.datastore]);

  function update(field, value) {
    setForm((f) => ({ ...f, [field]: value }));
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const payload = { ...form };
      if (!payload.iso) delete payload.iso;
      const { id } = await api.createVM(payload);
      onCreated(id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to create VM.');
      setSubmitting(false);
    }
  }

  return (
      <Modal title="Create VM" onClose={onClose} wide>
        {submitting ? (
            <div className="flex flex-col items-center gap-3 py-10 text-center">
              <div className="h-8 w-8 animate-spin rounded-full border-2 border-cyan-500 border-t-transparent" />
              <p className="text-sm text-slate-400">
                Creating <span className="font-mono text-slate-200">{form.name}</span>… this can take a minute while
                ESXi allocates the disk.
              </p>
            </div>
        ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <Field label="VM Name">
                <input
                    className={inputClass}
                    value={form.name}
                    onChange={(e) => update('name', e.target.value)}
                    placeholder="ubuntu-01"
                    required
                    autoFocus
                />
              </Field>

              <div className="grid grid-cols-3 gap-3">
                <Field label="vCPUs">
                  <input
                      type="number"
                      min={1}
                      className={inputClass}
                      value={form.cpu}
                      onChange={(e) => update('cpu', Number(e.target.value))}
                      required
                  />
                </Field>
                <Field label="Memory (MB)">
                  <input
                      type="number"
                      min={512}
                      step={512}
                      className={inputClass}
                      value={form.memoryMB}
                      onChange={(e) => update('memoryMB', Number(e.target.value))}
                      required
                  />
                </Field>
                <Field label="Disk (GB)">
                  <input
                      type="number"
                      min={20}
                      className={inputClass}
                      value={form.storageGB}
                      onChange={(e) => update('storageGB', Number(e.target.value))}
                      required
                  />
                </Field>
              </div>

              <Field label="Guest OS">
                <select className={inputClass} value={form.guestId} onChange={(e) => update('guestId', e.target.value)}>
                  {GUEST_OPTIONS.map((g) => (
                      <option key={g.value} value={g.value}>
                        {g.label}
                      </option>
                  ))}
                </select>
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field label="Datastore">
                  <select
                      className={inputClass}
                      value={form.datastore}
                      onChange={(e) => update('datastore', e.target.value)}
                      disabled={loadingInventory}
                  >
                    {datastores.map((d) => (
                        <option key={d.id} value={d.name}>
                          {d.name} ({d.freeSpaceGB} GB free)
                        </option>
                    ))}
                  </select>
                </Field>
                <Field label="Network">
                  <select
                      className={inputClass}
                      value={form.network}
                      onChange={(e) => update('network', e.target.value)}
                      disabled={loadingInventory}
                  >
                    {networks.map((n) => (
                        <option key={n.id} value={n.name}>
                          {n.name}
                        </option>
                    ))}
                  </select>
                </Field>
              </div>

              <Field
                  label="ISO (optional)"
                  hint={
                    manualIso
                        ? 'e.g. [datastore1] isos/ubuntu-24.04.iso'
                        : loadingIsos
                            ? 'Scanning datastore for .iso files…'
                            : isoFiles.length === 0
                                ? 'No .iso files found on this datastore.'
                                : `${isoFiles.length} ISO file(s) found`
                  }
              >
                {manualIso ? (
                    <input
                        className={inputClass}
                        value={form.iso}
                        onChange={(e) => update('iso', e.target.value)}
                        placeholder="[datastore1] isos/ubuntu-24.04.iso"
                    />
                ) : (
                    <select
                        className={inputClass}
                        value={form.iso}
                        onChange={(e) => update('iso', e.target.value)}
                        disabled={loadingIsos}
                    >
                      <option value="">No ISO (empty CD drive)</option>
                      {isoFiles.map((f) => (
                          <option key={f.path} value={f.path}>
                            {f.name}
                          </option>
                      ))}
                    </select>
                )}
                <button
                    type="button"
                    onClick={() => {
                      setManualIso((m) => !m);
                      update('iso', '');
                    }}
                    className="mt-1.5 text-xs text-cyan-500 hover:text-cyan-400"
                >
                  {manualIso ? 'Pick from datastore instead' : "Can't find it? Enter path manually"}
                </button>
              </Field>

              {error && (
                  <div className="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">
                    {error}
                  </div>
              )}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="ghost" onClick={onClose}>
                  Cancel
                </Button>
                <Button type="submit">Create VM</Button>
              </div>
            </form>
        )}
      </Modal>
  );
}