const BASE = '/api';

class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

async function request(path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    credentials: 'include', // send the PHP session cookie
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });

  const isJson = res.headers.get('content-type')?.includes('application/json');
  const body = isJson ? await res.json() : null;

  if (!res.ok) {
    throw new ApiError(body?.error || `Request failed (${res.status})`, res.status);
  }

  return body;
}

export const api = {
  // --- Auth ---
  login: (host, username, password, opts = {}) =>
    request('/login', { method: 'POST', body: JSON.stringify({ host, username, password, ...opts }) }),
  logout: () => request('/logout', { method: 'POST' }),
  session: () => request('/session'),

  // --- Inventory ---
  datastores: () => request('/datastores'),
  networks: () => request('/networks'),

  // --- VMs ---
  listVMs: () => request('/vms'),
  getVM: (id) => request(`/vms/${encodeURIComponent(id)}`),
  createVM: (payload) => request('/vms', { method: 'POST', body: JSON.stringify(payload) }),
  deleteVM: (id) => request(`/vms/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  powerVM: (id, action) =>
    request(`/vms/${encodeURIComponent(id)}/power`, { method: 'POST', body: JSON.stringify({ action }) }),
  resizeVM: (id, { cpu, memoryMB }) =>
    request(`/vms/${encodeURIComponent(id)}/resize`, { method: 'POST', body: JSON.stringify({ cpu, memoryMB }) }),
};

export { ApiError };
