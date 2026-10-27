---
name: architect
description: Bước 2a — soạn bản nháp thiết kế 02-architecture.md từ kết quả Discovery. Chỉ chạy khi người dùng gọi /architect.
argument-hint: "<slug>"
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Write, Edit, Bash(git log:*), Bash(git diff:*), Bash(ls:*)
---

# Bước 2a — Architecture draft (Claude)

Slug: **$ARGUMENTS**

## Cổng vào
- `docs/work/$ARGUMENTS/01-discovery.md` phải tồn tại. Nếu không, DỪNG và bảo người dùng chạy `/discover`.
- Nếu `01-discovery.md` còn câu hỏi mở chưa có câu trả lời: liệt kê chúng và hỏi người dùng trước. Người dùng bảo "dùng giả định" thì ghi giả định vào thiết kế.

## Làm
1. Đọc `AGENTS.md` (mục 4–7), `docs/architecture.md`, `01-discovery.md` và lại code liên quan khi cần.
2. Đưa ra ít nhất 2 phương án. Chọn 1 và giải thích bằng trade-off cụ thể (độ phức tạp, rủi ro, hiệu năng, mức độ khớp với pattern hiện có).
3. Copy `docs/work/_template/02-architecture.md` thành `docs/work/$ARGUMENTS/02-architecture.md` và điền đầy đủ.
4. Ghi `Status: DRAFT` và `Revision: 1`.
5. Phương án đi ngược rule trong AGENTS.md hoặc tạo pattern mới: soạn ADR nháp `docs/adr/NNNN-<tên>.md` theo `docs/adr/0000-template.md`.

## Quy tắc
- Không sửa code.
- Mỗi bước triển khai phải nhỏ, build được, test được độc lập.
- Mục 5 "Phạm vi file" là phạm vi mà bước Implementation được phép đụng tới, và CI kiểm tra bằng máy:
  mỗi dòng ghi đường dẫn trong dấu backtick ở cột đầu, ví dụ `app/Actions/Orders/CreateOrder.php`, có thể dùng glob như `resources/js/pages/Orders/*.vue`.
  Migration mới ghi dạng `database/migrations/*_create_coupons_table.php`, vì tên file có timestamp.
  Không cần liệt kê file MỚI trong test, factory, seeder, lang, docs (CI tự cho phép). **Test ĐÃ CÓ mà sẽ sửa hoặc xóa thì PHẢI liệt kê**, nếu không CI chặn.
- Không bao giờ sửa các dòng `Status`/`Approved-*`, không chạy `scripts/approve.js`.

## Kết thúc
Báo người dùng: tóm tắt 5 dòng về phương án đã chọn, các rủi ro chính.
Bước tiếp theo: mở Codex, gọi `$arch-critique $ARGUMENTS`.
