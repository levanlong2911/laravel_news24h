---
name: arch-audit
description: Audit kiến trúc trên code thật trước khi bật Arch test và rule trong AGENTS.md. Đề xuất GIỮ / GIỮ + ignoring / BỎ cho từng rule. Chỉ chạy khi người dùng gọi /arch-audit.
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Write, Bash(git log:*), Bash(git ls-files:*), Bash(php artisan route:list:*), Bash(php artisan about:*), Bash(php vendor/bin/pest tests/Arch:*), Bash(php vendor/bin/phpstan analyse:*)
---

# Architecture Audit (Claude) — làm MỘT LẦN khi cài bộ kit, và mỗi khi kiến trúc thay đổi lớn

Mục tiêu: rule trong `AGENTS.md` mục 4–5 và `tests/Arch/ArchitectureTest.php` phải **khớp project thật**.
Rule chung chung mà sai với project thì AI hoặc bị chặn vô lý, hoặc học cách lách. Cả hai đều hại.

Bạn **không sửa** file luật (AGENTS.md, tests/Arch, phpstan…). Bạn chỉ viết báo cáo và đề xuất. Người dùng tự sửa.

## Làm
1. Đọc `composer.json`, `composer.lock` (phiên bản PHP, Laravel, package), `package.json`, `config/queue.php`, `config/database.php`.
2. Lập bản đồ code thật:
   - Thư mục và namespace trong `app/`: đếm số class mỗi loại (Controllers, Actions, Services, Jobs, Models, …). Ghi rõ project dùng tên gì cho lớp nghiệp vụ (Actions? Services? Pipelines? Domain?).
   - Luồng chính của hệ thống, theo bảng DB và code. Ví dụ pipeline nội dung AI: nguồn tin → raw_articles → prompt (prompt_frameworks, prompt_versions, ai_phases, ai_json_schemas) → gọi Claude (claude_usages, claude_usage_logs) → render (render_attempts) → posts/articles. Dùng `php artisan route:list`, đọc model, migration, Job và Command. Không chạy lệnh kết nối DB hay gọi mạng (AGENTS.md mục 0b).
   - Job: liệt kê từng Job với `$tries`, `$timeout`, `backoff`, unique/khóa, và cách xử lý lỗi.
   - Nơi gọi API ngoài (Http facade, SDK) và nơi gọi lệnh shell (`exec`, `Process`, `shell_exec`…).
3. Chạy `php vendor/bin/pest tests/Arch` (nếu đã cài Pest), nếu không thì dùng Grep. Với **từng rule** trong `tests/Arch/ArchitectureTest.php` và từng rule [ARCH] ở AGENTS.md mục 4, ghi:
   - số chỗ vi phạm hiện tại, kèm danh sách file:dòng (tối đa 10, còn lại ghi số lượng);
   - đề xuất:
     - **GIỮ**: rule đúng với project, không có hoặc có ít vi phạm, nên sửa luôn;
     - **GIỮ + ignoring**: rule đúng, nhưng code cũ vi phạm nhiều. Đưa ra đoạn `->ignoring([...])` chính xác, kèm ghi chú nợ kỹ thuật;
     - **SỬA**: ý rule đúng nhưng namespace hoặc tên khác. Đưa ra rule viết lại;
     - **BỎ**: rule không hợp kiến trúc của project. Nêu lý do bằng bằng chứng.
4. Đề xuất rule MỚI mà project cần nhưng bộ kit chưa có (ví dụ: chỉ một class được gọi Claude API; Job pipeline phải ghi `render_attempts`; prompt phải lấy từ `prompt_versions` chứ không hard-code). Viết sẵn bằng cú pháp **Pest 2**: chỉ dùng `toUse`, `toBeUsed`, `toBeUsedIn`, `toOnlyUse`, `toOnlyBeUsedIn`, `toExtend`, `toImplement`, `toHaveSuffix`, `toHavePrefix`, `ignoring`. **Không** dùng `preset()` hay `toHaveMethod()` (Pest 3).
5. Larastan: chạy `php vendor/bin/phpstan analyse --memory-limit=1G` (nếu đã cài), ghi số lỗi theo level, và đề xuất level khởi đầu cùng việc có cần baseline hay không.
6. Ghi tất cả vào `docs/architecture-audit.md` với các mục:
   1. Tóm tắt (5 dòng)
   2. Bản đồ hệ thống (luồng chính, bảng, Job)
   3. Bảng rule hiện có → đề xuất
   4. Rule mới đề xuất (code Pest 2 sẵn để dán)
   5. Đề xuất sửa AGENTS.md mục 4–5 (đoạn văn sẵn để dán)
   6. Đề xuất `docs/architecture.md` (điền các mục TODO)
   7. Rủi ro lớn nhất thấy được (N+1, Job không idempotent, gọi API trong transaction…), xếp theo mức độ

## Quy tắc
- Mọi khẳng định kèm `file:dòng`. Không đoán.
- Không sửa code, không sửa file luật, không chạy migrate hay test có RefreshDatabase.

## Kết thúc
Báo người dùng: số rule GIỮ / GIỮ + ignoring / SỬA / BỎ, 3 rủi ro lớn nhất, và việc người dùng cần làm:
đọc `docs/architecture-audit.md`, tự sửa `tests/Arch/ArchitectureTest.php` và `AGENTS.md`, rồi commit trong PR có label `law-change-approved`.
