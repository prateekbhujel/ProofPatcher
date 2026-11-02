export interface BudgetStatus {
  attempts: number;
  maxAttempts: number;
  reservedMicros?: number;
  remainingMicros: number;
  canRequest: boolean;
}

export function budgetDollars(micros: number): string {
  return `$${(micros / 1000000).toFixed(2)}`;
}

/** Usage line, e.g. "21 model requests · $0.13 tracked". Metering only: no local cap refuses requests; provider quotas are the limit. */
export function budgetStatusLine(budget: BudgetStatus): string {
  return `${budget.attempts} model requests · ${budgetDollars(budget.reservedMicros ?? 0)} tracked`;
}

/** Compact line, e.g. "21 requests". */
export function budgetShortLine(budget: BudgetStatus): string {
  return `${budget.attempts} requests`;
}

/** Autopilot spend, e.g. "Spent so far: 4 model requests · $0.06 reserved." */
export function autopilotSpendLine(spentAttempts: number, spentDollars: number): string {
  return `Spent so far: ${spentAttempts} model requests · $${spentDollars.toFixed(2)} reserved.`;
}

/** Autopilot cap fragment, e.g. "25 model requests and $2.50", or null when uncapped. */
export function autopilotCapFragment(maxAttempts: number | null, maxReservedDollars: number | null): string | null {
  if (maxAttempts == null && maxReservedDollars == null) return null;
  let fragment = '';
  if (maxAttempts != null) fragment += `${maxAttempts} model requests`;
  if (maxAttempts != null && maxReservedDollars != null) fragment += ' and';
  if (maxReservedDollars != null) fragment += `${fragment ? ' ' : ''}$${maxReservedDollars.toFixed(2)}`;
  return fragment;
}

/** Autopilot cap suffix, e.g. "Capped at 25 model requests and $2.50." */
export function autopilotCapLine(maxAttempts: number | null, maxReservedDollars: number | null): string {
  const fragment = autopilotCapFragment(maxAttempts, maxReservedDollars);
  return fragment === null ? 'Uncapped.' : `Capped at ${fragment}.`;
}

/** One ledger serves everyone on this machine; people do not get separate budgets. */
export const sharedBudgetNote = 'One shared usage record on this machine — it is not split per person.';

/** Review usage, e.g. "3 model requests used". Uncapped unless an explicit small cap is configured. */
export function reviewUsageLine(attempts: number, maxAttempts?: number): string {
  return maxAttempts == null || maxAttempts >= 1_000_000_000 ? `${attempts} model requests used` : `${attempts} of ${maxAttempts} model requests`;
}
