interface ConfirmOptions {
  title: string;
  body: string;
  detail?: string;
  confirmLabel?: string;
}

export async function confirmWithUser(
  client: any,
  render: () => Promise<boolean> | boolean
): Promise<boolean> {
  if (client && typeof client.requestUserInteraction === 'function') {
    return await client.requestUserInteraction(render);
  }

  return await render();
}

export function showConfirmDialog(opts: ConfirmOptions): Promise<boolean> {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'swash-modal-overlay';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.zIndex = '9999';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.padding = '1rem';
    overlay.style.background = 'rgba(15, 23, 42, 0.5)';

    const panel = document.createElement('div');
    panel.className = 'swash-modal';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.style.background = '#ffffff';
    panel.style.color = '#0f172a';
    panel.style.borderRadius = '0.75rem';
    panel.style.padding = '1.5rem';
    panel.style.width = '100%';
    panel.style.maxWidth = '28rem';
    panel.style.boxShadow = '0 20px 50px rgba(15, 23, 42, 0.35)';

    const title = document.createElement('h2');
    title.textContent = opts.title;
    title.style.margin = '0';
    title.style.fontSize = '1.125rem';
    title.style.fontWeight = '600';

    const body = document.createElement('p');
    body.textContent = opts.body;
    body.style.margin = '0.75rem 0 0';
    body.style.color = '#334155';

    const actions = document.createElement('div');
    actions.style.display = 'flex';
    actions.style.justifyContent = 'flex-end';
    actions.style.gap = '0.75rem';
    actions.style.marginTop = '1.5rem';

    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'swash-modal-cancel';
    cancelButton.textContent = 'Cancel';
    cancelButton.style.border = '1px solid #cbd5e1';
    cancelButton.style.background = '#ffffff';
    cancelButton.style.color = '#0f172a';
    cancelButton.style.borderRadius = '0.5rem';
    cancelButton.style.padding = '0.5rem 0.875rem';
    cancelButton.style.cursor = 'pointer';

    const confirmButton = document.createElement('button');
    confirmButton.type = 'button';
    confirmButton.className = 'swash-modal-confirm';
    confirmButton.textContent = opts.confirmLabel ?? 'Confirm';
    confirmButton.style.border = '1px solid #0f172a';
    confirmButton.style.background = '#0f172a';
    confirmButton.style.color = '#ffffff';
    confirmButton.style.borderRadius = '0.5rem';
    confirmButton.style.padding = '0.5rem 0.875rem';
    confirmButton.style.cursor = 'pointer';

    if (opts.detail) {
      const detail = document.createElement('pre');
      detail.className = 'swash-modal-detail';
      detail.textContent = opts.detail;
      detail.style.margin = '0.75rem 0 0';
      detail.style.padding = '0.75rem';
      detail.style.borderRadius = '0.5rem';
      detail.style.background = '#f8fafc';
      detail.style.border = '1px solid #e2e8f0';
      detail.style.whiteSpace = 'pre-wrap';
      detail.style.wordBreak = 'break-word';
      panel.append(title, body, detail, actions);
    } else {
      panel.append(title, body, actions);
    }

    actions.append(cancelButton, confirmButton);
    overlay.append(panel);

    let settled = false;

    const finish = (result: boolean) => {
      if (settled) {
        return;
      }

      settled = true;
      document.removeEventListener('keydown', onKeyDown, true);
      overlay.remove();
      resolve(result);
    };

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        finish(false);
      }
    };

    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) {
        finish(false);
      }
    });

    cancelButton.addEventListener('click', () => {
      finish(false);
    });

    confirmButton.addEventListener('click', () => {
      finish(true);
    });

    document.addEventListener('keydown', onKeyDown, true);
    document.body.append(overlay);
    confirmButton.focus();
  });
}
