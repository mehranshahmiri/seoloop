<?php
declare(strict_types=1);

namespace SeoLoop;

/** Thin read-only view over an HTML document for the on-page checks. */
final class Page
{
    private \DOMDocument $dom;
    private array $names = [];
    private array $props = [];

    public function __construct(string $html)
    {
        $this->dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $this->dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        foreach ($this->dom->getElementsByTagName('meta') as $m) {
            $c = trim($m->getAttribute('content'));
            if (($n = strtolower($m->getAttribute('name'))) !== '') {
                $this->names[$n] ??= $c;
            }
            if (($p = strtolower($m->getAttribute('property'))) !== '') {
                $this->props[$p] ??= $c;
            }
        }
    }

    public function title(): string
    {
        $t = $this->dom->getElementsByTagName('title')->item(0);
        return $t ? trim(preg_replace('/\s+/', ' ', $t->textContent)) : '';
    }

    public function meta(string $name): string
    {
        return $this->names[strtolower($name)] ?? '';
    }

    public function prop(string $property): string
    {
        return $this->props[strtolower($property)] ?? '';
    }

    public function link(string $rel): string
    {
        foreach ($this->dom->getElementsByTagName('link') as $l) {
            if (in_array(strtolower($rel), preg_split('/\s+/', strtolower($l->getAttribute('rel'))), true)) {
                return trim($l->getAttribute('href'));
            }
        }
        return '';
    }

    public function count(string $tag): int
    {
        return $this->dom->getElementsByTagName($tag)->length;
    }

    public function lang(): string
    {
        $r = $this->dom->documentElement;
        return $r ? trim($r->getAttribute('lang')) : '';
    }

    public function imagesMissingAlt(): int
    {
        $n = 0;
        foreach ($this->dom->getElementsByTagName('img') as $img) {
            if (!$img->hasAttribute('alt')) {
                $n++;
            }
        }
        return $n;
    }

    public function words(): int
    {
        $xp = new \DOMXPath($this->dom);
        foreach (iterator_to_array($xp->query('//script|//style|//noscript')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        return (int) preg_match_all('/\p{L}[\p{L}\p{N}\'’-]*/u', $this->dom->textContent ?? '');
    }
}
