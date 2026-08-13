# ESXi V9 Panel (React + Vite + Tailwind)

A small web UI for the `esxi-v9-module` PHP library. Talks to the PHP REST
API under `api/` at the project root.

## Setup

```bash
npm install
```

## Development

Run the PHP API and the Vite dev server side by side (two terminals), from
the **project root** (one level up from this folder):

```bash
# terminal 1 — PHP API
php -S 127.0.0.1:8080 -t api api/index.php

# terminal 2 — React dev server
cd web
npm run dev
```

Open http://localhost:5173 — Vite proxies every `/api/*` request to the PHP
server on port 8080 (see `vite.config.js`), so there's no CORS setup needed
in development.

## Production build

```bash
npm run build
```

Outputs static files to `dist/`. Serve them from the same origin/domain as
`api/` (e.g. both behind the same Apache/Nginx vhost) so the PHP session
cookie set by `/api/login` is sent on every request. If you must serve them
from a different origin, add that origin to the `$allowedOrigins` list in
`api/bootstrap.php`.

## Pages

- **Login** — connects to an ESXi host (host/username/password), stored
  server-side in the PHP session (never in the browser).
- **Dashboard** — lists VMs with live power state, quick power on/off,
  and a "Create VM" button.
- **Create VM** — name, vCPUs, memory, disk, guest OS, datastore/network
  (pulled live from the host), optional ISO path.
- **VM Detail** — power on/off/restart, IP/hostname/VMware Tools status,
  resize vCPU/memory (only while powered off, matching ESXi's own
  hot-add limitations), delete.
