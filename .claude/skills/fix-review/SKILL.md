---
name: fix-review
description: Bước 5b — sửa code theo nhận xét của Codex trong 05-review.md. Chỉ chạy khi người dùng gọi /fix-review.
argument-hint: "<slug>"
disable-model-invocation: true
---

# Bước 5b — Sửa theo review (Claude)

Slug: **$ARGUMENTS**

## Cổng vào
- Chạy `node scripts/check-approval.js $ARGUMENTS`. Báo lỗi thì DỪNG.
- `docs/work/$ARGUMENTS/05-review.md` phải tồn tại. Nếu không, bảo người dùng chạy `$code-review $ARGUMENTS` trong Codex.

## Làm
1. Đọc từng nhận xét `R-xx`. Tự kiểm tra lại trong code trước khi sửa.
2. Xử lý theo mức độ:
   - **BLOCKER, MAJOR**: sửa. Nếu bạn cho rằng nhận xét sai, đừng sửa. Ghi lý do kèm bằng chứng và đánh dấu "Chờ người dùng quyết".
   - **MINOR**: sửa nếu nằm trong phạm vi thiết kế.
   - **QUESTION**: chuyển câu hỏi cho người dùng.
   - Sửa mà phải lệch lớn khỏi thiết kế: DỪNG, làm như quy tắc ở `/implement`.
3. Sau khi sửa, chạy lại: `php vendor/bin/pint`, `php vendor/bin/phpstan analyse --memory-limit=1G`, `php vendor/bin/pest tests/Arch`, `php artisan test`, và `npm run build` nếu có đụng JS.
   Commit: `git commit -m "fix($ARGUMENTS): address R-xx, R-yy"`. Cuối bước cây làm việc phải sạch (`node scripts/check-clean.js`).
4. Cập nhật `04-implementation.md`, mục "Phản hồi review": mã nhận xét → đã sửa / bác bỏ / chờ quyết → commit hoặc lý do.

## Quy tắc
- Không gọi API, không chạy chương trình (AGENTS.md mục 0b).
- Không sửa `05-review.md`, kể cả cột Trạng thái. Chỉ Codex đánh dấu `FIXED` sau khi review lại.
- Muốn bỏ qua một nhận xét: đưa lý do cho người dùng. Nếu người dùng đồng ý, họ **tự chạy** `node scripts/accept-risk.js <slug> R-xx --reason "…"`. Bạn không được chạy lệnh này. Sau đó Codex mới đánh dấu `ACCEPTED`.
- Chỉ sửa code liên quan đến nhận xét.

## Kết thúc
Báo người dùng kết quả. Bước tiếp theo:
- Còn BLOCKER/MAJOR vừa sửa: chạy lại `$code-review $ARGUMENTS` trong Codex.
- Mọi trường hợp: chạy lại `$code-review $ARGUMENTS` trong Codex để Codex xác nhận `FIXED`. Bước 6 chỉ chạy được khi review mới nhất là PASS.
