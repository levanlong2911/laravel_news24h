<?php

namespace App\Video\FinalComposition;

/**
 * Sinh filter graph va mang doi so cho mot ban ke hoach.
 *
 * HAI viec nay nam chung mot lop co chu dich: graph danh nhan `[0:v]`, `[1:v]`… con
 * mang doi so quyet dinh thu tu `-i`. Tach doi la tao ra mot bat bien chay ngang hai
 * file ma khong ai giu. O day no la bat bien noi bo.
 *
 * Clip khong co tieng KHONG them mot `-i` nao — im lang sinh bang `anullsrc` ngay
 * trong graph — nen chi so `-i` luon bang chi so clip.
 */
final class CompositionCommand
{
    public function __construct(private readonly CompositionPlan $plan) {}

    /** @return list<string> */
    public function arguments(string $graphPath, string $outputPath): array
    {
        $args = ['-hide_banner', '-nostdin', '-n'];

        foreach ($this->plan->clips as $clip) {
            $args[] = '-i';
            $args[] = $clip->path;
        }

        $profile = $this->plan->profile;

        return [
            ...$args,
            '-filter_complex_script', $graphPath,
            '-map', '[vout]', '-map', '[aout]',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', (string) $profile->crf,
            '-c:a', 'aac', '-ar', (string) $profile->sampleRate, '-ac', (string) $profile->channels,
            '-movflags', '+faststart',
            '-f', 'mp4', $outputPath,
        ];
    }

    public function graph(): string
    {
        $parts = [];

        foreach ($this->plan->clips as $i => $clip) {
            $parts[] = $this->videoChain($i, $clip);
            $parts[] = $this->audioChain($i, $clip);
        }

        return implode(";\n", [...$parts, ...$this->join()])."\n";
    }

    /**
     * `setpts` DUNG TRUOC `trim`.
     *
     * `trim` cat theo timestamp cua luong. Nguon co `start_time = 1.5` ma cat
     * `start=0:duration=8` thi cua so roi vao cho khac han y nguoi dung. Rebase ve 0
     * truoc, roi `trim_start_ms` moi that su la "tinh tu dau noi dung".
     *
     * Va cat theo SO KHUNG o cuoi, sau `fps=` — dao lai thi `end_frame` dang dem
     * khung NGUON, va mot nguon 30fps dua ve 24fps se ra so khung khac.
     */
    private function videoChain(int $i, CompositionClip $clip): string
    {
        $p = $this->plan->profile;

        // MOT lan scale, thang tu nguon toi kich thuoc noi dung cuoi cung.
        //
        // Khong dung `force_original_aspect_ratio`: no giu ti le LUU TRU, khong biet
        // gi ve SAR. Do that tren nguon 320x240 SAR 2:1 (hien thi 8:3): no vua khit
        // 320x240 va noi dung bi bop ngang hai lan, trong khi output van bao SAR 1:1
        // — hong ma khong co gi to cao.
        //
        // Va khong vuong hoa pixel o mot khung trung gian roi scale lan nua: hai lan
        // noi suy, va khung giua co the lon hon han dau ra.
        return sprintf(
            '[%d:v]setpts=PTS-STARTPTS,trim=start=%s:duration=%s,setpts=PTS-STARTPTS,'
            .'scale=%d:%d,setsar=1,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,'
            .'fps=%d,format=yuv420p,settb=AVTB,'
            .'trim=end_frame=%d,setpts=PTS-STARTPTS[v%d]',
            $i,
            $p->milliseconds($clip->trimStartMs),
            $p->milliseconds($clip->durationMs),
            $clip->contentWidth, $clip->contentHeight,
            $p->width, $p->height,
            $p->fps, $clip->frames, $i,
        );
    }

    /**
     * Do lech tieng duoc GIU, va no tuong tac voi cua so cat.
     *
     *   delta = tieng bat dau sau hinh bao nhieu
     *   cat tu nguon  = max(trimStart - delta, 0)
     *   cho lai o dau = max(delta - trimStart, 0)
     *
     * Tieng den muon 0,5s ma cat tu 0  -> giu 0,5s im lang dau.
     * Cung nguon ay ma cat tu giay 1   -> 0,5s da nam trong phan bo di, KHONG chen lai.
     * Tieng den som                    -> cat bo phan nam ngoai cua so.
     *
     * `apad` roi `atrim` o cuoi ep dung do dai timeline, de mot nguon ngan hon khong
     * keo lech moi noi phia sau.
     */
    private function audioChain(int $i, CompositionClip $clip): string
    {
        $p = $this->plan->profile;
        $length = $p->seconds($clip->frames);

        if (! $clip->hasAudio) {
            return sprintf(
                'anullsrc=r=%d:cl=stereo,atrim=duration=%s,asetpts=PTS-STARTPTS[a%d]',
                $p->sampleRate, $length, $i,
            );
        }

        $sourceStartMs = max($clip->trimStartMs - $clip->audioOffsetMs, 0);
        $delayMs = max($clip->audioOffsetMs - $clip->trimStartMs, 0);

        $chain = sprintf(
            '[%d:a]asetpts=PTS-STARTPTS,atrim=start=%s,asetpts=PTS-STARTPTS,'
            .'aresample=%d,aformat=sample_fmts=fltp:channel_layouts=stereo',
            $i, $p->milliseconds($sourceStartMs), $p->sampleRate,
        );

        if ($delayMs > 0) {
            // Sau `aresample` chu khong truoc: `adelay` tinh bang mau, nen dat truoc
            // la do tre duoc tinh o tan so nguon.
            $chain .= sprintf(',adelay=%d:all=1', $delayMs);
        }

        return $chain.sprintf(',apad,atrim=duration=%s,asetpts=PTS-STARTPTS[a%d]', $length, $i);
    }

    /**
     * Gap trai: ghep hai mot, khong ghep mot lan tat ca.
     *
     * `concat` gop duoc N nhanh mot luot nhung `xfade` thi khong — nen mot ban ke
     * hoach LAN ca hai loai chuyen canh chi dung duoc neu moi noi la mot phep rieng.
     *
     * @return list<string>
     */
    private function join(): array
    {
        $last = count($this->plan->clips) - 1;

        if ($last === 0) {
            return ['[v0]null[vout]', '[a0]anull[aout]'];
        }

        $p = $this->plan->profile;
        $parts = [];
        $video = '[v0]';
        $audio = '[a0]';

        foreach ($this->plan->transitions as $j => $transition) {
            $next = $j + 1;
            $vOut = $next === $last ? '[vout]' : '[vx'.$next.']';
            $aOut = $next === $last ? '[aout]' : '[ax'.$next.']';

            if ($transition->isCut()) {
                $parts[] = $video.'[v'.$next.']concat=n=2:v=1:a=0'.$vOut;
                $parts[] = $audio.'[a'.$next.']concat=n=2:v=0:a=1'.$aOut;
            } else {
                // `offset` do bang khung DA TICH LUY tru chong lan cua chinh moi noi
                // nay — khong phai tong do dai cac clip truoc do, vi cac moi noi
                // truoc da an bot khung roi.
                $parts[] = sprintf(
                    '%s[v%d]xfade=transition=fade:duration=%s:offset=%s%s',
                    $video, $next,
                    $p->seconds($transition->frames),
                    $p->seconds($this->plan->framesThrough($j) - $transition->frames),
                    $vOut,
                );
                $parts[] = sprintf(
                    '%s[a%d]acrossfade=d=%s:c1=tri:c2=tri%s',
                    $audio, $next, $p->seconds($transition->frames), $aOut,
                );
            }

            $video = $vOut;
            $audio = $aOut;
        }

        return $parts;
    }
}
