<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\PostRepositoryInterface;
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

    public function __construct(
        PostRepositoryInterface $postRepository
    ) {
        $this->postRepository = $postRepository;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListPost()
    {
        return $this->postRepository->paginate(Paginate::PAGE->value);
    }

    public function getInfoPost($id)
    {
        return $this->postRepository->find($id);
    }

    public function create($request): Model
    {
        // Chuyển đổi ảnh sang WebP và cập nhật editor_content
        $updatedContent = $this->convertImagesToWebp($request->editor_content);
        $params = [
            'title' => $request->title,
            'content' => $updatedContent,
            'slug' => Str::slug($request->title),
            'thumbnail' => $request->image,
            'category_id' => $request->category,
            'author_id' => Auth::id(),
            // 'thumbnail' => $passwordHash,
        ];

        // Create a new admin using the repository
        $post = $this->postRepository->create($params);
        // dd(3355);
         // Xử lý tags nếu có
         if ($request->has('tag') && !empty($request->tag)) {
            // Chuyển chuỗi tag thành mảng UUID
            $tagIds = array_map('trim', explode(',', $request->tag));

            // Gắn tags vào bài viết qua bảng pivot `post_tags`
            $post->tags()->sync($tagIds);
        }
        dd($post);

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


        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $src = str_replace(' ', '%20', $src); //Mã hóa khoảng trắng

            if (!filter_var($src, FILTER_VALIDATE_URL)) {
                continue; // Bỏ qua nếu không hợp lệ
            }

            $parsedUrl = parse_url($src);
            if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
                continue; // Kiểm tra domain hợp lệ
            }

            if (preg_match('/[^A-Za-z0-9\-_~:\/?#\[\]@!$&\'()*+,;=.%]/', $src)) {
                continue; // Kiểm tra ký tự đặc biệt không hợp lệ
            }

            // Tải xuống và chuyển đổi ảnh sang WebP
            $webpData = $this->downloadAndConvertToWebp($src);
            if ($webpData) {
                list($webpPath, $newHeight) = $webpData;

                // Cập nhật đường dẫn ảnh trong DOM
                $img->setAttribute('src', asset('storage/' . $webpPath));
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

    private function downloadAndConvertToWebp($imageUrl)
    {
        try {
            // Tải ảnh từ URL
            $imageContent = $this->fetchImage($imageUrl);
            if (!$imageContent) {
                return null;
            }
            // Lấy thông tin ảnh gốc
            $originalSize = getimagesizefromstring($imageContent);
            if (!$originalSize) {
                return null;
            }
            // dd(list($originalWidth, $originalHeight) = $originalSize);
            list($originalWidth, $originalHeight) = $originalSize;


            // Lấy tên file gốc và tạo tên WebP
            $originalName = pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_FILENAME);
            $timestamp = now()->format('Ymd_His');
            $fileName = $timestamp . '_' . Str::slug($originalName) . '.webp';
            $filePath = 'public/images/' . $fileName;

            // Nếu ảnh đã tồn tại, không cần xử lý lại
            if (Storage::exists($filePath)) {
                return [str_replace('public/', '', $filePath), null];
            }

            // 🔹 Lưu ảnh gốc vào file tạm (để xử lý bằng Intervention)
            $tempFile = storage_path('app/temp_' . $originalName);
            file_put_contents($tempFile, $imageContent);

            // Tạo ImageManager với Imagick
            $manager = new ImageManager(new ImagickDriver());

            // Tính toán chiều cao theo tỷ lệ width = 800
            $newHeight = (int) (($originalHeight * 800) / $originalWidth);

            // Chuyển đổi ảnh sang WebP
            $image = $manager->read($imageContent);
            $image = $image->scale(width: 800, height: $newHeight)->toWebp(quality: 80);

            // Lưu ảnh vào storage
            Storage::put($filePath, $image->toString());

            // 🗑 Xóa ảnh gốc sau khi xử lý
            unlink($tempFile);

            // Trả về đường dẫn public
            return [str_replace('public/', '', $filePath), $newHeight];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Tải ảnh từ URL bằng cURL để tránh lỗi `file_get_contents()`
     */
    private function fetchImage($url)
    {

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $data = curl_exec($ch);
        curl_close($ch);
        // dd($data);

        return $data ?: null;
    }

    /**
     * Xóa một danh mục
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    // public function delete($request)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $dataCate = $this->categoryRepository->getDataListIds($request->ids);
    //         foreach ($dataCate as $cate) {
    //             // Delete tag
    //             $tags = $this->tagRepositoryInterface->findBy(['category_id' => $cate->id]);
    //             if ($tags) {
    //                 foreach ($tags as $tag) {
    //                     $this->tagService->deletetagByIds(['ids' => $tag['id']]);
    //                 }
    //             }
    //             $cate->delete();
    //         }
    //         DB::commit();
    //         return true;
    //     } catch (Exception $e) {
    //         DB::rollback();
    //         return false;
    //     }
    // }

}
