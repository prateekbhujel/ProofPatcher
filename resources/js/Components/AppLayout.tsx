import React from 'react';
import { usePage } from '@inertiajs/react';
import { OsShell } from './os/OsShell';

interface AppLayoutProps {
  activeNav?: 'kernel' | 'overview' | 'repositories' | 'pulls' | 'checks' | 'issues' | 'settings' | 'docs' | 'telemetry' | 'terminal';
  children: React.ReactNode;
}

const APP_TITLES: Record<NonNullable<AppLayoutProps['activeNav']>, string> = {
  kernel: 'kernel',
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

/** Every app page runs inside the OS shell: menu bar, app window, taskbar. */
export function AppLayout({ activeNav = 'kernel', children }: AppLayoutProps) {
  const { embed } = usePage().props as { embed?: boolean };
  return (
    <OsShell appId={APP_TITLES[activeNav]} fullscreen={activeNav === 'issues'} embed={embed === true}>
      {children}
    </OsShell>
  );
}
