<?php

namespace App\Video\Media;

/**
 * Doc thong so cua mot file media.
 *
 * Ton tai de test tiem duoc mot ban gia ma KHONG phai bo `final` cua lop that. Mot
 * lop bi bo `final` chi vi nhu cau test la mo rong be mat production cho mot ly do
 * khong thuoc production: cai can la MOI NOI, khong phai thao niem phong.
 *
 * Hop dong o day chi la MO TA FILE. Moi chinh sach — bao nhieu luong thi chap nhan
 * duoc, truong nao bat buoc — thuoc ve noi quyet dinh ghep, khong thuoc ve day.
 */
interface MediaProbe
{
    /**
     * @return array{
     *     ok: bool,
     *     duration_ms: ?int,
     *     width: ?int,
     *     height: ?int,
     *     error: ?string,
     *     video: ?array<string, mixed>,
     *     audio: ?array<string, mixed>,
     *     video_count: int,
     *     audio_count: int,
     *     other_count: int,
     *     streams: list<array{index: int, codec_type: string}>
     * }
     */
    public function inspect(string $file, ?int $timeoutSeconds = null): array;
}
