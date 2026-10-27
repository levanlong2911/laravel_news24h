# 00 — Task rút gọn: <tên task>

- Slug: `<slug>`
- Nhánh: `chore/<slug>` (hoặc `hotfix/<slug>`)
- Người thực hiện: Claude Code
- Ngày: YYYY-MM-DD

<!--
LUỒNG RÚT GỌN — bỏ Discovery, Architecture, Approval. VẪN BẮT BUỘC: Codex review (05) + Codex verify (06) + CI.
Chỉ dùng khi (CI kiểm tra bằng máy, theo .github/workflow-gate.json → riskPolicy):
  - tối đa 3 file code và 80 dòng thay đổi (không tính test, docs);
  - KHÔNG đụng: migration, routes/, config/, Job, Console, Provider, Middleware, Policy, composer.*, package*.json.
Vượt ngưỡng → chuyển sang feat/<slug>, chạy đủ 6 bước.
-->

## Yêu cầu (nguyên văn người dùng)
> ...

## Vì sao là task nhỏ
- Không đổi hành vi công khai / không đổi schema / không thêm dependency: ...

## Thay đổi dự kiến
<!-- CI ĐỌC BẢNG NÀY: cột 1 ghi đường dẫn trong `backtick`. MỌI file code thay đổi, và mọi test ĐÃ CÓ bị sửa/xóa,
     phải có ở đây, nếu không CI chặn (G4). Test MỚI thì không cần liệt kê. -->
| File | Thay đổi |
|---|---|

## Kiểm chứng
- Test thêm/sửa:
- Lệnh đã chạy và kết quả: pint ..., phpstan ..., pest tests/Arch ..., php artisan test ...

## Sai lệch / ghi chú
-
