<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Services\Admin\CategoryService;
use App\Services\Admin\FontService;
use App\Services\Admin\InforDomainService;
use App\Services\Admin\PostService;
use Illuminate\Http\Request;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class PostController extends Controller
{

    private CategoryService $categoryService;
    private InforDomainService $domainService;
    private AdminCustomValidator $form;
    private PostService $postService;
    private FontService $fontService;

    public function __construct
    (
        CategoryService $categoryService,
        InforDomainService $domainService,
        AdminCustomValidator $form,
        FontService $fontService,
        PostService $postService
    )
    {
        $this->categoryService = $categoryService;
        $this->domainService = $domainService;
        $this->form = $form;
        $this->postService = $postService;
        $this->fontService = $fontService;
    }


    public function index()
    {
        $user = auth()->user(); // lấy id user đang đăng nhập
        $listsPost = $this->postService->getListPost($user);
        return view("post.index", [
            "route" => "post",
            "action" => "admin-post",
            "menu" => "menu-open",
            "active" => "active",
            'listsPost' => $listsPost,
            "listIdPost" => $this->postService->getListPost($user)->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        $listsCate = $this->categoryService->getListCategory();
        if($request->isMethod('post')) {
            $this->form->validate($request, 'PostAddForm');
            $addPost = $this->postService->create($request);
            if ($addPost) {
                return redirect()->route('post.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('post.index')->with('error', __('messages.add_error'));
        }
        return view("post.add", [
            "route" => "post",
            "action" => "post-index",
            "menu" => "menu-open",
            "active" => "active",
            "listsCate" => $listsCate,
        ]);
    }

    public function detail(Request $request)
    {
        // dd(11);
        $infoPost = $this->postService->getInfoPost($request->slug);
        if(!$infoPost) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        dd($infoPost);
        return view("post.detail", [
            "route" => "post",
            "action" => "post-detail",
            "menu" => "menu-open",
            "active" => "active",
            "infoTag" => $infoTag,
        ]);

    }

    public function update(Request $request, $id)
    {
        $listPost = $this->postService->getPostById($id, auth()->user());
        $listsCate = $this->categoryService->getListCategory();
        if($request->isMethod('post')) {
            $this->form->validate($request, 'PostUpdateForm');
            $upPost = $this->postService->update($id, $request);
            if ($upPost) {
                return redirect()->route('post.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('post.index')->with('error', __('messages.add_error'));
        }
        return view("post.update", [
            "route" => "post",
            "action" => "post-index",
            "menu" => "menu-open",
            "active" => "active",
            "listPost" => $listPost,
            "listsCate" => $listsCate,
        ]);
    }

    public function delete(Request $request)
    {
        $del = $this->postService->delete($request);
        if ($del) {
            return redirect()
                ->route('post.index')
                ->with("success", __("messages.delete_success"));
        }
        return redirect()
        ->route('post.index')
        ->with("error", __("messages.delete_error"));
    }

    public function addPost(Request $request)
    {
        // $this->form->validate($request, 'GetLinkForm');
        // dd(11);

        $url = "https://simpleflying.com/travel-tuesday-takes-off-unbeatable-flight-deals/";
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
            dd(implode("\n", $content));

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
        '/\s*q:key="[^"]*"/i' => '',
        '/\s*q:id="[^"]*"/i' => '',
        '/\s*on:qvisible="[^"]*"/i' => '',
    ];

    return preg_replace(array_keys($replacePatterns), array_values($replacePatterns), $html);
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
