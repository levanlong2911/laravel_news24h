# 06 — Verification: <tên task>

- Slug: `<slug>`
- Người thực hiện: Codex (+ CI)
- Ngày: YYYY-MM-DD
- Commit được kiểm chứng: `<sha>`   (git rev-parse --short HEAD)

<!--
CI ĐỌC 2 DÒNG DƯỚI BẰNG MÁY:
  Verdict:   PASS | FAIL
  Code-Tree: kết quả của  node scripts/code-tree.js  (chạy sau khi mọi code đã commit)
Code thay đổi sau khi ghi Code-Tree → hash lệch → CI chặn → phải verify lại.
-->

Verdict: FAIL
Code-Tree: <chạy node scripts/code-tree.js>

## Lệnh kiểm tra (chạy local)
| Lệnh | Exit code | Kết quả | Ghi chú |
|---|---|---|---|
| pint --test | | ✅/❌/KHÔNG CHẠY ĐƯỢC | |
| phpstan | | | |
| pest tests/Arch | | | |
| php artisan test | | | số test pass/fail |
| pest tests/Integration | | | queue database |
| infection (dòng thay đổi) | | | MSI = |
| npm run build | | | |
| ci-gate --local | | | |

## Tiêu chí chấp nhận
| AC | Bằng chứng (test / output) | Trạng thái |
|---|---|---|
| AC1 | | ĐẠT / CHƯA CHỨNG MINH / KHÔNG ĐẠT |

## Kiểm tra bổ sung
- Test skip/only mới: không / ...
- Migration: chạy được, rollback được / N/A
- Queue: job mới có ShouldQueue, có tries/backoff/timeout hợp lý, idempotent khi chạy lại / N/A
- Tài liệu đã cập nhật: ...

## CI
- PR: #...
- Trạng thái: ✅ xanh / ❌ đỏ / chờ CI

## Việc cần làm nếu chưa đạt
-
