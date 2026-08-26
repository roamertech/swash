import './bootstrap';
import { initEditor } from './editor';
import { bootWebMCP } from './webmcp/modes';

const start = (): void => {
  initEditor();

  if (!document.querySelector('.editor') && 'modelContext' in document) {
    void bootWebMCP();
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', start);
} else {
  start();
}
