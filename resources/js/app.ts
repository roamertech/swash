import './bootstrap';
import { initEditor } from './editor';
import { bootWebMCP } from './webmcp/modes';
import { hasModelContext } from './webmcp/context';

const start = (): void => {
  initEditor();

  if (!document.querySelector('.editor') && hasModelContext()) {
    void bootWebMCP();
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', start);
} else {
  start();
}
