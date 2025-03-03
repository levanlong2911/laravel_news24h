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

    public function __construct
    (
        InforDomainService $domainService,
        AdminCustomValidator $form
    )
    {
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
        if($result) {
            $class = sprintf('//div[contains(@class, "%s")]', $result->key_class);
        } else {
            $class = '//article | //div[contains(@class, "content") or contains(@class, "post-content") or contains(@class, "entry-content")]';
        }
        try {
            $client = new Client([
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
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
            $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true));

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
                    $tagsToRemove = ['div', 'section', 'figure', 'img'];
                    foreach ($tagsToRemove as $tag) {
                        $elements = $xpath->query(".//{$tag}", $node);
                        foreach ($elements as $element) {
                            $element->parentNode->removeChild($element);
                        }
                    }

                    // Loại bỏ tất cả thẻ <a> nhưng giữ lại nội dung bên trong
                    $links = $xpath->query('.//a', $node);
                    foreach ($links as $link) {
                        $textNode = $dom->createTextNode($link->textContent); // Giữ lại nội dung text
                        $link->parentNode->replaceChild($textNode, $link); // Thay thế thẻ <a> bằng nội dung text
                    }

                    // Loại bỏ hoàn toàn thẻ <strong> và <b>, chỉ giữ lại nội dung bên trong
                    $boldTags = $xpath->query('.//strong | .//b', $node);
                    foreach ($boldTags as $bold) {
                        while ($bold->firstChild) {
                            $bold->parentNode->insertBefore($bold->firstChild, $bold);
                        }
                        $bold->parentNode->removeChild($bold);
                    }

                    // Loại bỏ thẻ <u> (underline) nhưng giữ lại nội dung
                    $underlines = $xpath->query('.//u', $node);
                    foreach ($underlines as $underline) {
                        while ($underline->firstChild) {
                            $underline->parentNode->insertBefore($underline->firstChild, $underline);
                        }
                        $underline->parentNode->removeChild($underline);
                    }

                    // Lấy nội dung còn lại (chỉ giữ lại các thẻ còn cần thiết như <p>, <span>, <a>, <strong>, ...)
                    $content[] = trim($dom->saveHTML($node));
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
                return strip_tags(trim($titleNode->item(0)->textContent));
            }
        }

        return "Không có tiêu đề";
    }
}
