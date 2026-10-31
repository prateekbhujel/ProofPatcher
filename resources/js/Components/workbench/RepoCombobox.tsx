import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search } from 'lucide-react';

export interface RepoOption {
  fullName: string;
  isPrivate?: boolean;
}

interface RepoComboboxProps {
  id: string;
  repos: RepoOption[];
  value: string;
  onChange: (fullName: string) => void;
  disabled?: boolean;
}

/** Searchable repository picker. Only a listed repository can be selected. */
export function RepoCombobox({ id, repos, value, onChange, disabled = false }: RepoComboboxProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState(value);
  const [highlight, setHighlight] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
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

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return repos;
    return repos.filter((r) => r.fullName.toLowerCase().includes(q));
  }, [repos, query]);

  useEffect(() => {
    setHighlight(0);
  }, [matches.length]);

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
      setHighlight((h) => Math.min(h + 1, Math.max(matches.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const pick = matches[highlight];
      if (pick) select(pick.fullName);
      else setQuery(value);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      setQuery(value);
      setOpen(false);
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
          aria-activedescendant={matches.length > 0 ? `${listId}-${highlight}` : undefined}
          autoComplete="off"
          spellCheck={false}
          disabled={disabled}
          value={query}
          placeholder={repos.length === 0 ? 'No connected repo' : 'Search repositories…'}
          onChange={(e) => {
            setQuery(e.target.value);
            if (!open) setOpen(true);
          }}
          onFocus={() => {
            if (!disabled && repos.length > 0) setOpen(true);
          }}
          onKeyDown={onKeyDown}
          onBlur={() => setQuery(value)}
          className="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 pl-9 pr-8 py-2.5 text-xs font-mono text-slate-800 dark:text-slate-200 placeholder:text-slate-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 disabled:opacity-60 transition shadow-2xs"
        />
        <ChevronDown className="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" aria-hidden="true" />
      </div>
      {open && !disabled && (
        <ul
          id={listId}
          role="listbox"
          aria-label="Repositories"
          className="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 py-1 shadow-lg"
        >
          {matches.length === 0 ? (
            <li className="px-3.5 py-2.5 text-xs text-slate-500 dark:text-slate-400">
              No repositories match “{query.trim()}”.
            </li>
          ) : (
            matches.map((repo, index) => {
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
                      ? 'bg-accent-50 dark:bg-accent-950/50 text-slate-900 dark:text-slate-100'
                      : 'text-slate-700 dark:text-slate-300'
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
        </ul>
      )}
    </div>
  );
}
