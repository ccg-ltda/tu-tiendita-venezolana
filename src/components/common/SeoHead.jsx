import { useEffect, useMemo } from 'react';

const managedMeta = [
  ['name', 'description'], ['name', 'robots'], ['property', 'og:title'], ['property', 'og:description'],
  ['property', 'og:image'], ['property', 'og:url'], ['property', 'og:type'], ['name', 'twitter:card'],
  ['name', 'twitter:title'], ['name', 'twitter:description'], ['name', 'twitter:image'],
];

function setMeta(attribute, key, content) {
  const selector = `meta[${attribute}="${key}"]`;
  const existing = document.head.querySelector(selector);
  if (!content) { existing?.remove(); return; }
  const element = existing || document.createElement('meta');
  element.setAttribute(attribute, key);
  element.setAttribute('content', content);
  if (!existing) document.head.append(element);
}

function setCanonical(canonical) {
  const existing = document.head.querySelector('link[rel="canonical"]');
  if (!canonical) { existing?.remove(); return; }
  const element = existing || document.createElement('link');
  element.setAttribute('rel', 'canonical');
  element.setAttribute('href', canonical);
  if (!existing) document.head.append(element);
}

/** Updates route-specific metadata without a third-party dependency. */
export function SeoHead({ title, description, canonical, robots, openGraph = {}, twitter = {}, jsonLd }) {
  const jsonLdText = useMemo(() => (jsonLd ? JSON.stringify(jsonLd) : null), [jsonLd]);

  useEffect(() => {
    document.title = title;
    setMeta('name', 'description', description);
    setMeta('name', 'robots', robots);
    setCanonical(canonical);
    setMeta('property', 'og:title', openGraph.title);
    setMeta('property', 'og:description', openGraph.description);
    setMeta('property', 'og:image', openGraph.image);
    setMeta('property', 'og:url', openGraph.url);
    setMeta('property', 'og:type', openGraph.type);
    setMeta('name', 'twitter:card', twitter.card);
    setMeta('name', 'twitter:title', twitter.title);
    setMeta('name', 'twitter:description', twitter.description);
    setMeta('name', 'twitter:image', twitter.image);

    const existingJsonLd = document.head.querySelector('script[data-seo-json-ld]');
    if (jsonLdText) {
      const script = existingJsonLd || document.createElement('script');
      script.type = 'application/ld+json';
      script.dataset.seoJsonLd = 'true';
      script.textContent = jsonLdText;
      if (!existingJsonLd) document.head.append(script);
    } else {
      existingJsonLd?.remove();
    }
  }, [canonical, description, jsonLdText, openGraph.description, openGraph.image, openGraph.title, openGraph.type, openGraph.url, robots, title, twitter.card, twitter.description, twitter.image, twitter.title]);

  useEffect(() => () => {
    managedMeta.forEach(([attribute, key]) => document.head.querySelector(`meta[${attribute}="${key}"]`)?.remove());
    document.head.querySelector('link[rel="canonical"]')?.remove();
    document.head.querySelector('script[data-seo-json-ld]')?.remove();
  }, []);

  return null;
}
