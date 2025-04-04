<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\PostRepositoryInterface;
use App\Repositories\Interfaces\PostTagRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use DOMDocument;
use Illuminate\Support\Facades\Auth;
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

    public function create($request): Model
    {
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
            'slug' => Str::slug($request->title),
            'thumbnail' => $webpThumbnail,
            'category_id' => $request->category,
            'author_id' => Auth::id(),
        ];
        // Create a new admin using the repository
        $post = $this->postRepository->create($params);
         // Xử lý tags nếu có
         if ($request->has('tag') && !empty($request->tag)) {
            // Chuyển chuỗi tag thành mảng UUID
            $tagIds = array_map('trim', explode(',', $request->tag));

            // Gắn tags vào bài viết qua bảng pivot `post_tags`
            $post->tags()->attach($tagIds);
        }
        return $post;
    }

    private function convertImagesToWebp($content)
    {
        // Tạo DOM từ nội dung HTML
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        // Lấy tất cả thẻ <img>
        $images = $dom->getElementsByTagName('img');
        // Mảng lưu trữ ảnh cần tải
        $imageUrls = [];


        foreach ($images as $img) {
            $src = str_replace(' ', '%20', $img->getAttribute('src')); //Mã hóa khoảng trắng

            if (!filter_var($src, FILTER_VALIDATE_URL)) {
                continue; // Bỏ qua nếu không hợp lệ
            }

            $parsedUrl = parse_url($src);
            if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
                continue; // Kiểm tra domain hợp lệ
            }

            $imageUrls[] = $src;
        }
        // Tải tất cả ảnh một lần bằng multi cURL
        $imageDataList = $this->fetchImage($imageUrls);

        foreach ($images as $img) {
            $src = str_replace(' ', '%20', $img->getAttribute('src'));
            if (!isset($imageDataList[$src])) {
                continue;
            }

            $webpData = $this->downloadAndConvertToWebp($src, $imageDataList[$src]);
            if ($webpData) {
                list($webpPath, $newHeight) = $webpData;
                $img->setAttribute('src', asset("storage/$webpPath"));
                $img->setAttribute('width', '800');
                if ($newHeight) {
                    $img->setAttribute('height', $newHeight);
                }
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $contentWithoutHtmlBody = '';
        foreach ($body->childNodes as $node) {
            $contentWithoutHtmlBody .= $dom->saveHTML($node);
        }

        return $contentWithoutHtmlBody;
    }

    private function downloadAndConvertToWebp($imageUrl, $imageContent)
    {
        try {
            // Lấy thông tin ảnh gốc
            $originalSize = getimagesizefromstring($imageContent);
            if (!$originalSize) {
                return null;
            }

            list($originalWidth, $originalHeight, $imageType) = $originalSize;

            // Lấy phần mở rộng hợp lệ từ loại ảnh
            $validExtensions = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_AVIF => 'avif', // Hỗ trợ AVIF
            ];
            if (!isset($validExtensions[$imageType])) {
                return null;
            }

            // Lấy tên file gốc và tạo tên WebP
            $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
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

            // Xóa ảnh gốc sau khi xử lý
            $originalPath = 'public/images/' . $originalName . '.' . $validExtensions[$imageType];
            if (Storage::exists($originalPath)) {
                Storage::delete($originalPath);
            }

            // Trả về đường dẫn public
            return [str_replace('public/', '', $filePath), $newHeight];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Tải ảnh từ URL bằng cURL để tránh lỗi `file_get_contents()`
     */
    private function fetchImage($urls)
    {
        $mh = curl_multi_init();
        $chArray = [];
        $imageDataList = [];

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $ch);
            $chArray[$url] = $ch;
        }

        do {
            curl_multi_exec($mh, $running);
        } while ($running);

        foreach ($chArray as $url => $ch) {
            $imageDataList[$url] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $imageDataList;
    }

    private function convertThumbnailToWebp($thumbnailUrl)
    {
        if (!filter_var($thumbnailUrl, FILTER_VALIDATE_URL)) {
            return $thumbnailUrl; // Nếu URL không hợp lệ, giữ nguyên ảnh gốc
        }

        // Tải ảnh thumbnail bằng cURL
        $imageContent = $this->fetchImage([$thumbnailUrl])[$thumbnailUrl] ?? null;
        if (!$imageContent) {
            return $thumbnailUrl;
        }

        // Chuyển đổi ảnh sang WebP
        $webpData = $this->downloadAndConvertToWebp($thumbnailUrl, $imageContent);
        if ($webpData) {
            list($webpPath, $newHeight) = $webpData;
            return asset("storage/$webpPath"); // Trả về URL WebP
        }

        return $thumbnailUrl; // Nếu lỗi, trả về ảnh gốc
    }

    public function getPostById($id)
    {
        return $this->postRepository->getPostById($id);
    }

    public function update($id, $request)
    {
        $dataPost = $this->getPostById($id);
        // 1. Kiểm tra hình ảnh có thay đổi không
        if ($request->editor_content !== $dataPost->content) {
            $updatedContent = $this->convertImagesToWebp($request->editor_content);
        } else {
            $updatedContent = $dataPost->content; // Giữ nguyên nếu không thay đổi
        }

        if ($request->editor_content !== $dataPost->content) {
            $imageThumbnail = $this->convertThumbnailToWebp($request->image);
        } else {
            $imageThumbnail = $dataPost->thumbnail; // Giữ nguyên nếu không thay đổi
        }

        // Cập nhật thông tin bài viết nếu có thay đổi
        $params = [
            'title' => $request->title,
            'content' => $updatedContent,
            'slug' => Str::slug($request->title),
            'thumbnail' => $imageThumbnail,
            'category_id' => $request->category,
        ];
        // Cập nhật dữ liệu vào database
        DB::transaction(function () use ($dataPost, $params, $request) {
            $dataPost->update($params);
            if ($request->has('tag') && !is_null($request->tag) && trim($request->tag) !== '') {
                $tagIds = array_map('trim', explode(',', $request->tag));
                $dataPost->tags()->sync($tagIds);
            } else {
                $dataPost->tags()->detach();
            }
        });
        return $dataPost;
    }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public function delete($request)
    {
        DB::beginTransaction();
        try {
            $dataPost = $this->postRepository->getDataListIds($request->ids);
            foreach ($dataPost as $post) {
                // Delete post_tag
                $posttags = $this->postTagRepository->getListByPostId($post->id);
                // dd($posttags->toArray());
                if ($posttags) {
                    foreach ($posttags as $posttag) {
                        $this->postTagRepository->delete($posttag);
                    }
                }
                $post->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

}
