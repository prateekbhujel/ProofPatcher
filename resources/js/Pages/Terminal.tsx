import React, { useEffect, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { AppLayout } from '../Components/AppLayout';

interface Line {
  kind: 'boot' | 'cmd' | 'out' | 'err';
  text: string;
}

const BOOT_LINES: Line[] = [
  { kind: 'boot', text: 'ProofPatcher Kernel web terminal — read-only.' },
  { kind: 'boot', text: "Type 'help' to list commands. Reviews, autopilot, and settings stay in the app." },
];

function xsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  try {
    return match ? decodeURIComponent(match[1]) : null;
  } catch {
    return null;
  }
}

/** The kernel shell in the browser: allowlisted read-only commands only. */
export default function Terminal() {
  const { props } = usePage();
  const [lines, setLines] = useState<Line[]>(BOOT_LINES);
  const [value, setValue] = useState('');
  const [busy, setBusy] = useState(false);
  const [history, setHistory] = useState<string[]>([]);
  const [histPos, setHistPos] = useState(-1);
  const endRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end' });
  }, [lines.length]);

  async function run(raw: string) {
    const command = raw.trim();
    if (command === '' || busy) return;
    setLines((prev) => [...prev, { kind: 'cmd', text: `$ ${command}` }]);
    setHistory((prev) => [command, ...prev].slice(0, 50));
    setHistPos(-1);
    setValue('');
    if (command.toLowerCase() === 'clear') {
      setLines([]);
      return;
    }
    setBusy(true);
    try {
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };
      const token = xsrfToken();
      if (typeof props.csrf_token === 'string' && props.csrf_token !== '') {
        headers['X-CSRF-TOKEN'] = props.csrf_token;
      } else if (token) {
        headers['X-XSRF-TOKEN'] = token;
      }
      const res = await fetch('/terminal/run', {
        method: 'POST',
        headers,
        body: JSON.stringify({ command }),
      });
      const data: unknown = await res.json().catch(() => null);
      const output = typeof data === 'object' && data !== null && typeof (data as { output?: unknown }).output === 'string'
        ? ((data as { output: string }).output !== '' ? (data as { output: string }).output : '(no output)')
        : `Request failed (HTTP ${res.status}). Reload the page and try again.`;
      const failed = !res.ok || (typeof data === 'object' && data !== null && (data as { exit?: unknown }).exit === 1);
      setLines((prev) => [...prev, { kind: failed ? 'err' : 'out', text: output }]);
    } catch {
      setLines((prev) => [...prev, { kind: 'err', text: 'request failed — reload the page and retry.' }]);
    } finally {
      setBusy(false);
      inputRef.current?.focus();
    }
  }

  function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
    if (history.length === 0) return;
    event.preventDefault();
    const delta = event.key === 'ArrowUp' ? 1 : -1;
    const next = Math.max(-1, Math.min(history.length - 1, histPos + delta));
    setHistPos(next);
    setValue(next === -1 ? '' : history[next]);
  }

  return (
    <AppLayout activeNav="terminal">
      <Head title="Terminal · ProofPatcher" />
      <div
        onClick={() => inputRef.current?.focus()}
        className="term-scan relative min-h-[50vh] cursor-text rounded-lg bg-[#0d1117] px-4 py-3 font-mono text-[12.5px] leading-6"
      >
        <div className="max-h-[60vh] overflow-y-auto" aria-live="polite" aria-label="Terminal transcript">
          {lines.map((line, i) => (
            <pre
              key={i}
              className={`whitespace-pre-wrap break-words ${
                line.kind === 'cmd'
                  ? 'text-slate-100'
                  : line.kind === 'err'
                    ? 'text-rose-400'
                    : line.kind === 'boot'
                      ? 'text-slate-500'
                      : 'text-slate-300'
              }`}
            >
              {line.text}
            </pre>
          ))}
          <div ref={endRef} />
        </div>
        <form
          onSubmit={(event) => {
            event.preventDefault();
            void run(value);
          }}
          className="mt-1 flex items-center gap-2"
        >
          <span className="shrink-0 text-emerald-400">proofpatcher</span>
          <span className="shrink-0 text-slate-500">›</span>
          <input
            ref={inputRef}
            value={value}
            onChange={(event) => {
              setHistPos(-1);
              setValue(event.target.value);
            }}
            onKeyDown={onKeyDown}
            disabled={busy}
            autoFocus
            autoCapitalize="off"
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            aria-label="Terminal command"
            placeholder={busy ? 'running…' : "type 'help'"}
            className="w-full bg-transparent text-slate-100 placeholder:text-slate-600 focus:outline-none disabled:opacity-60"
          />
        </form>
      </div>
    </AppLayout>
  );
}
