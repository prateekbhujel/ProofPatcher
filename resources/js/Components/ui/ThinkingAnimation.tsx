import React, { useEffect, useState, useRef } from 'react';
import { Check, Loader2 } from 'lucide-react';

interface ThinkingStep {
  label: string;
  status: 'waiting' | 'active' | 'done' | 'error';
}

interface ThinkingAnimationProps {
  isActive: boolean;
  steps: ThinkingStep[];
  title?: string;
  subtitle?: string;
}

export function ThinkingAnimation({
  isActive,
  steps,
  title = 'Processing request…',
  subtitle = 'Waiting for response…',
}: ThinkingAnimationProps) {
  const [elapsed, setElapsed] = useState(0);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    if (isActive) {
      setElapsed(0);
      intervalRef.current = setInterval(() => {
        setElapsed((prev) => +(prev + 0.1).toFixed(1));
      }, 100);
    } else if (intervalRef.current) {
      clearInterval(intervalRef.current);
    }
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [isActive]);

  if (!isActive) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4 shadow-2xs space-y-3"
    >
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-2.5">
        <div className="flex items-center gap-2.5">
          <Loader2 className="w-4 h-4 text-accent-700 dark:text-accent-400 animate-spin shrink-0" />
          <div>
            <h3 className="text-xs font-semibold text-slate-900 dark:text-slate-100">{title}</h3>
            <p className="text-[11px] text-slate-500 dark:text-slate-400">{subtitle}</p>
          </div>
        </div>
        <span className="text-[11px] font-mono font-medium text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded border border-slate-200 dark:border-slate-700">
          {elapsed.toFixed(1)}s
        </span>
      </div>

      {steps.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {steps.map((step, idx) => {
            const isDone = step.status === 'done';
            const isCurrent = step.status === 'active';
            return (
              <div
                key={idx}
                className={`flex items-center gap-2 px-2.5 py-1.5 rounded-lg border text-xs ${
                  isDone
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                    : isCurrent
                    ? 'border-accent-300 bg-accent-50/60 text-accent-950 font-medium'
                    : 'border-slate-200 bg-slate-50 text-slate-500'
                }`}
              >
                {isDone ? (
                  <Check className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                ) : isCurrent ? (
                  <Loader2 className="w-3.5 h-3.5 text-accent-600 animate-spin shrink-0" />
                ) : (
                  <span className="w-3.5 h-3.5 rounded-full border border-slate-300 flex items-center justify-center text-[9px] font-mono text-slate-400 shrink-0">
                    {idx + 1}
                  </span>
                )}
                <span className="truncate">{step.label}</span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
