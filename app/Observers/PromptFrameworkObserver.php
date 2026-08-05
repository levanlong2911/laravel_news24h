<?php

namespace App\Observers;

use App\Models\PromptFramework;
use App\Models\PromptVersion;

class PromptFrameworkObserver
{
    /** Field prompt được chuẩn hoá dấu xuống dòng trước khi ghi. */
    private const TEXT_FIELDS = ['system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate'];

    /**
     * Quy dấu xuống dòng về LF trước khi ghi — cả tạo mới lẫn cập nhật.
     *
     * Theo đặc tả HTML, <textarea> luôn gửi lên CRLF. Không chặn ở đây thì mỗi
     * lần admin sửa một framework qua form là framework đó chuyển sang CRLF, và
     * từ đó mọi migration khớp chuỗi nhiều dòng đều trượt nó trong im lặng —
     * str_replace tìm "\n...", dữ liệu là "\r\n...", không khớp, migration vẫn
     * báo thành công. Đúng chuyện này đã xảy ra với travel_mobility; xem
     * 2026_07_30_000000_normalize_prompt_line_endings.
     *
     * Dùng saving chứ không phải updating: nó bao cả bản ghi mới, và nó chạy
     * TRƯỚC updating() bên dưới. Nhờ vậy nếu admin lưu mà chỉ có dấu xuống dòng
     * đổi thì isDirty() thành false — không đẻ version rác, không backup thừa.
     */
    public function saving(PromptFramework $framework): void
    {
        foreach (self::TEXT_FIELDS as $field) {
            $value = $framework->getAttribute($field);

            if (!is_string($value)) {
                continue;
            }

            // "\r\n" trước rồi "\r" lẻ — đảo thứ tự sẽ biến CRLF thành hai LF.
            $framework->setAttribute($field, str_replace(["\r\n", "\r"], "\n", $value));
        }
    }

    /**
     * Auto backup trước khi update — không bao giờ mất bản cũ.
     */
    public function updating(PromptFramework $framework): void
    {
        // Guard: chỉ backup khi thay đổi thực sự ở prompt fields
        if (!$framework->isDirty(['system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate'])) {
            return;
        }

        PromptVersion::create([
            'framework_id' => $framework->id,
            'snapshot'     => $framework->getOriginal(), // lưu bản CŨ trước khi ghi đè
            'change_note'  => 'auto-backup v' . $framework->version,
        ]);

        // Tăng version number
        $framework->version = $framework->version + 1;
    }
}
