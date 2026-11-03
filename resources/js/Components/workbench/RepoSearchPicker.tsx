import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search } from 'lucide-react';

export interface RepoSearchResult {
  fullName: string;
  isPrivate: boolean;
}

interface SearchPayload {
  data: RepoSearchResult[];
  meta: { total: number; page: number; perPage: number; hasMore: boolean };
}

interface RepoSearchPickerProps {
  id: string;
  /** Currently selected repository full name, or '' for none. */
  value: string;
  onChange: (fullName: string) => void;
  disabled?: boolean;
  ariaLabel?: string;
}

const PAGE_SIZE = 20;
const DEBOUNCE_MS = 200;

/**
 * Select2-style repository picker. Results page in from
 * /github/repositories/search, so thousands of repositories never ship to
 * the browser or render at once. Only a listed repository can be selected.
 */
export function RepoSearchPicker({ id, value, onChange, disabled = false, ariaLabel }: RepoSearchPickerProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState(value);
  const [items, setItems] = useState<RepoSearchResult[]>([]);
  const [total, setTotal] = useState(0);
  const [hasMore, setHasMore] = useState(false);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [highlight, setHighlight] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const abortRef = useRef<AbortController | null>(null);
  const requestId = useRef(0);
  const listId = `${id}-listbox`;

  useEffect(() => {
    if (!open) setQuery(value);
  }, [open, value]);

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: PointerEvent) => {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open ]);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  async function fetchPage(search: string, nextPage: number, append: boolean) {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;
    const mine = ++requestId.current;
    if (append) setLoadingMore(true);
    else setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ q: search.trim(), page: String(nextPage), per_page: String(PAGE_SIZE) });
      const response = await fetch(`/github/repositories/search?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller.signal,
      });
      if (!response.ok) {
        throw new Error(response.status === 401 ? 'GitHub is no longer connected.' : `Search failed (HTTP ${response.status}).`);
      }
      const payload = (await response.json()) as Partial<SearchPayload>;
      if (mine !== requestId.current) return;
      const data = Array.isArray(payload.data) ? payload.data : [];
      setItems((prev) => (append ? [...prev, ...data] : data));
      setTotal(payload.meta?.total ?? data.length);
      setHasMore(payload.meta?.hasMore ?? false);
      setPage(payload.meta?.page ?? nextPage);
      setHighlight(0);
    } catch (err) {
      if (mine !== requestId.current || (err instanceof DOMException && err.name === 'AbortError')) return;
      setError(err instanceof Error ? err.message : 'Search failed.');
    } finally {
      if (mine === requestId.current) {
        setLoading(false);
        setLoadingMore(false);
      }
    }
  }

  // Debounced search: opening lists everything, typing narrows from page one.
  useEffect(() => {
    if (!open || disabled) return;
    const fresh = query === value;
    const timer = window.setTimeout(() => {
      void fetchPage(fresh ? '' : query, 1, false);
    }, fresh ? 0 : DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, query, disabled]);

  const countLine = useMemo(() => {
    if (loading && items.length === 0) return 'Searching…';
    if (total === 0) return 'No repositories';
    return `${items.length} of ${total}`;
  }, [loading, items.length, total]);

  function select(fullName: string) {
    onChange(fullName);
    setQuery(fullName);
    setOpen(false);
  }

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === 'ArrowDown' && !open) {
      event.preventDefault();
      setOpen(true);
      return;
    }
    if (!open) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setHighlight((h) => Math.min(h + 1, Math.max(items.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const pick = items[highlight];
      if (pick) select(pick.fullName);
      else setQuery(value);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      setQuery(value);
      setOpen(false);
    }
  }

  function onListScroll() {
    const list = listRef.current;
    if (!list || loading || loadingMore || !hasMore || error) return;
    if (list.scrollHeight - list.scrollTop - list.clientHeight < 48) {
      void fetchPage(query, page + 1, true);
    }
  }

  return (
    <div ref={rootRef} className="relative">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" aria-hidden="true" />
        <input
          id={id}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-activedescendant={items.length > 0 ? `${listId}-${highlight}` : undefined}
          aria-label={ariaLabel}
          autoComplete="off"
          spellCheck={false}
          disabled={disabled}
          value={query}
          placeholder="Search repositories…"
          onChange={(e) => {
            setQuery(e.target.value);
            if (!open) setOpen(true);
          }}
          onFocus={() => {
            if (!disabled) setOpen(true);
          }}
          onKeyDown={onKeyDown}
          onBlur={() => setQuery(value)}
          className="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 pl-9 pr-8 py-1.5 text-xs font-mono text-slate-900 dark:text-slate-100 placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 disabled:opacity-60 transition"
        />
        <ChevronDown className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" aria-hidden="true" />
      </div>
      {open && !disabled && (
        <div className="absolute z-30 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-lg">
          <ul
            id={listId}
            ref={listRef}
            role="listbox"
            aria-label={ariaLabel ?? 'Repositories'}
            onScroll={onListScroll}
            className="max-h-56 overflow-y-auto py-1"
          >
            {items.length === 0 && !loading ? (
              <li className="px-3.5 py-2.5 text-xs text-slate-500 dark:text-slate-400">
                {error ?? (query.trim() ? `No repositories match “${query.trim()}”.` : 'No connected repositories.')}
              </li>
            ) : (
              items.map((repo, index) => {
                const selected = repo.fullName === value;
                return (
                  <li
                    key={repo.fullName}
                    id={`${listId}-${index}`}
                    role="option"
                    aria-selected={selected}
                    onMouseDown={(e) => {
                      e.preventDefault();
                      select(repo.fullName);
                    }}
                    onMouseEnter={() => setHighlight(index)}
                    className={`flex cursor-pointer items-center gap-2 px-3.5 py-2 font-mono text-xs ${
                      index === highlight
                        ? 'bg-[var(--accent-soft)] text-[var(--fg)]'
                        : 'text-[var(--fg-muted)]'
                    }`}
                  >
                    <span className="min-w-0 flex-1 truncate">{repo.fullName}</span>
                    {repo.isPrivate && (
                      <span className="shrink-0 rounded border border-slate-200 dark:border-slate-700 px-1 py-px font-sans text-[10px] text-slate-500 dark:text-slate-400">
                        private
                      </span>
                    )}
                    {selected && <Check className="h-3.5 w-3.5 shrink-0 text-accent-600" aria-hidden="true" />}
                  </li>
                );
              })
            )}
            {loadingMore && (
              <li className="px-3.5 py-2 font-mono text-[11px] text-slate-400" aria-hidden="true">
                Loading more…
              </li>
            )}
          </ul>
          <div className="flex items-center justify-between gap-2 border-t border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 px-3.5 py-1.5">
            <span className="font-mono text-[11px] text-slate-500 dark:text-slate-400" aria-live="polite">
              {countLine}
            </span>
            {error && (
              <button
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => void fetchPage(query, 1, false)}
                className="text-[11px] font-semibold text-accent-700 dark:text-accent-300 hover:underline"
              >
                Retry
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
