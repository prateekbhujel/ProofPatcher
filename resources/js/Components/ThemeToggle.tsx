import React, { useEffect, useState, useRef } from 'react';
import { Sun, Moon, Monitor } from 'lucide-react';

export type ThemePreference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'proofpatch-theme';

export function resolveSystemScheme(): 'light' | 'dark' {
  if (typeof window !== 'undefined' && window.matchMedia) {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  return 'light';
}

export function readStoredThemePreference(): ThemePreference {
  if (typeof window === 'undefined') return 'light';
  try {
    const val = window.localStorage.getItem(STORAGE_KEY);
    if (val === 'light' || val === 'dark' || val === 'system') {
      return val;
    }
  } catch {
    // Ignore storage access errors
  }
  return 'light';
}

export function applyThemePreference(pref: ThemePreference): 'light' | 'dark' {
  const resolved = pref === 'system' ? resolveSystemScheme() : pref;
  if (typeof document !== 'undefined') {
    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) {
      meta.setAttribute('content', pref === 'system' ? 'light dark' : resolved);
    }
    const root = document.documentElement;
    root.dataset.themePreference = pref;
    root.dataset.theme = resolved;
    root.classList.toggle('dark', resolved === 'dark');
  }
  return resolved;
}

interface ThemeToggleProps {
  compact?: boolean;
}

export function ThemeToggle({ compact = true }: ThemeToggleProps) {
  const [preference, setPreference] = useState<ThemePreference>(() => readStoredThemePreference());
  const [systemScheme, setSystemScheme] = useState<'light' | 'dark'>(() => resolveSystemScheme());
  const buttonRefs = useRef<Array<HTMLButtonElement | null>>([]);

  useEffect(() => {
    applyThemePreference(preference);
  }, [preference]);

  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return;
    const mql = window.matchMedia('(prefers-color-scheme: dark)');

    const handleMediaChange = (e: MediaQueryListEvent) => {
      const nextSys = e.matches ? 'dark' : 'light';
      setSystemScheme(nextSys);
      // Only update rendered scheme when following System; never overwrite an explicit Light/Dark pin
      const currentPref = readStoredThemePreference();
      if (currentPref === 'system') {
        applyThemePreference('system');
      }
    };

    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === STORAGE_KEY) {
        const nextPref = readStoredThemePreference();
        setPreference(nextPref);
        applyThemePreference(nextPref);
      }
    };

    mql.addEventListener('change', handleMediaChange);
    window.addEventListener('storage', handleStorageChange);
    return () => {
      mql.removeEventListener('change', handleMediaChange);
      window.removeEventListener('storage', handleStorageChange);
    };
  }, []);

  function selectPreference(next: ThemePreference) {
    setPreference(next);
    try {
      window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Ignore storage access errors
    }
    applyThemePreference(next);
  }

  const options: Array<{
    value: ThemePreference;
    label: string;
    detailLabel: string;
    icon: typeof Sun;
  }> = [
    { value: 'light', label: 'Light', detailLabel: 'Light theme', icon: Sun },
    { value: 'dark', label: 'Dark', detailLabel: 'Dark theme', icon: Moon },
    {
      value: 'system',
      label: 'System',
      detailLabel: `System (Currently ${systemScheme})`,
      icon: Monitor,
    },
  ];

  function handleKeyDown(e: React.KeyboardEvent, idx: number) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      const nextIdx = (idx + 1) % options.length;
      selectPreference(options[nextIdx].value);
      buttonRefs.current[nextIdx]?.focus();
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      const prevIdx = (idx - 1 + options.length) % options.length;
      selectPreference(options[prevIdx].value);
      buttonRefs.current[prevIdx]?.focus();
    }
  }

  return (
    <div
      role="radiogroup"
      aria-label="Color theme"
      className={`items-center rounded-lg border border-slate-300 dark:border-slate-700 bg-[#f2f0e8] dark:bg-slate-800 p-0.5 gap-0.5 ${
        compact ? 'inline-flex' : 'flex w-full'
      }`}
    >
      {options.map((opt, idx) => {
        const Icon = opt.icon;
        const isChecked = preference === opt.value;
        return (
          <button
            key={opt.value}
            ref={(el) => {
              buttonRefs.current[idx] = el;
            }}
            type="button"
            role="radio"
            aria-checked={isChecked}
            aria-label={opt.detailLabel}
            title={opt.detailLabel}
            tabIndex={isChecked ? 0 : -1}
            onClick={() => selectPreference(opt.value)}
            onKeyDown={(e) => handleKeyDown(e, idx)}
            className={`inline-flex items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-[11px] font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 ${
              compact ? '' : 'flex-1'
            } ${
              isChecked
                ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 shadow-2xs border border-slate-200/80 dark:border-slate-700'
                : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
            }`}
          >
            <Icon className="w-3.5 h-3.5 shrink-0" />
            <span className={compact ? 'sr-only' : 'inline'}>
              {opt.label}
            </span>
          </button>
        );
      })}
    </div>
  );
}
