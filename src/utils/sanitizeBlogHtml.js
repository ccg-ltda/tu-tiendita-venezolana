const allowedTags = new Set([
  'A', 'B', 'BLOCKQUOTE', 'BR', 'CODE', 'EM', 'FIGCAPTION', 'FIGURE', 'H2', 'H3', 'H4', 'HR', 'I', 'IMG',
  'LI', 'OL', 'P', 'PRE', 'STRONG', 'TABLE', 'TBODY', 'TD', 'TFOOT', 'TH', 'THEAD', 'TR', 'UL',
]);

const removeWithContents = new Set(['BASE', 'EMBED', 'FORM', 'IFRAME', 'INPUT', 'LINK', 'META', 'OBJECT', 'SCRIPT', 'STYLE', 'SVG']);

export function sanitizeBlogHtml(value) {
  if (typeof value !== 'string' || value.trim() === '') return '';

  const documentFragment = new DOMParser().parseFromString(value, 'text/html');

  Array.from(documentFragment.body.querySelectorAll('*')).reverse().forEach((element) => {
    if (!allowedTags.has(element.tagName)) {
      if (removeWithContents.has(element.tagName)) {
        element.remove();
      } else {
        element.replaceWith(...Array.from(element.childNodes));
      }

      return;
    }

    sanitizeAttributes(element);
  });

  return documentFragment.body.innerHTML;
}

function sanitizeAttributes(element) {
  const allowedAttributes = element.tagName === 'A'
    ? new Set(['href', 'title', 'target'])
    : element.tagName === 'IMG'
      ? new Set(['src', 'alt', 'title', 'width', 'height'])
      : new Set();

  Array.from(element.attributes).forEach((attribute) => {
    if (!allowedAttributes.has(attribute.name.toLowerCase())) {
      element.removeAttribute(attribute.name);
    }
  });

  if (element.tagName === 'A') {
    const href = element.getAttribute('href');

    if (!isSafeUrl(href)) element.removeAttribute('href');
    if (element.getAttribute('target') === '_blank') element.setAttribute('rel', 'noopener noreferrer');
    else element.removeAttribute('target');
  }

  if (element.tagName === 'IMG') {
    const src = element.getAttribute('src');

    if (!isSafeUrl(src)) element.remove();
    else element.setAttribute('loading', 'lazy');
  }
}

function isSafeUrl(value) {
  if (typeof value !== 'string' || value.trim() === '') return false;

  try {
    const url = new URL(value, window.location.origin);
    return url.protocol === 'http:' || url.protocol === 'https:';
  } catch {
    return false;
  }
}
