<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class ImageService
{
    /**
     * Tải ảnh từ URL, resize nếu vượt maxWidth, lưu WebP vào storage/public/images/.
     * Trả về public URL hoặc null nếu thất bại.
     */
    public function downloadToWebp(string $url, int $maxWidth = 1200, int $quality = 82): ?string
    {
        $imageContent = $this->fetchImage($url);
        if (!$imageContent) return null;

        try {
            $manager = new ImageManager(new ImagickDriver());
            $image   = $manager->read($imageContent);
            unset($imageContent);

            if ($image->width() > $maxWidth) {
                $image->scale(width: $maxWidth);
            }

            $originalName = Str::slug(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)) ?: md5($url);
            $fileName     = now()->format('Ymd_His') . '_' . uniqid() . '_' . $originalName . '.webp';
            $filePath     = 'images/' . $fileName;

            $encoded = $image->toWebp(quality: $quality);
            unset($image);

            Storage::disk('public')->put($filePath, $encoded->toString());

            return asset('storage/' . $filePath);
        } catch (\Throwable $e) {
            Log::warning('[ImageService] convert failed: ' . $e->getMessage());
            return null;
        }
    }

    public function fetchImage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $data     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($httpCode === 200 && $data !== false && strlen($data) > 2000) ? $data : null;
    }
}
