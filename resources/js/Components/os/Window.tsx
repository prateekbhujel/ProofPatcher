import React, { useEffect, useRef, useState } from 'react';
import { useFloatingDesktop, useReducedMotion } from './useReducedMotion';

export interface WindowOffset {
  x: number;
  y: number;
}

interface WindowRect extends WindowOffset {
  w: number;
}

interface WindowProps {
  id: string;
  title: string;
  children: React.ReactNode;
  focused: boolean;
  minimized: boolean;
  maximized: boolean;
  closing: boolean;
  zIndex: number;
  defaultOffset: WindowOffset;
  /** Flush content (app iframes): no padding, fills the window body. */
  flush?: boolean;
  wide?: boolean;
  onFocus: (id: string) => void;
  onMinimize: (id: string) => void;
  onClose: (id: string) => void;
  onToggleMaximize: (id: string) => void;
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

/**
 * A draggable OS window with smooth minimize/maximize. SSR and narrow
 * screens render a static stacked card; desktop clients float it with
 * pixel geometry so moves and maximizes glide. Dragging is user-driven
 * (always allowed); auto-motion honors reduced motion.
 */
export function Window({
  id,
  title,
  children,
  focused,
  minimized,
  maximized,
  closing,
  zIndex,
  defaultOffset,
  flush = false,
  wide = false,
  onFocus,
  onMinimize,
  onClose,
  onToggleMaximize,
}: WindowProps) {
  const floating = useFloatingDesktop();
  const reducedMotion = useReducedMotion();
  const [rect, setRect] = useState<WindowRect | null>(null);
  const [dragging, setDragging] = useState(false);
  const drag = useRef<{ dx: number; dy: number } | null>(null);
  const bodyRef = useRef<HTMLElement>(null);

  const defaultWidth = (): number => {
    if (typeof window === 'undefined') return wide ? 620 : 480;
    const area = bodyRef.current?.parentElement;
    const avail = area ? area.clientWidth : window.innerWidth;
    return Math.max(300, Math.min(wide ? 620 : 480, avail - 16));
  };

  useEffect(() => {
    if (!floating || maximized || rect !== null) return;
    setRect({ x: defaultOffset.x, y: defaultOffset.y, w: defaultWidth() });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [floating, maximized]);

  useEffect(() => {
    if (!floating || !maximized) return;
    const apply = () => {
      const area = bodyRef.current?.parentElement;
      const w = area ? area.clientWidth : typeof window !== 'undefined' ? window.innerWidth : 800;
      setRect({ x: 0, y: 0, w });
    };
    apply();
    window.addEventListener('resize', apply);
    return () => window.removeEventListener('resize', apply);
  }, [floating, maximized]);

  if (minimized) return null;

  const animate = floating && !reducedMotion && !dragging;
  const style: React.CSSProperties = floating && rect
    ? { zIndex, left: rect.x, top: rect.y, width: maximized ? '100%' : rect.w }
    : { zIndex: 'auto' };
  const widthClass = wide ? 'sm:w-[min(620px,92vw)]' : 'sm:w-[min(480px,90vw)]';

  const bodyClass = flush
    ? maximized
      ? 'h-[calc(100vh-230px)] min-h-[320px] overflow-hidden'
      : 'h-[55vh] min-h-[320px] overflow-hidden'
    : maximized
      ? 'h-[calc(100vh-230px)] min-h-[320px] overflow-y-auto p-4'
      : 'max-h-[60vh] overflow-y-auto p-4 sm:max-h-[52vh]';

  return (
    <section
      ref={bodyRef}
      aria-label={`${title} window`}
      data-window={id}
      data-maximized={maximized ? 'true' : undefined}
      onPointerDown={() => onFocus(id)}
      style={style}
      className={
        floating
          ? `absolute left-0 top-0 ${rect ? '' : widthClass} overflow-hidden rounded-xl border bg-white dark:bg-[#10141d] shadow-lg ${
              focused ? 'border-slate-400 dark:border-slate-500' : 'border-slate-200 dark:border-slate-800'
            } ${animate ? 'os-window-move' : ''} ${reducedMotion ? '' : 'os-window-enter'} ${
              closing ? 'os-window-leave' : ''
            }`
          : 'overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-[#10141d] shadow-xs'
      }
    >
      <div
        role="toolbar"
        aria-label={`${title} title bar${floating && !maximized ? ', drag to move' : ''}`}
        onDoubleClick={() => {
          if (floating) onToggleMaximize(id);
        }}
        onPointerDown={(event) => {
          if (!floating || maximized || event.button !== 0 || rect === null) return;
          (event.target as Element).setPointerCapture?.(event.pointerId);
          drag.current = { dx: event.clientX - rect.x, dy: event.clientY - rect.y };
          setDragging(true);
        }}
        onPointerMove={(event) => {
          if (!floating || !drag.current || rect === null || typeof window === 'undefined') return;
          setRect({
            ...rect,
            x: clamp(event.clientX - drag.current.dx, 160 - rect.w, window.innerWidth - 160),
            y: clamp(event.clientY - drag.current.dy, 0, window.innerHeight - 140),
          });
        }}
        onPointerUp={() => {
          drag.current = null;
          setDragging(false);
        }}
        onPointerCancel={() => {
          drag.current = null;
          setDragging(false);
        }}
        className={`flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-[#0b0f16] px-3 py-2 ${
          floating && !maximized ? 'cursor-move touch-none select-none' : ''
        }`}
      >
        <span className="flex items-center gap-1.5">
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onClose(id);
            }}
            aria-label={`Close ${title}`}
            className="h-2.5 w-2.5 rounded-full bg-rose-500/80 hover:bg-rose-500"
          />
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onMinimize(id);
            }}
            aria-label={`Minimize ${title}`}
            className="h-2.5 w-2.5 rounded-full bg-amber-400/80 hover:bg-amber-400"
          />
          <button
            type="button"
            onClick={(event) => {
              event.stopPropagation();
              onToggleMaximize(id);
            }}
            aria-label={maximized ? `Restore ${title}` : `Maximize ${title}`}
            className="h-2.5 w-2.5 rounded-full bg-emerald-500/80 hover:bg-emerald-500"
          />
        </span>
        <span className="ml-1 truncate font-mono text-[11px] font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          {title}
        </span>
      </div>
      <div className={bodyClass}>{children}</div>
    </section>
  );
}
