import React, { useEffect } from 'react';
import { AppWindow, Expand, Minimize2, TerminalSquare } from 'lucide-react';
import { useReducedMotion } from './useReducedMotion';

interface DesktopContextMenuProps {
  x: number;
  y: number;
  onOpenApp: (href: string) => void;
  onShowAll: () => void;
  onMinimizeAll: () => void;
  onClose: () => void;
}

/** The desktop's own right-click menu. Native menus keep working in inputs. */
export function DesktopContextMenu({ x, y, onOpenApp, onShowAll, onMinimizeAll, onClose }: DesktopContextMenuProps) {
  const reducedMotion = useReducedMotion();

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const left = typeof window === 'undefined' ? x : Math.min(x, window.innerWidth - 230);
  const top = typeof window === 'undefined' ? y : Math.min(y, window.innerHeight - 200);

  const itemClass =
    'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-xs font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800';

  return (
    <div className="fixed inset-0 z-50" role="presentation">
      <button type="button" aria-label="Close context menu" onClick={onClose} className="absolute inset-0 cursor-default" />
      <div
        role="menu"
        aria-label="Desktop menu"
        style={{ left, top }}
        className={`absolute w-[210px] overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-[#10141d] p-1.5 shadow-xl ${
          reducedMotion ? '' : 'os-menu-enter'
        }`}
      >
        <button
          type="button"
          role="menuitem"
          className={itemClass}
          onClick={() => {
            onOpenApp('/terminal');
            onClose();
          }}
        >
          <TerminalSquare className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
          Open Terminal
        </button>
        <button
          type="button"
          role="menuitem"
          className={itemClass}
          onClick={() => {
            onOpenApp('/issues');
            onClose();
          }}
        >
          <AppWindow className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
          Open Issues &amp; Tasks
        </button>
        <div className="my-1 border-t border-slate-200 dark:border-slate-800" aria-hidden="true" />
        <button
          type="button"
          role="menuitem"
          className={itemClass}
          onClick={() => {
            onShowAll();
            onClose();
          }}
        >
          <Expand className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
          Show all windows
        </button>
        <button
          type="button"
          role="menuitem"
          className={itemClass}
          onClick={() => {
            onMinimizeAll();
            onClose();
          }}
        >
          <Minimize2 className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
          Minimize all windows
        </button>
      </div>
    </div>
  );
}
