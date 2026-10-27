# 05 — Code review: <tên task>

- Slug: `<slug>`
- Người thực hiện: Codex
- Phạm vi diff: `<lệnh git diff đã dùng>`

<!--
CI ĐỌC FILE NÀY BẰNG MÁY (scripts/approval-lib.js → parseReview):
  - Chỉ xét lần review MỚI NHẤT (mục "## Lần N" cuối cùng).
  - Dòng "Verdict:" phải là một trong: PASS | PASS_WITH_MINOR | FAIL
  - Bảng nhận xét: cột 1 = mã R-xx, cột 2 = mức độ, cột 3 = trạng thái.
    Trạng thái: OPEN | FIXED | ACCEPTED | INVALID
      OPEN     còn phải sửa
      FIXED    đã sửa (Codex xác nhận khi review lại)
      ACCEPTED người dùng chấp nhận không sửa — CHỈ hợp lệ khi người dùng đã chạy
               node scripts/accept-risk.js <slug> R-xx --reason "..."  (CI kiểm tra commit đó)
      INVALID  Codex rút lại nhận xét (ghi lý do)
  - BLOCKER/MAJOR có trạng thái khác FIXED/ACCEPTED/INVALID (kể cả OPEN, PENDING, viết sai, trống) thì CI chặn.
  - Mọi dòng trong bảng nhận xét phải có mã R-xx hợp lệ và không trùng. Dòng thiếu mã thì CI chặn.
-->

## Lần 1 — YYYY-MM-DD

Verdict: FAIL

### Đối chiếu thiết kế
| Bước triển khai | Đã làm đúng? | Ghi chú |
|---|---|---|

File sửa ngoài phạm vi thiết kế: không / danh sách

### Nhận xét
| Mã | Mức độ | Trạng thái | File:dòng | Vấn đề | Rule | Đề xuất sửa |
|---|---|---|---|---|---|---|
| R-01 | MAJOR | OPEN | | | | |

### Điểm tốt
-

<!-- Review lại: thêm "## Lần 2 — ngày" với Verdict mới, CHÉP LẠI bảng nhận xét cũ và cập nhật cột Trạng thái,
     rồi thêm nhận xét mới (nếu có) với mã tiếp theo. CI chỉ đọc lần mới nhất. -->
