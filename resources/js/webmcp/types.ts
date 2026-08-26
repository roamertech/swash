export interface ToolDef {
  name: string;
  description: string;
  inputSchema?: Record<string, unknown>;
  annotations?: {
    readOnlyHint?: boolean;
    untrustedContentHint?: boolean;
  };
  execute: (input: any, client?: any) => Promise<string>;
}

export type ModeName = 'site' | 'write' | 'design' | 'publish';

export interface SharedState {
  mode: ModeName;
  openPage: { id: number; title: string; kind: string } | null;
  status: string | null;
  theme: {
    type_pair: string;
    mood: string;
    palette: Record<string, string>;
  } | null;
  cursorBlockId: number | null;
  selection: { text: string; blockId: number | null } | null;
  hasUnsavedChanges: boolean;
}

declare global {
  interface Document {
    modelContext?: any;
  }
}
