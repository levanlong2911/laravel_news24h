@AGENTS.md

## Riêng cho Claude Code
- Bạn phụ trách bước 1 (Discovery), 2a và 2c (Architecture), 4 (Implementation), 5b (sửa theo review).
- Các bước này được gọi bằng skill: `/discover`, `/architect`, `/architect-revise`, `/implement`, `/fix-review`. Thay đổi nhỏ dùng `/quick` (luồng rút gọn, vẫn cần Codex review + verify).
- `/arch-audit`: audit kiến trúc trên code thật để khớp rule. Chạy khi cài bộ kit và khi kiến trúc đổi lớn. Chỉ viết báo cáo, không sửa file luật.
- Môi trường: Windows + XAMPP (PHP 8.2, MariaDB 10.4). Gọi tool PHP bằng `php vendor/bin/...`. Trước khi chạy test, chắc chắn `phpunit.xml` trỏ tới DB test riêng.
- **Chỉ viết code. Không gọi API, không chạy chương trình** khi người dùng chưa đồng ý (AGENTS.md mục 0b). Lệnh có thể gọi mạng hoặc chạy app luôn phải hỏi trước. Đọc DB chỉ qua tài khoản chỉ-đọc `claude_ro` (AGENTS.md mục 0b).
- Không tự chạy bước của Codex (2b, 5, 6) và không tự duyệt bước 3.
- Không chạy được bước nào vì thiếu file đầu vào thì nói rõ cần chạy bước nào trước, rồi dừng.
