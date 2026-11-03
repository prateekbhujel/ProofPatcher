export interface ReviewFinding {
  path: string;
  status: 'note' | 'concern' | 'approval';
  lineHint: number | null;
  comment: string;
  suggestion?: string | null;
  commentable?: boolean;
  anchorStatus?: 'anchored' | 'hint_only' | 'stale';
  anchorMessage?: string;
  lineType?: string | null;
}

export interface CommentDraft {
  id: string;
  path: string;
  line: number | null;
  side: string;
  comment: string;
  headSha: string;
  status: 'anchored' | 'hint_only' | 'stale';
  valid: boolean;
  commentable?: boolean;
  lineType: string | null;
  message?: string;
  updatedAt?: string;
}

export interface PatchFile {
  name: string;
  status: string;
  additions?: number;
  deletions?: number;
  patch: string | null;
}

export interface DiffLine {
  type: 'addition' | 'deletion' | 'context' | 'header' | 'no_newline';
  oldLine: number | null;
  newLine: number | null;
  content: string;
  isCommentable: boolean;
  side: 'LEFT' | 'RIGHT';
}

export interface SplitDiffRow {
  type: 'header' | 'row';
  headerText?: string;
  left: {
    lineNum: number | null;
    type: 'deletion' | 'context' | 'empty';
    content: string;
  } | null;
  right: {
    lineNum: number | null;
    type: 'addition' | 'context' | 'empty';
    content: string;
  } | null;
}

export interface DiffViewerProps {
  file: PatchFile;
  headSha: string;
  findings?: ReviewFinding[];
  drafts?: CommentDraft[];
  initiallyExpanded?: boolean;
  onSaveDraft: (path: string, line: number | null, side: string, comment: string, onSaved?: () => void) => void;
  onDeleteDraft?: (draftId: string) => void;
  isSavingDraft?: boolean;
}
