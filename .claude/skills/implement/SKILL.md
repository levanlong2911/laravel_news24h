---
name: implement
description: Bước 4 — triển khai code đúng theo thiết kế đã được người dùng duyệt. Chỉ chạy khi người dùng gọi /implement.
argument-hint: "<slug>"
disable-model-invocation: true
---

# Bước 4 — Implementation (Claude)

Slug: **$ARGUMENTS**

## Cổng vào (kiểm tra TRƯỚC mọi thao tác sửa code)
1. Chạy `node scripts/check-approval.js $ARGUMENTS`.
   - Lệnh báo lỗi thì DỪNG, đưa nguyên thông báo cho người dùng và không sửa file nào.
   - Không tự sửa dòng `Status`, không tạo hay sửa file để lệnh này qua được.
2. Đọc `02-architecture.md` (bản đã duyệt), `01-discovery.md`, `AGENTS.md`.
3. Kiểm tra cây làm việc bằng `git status`. Có thay đổi lạ chưa commit thì hỏi người dùng trước.
   **An toàn DB:** trước lần chạy test đầu tiên, đọc `phpunit.xml` (hoặc `.env.testing`) để chắc test dùng DB riêng (`DB_DATABASE` khác DB dev, ví dụ `app_testing`). Không chắc thì DỪNG và yêu cầu người dùng chạy `node scripts/doctor.js`. `RefreshDatabase` xóa sạch DB mà nó dùng.
4. Làm việc trên nhánh `feat/$ARGUMENTS`. Nếu đang ở nhánh khác, tạo nhánh từ nhánh chính mới nhất (`git switch -c feat/$ARGUMENTS`). CI dựa vào tên nhánh này.

## Làm
Lần lượt từng bước trong mục "Các bước triển khai" của thiết kế:
1. Code đúng phạm vi file đã liệt kê ở mục 5 của thiết kế. CI sẽ chặn file nằm ngoài phạm vi (trừ test, factory, seeder, lang, docs).
   Tạo file bằng artisan khi có thể: `php artisan make:model|make:request|make:resource|make:policy|make:migration|make:job ...`.
2. Viết hoặc cập nhật test Pest theo "Kế hoạch test" (Feature cho endpoint, Unit cho Action).
3. Chạy theo thứ tự, có lỗi thì sửa ngay, chưa sang bước sau:
   - `php vendor/bin/pint` (tự format code PHP vừa sửa)
   - `php vendor/bin/phpstan analyse --memory-limit=1G`
   - `php vendor/bin/pest tests/Arch`
   - `php artisan test --exclude-group=integration` (hoặc `--filter=<Tên>` trong lúc làm, chạy toàn bộ ở cuối)
   - Nếu có sửa `resources/js`: `npm run build` (và `npm run lint`/`npm test` nếu có)
4. Ghi kết quả bước vào `docs/work/$ARGUMENTS/04-implementation.md` theo template.
5. Commit bước đó: `git add <file của bước> docs/work/$ARGUMENTS/04-implementation.md` rồi `git commit -m "feat($ARGUMENTS): <bước>"`.

## Không gọi API, không chạy chương trình (AGENTS.md mục 0b)
- Chỉ viết code và chạy công cụ kiểm tra offline (pint, phpstan, pest, npm run build). Test phải `Http::fake()` mọi request ra ngoài.
- Không chạy app, command artisan của app, `tinker`, `serve`, `queue:*`, không `curl`. Cần kiểm chứng bằng cách chạy thật thì DỪNG, ghi rõ lệnh vào `04-implementation.md` (mục "Người dùng tự chạy") và nhờ người dùng chạy.

## Khi cần lệch khỏi thiết kế
- Lệch nhỏ, trong các file đã có ở mục 5 (đổi tên hàm, thêm method private, thêm file test/factory): được làm. Ghi vào mục "Sai lệch so với thiết kế".
- Lệch lớn (cần file ngoài mục 5, đổi interface/route/schema, thêm package, đổi phương án): DỪNG.
  Ghi đề xuất vào `04-implementation.md` và báo người dùng. Cách xử lý: người dùng bảo bạn cập nhật mục 5 và các mục liên quan của `02-architecture.md`, sau đó người dùng chạy lại `node scripts/approve.js $ARGUMENTS`. CI sẽ chặn file ngoài phạm vi nếu thiết kế chưa được duyệt lại.

## Quy tắc
- Không tắt test, không `skip`, không hạ cấu hình lint.
- Không `git push`. Mỗi bước triển khai một commit. Cuối bước 4 cây làm việc phải sạch (`node scripts/check-clean.js`) để Codex review được.
- Không sửa các file "luật" ở AGENTS.md mục 7. Rule gây cản trở thì báo người dùng.
- Migration chỉ chạy trên DB test (`php artisan test` tự lo). Không chạy `migrate` trên DB thật.

## Kết thúc
- Điền mục "Tổng kết" trong `04-implementation.md`: file đã đổi, kết quả lint/typecheck/test.
- Báo người dùng và nhắc bước tiếp theo: mở Codex, gọi `$code-review $ARGUMENTS`.
