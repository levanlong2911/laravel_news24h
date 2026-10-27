---
name: quick
description: Luồng rút gọn cho thay đổi nhỏ (≤3 file code, ≤80 dòng, không đụng vùng rủi ro) — bỏ Discovery/Architecture/Approval nhưng vẫn bắt buộc Codex review + verify + CI. Chỉ chạy khi người dùng gọi /quick.
argument-hint: "<mô tả thay đổi nhỏ>"
disable-model-invocation: true
---

# Luồng rút gọn (Claude) — thay đổi nhỏ

Yêu cầu: **$ARGUMENTS**

## Cổng vào: có đúng là task nhỏ không?
Đây là thay đổi nhỏ chỉ khi **tất cả** điều sau đều đúng (CI kiểm tra lại bằng máy):
- tối đa **3 file code** và **80 dòng** thay đổi (không tính `tests/`, `docs/`);
- **không** đụng: `database/migrations/`, `routes/`, `config/`, `app/Jobs/`, `app/Console/`, `app/Providers/`, `app/Http/Middleware/`, `app/Http/Kernel.php`, `app/Policies/`, `bootstrap/`, `composer.*`, `package*.json`, `vite.config.js`, `.env.example`;
- không đổi hành vi công khai (API, route, schema), không thêm package.

Đọc code liên quan trước. Không đạt dù chỉ một điều thì **DỪNG**, giải thích lý do, và đề nghị người dùng dùng `/discover` (quy trình đủ 6 bước).

## Làm
1. Đặt `<slug>` kebab-case ngắn. Tạo nhánh `chore/<slug>` (sửa lỗi gấp: `hotfix/<slug>`) từ nhánh chính mới nhất.
2. Copy `docs/work/_template/00-lite.md` thành `docs/work/<slug>/00-lite.md`, điền phần "Yêu cầu", "Vì sao là task nhỏ" và "Thay đổi dự kiến".
   Bảng "Thay đổi dự kiến" là **phạm vi** CI kiểm tra: mỗi file code sẽ sửa, và mỗi test ĐÃ CÓ sẽ sửa/xóa, một dòng, đường dẫn trong `backtick`. Test mới thì không cần liệt kê.
3. An toàn DB: trước khi chạy test, chắc chắn `phpunit.xml` trỏ tới DB test riêng (xem `/implement`).
4. Sửa code, viết hoặc cập nhật test. Chạy: `php vendor/bin/pint`, `php vendor/bin/phpstan analyse --memory-limit=1G`, `php vendor/bin/pest tests/Arch`, `php artisan test --exclude-group=integration`.
5. Chạy `node scripts/ci-gate.js --local`. Lệnh báo vượt ngưỡng (G2) thì DỪNG và đề nghị chuyển sang quy trình đủ.
6. Điền phần "Kiểm chứng" trong `00-lite.md`. Không bắt buộc commit để Codex review. Khi người dùng yêu cầu commit:
   `git add <file> docs/work/<slug>/00-lite.md && git commit -m "chore(<slug>): <mô tả>"`
7. Chạy `node scripts/check-clean.js` để xem trạng thái; code chưa commit không chặn review local.

## Quy tắc
- **Không gọi API, không chạy chương trình** (AGENTS.md mục 0b). Chỉ viết code và chạy công cụ kiểm tra offline.
- Mọi rule ở AGENTS.md mục 4–7 vẫn áp dụng đầy đủ.
- Không chia một thay đổi lớn thành nhiều task nhỏ để lọt ngưỡng. Thấy thay đổi đang phình ra thì dừng và báo người dùng.
- Không sửa file luật.

## Kết thúc
Báo người dùng: file đã đổi, kết quả lệnh. Bước tiếp theo **bắt buộc**:
mở Codex và gọi `$code-review <slug>`, rồi `$verify <slug>`. Sau đó push nhánh `chore/<slug>` và mở PR.
