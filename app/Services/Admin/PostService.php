<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Models\Post;
use App\Repositories\Interfaces\PostRepositoryInterface;
use App\Repositories\Interfaces\PostTagRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use DOMDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Illuminate\Support\Facades\Storage;

class PostService
{
    private PostRepositoryInterface $postRepository;
    private PostTagRepositoryInterface $postTagRepository;
    private PostTagService $postTagService;

    public function __construct(
        PostRepositoryInterface $postRepository,
        PostTagRepositoryInterface $postTagRepository,
        PostTagService $postTagService,
    )
    {
        $this->postRepository = $postRepository;
        $this->postTagRepository = $postTagRepository;
        $this->postTagService = $postTagService;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListPost()
    {
        return $this->postRepository->getListPost();
    }

    public function getInfoPost($id)
    {
        return $this->postRepository->find($id);
    }

    public function create($request, $domainId): Model
    {
        DB::beginTransaction();

        try {
            // Chuyển đổi ảnh sang WebP và cập nhật editor_content
            $updatedContent = $this->convertImagesToWebp($request->editor_content);
            // Chuyển đổi ảnh thumbnail sang WebP
            $webpThumbnail = $this->convertThumbnailToWebp($request->image);
            // Tạo UUID cho bài viết
            $postId = Str::uuid()->toString();
            $params = [
                'id' => $postId,
                'title' => $request->title,
                'content' => $updatedContent,
                'slug' => $request->slug,
                'thumbnail' => $webpThumbnail,
                'category_id' => $request->category,
                'author_id' => Auth::id(),
                'domain_id' => $domainId,
            ];
            // Create a new admin using the repository
            $post = $this->postRepository->create($params);
            // Xử lý tags nếu có
            if ($request->has('tagIds') && !empty($request->tagIds)) {
                // Chuyển chuỗi tag thành mảng UUID
                $tagIds = array_map('trim', explode(',', $request->tagIds));

                // Gắn tags vào bài viết qua bảng pivot `post_tags`
                $post->tags()->attach($tagIds);
            }
            DB::commit();
            // 🔥 CLEAR CACHE SAU KHI CREATE
            // $this->clearPostCacheAfterCreate($post);
            return $post;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // private function convertImagesToWebp(string $content): string
    // {
    //     // Tạo DOM từ nội dung HTML
    //     $dom = new DOMDocument();
    //     libxml_use_internal_errors(true);
    //     $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
    //     libxml_clear_errors();

    //     // Lấy tất cả thẻ <img>
    //     $images = $dom->getElementsByTagName('img');
    //     // Mảng lưu trữ ảnh cần tải
    //     $imageUrls = [];


    //     foreach ($images as $img) {
    //         $src = str_replace(' ', '%20', $img->getAttribute('src')); //Mã hóa khoảng trắng

    //         if (!filter_var($src, FILTER_VALIDATE_URL)) {
    //             continue; // Bỏ qua nếu không hợp lệ
    //         }

    //         $parsedUrl = parse_url($src);
    //         if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
    //             continue; // Kiểm tra domain hợp lệ
    //         }

    //         $imageUrls[] = $src;
    //     }
    //     // Tải tất cả ảnh một lần bằng multi cURL
    //     $imageDataList = $this->fetchImage($imageUrls);

    //     foreach ($images as $img) {
    //         $src = str_replace(' ', '%20', $img->getAttribute('src'));
    //         if (!isset($imageDataList[$src])) {
    //             continue;
    //         }

    //         $webpData = $this->downloadAndConvertToWebp($src, $imageDataList[$src]);
    //         if ($webpData) {
    //             list($webpPath, $newHeight) = $webpData;
    //             $img->setAttribute('src', asset("storage/$webpPath"));
    //             $img->setAttribute('width', '800');
    //             if ($newHeight) {
    //                 $img->setAttribute('height', $newHeight);
    //             }
    //         }
    //     }

    //     $body = $dom->getElementsByTagName('body')->item(0);
    //     $contentWithoutHtmlBody = '';
    //     foreach ($body->childNodes as $node) {
    //         $contentWithoutHtmlBody .= $dom->saveHTML($node);
    //     }

    //     return $contentWithoutHtmlBody;
    // }

    private function convertImagesToWebp(string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        // 1️⃣ Fix encoding toàn bộ HTML trước khi DOM parse
        $content = $this->normalizeHtml($content);

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        // 2️⃣ Snapshot img nodes (BẮT BUỘC)
        $images = iterator_to_array($dom->getElementsByTagName('img'));

        foreach ($images as $img) {
            $rawSrc = $img->getAttribute('src');
            $src = $this->normalizeImageUrl($rawSrc);

            if (!$src) {
                continue;
            }

            $imageContent = $this->fetchSingleImage($src);
            if (!$imageContent) {
                continue;
            }

            $webpData = $this->downloadAndConvertToWebp($src, $imageContent);
            if (!$webpData) {
                continue;
            }

            [$webpPath, $newHeight] = $webpData;

            $img->setAttribute('src', asset("storage/$webpPath"));
            $img->setAttribute('width', '800');

            if ($newHeight) {
                $img->setAttribute('height', (string) $newHeight);
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $contentWithoutHtmlBody = '';
        foreach ($body->childNodes as $node) {
            $html = trim($dom->saveHTML($node));
            if ($html !== '') {
                $contentWithoutHtmlBody .= $html . PHP_EOL . PHP_EOL;
            }
        }

        return trim($contentWithoutHtmlBody);
    }

    private function normalizeHtml(string $html): string
    {
        $encoding = mb_detect_encoding($html, [
            'UTF-8', 'ISO-8859-1', 'Windows-1252'
        ], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        }

        for ($i = 0; $i < 3; $i++) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Xóa byte lỗi
        $html = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $html);

        return $html;
    }

    private function normalizeImageUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $url) {
                break;
            }
            $url = $decoded;
        }

        $url = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $url);

        $encoding = mb_detect_encoding($url, [
            'UTF-8', 'ISO-8859-1', 'Windows-1252'
        ], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $url = mb_convert_encoding($url, 'UTF-8', $encoding);
        }

        $url = strtok($url, '?');

        $url = trim(str_replace(' ', '%20', $url));

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    private function fetchSingleImage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/*,*/*;q=0.8',
                'Accept-Encoding: identity',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$data) {
            return null;
        }

        return $data;
    }

    private function downloadAndConvertToWebp($imageUrl, $imageContent)
    {
        if (strlen($imageContent) < 2000) {
            return null;
        }

        try {
            $originalWidth  = null;
            $originalHeight = null;

            // 1️⃣ Thử lấy size bằng GD
            $size = @getimagesizefromstring($imageContent);
            if ($size && isset($size[0], $size[1])) {
                $originalWidth  = $size[0];
                $originalHeight = $size[1];
            }

            // list($originalWidth, $originalHeight, $imageType) = $originalSize;

            if (!$originalWidth || !$originalHeight) {
                try {
                    $imagick = new \Imagick();
                    $imagick->readImageBlob($imageContent);

                    $originalWidth  = $imagick->getImageWidth();
                    $originalHeight = $imagick->getImageHeight();

                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Throwable $e) {
                    return null;
                }
            }

            if ($originalWidth < 10 || $originalHeight < 10) {
                return null;
            }

            // Lấy phần mở rộng hợp lệ từ loại ảnh
            $validExtensions = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_AVIF => 'avif', // Hỗ trợ AVIF
            ];
            if (!isset($validExtensions)) {
                return null;
            }

            // Lấy tên file gốc và tạo tên WebP
            $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
            if (!$originalName) {
                $originalName = md5($imageUrl);
            }
            $timestamp = now()->format('Ymd_His');
            $fileName = $timestamp . '_' . Str::slug($originalName) . '.webp';
            $filePath = 'public/images/' . $fileName;

            // Nếu ảnh đã tồn tại, không cần xử lý lại
            if (Storage::exists($filePath)) {
                return [str_replace('public/', '', $filePath), null];
            }

            // Tạo ImageManager với Imagick
            $manager = new ImageManager(new ImagickDriver());

            // Tính toán chiều cao theo tỷ lệ width = 800
            $newHeight = ($originalWidth > 0) ? (int) (($originalHeight * 800) / $originalWidth) : 800;

            // Chuyển đổi ảnh sang WebP
            $image = $manager->read($imageContent);
            $image = $image->scale(width: 800, height: $newHeight)->toWebp(quality: 80);

            // Lưu ảnh vào storage
            Storage::put($filePath, $image->toString());
            unset($image, $imageContent);
            // Trả về đường dẫn public
            return [str_replace('public/', '', $filePath), $newHeight];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Tải ảnh từ URL bằng cURL để tránh lỗi `file_get_contents()`
     */
    // private function fetchImage($urls)
    // {
    //     $mh = curl_multi_init();
    //     $chArray = [];
    //     $imageDataList = [];

    //     foreach ($urls as $url) {
    //         $ch = curl_init($url);
    //         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //         curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    //         curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    //         curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    //         curl_multi_add_handle($mh, $ch);
    //         $chArray[$url] = $ch;
    //     }

    //     do {
    //         curl_multi_exec($mh, $running);
    //     } while ($running);

    //     foreach ($chArray as $url => $ch) {
    //         $imageDataList[$url] = curl_multi_getcontent($ch);
    //         curl_multi_remove_handle($mh, $ch);
    //         curl_close($ch);
    //     }

    //     curl_multi_close($mh);
    //     return $imageDataList;
    // }

    private function convertThumbnailToWebp(?string $Url): ?string
    {

        if (!filter_var($Url, FILTER_VALIDATE_URL)) {
            return $Url; // Nếu URL không hợp lệ, giữ nguyên ảnh gốc
        }

        $url = $this->normalizeImageUrl($Url);
        if (!$url) {
            return $url;
        }
        // Tải ảnh thumbnail bằng cURL
        $imageContent = $this->fetchSingleImage($url);
        if (!$imageContent) {
            return $url;
        }
        // Chuyển đổi ảnh sang WebP
        $webpData = $this->downloadAndConvertToWebp($url, $imageContent);
        if (!$webpData) {
            return $url;
        }
        return asset('storage/' . $webpData[0]); // Nếu lỗi, trả về ảnh gốc
    }

    // protected function clearPostCacheAfterCreate(Post $post): void
    // {
    //     Cache::tags([
    //         "domain:{$post->domain_id}",
    //         "posts",
    //         "category:{$post->category_id}",
    //     ])->flush();
    // }


    public function getPostById($id)
    {
        return $this->postRepository->getPostById($id);
    }

    public function update($id, $request, $dataPost)
    {
        // $oldSlug      = $dataPost->slug;
        // $oldCategory  = $dataPost->category_id;
        // CONTENT
        $updatedContent = $request->editor_content !== $dataPost->content
            ? $this->convertImagesToWebp($request->editor_content)
            : $dataPost->content;

        // THUMBNAIL
        $imageThumbnail = $request->thumbnail !== $dataPost->thumbnail
            ? $this->convertThumbnailToWebp($request->image)
            : $dataPost->thumbnail;
        $slug = $dataPost->slug;
        if ($request->title !== $dataPost->title) {
            $slug = $this->generateUniqueSlug(
                $request->slug,
                $dataPost->domain_id,
                $dataPost->id
            );
        }
        // Cập nhật thông tin bài viết nếu có thay đổi
        $params = [
            'title' => $request->title,
            'content' => $updatedContent,
            'slug' => $slug,
            'thumbnail' => $imageThumbnail,
            'category_id' => $request->category,
        ];
        // Cập nhật dữ liệu vào database
        DB::transaction(function () use ($dataPost, $params, $request) {
            $dataPost->update($params);
            // if ($request->has('tagIds') && !is_null($request->tagIds) && trim($request->tagIds) !== '') {
            //     $tagIds = array_map('trim', explode(',', $request->tagIds));
            //     $dataPost->tags()->sync($tagIds);
            // } else {
            //     $dataPost->tags()->detach();
            // }
            if ($request->filled('tagIds')) {
                $tagIds = array_map('trim', explode(',', $request->tagIds));
                $dataPost->tags()->sync($tagIds);
            } else {
                $dataPost->tags()->detach();
            }
        });

        // 🔥 CLEAR CACHE SAU UPDATE
        // $this->clearPostCacheAfterUpdate(
        //     $dataPost,
        //     $oldSlug,
        //     $oldCategory
        // );
        return $dataPost;
    }

    // protected function clearPostCacheAfterUpdate(
    //     Post $post,
    //     string $oldSlug,
    //     string $oldCategoryId
    // ): void {
    //     Cache::tags([
    //         "domain:{$post->domain_id}",
    //         "posts",
    //         "post:{$oldSlug}",        // ❗ slug cũ
    //         "post:{$post->slug}",     // slug mới
    //         "category:{$oldCategoryId}",
    //         "category:{$post->category_id}",
    //     ])->flush();
    // }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public function delete($request)
    {
        $ids = is_array($request->ids) ? $request->ids : [$request->ids];

        DB::beginTransaction();

        try {
            // Lấy danh sách bài viết cần xóa
            $posts = $this->postRepository->getDataListIds($ids);

            foreach ($posts as $post) {

                // ❌ Không có quyền → ném exception
                if (!Gate::allows('delete', $post)) {
                    throw new \Exception('NO_PERMISSION');
                }
                $this->deletePostWithRelations($post);
            }
            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            // Có thể log lỗi ở đây nếu cần: Log::error($e);
            return false;
        }
    }

    /**
     * Xử lý xóa post kèm các mối liên hệ
     */
    protected function deletePostWithRelations($post)
    {
        // Xóa post_tag liên quan
        $postTags = $this->postTagRepository->getListByPostId($post->id);

        foreach ($postTags as $tag) {
            $this->postTagRepository->delete($tag);
        }
        // 🔥 CLEAR CACHE TRƯỚC KHI DELETE
        // $this->clearPostCacheAfterDelete($post);

        // Xóa post
        $post->delete(); // Hoặc forceDelete() nếu dùng soft delete
    }

    // protected function clearPostCacheAfterDelete(Post $post): void
    // {
    //     Cache::tags([
    //         "domain:{$post->domain_id}",
    //         "posts",
    //         "post:{$post->slug}",
    //         "category:{$post->category_id}",
    //     ])->flush();
    // }


    private function generateUniqueSlug($title, $domainId, $postId = null)
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $i = 1;

        while (
            Post::where('slug', $slug)
                ->where('domain_id', $domainId)
                ->when($postId, fn ($q) => $q->where('id', '!=', $postId))
                ->exists()
        ) {
            $slug = $originalSlug . '-' . $i++;
        }

        return $slug;
    }


}
