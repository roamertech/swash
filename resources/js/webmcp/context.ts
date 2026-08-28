/**
 * WebMCP moved from Navigator to Document while Chromium's implementation was
 * being standardised. Chrome 149 exposes navigator.modelContext, while newer
 * releases expose document.modelContext and retain the navigator alias for
 * compatibility. Always use the browser's native object; this is not a
 * polyfill.
 */
export function getModelContext(): any | null {
  const documentContext = (document as Document & { modelContext?: any }).modelContext;

  if (documentContext) {
    return documentContext;
  }

  return (navigator as Navigator & { modelContext?: any }).modelContext ?? null;
}

export function hasModelContext(): boolean {
  return typeof getModelContext()?.registerTool === 'function';
}
