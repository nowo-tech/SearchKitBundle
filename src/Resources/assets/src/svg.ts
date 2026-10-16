/** Namespace of SVG elements. */
const SVG_NS = 'http://www.w3.org/2000/svg';

/** Elements an icon may contain (lower-cased local names): shapes, grouping, paint servers, clipping and text alternatives. */
const ALLOWED_ELEMENTS = new Set([
  'svg',
  'g',
  'path',
  'circle',
  'ellipse',
  'line',
  'polyline',
  'polygon',
  'rect',
  'title',
  'desc',
  'defs',
  'lineargradient',
  'radialgradient',
  'stop',
  'clippath',
  'mask',
]);

/** Attribute values that could run code or load a document: script / data URLs (control characters ignored). */
// eslint-disable-next-line no-control-regex -- browsers ignore control characters inside URL schemes
const UNSAFE_VALUE = /^[\u0000- ]*(?:javascript|vbscript|data):/i;

/**
 * Turns the SVG markup of a row icon (rendered server side from the application's icon set) into a detached SVG
 * element, keeping only drawing elements and harmless attributes: no script, `foreignObject`, animation, link or
 * external reference, no event handler, no `href`, no `javascript:` / `data:` value. The markup is parsed into an
 * inert document (nothing runs or loads while it is cleaned).
 *
 * @param markup SVG markup with a single `<svg>` root
 * @returns The cleaned element, owned by the current document, or null when the markup is not a single `<svg>`
 */
export function sanitizeSvg(markup: string): SVGSVGElement | null {
  const body = new DOMParser().parseFromString(markup, 'text/html').body;
  const root = body.firstElementChild;
  if (root === null || body.childElementCount !== 1 || root.namespaceURI !== SVG_NS || root.localName !== 'svg') {
    return null;
  }
  for (const element of [root, ...Array.from(root.querySelectorAll('*'))]) {
    if (element.namespaceURI !== SVG_NS || !ALLOWED_ELEMENTS.has(element.localName.toLowerCase())) {
      element.remove();
      continue;
    }
    for (const attribute of Array.from(element.attributes)) {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('on') || name === 'href' || name.endsWith(':href') || UNSAFE_VALUE.test(attribute.value)) {
        element.removeAttribute(attribute.name);
      }
    }
  }

  return document.importNode(root, true) as SVGSVGElement;
}
