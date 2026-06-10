<?php
// admin/lib/sanitize.php — sanityzacja HTML tresci postow (whitelista, DOMDocument)
// Tresc z edytora Quill oraz z importu (HTML) przechodzi tu PRZED zapisem.
declare(strict_types=1);

function sanitize_html(string $html): string {
    if (trim($html) === '') return '';
    if (!class_exists('DOMDocument')) {
        // Brak rozszerzenia DOM — bezpieczny fallback (sam tekst)
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    // Zamiana UTF-8 na encje liczbowe PRZED loadHTML (DOMDocument inaczej psuje polskie znaki).
    $wrapped = '<div id="__root__">' . $html . '</div>';
    $encoded = @mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
    if ($encoded === false) $encoded = $wrapped;
    if (!$doc->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $commonAttrs = ['class', 'style'];
    $allowed = [
        'p' => $commonAttrs, 'br' => [], 'hr' => [],
        'strong' => $commonAttrs, 'em' => $commonAttrs, 'b' => $commonAttrs, 'i' => $commonAttrs,
        'u' => $commonAttrs, 's' => $commonAttrs, 'sub' => $commonAttrs, 'sup' => $commonAttrs,
        'h2' => array_merge(['id'], $commonAttrs), 'h3' => array_merge(['id'], $commonAttrs),
        'h4' => array_merge(['id'], $commonAttrs),
        'ul' => $commonAttrs, 'ol' => $commonAttrs,
        'li' => array_merge(['data-list', 'data-checked'], $commonAttrs),
        'a' => array_merge(['href', 'title', 'target', 'rel'], $commonAttrs),
        'img' => array_merge(['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding'], $commonAttrs),
        'blockquote' => $commonAttrs, 'code' => $commonAttrs, 'pre' => $commonAttrs,
        'span' => $commonAttrs, 'div' => $commonAttrs,
        'figure' => $commonAttrs, 'figcaption' => $commonAttrs,
    ];

    $safeStyleProps = ['color', 'background-color', 'text-align', 'text-decoration', 'font-weight', 'font-style'];
    $safeClassPrefixes = ['ql-align-', 'ql-indent-', 'ql-direction-', 'ql-size-', 'ql-syntax', 'post-figure'];
    $allowedSchemes = ['http', 'https', 'mailto', 'tel', ''];

    $walk = function (DOMNode $node) use (&$walk, $allowed, $allowedSchemes, $safeStyleProps, $safeClassPrefixes) {
        $toRemove = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->nodeName);
                if (!isset($allowed[$tag])) {
                    // Nieznany tag: rozpakuj dzieci (zachowaj tekst), usun tag
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $toRemove[] = $child;
                    continue;
                }
                $allowedAttrs = $allowed[$tag];
                $attrsToRemove = [];
                $attrsToSet = [];
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->nodeName);
                    if (!in_array($name, $allowedAttrs, true)) { $attrsToRemove[] = $attr->nodeName; continue; }
                    $val = $attr->nodeValue;
                    if (in_array($name, ['href', 'src'], true)) {
                        // Usun znaki sterujace/biale (chronia przed javasc&#9;ript:) przed analiza schematu
                        $cleanUrl = preg_replace('/[\x00-\x20\x7f]+/', '', (string)$val) ?? '';
                        $scheme = strtolower((string)(parse_url($cleanUrl, PHP_URL_SCHEME) ?: ''));
                        // Dozwolone: relatywne/kotwica ('') oraz http/https/mailto/tel. Reszta (javascript:, data:, ...) odpada.
                        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) { $attrsToRemove[] = $attr->nodeName; continue; }
                        if ($cleanUrl !== $val) { $attrsToSet[$name] = $cleanUrl; }
                    }
                    if ($name === 'style') {
                        $clean = [];
                        foreach (explode(';', $val) as $decl) {
                            if (strpos($decl, ':') === false) continue;
                            [$prop, $pval] = array_map('trim', explode(':', $decl, 2));
                            $prop = strtolower($prop);
                            if (!in_array($prop, $safeStyleProps, true)) continue;
                            // Odrzuc wartosci z cudzyslowami/nawiasami katowymi/backslashem (proba wyjscia z atrybutu)
                            if (preg_match('/["\'<>\\\\]/', $pval)) continue;
                            if (preg_match('/url\s*\(|expression\s*\(|javascript:|@import/i', $pval)) continue;
                            $clean[] = $prop . ': ' . $pval;
                        }
                        if ($clean) $attrsToSet[$name] = implode('; ', $clean); else $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    if ($name === 'class') {
                        $classes = preg_split('/\s+/', trim($val), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                        $kept = [];
                        foreach ($classes as $c) {
                            foreach ($safeClassPrefixes as $pref) {
                                if (strncmp($c, $pref, strlen($pref)) === 0) { $kept[] = $c; break; }
                            }
                        }
                        if ($kept) $attrsToSet[$name] = implode(' ', $kept); else $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    if ($name === 'target' && $val === '_blank') {
                        $rel = $child->getAttribute('rel');
                        if (strpos($rel, 'noopener') === false) $rel .= ' noopener';
                        if (strpos($rel, 'noreferrer') === false) $rel .= ' noreferrer';
                        $child->setAttribute('rel', trim($rel));
                    }
                }
                foreach ($attrsToRemove as $a) $child->removeAttribute($a);
                foreach ($attrsToSet as $name => $val) $child->setAttribute($name, $val);
                $walk($child);
            } elseif ($child instanceof DOMText || $child instanceof DOMCdataSection) {
                // zostaw tekst
            } else {
                // komentarze, PI itp. — usun
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $n) $node->removeChild($n);
    };

    $root = $doc->getElementById('__root__');
    if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    libxml_clear_errors();
    // KRYTYCZNE: NIE uzywac html_entity_decode na zserializowanym HTML — to cofnelo by escapowanie
    // saveHTML i wpuscilo XSS. Odkodowujemy WYLACZNIE polskie znaki (kody >= 0x80) z encji liczbowych,
    // zeby JSON byl czytelny. Encje <, >, &, ", &#60; itd. (ponizej 0x80) zostaja zescapowane.
    $out = mb_decode_numericentity($out, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
    if (function_exists('mb_scrub')) $out = mb_scrub($out, 'UTF-8');
    return trim($out);
}
