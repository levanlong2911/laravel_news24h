<?php

namespace App\Video\FinalComposition;

/**
 * Sao nguon vao thu muc luot chay, roi MOI THU doc tu ban sao do.
 *
 * Probe mot ban roi encode mot ban khac la mot khe co that: nguon co the doi giua
 * chung. Bam truoc/sau khong bit duoc khe do — file co the doi A -> B -> A. Va so
 * khung cung khong: `E` tinh tu metadata, nen mot file khac noi dung ma cung thoi
 * luong va cung fps cho dung `E` ay, va phep so di qua em.
 */
final class CompositionInputs
{
    private const CHUNK = 1 << 20;

    public function __construct(
        private readonly string $inputRoot,
        private readonly int $maxTotalBytes,
    ) {}

    /**
     * @param  list<string>  $sources
     * @return list<string> duong dan cac ban sao
     *
     * @throws CompositionRefused
     */
    public function materialise(array $sources, CompositionWorkspace $workspace, Deadline $deadline): array
    {
        $root = realpath($this->inputRoot);

        if ($root === false) {
            throw new CompositionRefused('thu muc nguon khong ton tai: '.$this->inputRoot);
        }

        $root = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        $total = 0;
        $copies = [];

        foreach (array_values($sources) as $i => $source) {
            $real = realpath($source);

            // So tien to KEM DAU PHAN CACH: `D:\media` khong duoc nhan `D:\media-other`.
            if ($real === false || ! is_file($real) || ! str_starts_with($real, $root)) {
                throw new CompositionRefused('nguon nam ngoai thu muc cho phep: '.$source);
            }

            $target = $workspace->path(sprintf('input-%03d.mp4', $i));
            $total = $this->copy($real, $target, $total, $deadline);
            $copies[] = $target;
        }

        return $copies;
    }

    /**
     * @return int tong byte da chep sau file nay
     *
     * @throws CompositionRefused
     */
    private function copy(string $source, string $target, int $total, Deadline $deadline): int
    {
        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new CompositionRefused('khong mo duoc nguon: '.$source);
        }

        // Mo theo bac thang: neu handle thu hai hong thi handle thu nhat da mo phai
        // duoc dong, khong de ro.
        $out = @fopen($target, 'wb');

        if ($out === false) {
            fclose($in);

            throw new CompositionRefused('khong mo duoc dich: '.$target);
        }

        try {
            while (! feof($in)) {
                if ($deadline->expired()) {
                    throw new CompositionRefused('het ngan sach thoi gian khi dang sao chep');
                }

                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new CompositionRefused('doc nguon that bai giua chung: '.$source);
                }

                if ($chunk === '') {
                    continue;
                }

                // Dem byte DA CHEP, va kiem TRUOC khi ghi: `filesize()` truoc khi chep
                // chi la mot loi chuc — nguon co the lon len giua chung.
                $total += strlen($chunk);

                if ($total > $this->maxTotalBytes) {
                    throw new CompositionRefused(sprintf(
                        'tong dung luong nguon vuot han muc %d byte', $this->maxTotalBytes,
                    ));
                }

                $this->writeAll($out, $chunk, $target);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $total;
    }

    /**
     * `fwrite()` co the ghi IT HON do dai chuoi ma khong bao loi. Bo qua phan con
     * lai la sao ra mot ban thieu, roi bam va encode ban thieu do.
     *
     * @param  resource  $handle
     *
     * @throws CompositionRefused
     */
    private function writeAll($handle, string $data, string $target): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($handle, substr($data, $written));

            if ($result === false || $result === 0) {
                throw new CompositionRefused('ghi ban sao that bai: '.$target);
            }

            $written += $result;
        }
    }
}
