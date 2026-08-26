/**
 * Thin fetch wrapper for the Swash API.
 *
 * Accepts both call styles so tool modules stay readable:
 *   api('/pages')                                  → GET
 *   api('/pages', { method: 'POST', body: {...} })
 *   api('GET', '/pages')                           → explicit verb first
 *   api('POST', '/pages', {...})
 */
export class ToolError extends Error {}

type RequestOpts = { method?: string; body?: unknown };

function csrf(): string {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
  );
}

export async function api<T = any>(
  first: string,
  second?: string | RequestOpts,
  third?: unknown,
): Promise<T> {
  let method = 'GET';
  let path: string;
  let body: unknown;

  if (typeof second === 'string') {
    // api(method, path, body?)
    method = first.toUpperCase();
    path = second;
    body = third;
  } else {
    // api(path, { method, body }?)
    path = first;
    method = (second?.method ?? 'GET').toUpperCase();
    body = second?.body;
  }

  const url = `/api${path.startsWith('/') ? path : `/${path}`}`;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  const init: RequestInit = { method, headers, credentials: 'same-origin' };

  if (method !== 'GET' && method !== 'HEAD') {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-TOKEN'] = csrf();
    if (body !== undefined) {
      init.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
  }

  const res = await fetch(url, init);
  const text = await res.text();

  let data: any = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = null;
    }
  }

  if (!res.ok) {
    // Surface Laravel validation messages verbatim — tools relay them to the agent,
    // which is how a rejected value becomes an actionable message instead of a silent failure.
    const detail =
      data?.message ??
      (data?.errors ? Object.values(data.errors).flat().join(' ') : null) ??
      res.statusText;
    throw new ToolError(detail);
  }

  return data as T;
}
