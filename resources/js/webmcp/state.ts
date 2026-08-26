import type { SharedState } from './types';

const state: SharedState = {
  mode: 'site',
  openPage: null,
  status: null,
  theme: null,
  cursorBlockId: null,
  selection: null,
  hasUnsavedChanges: false,
};

export function patchState(partial: Partial<SharedState>): void {
  Object.assign(state, partial);
  sync();
}

export function getState(): Readonly<SharedState> {
  return state;
}

function sync(): void {
  const compact = {
    mode: state.mode,
    openPage: state.openPage,
    status: state.status,
    theme: state.theme,
    cursorBlockId: state.cursorBlockId,
    selection: state.selection
      ? {
          text: state.selection.text.slice(0, 200),
          blockId: state.selection.blockId,
        }
      : null,
    hasUnsavedChanges: state.hasUnsavedChanges,
  };

  try {
    const modelContext = document.modelContext;

    if (modelContext && typeof modelContext.provideContext === 'function') {
      modelContext.provideContext(compact);
    }
  } catch {
  }

  document.dispatchEvent(
    new CustomEvent('swash:state', {
      detail: { ...state },
    })
  );
}
