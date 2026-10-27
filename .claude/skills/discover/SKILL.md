---
name: discover
description: Bước 1 — Discovery. Khảo sát codebase cho một task mới và ghi 01-discovery.md. Chỉ chạy khi người dùng gọi /discover.
argument-hint: "<mô tả task>"
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Write, Bash(git log:*), Bash(git status:*), Bash(git diff:*), Bash(ls:*), Bash(php artisan route:list:*), Bash(php artisan about:*)
---

# Bước 1 — Discovery (Claude)

Task: **$ARGUMENTS**

Bạn là người khảo sát. Mục tiêu là HIỂU, không thiết kế, không sửa code.

## Làm
1. Đặt `<slug>` kebab-case ngắn cho task (3–5 từ tiếng Anh). Nếu `docs/work/<slug>/` đã tồn tại, hỏi người dùng có ghi đè không.
2. Đọc `AGENTS.md`, `docs/architecture.md`, các ADR trong `docs/adr/` có liên quan.
3. Tìm trong code:
   - Điểm vào liên quan: `php artisan route:list --path=<tiền tố>`, controller, Livewire/Inertia page, job, command, listener.
   - Model và quan hệ liên quan: đọc `app/Models/*.php` và `database/migrations/` (bảng, cột, quan hệ, cast). **Không** chạy lệnh kết nối DB (`model:show`, `tinker`, `db:*`), vì DB dev chứa dữ liệu thật (AGENTS.md mục 0b).
   - Policy, FormRequest, middleware đang áp dụng.
   - Phần frontend: `resources/js/` (page, component, module API) và `resources/views/`.
   - Luồng dữ liệu hiện tại từ điểm vào xuống DB/API ngoài.
   - Code tương tự đã có, để thiết kế sau này đi theo pattern đó.
   - Test hiện có cho vùng này.
   - Dữ liệu và schema liên quan.
4. Xem `git log` gần đây của các file chính để biết vùng nào đang thay đổi.
5. Copy `docs/work/_template/01-discovery.md` thành `docs/work/<slug>/01-discovery.md` và điền đầy đủ.

## Quy tắc
- Mọi khẳng định phải kèm `đường/dẫn/file:dòng`. Không đoán.
- Không đề xuất giải pháp. Nếu nảy ra ý tưởng, ghi một dòng vào mục "Gợi ý cho bước Architecture".
- Không sửa file nào ngoài `docs/work/<slug>/01-discovery.md`.

## Kết thúc
Báo cho người dùng, bằng tiếng Việt:
- Đường dẫn file đã tạo và slug.
- 3–5 phát hiện chính và các câu hỏi mở cần trả lời.
- Bước tiếp theo: `/architect <slug>` (trả lời câu hỏi mở trước nếu có).
