export function Spinner({ className = 'w-4 h-4' }) {
  return (
    <svg className={`animate-spin ${className}`} viewBox="0 0 24 24" fill="none">
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
  );
}

export function Button({ children, variant = 'primary', className = '', disabled, loading, ...props }) {
  const base =
    'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed';
  const variants = {
    primary: 'bg-cyan-500 text-slate-950 hover:bg-cyan-400',
    danger: 'bg-red-500/10 text-red-400 border border-red-500/30 hover:bg-red-500/20',
    ghost: 'bg-slate-800 text-slate-200 hover:bg-slate-700 border border-slate-700',
    subtle: 'bg-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-800',
  };

  return (
    <button className={`${base} ${variants[variant]} ${className}`} disabled={disabled || loading} {...props}>
      {loading && <Spinner />}
      {children}
    </button>
  );
}

const STATE_STYLES = {
  poweredOn: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
  poweredOff: 'bg-slate-500/10 text-slate-400 border-slate-500/30',
  suspended: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
};

export function PowerBadge({ state }) {
  const style = STATE_STYLES[state] || STATE_STYLES.poweredOff;
  const label = { poweredOn: 'Running', poweredOff: 'Stopped', suspended: 'Suspended' }[state] || state || 'Unknown';

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${style}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${state === 'poweredOn' ? 'bg-emerald-400' : state === 'suspended' ? 'bg-amber-400' : 'bg-slate-500'}`} />
      {label}
    </span>
  );
}

export function Field({ label, children, hint }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-xs font-medium text-slate-400">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

export const inputClass =
  'w-full rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500';

export function Modal({ title, onClose, children, wide }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div
        className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'} rounded-lg border border-slate-800 bg-slate-900 shadow-2xl`}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between border-b border-slate-800 px-5 py-4">
          <h2 className="text-sm font-semibold text-slate-100">{title}</h2>
          <button onClick={onClose} className="text-slate-500 hover:text-slate-300">
            ✕
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  );
}
