#!/usr/bin/env bash
# Dung lai toan bo fixture cua Final Composition.
#
# Fixture la BANG CHUNG, nen phai tai tao duoc. Mot file .mp4 nam tran trong repo
# khong noi duoc no dang khang dinh dieu gi, cung khong noi duoc ai da doi no.
#
#   FFMPEG=/duong/dan/ffmpeg ./build-fc.sh
#
# Chay xong phai chay `php artisan test tests/Video/Media/FixtureCharacterTest.php`
# — script nay TAO ra file, con test kia moi xac nhan chung mang dung tinh chat.

set -euo pipefail

FFMPEG="${FFMPEG:-ffmpeg}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TMP_BROKEN="$DIR/.tmp_broken.mp4"
TMP_TRUNCATED="$DIR/.tmp_truncated.mp4"

# Chi xoa dung hai file script nay tao ra, khong quet wildcard.
trap 'rm -f "$TMP_BROKEN" "$TMP_TRUNCATED"' EXIT

V=(-c:v libx264 -pix_fmt yuv420p -preset ultrafast)
A=(-c:a aac -ar 48000 -ac 2)

# Cap nghiem thu: 192 + 192 - 12 = 372 khung o 24fps.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=8" \
    -f lavfi -i "sine=frequency=440:duration=8" "${V[@]}" "${A[@]}" "$DIR/fc_a.mp4"
"$FFMPEG" -v error -y -f lavfi -i "testsrc2=size=320x240:rate=24:duration=8" \
    -f lavfi -i "sine=frequency=660:duration=8" "${V[@]}" "${A[@]}" "$DIR/fc_b.mp4"

# Nguon khac FPS dau ra.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=30:duration=4" \
    -f lavfi -i "sine=frequency=440:duration=4" "${V[@]}" "${A[@]}" "$DIR/fc_30fps.mp4"

# VFR THAT: giay dau 30fps, phan sau 15fps, giu nguyen timestamp goc.
# `-fps_mode passthrough` la cho quyet dinh — thieu no thi ffmpeg tai dinh thoi
# thanh mot nhip deu, va file ra la CFR doi lot.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=30:duration=5" \
    -vf "select='if(lt(n,30),1,not(mod(n,2)))'" -fps_mode passthrough \
    "${V[@]}" "$DIR/fc_vfr.mp4"

"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    -vf "setsar=2/1" "${V[@]}" "$DIR/fc_sar21.mp4"

"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    -f lavfi -i "sine=frequency=440:duration=6" "${V[@]}" "${A[@]}" "$DIR/fc_audio_longer.mp4"
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=6" \
    -f lavfi -i "sine=frequency=440:duration=2" "${V[@]}" "${A[@]}" "$DIR/fc_audio_shorter.mp4"
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    "${V[@]}" "$DIR/fc_no_audio.mp4"

# Hai luong bat dau o hai thoi diem khac nhau. `-itsoffset` la tham so DAU VAO nen
# phai dung truoc `-i` cua chinh luong do.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    -itsoffset 0.5 -f lavfi -i "sine=frequency=440:duration=4" \
    -map 0:v -map 1:a "${V[@]}" "${A[@]}" \
    -output_ts_offset 1.5 -muxpreload 0 -muxdelay 0 "$DIR/fc_offset.mp4"

# Hong han: moov nam cuoi, cat cut thi khong con doc duoc gi.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    "${V[@]}" "$TMP_BROKEN"
head -c 30000 "$TMP_BROKEN" > "$DIR/fc_broken.mp4"

# Hong MOT PHAN: `+faststart` dua moov len dau, nen header van doc duoc con du lieu
# khung thi cut. Day la file khien ffprobe thoat 0 MA VAN in loi giai ma.
"$FFMPEG" -v error -y -f lavfi -i "testsrc=size=320x240:rate=24:duration=4" \
    "${V[@]}" -movflags +faststart "$TMP_TRUNCATED"
head -c 38686 "$TMP_TRUNCATED" > "$DIR/fc_truncated.mp4"

ls -la "$DIR"/fc_*.mp4
