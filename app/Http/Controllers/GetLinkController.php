<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Services\Admin\InforDomainService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use DOMDocument;
use DOMXPath;

class GetLinkController extends Controller
{
    private InforDomainService $domainService;
    private AdminCustomValidator $form;

    public function __construct(
        InforDomainService $domainService,
        AdminCustomValidator $form
    ) {
        $this->domainService = $domainService;
        $this->form = $form;
    }

    public function getLink(Request $request)
    {
        // $request->validate([
        //     'url' => 'required|url',
        // ]);
        $this->form->validate($request, 'GetLinkForm');

        $url = $request->input('url');
        $domain = str_replace("www.", "", parse_url($url, PHP_URL_HOST)); // Lấy domain từ URL
        $result = $this->domainService->checkDomain($domain);
        if ($result) {
            $class = sprintf('//div[contains(@class, "%s")]', $result->key_class);
        } else {
            $class = '//article | //div[contains(@class, "content") or contains(@class, "post-content") or contains(@class, "entry-content")]';
        }
        try {
            $client = new Client([
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Connection' => 'keep-alive',
                    'Referer' => 'https://www.google.com/',
                ]
            ]);
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lỗi HTTP: ' . $response->getStatusCode(),
                ]);
            }
            $html = $response->getBody()->getContents();
            // Chuyển đổi mã hóa để tránh lỗi ký tự đặc biệt
            $html = $this->cleanHtmlContent($html);

            // Tạo đối tượng DOMDocument
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();

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
    $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15'], true);

    // Chuyển sang UTF-8 nếu cần
    if ($encoding !== 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }

    // Giải mã thực thể HTML nhiều lần để loại bỏ lỗi ký tự đặc biệt
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Xử lý lỗi mã hóa ký tự phổ biến
    $replacePatterns = [
        '/Â£/u' => '£',    // Sửa lỗi "Â£" thành "£"
        '/â€™|â€˜/u' => "'", // Dấu nháy đơn
        '/â€œ|â€/u' => '"', // Dấu nháy kép
        '/â€“|â€”/u' => '-', // Gạch ngang
        '/â€¢/u' => '•',   // Bullet point
        '/â€¦/u' => '...', // Dấu ba chấm
        '/Â/u' => '',      // Xóa ký tự "Â" thừa
        '/â /u' => '',     // Xóa lỗi "â" bị dư
        '/â/u' => '”',
        '/&acirc;/u' => '”',
        '/[\x00-\x1F\x7F-\x9F]/u' => '', // Xóa ký tự điều khiển ẩn
    ];

    return preg_replace(array_keys($replacePatterns), array_values($replacePatterns), $html);
    }

    private function cleanNode($xpath, $node, $dom)
    {
        $tagsToRemove = ['div', 'section', 'figure', 'img'];
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
