---
name: verify
description: Bước 6 — kiểm chứng cuối cùng. Chạy toàn bộ lệnh kiểm tra như CI, đối chiếu tiêu chí chấp nhận của thiết kế, ghi 06-verification.md. Dùng khi người dùng gọi $verify <slug>.
---

# Bước 6 — Verification (Codex)

Bạn là **người kiểm chứng**. Bạn chứng minh bằng kết quả chạy thật rằng task đã đạt, không dựa vào lời của ai.

## Xác định slug
Lấy `<slug>` từ câu lệnh của người dùng. Không có thì hỏi.

## Cổng vào
- Chạy `node scripts/check-clean.js --capture docs/work/<slug>/.review-baseline.json`. Cho phép code chưa commit; lỗi chụp baseline thì dừng và báo lý do.
- `node scripts/check-approval.js <slug>` phải pass (task rút gọn có `00-lite.md` tự pass bước này).
- `05-review.md`: lần review mới nhất phải có `Verdict: PASS` hoặc `PASS_WITH_MINOR`, không còn BLOCKER/MAJOR ở trạng thái `OPEN`.
  Nếu không, DỪNG và báo người dùng quay lại bước 5b.

## Làm
1. Chạy lần lượt các lệnh ở AGENTS.md mục 6:
   `php vendor/bin/pint --test` → `php vendor/bin/phpstan analyse --memory-limit=1G` → `php vendor/bin/pest tests/Arch` → `php artisan test --exclude-group=integration`
   → mutation test trên dòng thay đổi (`php vendor/bin/infection --git-diff-lines --git-diff-base=origin/main --threads=max --min-msi=60 --min-covered-msi=75`, cần PCOV hoặc Xdebug; không có thì ghi KHÔNG CHẠY ĐƯỢC, CI sẽ chạy)
   → `npm run build` (và `npm run lint`/`npm test` nếu có)
   → `node scripts/ci-gate.js --local` (kiểm tra trước các cổng mà CI sẽ chạy).
   Ghi lại lệnh, exit code và tóm tắt output (phần lỗi nếu có).
   Sandbox chặn mạng hay quyền khiến lệnh không chạy được: ghi "KHÔNG CHẠY ĐƯỢC" kèm lý do. Tuyệt đối không ghi là pass.
2. Đối chiếu từng mục trong "Tiêu chí chấp nhận" của `02-architecture.md` (task rút gọn: đối chiếu với "Yêu cầu" và "Thay đổi dự kiến" trong `00-lite.md`):
   - Chỉ ra test nào hoặc bằng chứng nào chứng minh mục đó (file test:tên test, hoặc output).
   - Không có bằng chứng thì đánh dấu CHƯA CHỨNG MINH.
3. Kiểm tra thêm:
   - Có test bị skip/only, lint bị disable mới thêm trong diff không?
   - Migration (nếu có) có chạy được, có rollback không?
   - Tài liệu liên quan (`docs/architecture.md`, ADR, README) đã cập nhật chưa, nếu thiết kế yêu cầu?
4. Kiểm tra CI: nếu có Pull Request và dùng được `gh`, chạy `gh pr checks` để lấy trạng thái. Không thì ghi "Chờ CI".
5. Ghi `docs/work/<slug>/06-verification.md` theo template. **CI đọc 2 dòng bằng máy**:
   - `Verdict: PASS` chỉ khi mọi lệnh pass và mọi tiêu chí chấp nhận ĐẠT. Ngược lại ghi `Verdict: FAIL`.
   - `Code-Tree: <hash>`: lấy từ `node scripts/code-tree.js`, chạy SAU CÙNG. Hash bao gồm code hiện tại, kể cả staged, unstaged và file mới không bị ignore; không yêu cầu commit. Không tự tạo hash. Sau này CI so với nội dung commit/PR; nội dung khác bản đã kiểm thì phải verify lại.
   - `ci-gate.js --local` đọc HEAD, không đọc code chưa commit. Nếu chưa commit, ghi riêng kết quả gate cho HEAD; không dùng nó để khẳng định code trên đĩa đã qua cổng CI.

## Quy tắc
- KHÔNG sửa code. Chỉ ghi `06-verification.md`.
- KHÔNG gọi API, KHÔNG chạy chương trình (AGENTS.md mục 0b). Chỉ chạy công cụ kiểm tra offline. Tiêu chí chấp nhận nào chỉ chứng minh được bằng cách chạy app thật: ghi `CHƯA CHỨNG MINH — cần người dùng chạy: <lệnh>`, và Verdict là FAIL cho tới khi người dùng xác nhận.
- Không làm tròn kết quả. Một lệnh fail hoặc một tiêu chí chưa chứng minh là chưa đạt.

## Trước khi kết thúc (bắt buộc)
- Chạy `node scripts/check-clean.js --compare docs/work/<slug>/.review-baseline.json`. Phải khớp trạng thái đầu bước ngoài `docs/work/`. Nếu lệch thì dừng và kiểm tra lại; không reset/stash hoặc xóa thay đổi có sẵn.
- Khi người dùng muốn commit, file của bạn phải commit riêng:
  `git add docs/work/<slug>/06-verification.md && git commit -m "docs(<slug>): 06-verification"`

## Kết thúc
Trả lời người dùng bằng tiếng Việt:
- Kết luận: `PASS` (sẵn sàng merge khi CI xanh) / `FAIL`.
- Bảng tóm tắt lệnh và tiêu chí.
- Bước tiếp theo: không đạt thì quay lại Claude `/fix-review <slug>`, chỉ rõ việc cần làm. Đạt thì push và mở PR, chờ CI xanh, rồi người dùng merge.
