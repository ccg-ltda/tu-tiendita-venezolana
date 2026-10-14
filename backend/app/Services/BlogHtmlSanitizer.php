<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

final class BlogHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i',
        'blockquote', 'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody',
        'tr', 'th', 'td', 'code', 'pre', 'hr',
    ];

    /** @var list<string> */
    private const REMOVE_WITH_CONTENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'svg', 'math', 'video', 'audio', 'canvas',
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="blog-html-root">'.$html.'</div></body></html>',
                LIBXML_HTML_NODEFDTD,
            );

            $root = $document->getElementById('blog-html-root');
            if (! $root instanceof DOMElement) {
                return '';
            }

            $elements = [];
            foreach ($root->getElementsByTagName('*') as $element) {
                $elements[] = $element;
            }

            foreach (array_reverse($elements) as $element) {
                if ($element->parentNode === null) {
                    continue;
                }

                $tag = strtolower($element->tagName);
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    if (in_array($tag, self::REMOVE_WITH_CONTENTS, true)) {
                        $this->remove($element);
                    } else {
                        $this->unwrap($element);
                    }

                    continue;
                }

                if (! $this->sanitizeAttributes($element, $tag)) {
                    $this->remove($element);
                }
            }

            $sanitized = '';
            foreach ($root->childNodes as $child) {
                $sanitized .= $document->saveHTML($child);
            }

            return $sanitized;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): bool
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[strtolower($attribute->name)] = $attribute->value;
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttributeNode($attribute);
        }

        if ($tag === 'a') {
            $href = $this->safeUrl($attributes['href'] ?? null, true);
            if ($href !== null) {
                $element->setAttribute('href', $href);
            }

            foreach (['title', 'target'] as $attribute) {
                if (isset($attributes[$attribute])) {
                    $element->setAttribute($attribute, $attributes[$attribute]);
                }
            }

            if (strtolower($attributes['target'] ?? '') === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            } elseif (isset($attributes['rel'])) {
                $element->setAttribute('rel', $attributes['rel']);
            }

            return true;
        }

        if ($tag === 'img') {
            $src = $this->safeUrl($attributes['src'] ?? null, false);
            if ($src === null) {
                return false;
            }

            $element->setAttribute('src', $src);
            foreach (['alt', 'title'] as $attribute) {
                if (isset($attributes[$attribute])) {
                    $element->setAttribute($attribute, $attributes[$attribute]);
                }
            }

            foreach (['width', 'height'] as $attribute) {
                if (isset($attributes[$attribute]) && preg_match('/^\d{1,5}$/', $attributes[$attribute])) {
                    $element->setAttribute($attribute, $attributes[$attribute]);
                }
            }

            $loading = strtolower($attributes['loading'] ?? 'lazy');
            $element->setAttribute('loading', in_array($loading, ['lazy', 'eager'], true) ? $loading : 'lazy');
        }

        return true;
    }

    private function safeUrl(?string $value, bool $allowRelative): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = trim($value);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        $url = preg_replace('/[\x00-\x20\x7F]+/u', '', $decoded);
        if ($url === null || $url === '' || str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
            if (! in_array(strtolower($matches[1]), ['http', 'https'], true)) {
                return null;
            }

            $parts = parse_url($url);

            return is_array($parts) && isset($parts['host']) ? $url : null;
        }

        return $allowRelative ? $url : null;
    }

    private function remove(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    private function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
