<?php

namespace App\Services;

class ArticleCleanerService
{
    public function clean(string $html): string
    {
        // Remove script / style blocks
        $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);

        // Convert block tags → newlines (preserve paragraph breaks)
        $html = preg_replace('/<\/?(p|h[1-6]|div|br|li|blockquote)[^>]*>/i', "\n", $html);

        // Strip remaining HTML
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines  = explode("\n", $text);
        $result = [];

        foreach ($lines as $line) {
            $t = trim($line);

            if ($t === '' || strlen($t) < 20) continue;

            // Stop at comment/disclaimer sections
            if (preg_match('/^All comments are subject to/i', $t)) break;
            if (preg_match('/^NEED TO KNOW\s*$/i', $t)) continue;

            // Remove image CDN URLs
            if (preg_match('/^https?:\/\/\S+\.(jpg|jpeg|png|gif|webp|svg|avif)(\?[^\s]*)?$/i', $t)) continue;

            // Remove bare URLs
            if (preg_match('/^https?:\/\/\S+$/', $t)) continue;

            // Remove photo credits
            if (preg_match('/\b(getty|ap photo|reuters|afp|shutterstock|wire image|photo by|image by|credit:|imagn)\b/i', $t)) continue;

            // Remove copyright lines
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $t)) continue;

            // Remove author bio lines
            if (preg_match('/\bis (?:a|an) .{2,40}(?:Writer|Editor|Reporter|Correspondent|Contributor) at /i', $t)) continue;

            // Remove app/newsletter promo lines
            if (preg_match('/^Never miss a story/i', $t)) continue;
            if (preg_match('/^(Get more in our free app|Download the app)\s*$/i', $t)) continue;

            // Convert bullet points to plain lines
            $t = preg_replace('/^\s*[-•*]\s+/', '', $t);

            $result[] = $t;
        }

        return implode("\n\n", $result);
    }

    // Trim to N words before sending to AI — prevents token overflow on long articles
    public function limit(string $text, int $words = 1500): string
    {
        $wordsArr = str_word_count($text, 1);

        if (count($wordsArr) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($wordsArr, 0, $words));
    }
}
