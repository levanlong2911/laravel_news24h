<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Services\Admin\FontService;
use App\Services\Admin\InforDomainService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use DOMDocument;
use DOMXPath;

class GetLinkController extends Controller
{
    private InforDomainService $domainService;
    private AdminCustomValidator $form;
    private FontService $fontService;

    public function __construct(
        InforDomainService $domainService,
        AdminCustomValidator $form,
        FontService $fontService
    )
    {
        $this->domainService = $domainService;
        $this->form = $form;
        $this->fontService = $fontService;
    }

    public function getLink(Request $request)
    {
        $this->form->validate($request, 'GetLinkForm');

        $url = $request->input('url');
        $domain = str_replace("www.", "", parse_url($url, PHP_URL_HOST)); // Lấy domain từ URL
        $result = $this->domainService->checkDomain($domain);
        if ($result) {
            $class = sprintf('//div[contains(@class, "%s")]', $result->key_class);
        } else {
            $class = '//article//p | //div[contains(@class, "content") or contains(@class, "post-content") or contains(@class, "entry-content")]';
        }
        try {
            $client = new Client([
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Referer' => 'https://www.google.com/',
                    'X-Forwarded-For' => '66.249.66.1',
                    'Cookie' => 'CONSENT=YES+1;',
                ],
                'allow_redirects' => true,
                'cookies' => true,
            ]);
            $response = $client->request('GET', $url);
            // $client = new Client([
            //     'timeout' => 15,
            //     'connect_timeout' => 10,
            //     'http_errors' => false,
            //     'allow_redirects' => [
            //         'max' => 5,
            //         'track_redirects' => true,
            //     ],
            //     'headers' => $this->getStealthHeaders(),
            //     'version' => 2.0, // HTTP/2
            // ]);
            // $response = $client->request('GET', $url);
            // $response = null;

            // for ($i = 0; $i < 2; $i++) {
            //     $response = $client->get($url);
            //     if ($response->getStatusCode() === 200) {
            //         break;
            //     }
            //     sleep(1);
            // }

            if ($response->getStatusCode() !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lỗi HTTP: ' . $response->getStatusCode(),
                ]);
            }
            $html = $response->getBody()->getContents();
            // Chuyển đổi mã hóa để tránh lỗi ký tự đặc biệt
            $html = $this->cleanHtmlContent($html);

            // --- Loại bỏ tất cả comment qv trước khi load DOM ---
            $html = $this->removeQVBlocks($html);

            // Tạo đối tượng DOMDocument
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            // $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            libxml_clear_errors();

            // Remove comment qv trực tiếp từ DOM (an toàn)
            $this->removeQVCommentsFromDOM($dom);

            // Tạo đối tượng DOMXPath
            $xpath = new DOMXPath($dom);

            // Lấy tiêu đề từ <title>
            $title = $this->getTitleFromHtml($dom);

            // Tìm div có class "content-block-regular"
            $contentNodes = $xpath->query($class);

            $content = [];
            if ($contentNodes->length > 0) {
                foreach ($contentNodes as $node) {
                    $this->cleanNode($xpath, $node, $dom);
                    $content[] = $this->cleanHtmlContent(trim($dom->saveHTML($node)));
                }
            }

            return response()->json([
                'success' => true,
                'title' => trim($title),
                'content' => implode("\n", $content),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể lấy dữ liệu từ trang web này. Lỗi: ' . $e->getMessage(),
            ]);
        }
    }

    // private function getStealthHeaders(): array
    // {
    //     $userAgents = [
    //         'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    //         'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
    //     ];

    //     return [
    //         'User-Agent' => $userAgents[array_rand($userAgents)],
    //         'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    //         'Accept-Language' => 'en-US,en;q=0.9',

    //         // ❌ KHÔNG br
    //         'Accept-Encoding' => 'gzip, deflate',

    //         'Cache-Control' => 'no-cache',
    //         'Pragma' => 'no-cache',
    //         'Upgrade-Insecure-Requests' => '1',
    //         'Sec-Fetch-Site' => 'none',
    //         'Sec-Fetch-Mode' => 'navigate',
    //         'Sec-Fetch-User' => '?1',
    //         'Sec-Fetch-Dest' => 'document',
    //     ];
    // }


    private function removeQVBlocks($html)
    {
        // Xóa tất cả comment kiểu <!--qv ...-->
        $html = preg_replace('/<!--\s*\/?qv[\s\S]*?-->/i', '', $html);

        // Xóa mọi dạng <!-- qv ... --> lồng nhiều tầng
        $html = preg_replace('/<!--\s*qv[^>]*-->/i', '', $html);

        return $html;
    }

    private function removeQVCommentsFromDOM($dom)
    {
        $xpath = new DOMXPath($dom);
        $comments = $xpath->query('//comment()');

        foreach ($comments as $comment) {
            if (stripos($comment->nodeValue, 'qv') !== false) {
                $comment->parentNode->removeChild($comment);
            }
        }
    }


    private function getTitleFromHtml($dom)
    {
        $xpath = new DOMXPath($dom);
        $titleQueries = [
            '//h1[contains(@class, "article-header-title")]',
            '//h1',
            '//title'
        ];

        foreach ($titleQueries as $query) {
            $titleNode = $xpath->query($query);
            if ($titleNode->length > 0) {
                $title = strip_tags(trim($titleNode->item(0)->textContent));

                // Làm sạch lỗi ký tự trước khi trả về
                return $this->cleanHtmlContent($title);
            }
        }

        return "Không có tiêu đề";
    }

    private function cleanHtmlContent($html)
    {
        // Phát hiện mã hóa
        $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        // Chuyển sang UTF-8 nếu cần
        if ($encoding !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        }

        // Bước 2: Dùng iconv để chuyển đổi encoding chuẩn hơn (tùy môi trường)
        $html = @iconv('UTF-8', 'UTF-8//IGNORE', $html);

        // Bước 3: Decode HTML entities nhiều lần
        for ($i = 0; $i < 3; $i++) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Bước 4: Regex thay thế lỗi mã hóa thường gặp
        $replacePatterns = [
            // Em dash hoặc dash
            '/€”|â€”|Ã¢â‚¬â€œ|Ã¢€”|Ã¢â‚¬”|â€"|' . preg_quote('Ã¢â€”', '/') . '/u' => '—',

            // Dấu nháy đơn cong
            '/€™|â€™|â€˜|Ã¢â‚¬â„¢|Ã¢€™|â€˜/u' => '’',

            // Dấu nháy kép cong
            '/â€œ|â€|Ã¢â‚¬Å“|Ã¢â‚¬Â|“|”/u' => '"',

            // Dấu ba chấm
            '/â€¦|Ã¢â‚¬Â¦/u' => '...',

            // Bullet
            '/â€¢|Ã¢â‚¬Â¢/u' => '•',

            // Ký hiệu tiền tệ
            '/Â£/u' => '£',

            // Loại bỏ byte lỗi
            '/Â/u' => '',
            '/â /u' => '',
            '/Ã¢/u' => '',
            '/[\x00-\x1F\x7F-\x9F]/u' => '', // Xóa ký tự điều khiển ẩn
            '/\s*q:key="[^"]*"/i' => '',
            '/\s*q:id="[^"]*"/i' => '',
            '/\s*on:qvisible="[^"]*"/i' => '',
        ];

        $html = preg_replace(array_keys($replacePatterns), array_values($replacePatterns), $html);

        // 5. Thay thế theo DB nếu có
        if (isset($this->fontService)) {
            $replacements = $this->fontService->getListFont();
            foreach ($replacements as $rep) {
                $html = preg_replace('/' . preg_quote($rep->find, '/') . '/u', $rep->replace, $html);
            }
        }

        return $html;
    }

    private function cleanNode($xpath, $node, $dom)
    {
        // Xử lý thẻ div: giữ lại div có class "table-container", xóa các div khác
        $divs = $xpath->query('.//div', $node);
        foreach ($divs as $div) {
            $classAttr = $div->getAttribute('class');
            if (strpos($classAttr, 'table-container') === false) {
                $div->parentNode->removeChild($div);
            }
        }

        $tagsToRemove = ['section', 'figure', 'img'];
        foreach ($tagsToRemove as $tag) {
            $elements = $xpath->query(".//{$tag}", $node);
            foreach ($elements as $element) {
                $element->parentNode->removeChild($element);
            }
        }

        $links = $xpath->query('.//a', $node);
        foreach ($links as $link) {
            $textNode = $dom->createTextNode($link->textContent);
            $link->parentNode->replaceChild($textNode, $link);
        }

        $tagsToKeepContent = ['strong', 'b', 'u'];
        foreach ($tagsToKeepContent as $tag) {
            $elements = $xpath->query(".//{$tag}", $node);
            foreach ($elements as $element) {
                while ($element->firstChild) {
                    $element->parentNode->insertBefore($element->firstChild, $element);
                }
                $element->parentNode->removeChild($element);
            }
        }
    }
}


