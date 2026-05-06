<?php

namespace App\Services;

class ArticleCleanerService
{
    private const MAX_CHARS = 9000;   // ~2250 tokens — đủ cho Haiku extract facts
    private const MIN_LINE  = 30;     // bỏ dòng ngắn hơn 30 ký tự (menu, nav, label)

    public function clean(string $html): string
    {
        // ── 1. Strip HTML, giữ paragraph breaks ──────────────────────────────
        $html = preg_replace('#<script[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style[^>]*>.*?</style>#is', '', $html);
        $html = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $html);      // H1 = title trang
        $html = preg_replace('/<h2[^>]*>.*?<\/h2>/is', '', $html, 1);   // H2 đầu = title bài (đã có trong articles.title)
        $html = preg_replace('/<\/?(p|h[1-6]|div|br|li|blockquote|tr|td|th)[^>]*>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/', ' ', $text); // gộp khoảng trắng ngang

        $lines  = explode("\n", $text);
        $result = [];
        $seen   = [];

        foreach ($lines as $line) {
            $t = trim($line);

            if ($t === '') continue;

            // ── 2. Hard stop TRƯỚC min-line — "Recent articles:" chỉ 16 chars ─
            if (preg_match('/^(All comments are subject to|Leave a (Comment|Reply)|Comments?\s*\(\d+\)|You may also like|Recommended for you|More from the web|Recent articles|More articles|Related articles|Press inquiries|Media relations|Media contact|Environmental,?\s+social|Resources|Privacy and cookies|Thanks for sharing)/i', $t)) break;
            if (preg_match('/^(SIGN IN TO COMMENT|POST A COMMENT|JOIN THE DISCUSSION)\s*$/i', $t)) break;

            if (strlen($t) < self::MIN_LINE) continue;

            // Date/category header dạng "Corporate Saturday, May 2, 2026"
            if (preg_match('/^(Corporate|Sports?|Politics?|Business|Entertainment|Technology|Health|Travel|Auto)\s+(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),/i', $t)) continue;

            // ── 3. Loại bỏ rác ───────────────────────────────────────────────

            // URL trần (ảnh, link)
            if (preg_match('/^https?:\/\/\S+$/', $t)) continue;

            // PDF / file download link
            if (preg_match('/\(opens in new window\)|PDF\s+\d+\s*(KB|MB)|\.(pdf|docx?|xlsx?)\b/i', $t)) continue;

            // Subscribe / newsletter / app promo
            if (preg_match('/\b(subscribe|sign up for|newsletter|get the latest|never miss|download the app|get more in our free app|join our|click here to|subscribe to notifications)\b/i', $t) && strlen($t) < 200) continue;

            // App store / loyalty promo
            if (preg_match('/\bdownload (the|our) .{1,40} app\b/i', $t)) continue;
            if (preg_match('/\b(joining is free|sign in with your|continue as a guest|manage trips|real-time updates|not an .{1,30} member)\b/i', $t)) continue;

            // Social share / follow
            if (preg_match('/\b(share (this article|this|on|via)|tweet this|follow us on|like us on|find us on|connect with us|join us on)\b/i', $t)) continue;

            // Advertisement / sponsored
            if (preg_match('/\b(advertisement|sponsored (content|post|by)|paid content|brought to you by|promoted content)\b/i', $t)) continue;

            // Related / read more (dòng đơn giới thiệu bài khác)
            if (preg_match('/^(read more|also read|related|see also|more from|trending now|up next|you might (like|also like)|watch:|listen:)\s*[:\-]?\s*/i', $t)) continue;

            // Cookie / GDPR
            if (preg_match('/\b(cookie policy|privacy policy|we use cookies|by (using|continuing|clicking)|gdpr|your (privacy|data) (choices?|settings?))\b/i', $t) && strlen($t) < 200) continue;

            // Photo credit / caption
            if (preg_match('/\b(getty|ap photo|reuters|afp|shutterstock|wireimage|imagn|photo by|image by|credit:|©)\b/i', $t)) continue;

            // Copyright
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $t)) continue;

            // Author bio
            if (preg_match('/\bis (?:a|an|the) .{0,40}(?:Writer|Editor|Reporter|Correspondent|Contributor|Journalist|Anchor|Presenter|Correspondent) (?:at|for)\b/i', $t)) continue;
            if (preg_match('/^(This is my \d+|I\'ve been covering|I cover the)/i', $t)) continue;

            // Timestamp / date metadata
            if (preg_match('/^(Updated on|Published|Posted|Last updated)\s*[:\-]/i', $t)) continue;

            // Breadcrumb: "Newsroom>News>Details" hoặc "Home / Sport / NFL"
            if (preg_match('/[\w\s]+[>\/][\w\s]+[>\/][\w\s]+/', $t) && strlen($t) < 150) continue;

            // Dòng chỉ có pipe (nav): "NFL | NBA | MLB | NHL"
            if (substr_count($t, ' | ') >= 2 && strlen($t) < 150) continue;

            // Dòng chỉ có dash separator (related list): "Title A - Title B - Title C"
            if (substr_count($t, ' - ') >= 2 && strlen($t) < 200) continue;

            // Loại bỏ dòng trùng lặp (cùng nội dung xuất hiện nhiều lần)
            $key = md5($t);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            // Xóa dấu bullet
            $t = preg_replace('/^\s*[-•*]\s+/', '', $t);

            $result[] = $t;
        }

        return implode("\n\n", $result);
    }

    /**
     * Giới hạn độ dài theo ký tự, giữ nguyên cấu trúc đoạn văn.
     */
    public function limit(string $text, int $maxChars = self::MAX_CHARS): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        $cut  = substr($text, 0, $maxChars);
        $last = strrpos($cut, "\n\n");

        return $last ? substr($cut, 0, $last) : $cut;
    }
}
