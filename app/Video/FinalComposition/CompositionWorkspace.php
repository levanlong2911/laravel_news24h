<?php

namespace App\Video\FinalComposition;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thu muc rieng cua MOT luot chay.
 *
 * Dich nam luon trong day (`final.mp4`), khong phai mot duong dan dung chung: hai
 * luot khong the chon trung dich, nen khong co tranh chap, khong can dat cho truoc
 * bang mot file rong, va khong co khe nao giua "kiem dich trong" voi "ghi ra dich".
 *
 * Giu DANH SACH file minh tao ra. Don bang cach quet thu muc la don ca thu nguoi
 * khac vo tinh de vao do.
 */
final class CompositionWorkspace
{
    /** @var list<string> */
    private array $owned = [];

    private function __construct(public readonly string $directory) {}

    /** @throws CompositionRefused */
    public static function create(string $root): self
    {
        $directory = rtrim($root, '/\\').DIRECTORY_SEPARATOR.Str::uuid()->toString();

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new CompositionRefused('khong tao duoc thu muc luot chay: '.$directory);
        }

        return new self($directory);
    }

    public function path(string $name): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        $this->owned[] = $path;

        return $path;
    }

    public function output(): string
    {
        return $this->path('final.mp4');
    }

    /**
     * Xoa moi thu luot nay tao ra, tru cac file duoc giu lai.
     *
     * @param  list<string>  $keep
     */
    public function discard(array $keep = []): void
    {
        $stuck = [];

        foreach (array_unique($this->owned) as $path) {
            if (in_array($path, $keep, true) || ! is_file($path)) {
                continue;
            }

            if (! @unlink($path)) {
                $stuck[] = $path;
            }
        }

        if ($keep === [] && $stuck === [] && ! @rmdir($this->directory)) {
            $stuck[] = $this->directory;
        }

        // Don that bai KHONG duoc thay the ket qua chinh — nhung cung khong duoc im.
        // File input va PCM co the hang tram MB; ton dong ma khong dau vet la mot o
        // dia day len ma khong ai truy ra tu dau.
        if ($stuck === []) {
            return;
        }

        // Ban than duong BAO LOI cung phai khong nem ra ngoai. `discard()` duoc goi
        // trong `finally`, nen mot exception tu logger — o dia day, quyen ghi sai —
        // se che mat ket qua that hoac loi that cua luot chay.
        try {
            Log::warning('CompositionWorkspace: khong don duoc file cua luot chay', [
                'directory' => $this->directory,
                'stuck' => $stuck,
            ]);
        } catch (Throwable) {
            // Khong con cho nao de bao. Nuot o day la lua chon it te nhat.
        }
    }
}
