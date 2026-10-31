import React, { useState, useCallback } from 'react';
import {
  DragDropContext,
  Droppable,
  Draggable,
  DropResult,
} from '@hello-pangea/dnd';
import { motion, AnimatePresence, type Variants } from 'framer-motion';
import { router } from '@inertiajs/react';
import {
  Clock,
  Sparkles,
  Shield,
  CheckCircle2,
  XCircle,
  GripVertical,
  ExternalLink,
  FolderGit2,
  FileCode2,
  ArrowRight,
  ChevronRight,
} from 'lucide-react';

export interface KanbanTask {
  id: number;
  key: string;
  repository: string;
  issueNumber: number;
  issueUrl: string | null;
  title: string;
  path: string;
  status: 'queued' | 'in_progress' | 'needs_review' | 'completed' | 'failed' | 'publishing' | 'outcome_unknown';
  provenance: string | null;
  isDedicated: boolean;
  isSynthetic: boolean;
  isOfflineExample: boolean;
  isSimulated: boolean;
  verificationDecision: string | null;
  model?: string | null;
  publishedPrNumber: number | null;
  publishedPrUrl: string | null;
  publishedBranch: string | null;
  updatedAt: string;
}

interface Column {
  id: string;
  title: string;
  icon: React.ReactNode;
  color: string;
  badgeBg: string;
  badgeText: string;
  bgColor: string;
  borderColor: string;
  dotColor: string;
  tasks: KanbanTask[];
}

interface KanbanBoardProps {
  tasks: KanbanTask[];
  activeTaskId?: number | null;
  onSelectTask?: (task: KanbanTask) => void;
}

function statusBadge(task: KanbanTask) {
  if (task.isSimulated && task.status === 'needs_review') {
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-800 border border-amber-200">
        Simulated (Not Publishable)
      </span>
    );
  }
  if (!task.isSimulated && task.verificationDecision === 'VERIFIED') {
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
        <CheckCircle2 className="w-3 h-3 text-emerald-600" />
        VERIFIED
      </span>
    );
  }
  if (task.publishedPrNumber) {
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-accent-50 text-accent-800 border border-accent-200">
        PR #{task.publishedPrNumber}
      </span>
    );
  }
  if (task.status === 'failed') {
    return (
      <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-800 border border-rose-200">
        <XCircle className="w-3 h-3 text-rose-600" />
        {task.verificationDecision || 'FAILED'}
      </span>
    );
  }
  return null;
}

const cardVariants: Variants = {
  initial: { opacity: 0, y: 15, scale: 0.98 },
  animate: { opacity: 1, y: 0, scale: 1, transition: { type: 'spring' as const, stiffness: 350, damping: 25 } },
  exit: { opacity: 0, scale: 0.95, transition: { duration: 0.15 } },
};

function TaskCard({
  task,
  index,
  isActive,
  onSelect,
}: {
  task: KanbanTask;
  index: number;
  isActive: boolean;
  onSelect?: (t: KanbanTask) => void;
}) {
  return (
    <Draggable draggableId={String(task.id)} index={index}>
      {(provided, snapshot) => (
        <div
          ref={provided.innerRef}
          {...provided.draggableProps}
          onClick={() => onSelect?.(task)}
          className={`group relative rounded-xl border p-3 space-y-2 transition-all duration-200 cursor-grab active:cursor-grabbing select-none ${
            snapshot.isDragging
              ? 'shadow-2xl shadow-accent-500/20 ring-2 ring-accent-500 border-accent-400 bg-white scale-[1.03] rotate-[1.5deg] z-50'
              : isActive
              ? 'border-accent-400 bg-accent-50/40 ring-1 ring-accent-400 shadow-sm'
              : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md shadow-2xs'
          }`}
        >
          {/* Card Top: Drag Handle & Meta */}
          <div className="flex items-start justify-between gap-1.5">
            <div className="flex items-center gap-1.5 min-w-0">
              <div
                {...provided.dragHandleProps}
                className="p-1 -ml-1 text-slate-300 hover:text-slate-600 rounded transition group-hover:opacity-100 opacity-60"
                title="Drag to move card"
              >
                <GripVertical className="w-3.5 h-3.5" />
              </div>
              <span className="text-[10px] font-semibold text-slate-700 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200 truncate">
                #{task.issueNumber} {task.repository.split('/')[1] || task.repository}
              </span>
            </div>

            {isActive ? (
              <span className="text-[9px] font-bold uppercase tracking-wider text-accent-800 bg-accent-100 px-1.5 py-0.5 rounded shrink-0">
                Active
              </span>
            ) : (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  onSelect?.(task);
                }}
                className="text-[10px] font-semibold text-slate-500 hover:text-accent-700 underline shrink-0"
              >
                Select
              </button>
            )}
          </div>

          {/* Title */}
          <p className="text-xs font-semibold text-slate-900 line-clamp-2 leading-snug">
            {task.title}
          </p>

          {/* Target File */}
          <div className="flex items-center gap-1 text-[10px] text-slate-500 font-mono">
            <FileCode2 className="w-3 h-3 text-slate-400 shrink-0" />
            <span className="truncate">{task.path}</span>
          </div>

          {/* Bottom Badges */}
          <div className="flex flex-wrap items-center gap-1 pt-1 border-t border-slate-100">
            {task.isDedicated && (
              <span className="text-[9px] font-semibold text-emerald-800 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
                Dedicated Repo
              </span>
            )}
            {task.model && (
              <span className="text-[9px] font-medium text-accent-800 bg-accent-50 px-1.5 py-0.5 rounded border border-accent-200">
                {task.model}
              </span>
            )}
            {task.isOfflineExample && (
              <span className="text-[9px] font-medium text-slate-600 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">
                Offline Reference
              </span>
            )}
            {statusBadge(task)}
          </div>

          {/* PR link if published */}
          {task.publishedPrUrl && (
            <a
              href={task.publishedPrUrl}
              target="_blank"
              rel="noreferrer"
              onClick={(e) => e.stopPropagation()}
              className="inline-flex items-center gap-1 text-[10px] font-semibold text-accent-700 hover:text-accent-900 hover:underline pt-0.5"
            >
              <span>View GitHub Draft PR</span>
              <ExternalLink className="w-2.5 h-2.5" />
            </a>
          )}
        </div>
      )}
    </Draggable>
  );
}

export function KanbanBoard({ tasks, activeTaskId, onSelectTask }: KanbanBoardProps) {
  const [localTasks, setLocalTasks] = useState(tasks);

  React.useEffect(() => {
    setLocalTasks(tasks);
  }, [tasks]);

  const columns: Column[] = [
    {
      id: 'queued',
      title: 'Queued',
      icon: <Clock className="w-3.5 h-3.5 text-slate-500" />,
      color: 'text-slate-800',
      badgeBg: 'bg-slate-200',
      badgeText: 'text-slate-700',
      bgColor: 'bg-slate-50/70',
      borderColor: 'border-slate-200',
      dotColor: 'bg-slate-400',
      tasks: localTasks.filter((t) => t.status === 'queued'),
    },
    {
      id: 'in_progress',
      title: 'In Progress',
      icon: <Sparkles className="w-3.5 h-3.5 text-accent-600" />,
      color: 'text-accent-900',
      badgeBg: 'bg-accent-100',
      badgeText: 'text-accent-800',
      bgColor: 'bg-accent-50/30',
      borderColor: 'border-accent-200/80',
      dotColor: 'bg-accent-500',
      tasks: localTasks.filter((t) => t.status === 'in_progress' || t.status === 'publishing'),
    },
    {
      id: 'needs_review',
      title: 'Needs Review',
      icon: <Shield className="w-3.5 h-3.5 text-amber-600" />,
      color: 'text-amber-900',
      badgeBg: 'bg-amber-100',
      badgeText: 'text-amber-800',
      bgColor: 'bg-amber-50/30',
      borderColor: 'border-amber-200/80',
      dotColor: 'bg-amber-500',
      tasks: localTasks.filter((t) => t.status === 'needs_review'),
    },
    {
      id: 'completed',
      title: 'Completed',
      icon: <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />,
      color: 'text-emerald-900',
      badgeBg: 'bg-emerald-100',
      badgeText: 'text-emerald-800',
      bgColor: 'bg-emerald-50/30',
      borderColor: 'border-emerald-200/80',
      dotColor: 'bg-emerald-500',
      tasks: localTasks.filter((t) => t.status === 'completed'),
    },
  ];

  const failedTasks = localTasks.filter((t) => t.status === 'failed' || t.status === 'outcome_unknown');

  const handleDragEnd = useCallback(
    (result: DropResult) => {
      if (!result.destination) return;
      const { source, destination, draggableId } = result;
      if (source.droppableId === destination.droppableId && source.index === destination.index) return;

      const taskId = parseInt(draggableId, 10);
      const task = localTasks.find((t) => t.id === taskId);
      if (!task) return;

      // Select the task into the active workbench context
      if (onSelectTask) {
        onSelectTask(task);
      } else {
        router.post('/issues/switch-task', { task_id: taskId }, { preserveScroll: true });
      }
    },
    [localTasks, onSelectTask]
  );

  return (
    <div className="space-y-4">
      <DragDropContext onDragEnd={handleDragEnd}>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
          {columns.map((col) => (
            <div
              key={col.id}
              className={`rounded-xl border ${col.borderColor} ${col.bgColor} p-3 space-y-2.5 min-h-[220px] flex flex-col justify-between`}
            >
              {/* Column Header */}
              <div className="flex items-center justify-between pb-1 border-b border-slate-200/60">
                <div className={`flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider ${col.color}`}>
                  <span className={`w-2 h-2 rounded-full ${col.dotColor}`} />
                  {col.icon}
                  <span>{col.title}</span>
                </div>
                <span className={`text-[10px] font-bold px-1.5 py-0.2 rounded-full ${col.badgeBg} ${col.badgeText}`}>
                  {col.tasks.length}
                </span>
              </div>

              {/* Droppable Drop Zone */}
              <Droppable droppableId={col.id}>
                {(provided, snapshot) => (
                  <div
                    ref={provided.innerRef}
                    {...provided.droppableProps}
                    className={`flex-1 space-y-2.5 rounded-lg p-1 transition-all duration-200 ${
                      snapshot.isDraggingOver
                        ? 'bg-accent-100/60 ring-2 ring-accent-400 ring-dashed'
                        : ''
                    }`}
                  >
                    <AnimatePresence mode="popLayout">
                      {col.tasks.length > 0 ? (
                        col.tasks.map((task, index) => (
                          <motion.div
                            key={task.id}
                            variants={cardVariants}
                            initial="initial"
                            animate="animate"
                            exit="exit"
                            layout
                          >
                            <TaskCard
                              task={task}
                              index={index}
                              isActive={task.id === activeTaskId}
                              onSelect={onSelectTask}
                            />
                          </motion.div>
                        ))
                      ) : (
                        <div className="h-full min-h-[100px] flex flex-col items-center justify-center p-4 text-center">
                          <p className="text-xs text-slate-400 italic">No tickets</p>
                          <p className="text-[10px] text-slate-400">Drag cards here</p>
                        </div>
                      )}
                    </AnimatePresence>
                    {provided.placeholder}
                  </div>
                )}
              </Droppable>
            </div>
          ))}
        </div>
      </DragDropContext>

      {/* Failed Tasks Drawer */}
      {failedTasks.length > 0 && (
        <div className="rounded-xl border border-rose-200 bg-rose-50/40 p-3.5 space-y-2.5">
          <div className="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-rose-800">
            <XCircle className="w-3.5 h-3.5 text-rose-600" />
            <span>Failed Verification Tasks ({failedTasks.length})</span>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {failedTasks.map((task) => (
              <div
                key={task.id}
                onClick={() => onSelectTask?.(task)}
                className="rounded-lg border border-rose-200 bg-white p-2.5 space-y-1.5 shadow-2xs cursor-pointer hover:border-rose-300 transition"
              >
                <div className="flex items-center justify-between gap-1">
                  <span className="text-[10px] font-semibold text-rose-800 bg-rose-100 px-1.5 py-0.5 rounded border border-rose-200 truncate max-w-[120px]">
                    #{task.issueNumber} {task.repository.split('/')[1] || task.repository}
                  </span>
                  <button
                    type="button"
                    className="text-[10px] font-semibold text-slate-500 hover:text-slate-900 underline"
                  >
                    Select
                  </button>
                </div>
                <p className="text-xs font-semibold text-slate-900 line-clamp-2">{task.title}</p>
                <div className="text-[9px] font-semibold text-rose-700">
                  <span>Failed: {task.verificationDecision || 'FAILED'}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
