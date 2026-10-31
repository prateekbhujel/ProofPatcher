import React from 'react';
import { Activity, BookOpen, CircleDot, GitPullRequest, Settings, Terminal } from 'lucide-react';
import { RAIL_APPS } from './apps';
import Dock from '../bits/Dock';

const ICONS: Record<string, typeof CircleDot> = {
  '/issues': CircleDot,
  '/repositories': GitPullRequest,
  '/telemetry': Activity,
  '/terminal': Terminal,
  '/settings': Settings,
  '/docs': BookOpen,
};

interface AppDockProps {
  onOpenApp: (href: string) => void;
  /** Hrefs with an open window get the running dot. */
  openApps?: string[];
  theme?: 'light' | 'dark';
}

/** The desktop's mac-style magnification dock (sm screens and up). */
export function AppDock({ onOpenApp, openApps = [], theme = 'light' }: AppDockProps) {
  return (
    <Dock
      items={RAIL_APPS.map((app) => {
        const Icon = ICONS[app.href] ?? CircleDot;
        return {
          label: `${app.label} · ${app.detail}`,
          icon: <Icon size="1em" style={{ color: 'var(--dock-ink)' }} aria-hidden="true" />,
          active: openApps.includes(app.href),
          onClick: () => onOpenApp(app.href),
        };
      })}
      position="left"
      theme={theme}
      baseItemSize={40}
      magnification={58}
      panelHeight={54}
      distance={160}
      gap={8}
      className="os-dock-embed"
    />
  );
}
