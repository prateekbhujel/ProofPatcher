import React from 'react';
import { ConsoleLayout } from './console/ConsoleLayout';
import { ConsoleNavKey } from './console/primitives';

interface AppLayoutProps {
  activeNav?: ConsoleNavKey;
  children: React.ReactNode;
}

const APP_TITLES: Record<ConsoleNavKey, string> = {
  kernel: 'console',
  overview: 'overview',
  repositories: 'pull requests',
  pulls: 'pull requests',
  checks: 'checks',
  issues: 'issues & tasks',
  settings: 'settings',
  docs: 'docs',
  telemetry: 'telemetry',
  terminal: 'terminal',
};

/** Every page runs inside the console frame. */
export function AppLayout({ activeNav = 'kernel', children }: AppLayoutProps) {
  return (
    <ConsoleLayout activeNav={activeNav} title={APP_TITLES[activeNav]}>
      {children}
    </ConsoleLayout>
  );
}
