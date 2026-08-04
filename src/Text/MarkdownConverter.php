<?php
declare(strict_types=1);

namespace Mcp\Text;

/**
 * Converts an HTML fragment to Markdown. Not a general-purpose converter —
 * scoped to the tag set Readability actually emits after cleanup (headings,
 * paragraphs, lists, links, images, emphasis, code, blockquotes, tables), so
 * it favors correctness on that narrow input over handling arbitrary HTML.
 */
final class MarkdownConverter
{
    public static function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if ($root === null) {
            return '';
        }

        $markdown = self::renderChildren($root);
        $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown ?? '');
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown ?? '');

        return trim($markdown ?? '');
    }

    private static function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::renderNode($child);
        }

        return $out;
    }

    private static function renderNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return self::escapeText($node->textContent);
        }
        if ($node->nodeType !== XML_ELEMENT_NODE || !($node instanceof \DOMElement)) {
            return '';
        }

        switch (strtolower($node->nodeName)) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $level = (int) substr(strtolower($node->nodeName), 1);
                $text = trim(self::renderChildren($node));
                return $text === '' ? '' : "\n\n" . str_repeat('#', $level) . " {$text}\n\n";

            case 'p':
            case 'div':
                $text = trim(self::renderChildren($node));
                return $text === '' ? '' : "\n\n{$text}\n\n";

            case 'br':
                return "  \n";

            case 'hr':
                return "\n\n---\n\n";

            case 'strong':
            case 'b':
                $text = trim(self::renderChildren($node));
                return $text === '' ? '' : "**{$text}**";

            case 'em':
            case 'i':
                $text = trim(self::renderChildren($node));
                return $text === '' ? '' : "*{$text}*";

            case 'code':
                return "`{$node->textContent}`";

            case 'pre':
                $code = rtrim($node->textContent, "\n");
                return "\n\n```\n{$code}\n```\n\n";

            case 'blockquote':
                $inner = trim(self::renderChildren($node));
                if ($inner === '') {
                    return '';
                }
                $quoted = implode("\n", array_map(static function (string $line): string {
                    return '> ' . $line;
                }, explode("\n", $inner)));
                return "\n\n{$quoted}\n\n";

            case 'a':
                $href = trim($node->getAttribute('href'));
                $text = trim(self::renderChildren($node));
                if ($text === '') {
                    $text = $href;
                }
                return $href !== '' ? "[{$text}]({$href})" : $text;

            case 'img':
                $src = trim($node->getAttribute('src'));
                $alt = trim($node->getAttribute('alt'));
                return $src !== '' ? "![{$alt}]({$src})" : '';

            case 'ul':
                $list = self::renderList($node, false);
                return $list === '' ? '' : "\n\n{$list}\n\n";

            case 'ol':
                $list = self::renderList($node, true);
                return $list === '' ? '' : "\n\n{$list}\n\n";

            case 'table':
                $table = self::renderTable($node);
                return $table === '' ? '' : "\n\n{$table}\n\n";

            case 'script':
            case 'style':
                return '';

            default:
                return self::renderChildren($node);
        }
    }

    private static function renderList(\DOMElement $list, bool $ordered, int $depth = 0): string
    {
        $lines = [];
        $index = 1;
        $indent = str_repeat('  ', $depth);

        foreach ($list->childNodes as $item) {
            if ($item->nodeType !== XML_ELEMENT_NODE || strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $prefix = $ordered ? ($index++ . '. ') : '- ';
            $text = '';
            $nested = '';

            foreach ($item->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && in_array(strtolower($child->nodeName), ['ul', 'ol'], true)) {
                    $nested .= "\n" . self::renderList($child, strtolower($child->nodeName) === 'ol', $depth + 1);
                } else {
                    $text .= self::renderNode($child);
                }
            }

            $lines[] = $indent . $prefix . trim($text) . $nested;
        }

        return implode("\n", $lines);
    }

    private static function renderTable(\DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim(self::renderChildren($cell));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $lines = ['| ' . implode(' | ', $rows[0]) . ' |'];
        $lines[] = '|' . str_repeat(' --- |', count($rows[0]));
        for ($i = 1; $i < count($rows); $i++) {
            $lines[] = '| ' . implode(' | ', $rows[$i]) . ' |';
        }

        return implode("\n", $lines);
    }

    private static function escapeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', $text) ?? $text;
    }
}
