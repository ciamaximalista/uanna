<?php

namespace Oannes;

final class Html
{
    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function safeContent(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\2/i', ' $1="#"', $html) ?? '';

        return $html;
    }
}

