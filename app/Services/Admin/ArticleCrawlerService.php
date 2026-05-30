<?php

namespace App\Services\Admin;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArticleCrawlerService
{
    private const CACHE_TTL   = 3600;
    private const MAX_CHARS   = 20000;
    private const TIMEOUT     = 12;
    private const CONCURRENCY = 5;

    private Client  $client;
    private ?string $pythonUrl;
    private ?string $pythonKey;

    public function __construct()
    {
        $this->pythonUrl = rtrim(env('CRAWLER_SERVICE_URL', ''), '/') ?: null;
        $this->pythonKey = env('CRAWLER_SERVICE_KEY') ?: null;

        $this->client = new Client([
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'allow_redirects' => ['max' => 5],
            'verify'          => false,
        ]);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function crawlMany(array $urls): array
    {
        $results = [];
        $toFetch = [];

        foreach ($urls as $url) {
            $hit = Cache::get('crawl:' . md5($url));
            if ($hit !== null) {
                $results[$url] = $hit;
            } else {
                $toFetch[] = $url;
            }
        }

        if (empty($toFetch)) {
            return $results;
        }

        $fetched = $this->pythonUrl
            ? $this->fetchViaPython($toFetch)
            : $this->poolFetch($toFetch);

        foreach ($fetched as $url => $content) {
            Cache::put('crawl:' . md5($url), $content, self::CACHE_TTL);
        }

        return array_merge($results, $fetched);
    }

    public function extractPublishDate(string $url): ?string
    {
        try {
            $html     = (string) $this->client->get($url)->getBody();
            $patterns = [
                '/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\'][^>]*>/i',
                '/<meta[^>]+itemprop=["\']datePublished["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/"datePublished"\s*:\s*"([^"]+)"/i',
                '/"publishedAt"\s*:\s*"([^"]+)"/i',
                '/<time[^>]+datetime=["\']([^"\']+)["\'][^>]*>/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $parsed = strtotime($m[1]);
                    if ($parsed !== false && $parsed > 0) {
                        return date('c', $parsed);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('[Crawler] extractPublishDate failed: ' . $url . ' — ' . $e->getMessage());
        }

        return null;
    }

    // ── Python service ────────────────────────────────────────────────────────

    private function fetchViaPython(array $urls): array
    {
        $results = array_fill_keys($urls, '');

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($this->pythonKey) {
                $headers['X-API-Key'] = $this->pythonKey;
            }

            $python = new Client([
                'base_uri'        => $this->pythonUrl,
                'timeout'         => self::TIMEOUT + 10,
                'connect_timeout' => 3,
                'http_errors'     => false,
                'verify'          => false,
                'headers'         => $headers,
            ]);

            if (count($urls) === 1) {
                $url      = $urls[0];
                $response = $python->post('/crawl', ['json' => ['url' => $url]]);

                if ($response->getStatusCode() === 200) {
                    $data          = json_decode((string) $response->getBody(), true);
                    $results[$url] = $data['content'] ?? '';
                    Log::debug('[Crawler] Python ' . ($data['method'] ?? '?') . ' — ' . $url);
                } else {
                    Log::warning('[Crawler] Python /crawl HTTP ' . $response->getStatusCode() . ', falling back to PHP');
                    $results = $this->poolFetch($urls);
                }
            } else {
                $response = $python->post('/crawl/batch', ['json' => $urls]);

                if ($response->getStatusCode() === 200) {
                    $data = json_decode((string) $response->getBody(), true);
                    foreach ($data as $url => $item) {
                        $results[$url] = $item['content'] ?? '';
                        Log::debug('[Crawler] Python ' . ($item['method'] ?? '?') . ' — ' . $url);
                    }
                } else {
                    Log::warning('[Crawler] Python /crawl/batch HTTP ' . $response->getStatusCode() . ', falling back to PHP');
                    $results = $this->poolFetch($urls);
                }
            }
        } catch (\Exception $e) {
            // Python service down → tự động fallback về PHP
            Log::warning('[Crawler] Python service unavailable (' . $e->getMessage() . '), falling back to PHP');
            $results = $this->poolFetch($urls);
        }

        return $results;
    }

    // ── PHP fallback ──────────────────────────────────────────────────────────

    private function poolFetch(array $urls): array
    {
        $results  = array_fill_keys($urls, '');
        $blocked  = [];
        $requests = function (array $urls) {
            foreach ($urls as $url) {
                yield $url => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->client, $requests($urls), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled'   => function (Response $response, string $url) use (&$results, &$blocked) {
                if ($response->getStatusCode() >= 400) {
                    $blocked[] = $url;
                } else {
                    $results[$url] = $this->extractWithReadability((string) $response->getBody(), $url);
                }
            },
            'rejected' => function (\Throwable $e, string $url) use (&$blocked) {
                Log::warning('[Crawler] Failed: ' . $url . ' — ' . $e->getMessage());
                $blocked[] = $url;
            },
        ]);

        $pool->promise()->wait();

        foreach ($blocked as $url) {
            $jinaText      = $this->fetchViaJina($url);
            $results[$url] = strlen($jinaText) > 200 ? $jinaText : '';
        }

        return $results;
    }

    private function extractWithReadability(string $html, string $url): string
    {
        if (empty(trim($html))) return '';

        $html = mb_scrub($html, 'UTF-8');
        $html = str_replace("\xef\xbf\xbd", ' ', $html);

        $rawHtml = $html;
        $html    = $this->removeNoisyElements($html);

        try {
            $config = new Configuration([
                'FixRelativeURLs'     => true,
                'OriginalURL'         => $url,
                'SummonCthulhu'       => false,
                'NormalizeWhitespace' => true,
            ]);

            $readability = new Readability($config);
            $readability->parse($html);

            $text = $this->htmlToText($readability->getContent() ?? '');
            if (!empty(trim($text)) && strlen(trim($text)) > 200) {
                return $this->cleanText($text);
            }
        } catch (ParseException $e) {
            Log::debug('[Crawler] Readability parse failed for ' . $url . ': ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::warning('[Crawler] Readability error for ' . $url . ': ' . $e->getMessage());
        }

        $jsonText = $this->extractFromJsonLd($rawHtml);
        if (strlen($jsonText) > 200) return $jsonText;

        $ampText = $this->fetchAmpVersion($url);
        if (strlen($ampText) > 200) return $ampText;

        $jinaText = $this->fetchViaJina($url);
        if (strlen($jinaText) > 200) return $jinaText;

        return $this->fallbackExtract($html);
    }

    private function isJinaError(string $text): bool
    {
        $firstLine = trim(explode("\n", $text)[0]);
        return (bool) preg_match(
            '/^(SecurityCompromiseError|AccessDeniedError|RateLimitError|Error:|DDoS attack suspected|Too many requests|blocked until|Anonymous access.*blocked|Unable to access|Failed to fetch|This page requires JavaScript)/i',
            $firstLine
        );
    }

    private function fetchViaJina(string $url): string
    {
        try {
            $response = (new Client(['timeout' => 30, 'verify' => false, 'headers' => ['Accept' => 'text/plain', 'X-Timeout' => '25']]))
                ->get('https://r.jina.ai/' . $url);
            $text = trim((string) $response->getBody());

            if (strlen($text) < 200) return '';

            if ($this->isJinaError($text)) {
                Log::debug('[Crawler] Jina blocked/error for ' . $url . ': ' . substr($text, 0, 120));
                return '';
            }

            $text = str_replace("\r\n", "\n", $text);

            if (str_contains($text, "\nMarkdown Content:\n\n")) {
                $text = substr($text, strpos($text, "\nMarkdown Content:\n\n") + strlen("\nMarkdown Content:\n\n"));
            } elseif (str_contains($text, "\n\n---\n\n")) {
                $text = substr($text, strpos($text, "\n\n---\n\n") + strlen("\n\n---\n\n"));
            } elseif (preg_match('/^(?:Title:|URL Source:|Published Time:).+?(?:\n---+\n\n|\n\n\n)/s', $text, $m)) {
                $text = substr($text, strlen($m[0]));
            }

            $text = trim($text);
            return strlen($text) > 200 ? $this->markdownToHtml($text) : '';

        } catch (\Exception $e) {
            Log::debug('[Crawler] Jina failed: ' . $url . ' — ' . $e->getMessage());
            return '';
        }
    }

    private function fetchAmpVersion(string $url): string
    {
        $parsed = parse_url($url);
        if (empty($parsed['host'])) return '';

        $encoded     = str_replace('.', '-', str_replace('-', '--', $parsed['host']));
        $ampCacheUrl = 'https://' . $encoded . '.cdn.ampproject.org/v/s/' . preg_replace('#^https?://#', '', $url);

        try {
            $response = $this->client->get($ampCacheUrl, ['timeout' => 15]);
            $ampHtml  = (string) $response->getBody();
            if (strlen($ampHtml) < 500) return '';

            $ampHtml = mb_scrub($ampHtml, 'UTF-8');
            $ampHtml = str_replace("\xef\xbf\xbd", ' ', $ampHtml);

            $readability = new Readability(new Configuration(['FixRelativeURLs' => true, 'OriginalURL' => $url, 'NormalizeWhitespace' => true]));
            $readability->parse($this->removeNoisyElements($ampHtml));
            $text = $this->htmlToText($readability->getContent() ?? '');

            return strlen(trim($text)) > 200 ? $this->cleanText($text) : '';
        } catch (\Exception $e) {
            Log::debug('[Crawler] AMP failed: ' . $url . ' — ' . $e->getMessage());
            return '';
        }
    }

    private function extractFromJsonLd(string $html): string
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $scripts);

        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) continue;
            foreach (isset($data['@graph']) ? $data['@graph'] : [$data] as $node) {
                if (!empty($node['articleBody']) && strlen($node['articleBody']) > 200) {
                    return $this->cleanText($this->htmlToText($node['articleBody']));
                }
            }
        }

        if (preg_match('/"articleBody"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $html, $m)) {
            $body = json_decode('"' . $m[1] . '"');
            if (is_string($body) && strlen($body) > 200) {
                return $this->cleanText($this->htmlToText($body));
            }
        }

        return '';
    }

    private function removeNoisyElements(string $html): string
    {
        return preg_replace('/<(script|style|noscript|iframe|svg|canvas|form)[^>]*>.*?<\/\1>/si', '', $html) ?? $html;
    }

    private function cleanText(string $text): string
    {
        $lines       = explode("\n", $text);
        $result      = [];
        $skipSection = false;

        foreach ($lines as $line) {
            $line  = trim(preg_replace('/[\x{25A0}-\x{25FF}\x{2600}-\x{27BF}]/u', '', $line));
            $line  = str_replace(['◆', '◇', '♦', '●', '▸', '▪', '►', '◊'], '', $line);
            $line  = trim($line);
            $lower = strtolower($line);
            $len   = strlen($line);

            if ($len === 0) { $result[] = ''; continue; }
            if ($len < 8 && !preg_match('/[.!?]$/', $line) && !str_starts_with($line, '- ')) continue;
            if (preg_match('/\b(advertisement|sponsored content|paid content)\b/i', $line)) continue;
            if (preg_match('/article continues below|continues below this ad/i', $lower)) continue;
            if (preg_match('/preferred source|subscriber only|subscription required|sign in to read/i', $lower)) continue;
            if (preg_match('/\b(subscribe|sign up|newsletter|follow us|connect with us)\b/i', $lower) && $len < 150) continue;
            if (preg_match('/\b(share this|share on|tweet this|copy link)\b/i', $lower)) continue;
            if (preg_match('/\b(cookie|privacy policy|gdpr|we use cookies)\b/i', $lower) && $len < 200) continue;
            if (preg_match('/^[\w\s]+(?:\s*[>\/|]\s*[\w\s]+){2,}$/', $line) && $len < 100) continue;
            if (preg_match('/^https?:\/\/\S+$/', $line)) continue;
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $lower)) continue;
            if (preg_match('/^(read more|also read|related|more:|trending now|up next)/i', $lower)) continue;
            if (preg_match('/^##heading##(more|related|trending|up next)/i', $lower)) { $skipSection = true; continue; }
            if (preg_match('/^##heading##/i', $lower)) $skipSection = false;
            if ($skipSection) continue;
            if (preg_match('/\b(getty|ap photo|reuters|afp|shutterstock|photo by|image by|credit:|imagn)\b/i', $lower)) continue;
            if (preg_match('/\b(is a (sports|news|staff|senior|contributing|freelance) (writer|reporter|editor|contributor))\b/i', $lower)) continue;
            if (substr_count($line, ' - ') >= 2 && $len > 80) continue;
            if (substr_count($line, ' | ') >= 2) continue;

            $result[] = $line;
        }

        // Xóa orphaned heading (không có nội dung phía sau)
        $filtered = [];
        $total    = count($result);
        for ($i = 0; $i < $total; $i++) {
            if (!str_starts_with($result[$i], '##HEADING##')) { $filtered[] = $result[$i]; continue; }
            $hasContent = false;
            for ($j = $i + 1; $j < $total; $j++) {
                $next = trim($result[$j]);
                if ($next === '') continue;
                if (str_starts_with($next, '##HEADING##')) break;
                $hasContent = true; break;
            }
            if ($hasContent) $filtered[] = $result[$i];
        }

        $text = implode("\n", $filtered);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/\h+/u', ' ', $text);

        return $this->truncate(trim($text));
    }

    private function fallbackExtract(string $html): string
    {
        $html = preg_replace('/<(script|style|nav|footer|header|aside|noscript|iframe|form)[^>]*>.*?<\/\1>/si', '', $html);
        return $this->cleanText($this->htmlToText($html));
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<figure[^>]*>.*?<\/figure>/si', '', $html);
        $html = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/si', '', $html);
        $html = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/si', fn($m) => "\n\n##HEADING##" . trim(strip_tags($m[2])) . "##/HEADING##\n\n", $html);
        $html = preg_replace('/<li[^>]*>/i', '- ', $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        $html = preg_replace('/<[uo]l[^>]*>/i', "\n\n", $html);
        $html = preg_replace('/<\/[uo]l>/i', "\n\n", $html);
        $html = preg_replace('/<(?:p|div|section|article|blockquote)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|section|article|blockquote)>/i', "\n\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/a>\s*<a\b/i', "</a>\n<a", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xef\xbf\xbd", ' ', $text);
        $text = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{007E}\x{00A0}-\x{024F}\x{2010}-\x{2015}\x{2018}-\x{201D}\x{2026}]/u', ' ', $text);
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        return $text;
    }

    private function markdownToHtml(string $markdown): string
    {
        $markdown  = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines     = explode("\n", $markdown);
        $total     = count($lines);
        $startIdx  = 0;
        $seenList  = false;
        $firstFb   = -1;

        for ($i = 0; $i < $total; $i++) {
            $t = trim($lines[$i]);
            if (preg_match('/^[*\-]\s+\S/', $t) && strlen($t) >= 40) $seenList = true;
            if (strlen($t) >= 80 && !preg_match('/^[*\-]\s+/', $t) && !preg_match('/^#{1,6}\s+/', $t) && !preg_match('/^(\[[^\]]*\]\(https?:\/\/[^)]+\))+\s*$/', $t)) {
                if ($firstFb === -1) $firstFb = $i;
                if ($seenList) {
                    $startIdx = $i;
                    for ($j = $i - 1; $j >= max(0, $i - 20); $j--) {
                        $lj = trim($lines[$j]);
                        if (preg_match('/^[*\-]\s+/', $lj)) break;
                        if (preg_match('/^#{1,6}\s+/', $lj)) { $startIdx = $j; break; }
                    }
                    break;
                }
            }
        }

        if ($startIdx === 0 && $firstFb > 0) {
            $startIdx = $firstFb;
            for ($j = $firstFb - 1; $j >= max(0, $firstFb - 20); $j--) {
                $lj = trim($lines[$j]);
                if (preg_match('/^[*\-]\s+/', $lj)) break;
                if (preg_match('/^#{1,6}\s+/', $lj)) { $startIdx = $j; break; }
            }
        }

        $lines         = array_slice($lines, $startIdx);
        $result        = [];
        $foundBody     = false;
        $prevWasImage  = false;
        $skipLines     = 0;
        $stopPattern   = '/^(Follow Us|Newsletter Sign Up|About Us|Privacy Policy|Terms of Service|Advertise)\s*[:\-]?\s*$/i';
        $skipPattern   = '/^(Read More:|Trending Now|Trending Stories|Related Articles|Related Stories)\s*[:\-]?\s*$/i';

        foreach ($lines as $line) {
            $t      = trim($line);
            $checkT = preg_match('/^#{1,6}\s+(.+)/', $t, $hm) ? trim($hm[1]) : $t;
            if ($foundBody && $checkT !== '' && preg_match($stopPattern, $checkT)) break;
            if ($foundBody && $checkT !== '' && preg_match($skipPattern, $checkT)) { $skipLines = 3; continue; }
            if ($skipLines > 0) { if ($t !== '') $skipLines--; continue; }
            if ($t === '') { $result[] = ''; continue; }

            $isImage = str_starts_with($t, '![');
            if ($prevWasImage && !$isImage && strlen($t) < 100 && !preg_match('/^[*\-#]/', $t)) { $prevWasImage = false; continue; }
            if (!$foundBody && strlen($t) >= 80 && !preg_match('/^[*\-#]/', $t)) $foundBody = true;

            if (preg_match('/^#{1,6}\s+(.+)/', $t, $m)) { $prevWasImage = false; $result[] = ''; $result[] = '<h2>' . htmlspecialchars(trim($m[1])) . '</h2>'; $result[] = ''; continue; }
            if (preg_match('/^[-*_]{3,}$/', $t)) { $prevWasImage = false; $result[] = ''; continue; }

            $t = preg_replace('/!\[[^\]]*\]\((?:[^()]*|\([^()]*\))*\)/', '', $t);
            $t = preg_replace('/\[([^\]]+)\]\((?:[^()]*|\([^()]*\))*\)/', '$1', $t);
            $t = preg_replace('/(\*{1,2}|_{1,2})([^*_]+)\1/', '$2', $t);
            $t = preg_replace('/\[[^\]]*\]\(https?:\/\/[^)]*\)/', '', $t);
            $t = trim($t);

            if (!$t || strlen($t) < 8) { $prevWasImage = $isImage; continue; }
            if (preg_match('/^https?:\/\/\S+$/', $t)) continue;
            if (preg_match('/^©|\bcopyright\b|\ball rights reserved\b/i', $t)) continue;
            if (preg_match('/\bis (?:a|an) .{2,40}(?:Writer|Editor|Reporter|Correspondent|Contributor) at /i', $t)) continue;
            if (preg_match('/^[A-Z][a-zA-Z]+(?: [A-Z][a-zA-Z]+){0,2}$/', $t) && strlen($t) < 35) continue;

            $prevWasImage = false;
            $result[] = $t;
        }

        $html  = '';
        $paras = preg_split('/\n{2,}/', implode("\n", $result));
        foreach ($paras as $para) {
            $para = trim($para);
            if (!$para) continue;
            if (str_starts_with($para, '<h2>')) { $html .= $para; continue; }
            $html .= '<p>' . nl2br(htmlspecialchars($para)) . '</p>';
        }

        if (strlen($html) > self::MAX_CHARS) {
            $cut  = substr($html, 0, self::MAX_CHARS);
            $last = strrpos($cut, '</p>');
            $html = $last ? substr($cut, 0, $last + 4) : $cut;
        }

        return $html;
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= self::MAX_CHARS) return $this->toHtml($text);
        $cut  = substr($text, 0, self::MAX_CHARS);
        $last = strrpos($cut, '. ');
        return $this->toHtml($last ? substr($cut, 0, $last + 1) . ' [...]' : $cut . ' [...]');
    }

    private function toHtml(string $text): string
    {
        $paragraphs    = preg_split('/\n{2,}/', trim($text));
        $html          = '';
        $seenParagraph = false;

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (!$para) continue;

            if (preg_match('/^##HEADING##(.+)##\/HEADING##$/s', $para, $m)) {
                $html .= '<h2>' . htmlspecialchars(trim($m[1])) . '</h2>';
                continue;
            }
            if (preg_match('/\|\s*\w+.{0,60}(Images?|Photo|Getty|Reuters|AFP|AP|Imagn)/i', $para) && strlen($para) < 200) continue;
            if (preg_match('/^(Getty|AP|Reuters|AFP|Imagn|Shutterstock|USA Today|Icon Sportswire)/i', $para) && strlen($para) < 120) continue;

            $lines     = explode("\n", $para);
            $nonEmpty  = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
            $listLines = array_filter($nonEmpty, fn($l) => str_starts_with(trim($l), '- '));

            if (count($nonEmpty) >= 2 && count($listLines) >= 2) {
                if (!$seenParagraph) {
                    $allSentences = count(array_filter($nonEmpty, fn($l) => preg_match('/[.!?]\s*$/', trim(preg_replace('/^-\s+/', '', $l))))) === count($nonEmpty);
                    if ($allSentences && count($nonEmpty) <= 6) continue;
                }
                $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . htmlspecialchars(preg_replace('/^-\s+/', '', trim($l))) . '</li>', $nonEmpty)) . '</ul>';
                continue;
            }

            if (str_starts_with(trim($para), '- ')) {
                $html .= '<ul><li>' . htmlspecialchars(preg_replace('/^-\s+/', '', trim($para))) . '</li></ul>';
                continue;
            }

            $shortLines = array_filter($nonEmpty, fn($l) => strlen(trim($l)) <= 40 && !preg_match('/[.!?,]$/', trim($l)));
            if (count($nonEmpty) >= 3 && count($shortLines) === count($nonEmpty)) {
                $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . htmlspecialchars(trim($l)) . '</li>', $nonEmpty)) . '</ul>';
                continue;
            }

            $seenParagraph = true;
            $html .= '<p>' . nl2br(htmlspecialchars($para)) . '</p>';
        }

        return $html;
    }
}
