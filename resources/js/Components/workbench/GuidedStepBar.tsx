import React from 'react';
import { Check, ChevronRight } from 'lucide-react';
import { IssueContext, Suggestion, VerificationReport } from './types';

interface GuidedStepBarProps {
  context: IssueContext | null;
  suggestion: Suggestion | null;
  verificationReport: VerificationReport | null;
  hasPublishedPr: boolean;
  fixtureChecksPassed?: boolean;
}

export function GuidedStepBar({ context, suggestion, verificationReport, hasPublishedPr, fixtureChecksPassed = false }: GuidedStepBarProps) {
  const checks = verificationReport?.gates;
  const checksPassed = verificationReport?.decision === 'VERIFIED' && checks != null
    && Object.values(checks).length === 5 && Object.values(checks).every((passed) => passed === true);
  const complete = [Boolean(context), Boolean(suggestion), checksPassed || fixtureChecksPassed, hasPublishedPr];
  const firstIncomplete = complete.findIndex((done) => !done);
  const current = firstIncomplete < 0 ? -1 : firstIncomplete;
  const labels = ['Issue', 'Change', 'Checks', 'Draft PR'];

  return (
    <nav aria-label="Issue progress" className="border-b border-slate-200/80 px-5 py-3 dark:border-slate-800 sm:px-7">
      <ol className="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-x-2 gap-y-2 text-xs sm:gap-x-3">
        {labels.map((label, index) => (
          <li key={label} className="flex items-center gap-2 sm:gap-3">
            {index > 0 && <ChevronRight aria-hidden="true" className="h-3 w-3 text-slate-300 dark:text-slate-700" />}
            <span
              aria-current={current === index ? 'step' : undefined}
              className={`inline-flex items-center gap-1.5 ${complete[index] ? 'text-slate-700 dark:text-slate-300' : current === index ? 'font-semibold text-accent-700 dark:text-accent-300' : 'text-slate-400 dark:text-slate-500'}`}
            >
              <span className={`flex h-5 w-5 items-center justify-center rounded-full text-[10px] ${complete[index] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : current === index ? 'bg-accent-100 text-accent-700 dark:bg-accent-950 dark:text-accent-300' : 'bg-slate-100 dark:bg-slate-800'}`}>
                {complete[index] ? <Check aria-label="Done" className="h-3 w-3" /> : index + 1}
              </span>
              {label}
            </span>
          </li>
        ))}
      </ol>
    </nav>
  );
}
