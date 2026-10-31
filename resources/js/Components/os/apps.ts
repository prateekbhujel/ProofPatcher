export interface OsApp {
  href: string;
  label: string;
  detail: string;
}

export interface OsAppFolder {
  title: string;
  apps: OsApp[];
}

/** The single app catalog: desktop rail, start menu, and shell agree. */
export const APP_FOLDERS: OsAppFolder[] = [
  {
    title: 'pipeline',
    apps: [
      { href: '/issues', label: 'Issues & Tasks', detail: 'Workbench' },
      { href: '/repositories', label: 'Pull Requests', detail: 'Reviews' },
    ],
  },
  {
    title: 'system',
    apps: [
      { href: '/telemetry', label: 'Telemetry', detail: 'Usage' },
      { href: '/terminal', label: 'Terminal', detail: 'Console' },
      { href: '/settings', label: 'Settings', detail: 'Providers' },
      { href: '/docs', label: 'Docs', detail: 'Guide' },
    ],
  },
];

/** Flat rail order for the desktop icon column. */
export const RAIL_APPS: OsApp[] = [
  { href: '/issues', label: 'Issues & Tasks', detail: 'Workbench' },
  { href: '/repositories', label: 'Pull Requests', detail: 'Reviews' },
  { href: '/telemetry', label: 'Telemetry', detail: 'Usage' },
  { href: '/terminal', label: 'Terminal', detail: 'Console' },
  { href: '/settings', label: 'Settings', detail: 'Providers' },
  { href: '/docs', label: 'Docs', detail: 'Guide' },
];
