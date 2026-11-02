import React, { useState, useRef, useEffect, MouseEvent } from 'react';
import {
  ZoomIn,
  ZoomOut,
  Maximize2,
  Minimize2,
  SplitSquareVertical,
  Columns,
  RefreshCw,
  Eye,
  SlidersHorizontal,
} from 'lucide-react';

interface VisualComparisonViewerProps {
  beforeUrl: string;
  afterUrl: string;
  outsideChangedPixels?: number;
  runId?: string;
}

export function VisualComparisonViewer({
  beforeUrl,
  afterUrl,
  outsideChangedPixels = 0,
  runId,
}: VisualComparisonViewerProps) {
  const [mode, setMode] = useState<'slider' | 'sideBySide' | 'toggle'>('slider');
  const [sliderPosition, setSliderPosition] = useState(50); // percentage 0 - 100
  const [toggleState, setToggleState] = useState<'before' | 'after'>('after');
  const [zoomLevel, setZoomLevel] = useState<number>(100); // percentage, 100 = 1:1
  const [isCrisp, setIsCrisp] = useState(true);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [panOffset, setPanOffset] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [imageLoadError, setImageLoadError] = useState(false);
  const dragStartRef = useRef<{ x: number; y: number }>({ x: 0, y: 0 });
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setImageLoadError(false);
  }, [beforeUrl, afterUrl]);

  // Keyboard navigation
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;

      if (e.code === 'Space') {
        e.preventDefault();
        setToggleState((prev) => (prev === 'before' ? 'after' : 'before'));
      } else if (e.key === '+' || e.key === '=') {
        setZoomLevel((z) => Math.min(z + 25, 300));
      } else if (e.key === '-' || e.key === '_') {
        setZoomLevel((z) => Math.max(z - 25, 50));
      } else if (e.key === '0') {
        setZoomLevel(100);
        setPanOffset({ x: 0, y: 0 });
      } else if (e.key === 'Escape' && isFullscreen) {
        setIsFullscreen(false);
      }
    }
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isFullscreen]);

  // Mouse pan handlers
  const handleMouseDown = (e: MouseEvent) => {
    if (zoomLevel <= 100) return;
    setIsDragging(true);
    dragStartRef.current = { x: e.clientX - panOffset.x, y: e.clientY - panOffset.y };
  };

  const handleMouseMove = (e: MouseEvent) => {
    if (!isDragging) return;
    setPanOffset({
      x: e.clientX - dragStartRef.current.x,
      y: e.clientY - dragStartRef.current.y,
    });
  };

  const handleMouseUp = () => {
    setIsDragging(false);
  };

  const resetZoom = () => {
    setZoomLevel(100);
    setPanOffset({ x: 0, y: 0 });
  };

  const crispClass = isCrisp ? 'pixel-crisp' : '';

  return (
    <div
      className={`bg-white dark:bg-[#141821] rounded-xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden flex flex-col ${
        isFullscreen ? 'fixed inset-0 z-50 rounded-none bg-slate-900 border-none' : ''
      }`}
    >
      {/* Control Toolbar */}
      <div className="px-5 py-3.5 border-b border-slate-200/80 flex flex-wrap items-center justify-between gap-3 bg-slate-50/70 dark:bg-slate-900/70 dark:border-slate-800/80">
        {/* Left: Modes */}
        <div className="flex items-center gap-1.5 bg-white p-1 rounded-lg border border-slate-200 shadow-xs dark:bg-[#141821] dark:border-slate-800">
          <button
            type="button"
            onClick={() => setMode('slider')}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition ${
              mode === 'slider'
                ? 'bg-slate-900 text-white shadow-xs dark:bg-slate-200 dark:text-slate-950'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100'
            }`}
          >
            <SplitSquareVertical className="w-3.5 h-3.5" />
            <span>Split Slider</span>
          </button>

          <button
            type="button"
            onClick={() => setMode('sideBySide')}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition ${
              mode === 'sideBySide'
                ? 'bg-slate-900 text-white shadow-xs dark:bg-slate-200 dark:text-slate-950'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100'
            }`}
          >
            <Columns className="w-3.5 h-3.5" />
            <span>Side-by-Side</span>
          </button>

          <button
            type="button"
            onClick={() => setMode('toggle')}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition ${
              mode === 'toggle'
                ? 'bg-slate-900 text-white shadow-xs dark:bg-slate-200 dark:text-slate-950'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100'
            }`}
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>A/B Flip (Space)</span>
          </button>
        </div>

        {/* Center / Right: Zoom & Image Quality Controls */}
        <div className="flex items-center gap-3">
          {/* Zoom buttons */}
          <div className="flex items-center gap-1 bg-white p-1 rounded-lg border border-slate-200 shadow-xs text-xs font-mono dark:bg-[#141821] dark:border-slate-800">
            <button
              type="button"
              onClick={() => setZoomLevel((z) => Math.max(z - 25, 50))}
              title="Zoom Out (-)"
              className="p-1 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100"
            >
              <ZoomOut className="w-3.5 h-3.5" />
            </button>

            <span className="px-2 font-medium text-slate-700 min-w-[3.5rem] text-center dark:text-slate-300">
              {zoomLevel}%
            </span>

            <button
              type="button"
              onClick={() => setZoomLevel((z) => Math.min(z + 25, 300))}
              title="Zoom In (+)"
              className="p-1 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100"
            >
              <ZoomIn className="w-3.5 h-3.5" />
            </button>

            <button
              type="button"
              onClick={resetZoom}
              title="Reset 100% (0)"
              className="px-2 py-0.5 text-[11px] font-sans font-medium text-slate-800 hover:bg-slate-100 rounded dark:text-slate-200 dark:hover:bg-slate-700"
            >
              1:1
            </button>
          </div>

          {/* Pixel crisp toggle */}
          <button
            type="button"
            onClick={() => setIsCrisp(!isCrisp)}
            className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg border transition ${
              isCrisp
                ? 'bg-slate-900 text-white border-slate-900 shadow-xs dark:bg-slate-200 dark:text-slate-950 dark:border-slate-200'
                : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50 dark:bg-[#141821] dark:border-slate-800 dark:text-slate-400 dark:hover:bg-slate-800'
            }`}
            title="Preserve sharp pixel edges on high zoom"
          >
            <SlidersHorizontal className="w-3.5 h-3.5" />
            <span>Pixel Sharp</span>
          </button>

          {/* Fullscreen toggle */}
          <button
            type="button"
            onClick={() => setIsFullscreen(!isFullscreen)}
            className="p-1.5 text-slate-600 hover:text-slate-900 bg-white border border-slate-200 hover:bg-slate-50 rounded-lg shadow-xs dark:bg-[#141821] dark:border-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
            title={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
          >
            {isFullscreen ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
          </button>
        </div>
      </div>

      {/* Status strip */}
      <div className="px-5 py-2 border-b border-slate-100 flex items-center justify-between text-xs text-slate-500 bg-slate-50/40 dark:bg-slate-900/40 dark:border-slate-800 dark:text-slate-400">
        <div className="flex items-center gap-3">
          <span>Viewport: <strong className="font-mono text-slate-700 dark:text-slate-300">1280 × 720</strong></span>
          <span>·</span>
          <span>Visual guard drift: <strong className="font-mono text-emerald-700 dark:text-emerald-400">{outsideChangedPixels}px</strong></span>
        </div>
        <div className="text-[11px] text-slate-400">
          {zoomLevel > 100 ? 'Click & drag to pan canvas' : 'Press Space to toggle A/B'}
        </div>
      </div>

      {/* Main Viewport Canvas */}
      <div
        ref={containerRef}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
        className={`relative flex-1 bg-slate-950 overflow-hidden flex items-center justify-center select-none ${
          zoomLevel > 100 ? (isDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-default'
        } ${isFullscreen ? 'h-[calc(100vh-80px)]' : 'min-h-[520px]'}`}
      >
        {imageLoadError ? (
          <div className="p-8 text-center text-slate-300 space-y-3 max-w-md">
            <div className="w-10 h-10 rounded-full bg-slate-800 text-amber-400 flex items-center justify-center mx-auto">
              <Eye className="w-5 h-5" />
            </div>
            <p className="text-xs font-semibold text-white">Visual Screenshot Unavailable</p>
            <p className="text-[11px] text-slate-400 leading-relaxed">
              Screenshot assets for run <code className="font-mono text-slate-300">{runId || 'current'}</code> could not be rendered. This run may be a headless backend test suite without UI screenshots.
            </p>
          </div>
        ) : (
          <>
            {/* MODE 1: SPLIT SLIDER */}
            {mode === 'slider' && (
              <div
                className="relative overflow-hidden shadow-2xl transition-transform duration-75"
                style={{
                  width: `${(1280 * zoomLevel) / 100}px`,
                  height: `${(720 * zoomLevel) / 100}px`,
                  transform: `translate(${panOffset.x}px, ${panOffset.y}px)`,
                }}
              >
                {/* Candidate image (background) */}
                <img
                  src={afterUrl}
                  alt="Candidate"
                  onError={() => setImageLoadError(true)}
                  className={`absolute inset-0 w-full h-full object-cover select-none ${crispClass}`}
                  draggable={false}
                />

                {/* Baseline image (clipped on top) */}
                <div
                  className="absolute inset-0 overflow-hidden"
                  style={{ width: `${sliderPosition}%` }}
                >
                  <img
                    src={beforeUrl}
                    alt="Baseline"
                    onError={() => setImageLoadError(true)}
                    className={`w-full h-full object-cover select-none ${crispClass}`}
                    style={{ width: `${(1280 * zoomLevel) / 100}px`, maxWidth: 'none' }}
                    draggable={false}
                  />
                </div>

                {/* Divider line */}
                <div
                  className="absolute top-0 bottom-0 w-0.5 bg-white shadow-[0_0_8px_rgba(0,0,0,0.6)] pointer-events-none dark:bg-[#141821]"
                  style={{ left: `${sliderPosition}%` }}
                >
                  <div className="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-5 h-5 rounded-full bg-slate-900 text-white flex items-center justify-center text-[10px] font-bold shadow-md border border-slate-700">
                    ↔
                  </div>
                </div>

                {/* Range input overlay for smooth slider dragging */}
                <input
                  type="range"
                  min="0"
                  max="100"
                  value={sliderPosition}
                  onChange={(e) => setSliderPosition(Number(e.target.value))}
                  className="absolute inset-0 w-full h-full opacity-0 cursor-ew-resize z-20"
                />

                {/* Badges */}
                <div className="absolute bottom-3 left-3 pointer-events-none px-2.5 py-1 rounded bg-slate-900/80 backdrop-blur-xs text-white text-[11px] font-mono border border-slate-700/60">
                  Baseline (Before)
                </div>
                <div className="absolute bottom-3 right-3 pointer-events-none px-2.5 py-1 rounded bg-slate-900/80 backdrop-blur-xs text-white text-[11px] font-mono border border-slate-700/60">
                  Candidate (Patched)
                </div>
              </div>
            )}

            {/* MODE 2: SIDE-BY-SIDE */}
            {mode === 'sideBySide' && (
              <div
                className="flex gap-4 p-4 transition-transform duration-75 overflow-auto"
                style={{
                  transform: `translate(${panOffset.x}px, ${panOffset.y}px)`,
                }}
              >
                {/* Before */}
                <div className="space-y-1.5 flex-1 min-w-[500px]">
                  <div className="flex items-center justify-between text-xs font-mono text-slate-400">
                    <span>Baseline</span>
                  </div>
                  <div
                    className="rounded-lg overflow-hidden border border-slate-800 shadow-lg bg-black"
                    style={{
                      width: `${(640 * zoomLevel) / 100}px`,
                      height: `${(360 * zoomLevel) / 100}px`,
                    }}
                  >
                    <img
                      src={beforeUrl}
                      alt="Baseline"
                      onError={() => setImageLoadError(true)}
                      className={`w-full h-full object-cover select-none ${crispClass}`}
                      draggable={false}
                    />
                  </div>
                </div>

                {/* After */}
                <div className="space-y-1.5 flex-1 min-w-[500px]">
                  <div className="flex items-center justify-between text-xs font-mono text-slate-400">
                    <span>Candidate</span>
                  </div>
                  <div
                    className="rounded-lg overflow-hidden border border-slate-800 shadow-lg bg-black"
                    style={{
                      width: `${(640 * zoomLevel) / 100}px`,
                      height: `${(360 * zoomLevel) / 100}px`,
                    }}
                  >
                    <img
                      src={afterUrl}
                      alt="Candidate"
                      onError={() => setImageLoadError(true)}
                      className={`w-full h-full object-cover select-none ${crispClass}`}
                      draggable={false}
                    />
                  </div>
                </div>
              </div>
            )}

            {/* MODE 3: INSTANT A/B FLIP TOGGLE */}
            {mode === 'toggle' && (
              <div
                className="relative cursor-pointer transition-transform duration-75"
                onClick={() => setToggleState((prev) => (prev === 'before' ? 'after' : 'before'))}
                style={{
                  width: `${(1280 * zoomLevel) / 100}px`,
                  height: `${(720 * zoomLevel) / 100}px`,
                  transform: `translate(${panOffset.x}px, ${panOffset.y}px)`,
                }}
              >
                <img
                  src={toggleState === 'before' ? beforeUrl : afterUrl}
                  alt={toggleState === 'before' ? 'Baseline' : 'Candidate'}
                  onError={() => setImageLoadError(true)}
                  className={`w-full h-full object-cover shadow-2xl select-none ${crispClass}`}
                  draggable={false}
                />

                {/* Pill showing active state */}
                <div className="absolute top-4 right-4 pointer-events-none flex items-center gap-2">
                  <span
                    className={`px-3 py-1.5 rounded-full text-xs font-bold font-mono shadow-md ${
                      toggleState === 'before'
                        ? 'bg-rose-500 text-white'
                        : 'bg-emerald-500 text-white'
                    }`}
                  >
                    {toggleState === 'before' ? 'BASELINE (BEFORE FIX)' : 'CANDIDATE (PATCHED)'}
                  </span>
                </div>

                <div className="absolute bottom-4 left-1/2 -translate-x-1/2 pointer-events-none px-4 py-1.5 rounded-full bg-slate-900/80 backdrop-blur-xs text-slate-300 text-xs border border-slate-700">
                  Click image or tap <kbd className="bg-slate-800 px-1.5 py-0.5 rounded font-mono text-white">Space</kbd> to flip
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
