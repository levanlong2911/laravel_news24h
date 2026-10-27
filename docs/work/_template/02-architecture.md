# 02 — Architecture: <tên task>

Status: DRAFT
Revision: 1
Approved-by:
Approved-date:
Approved-hash:

<!--
BƯỚC 3 (APPROVAL) — CHỈ NGƯỜI DÙNG LÀM:
  1. Đọc toàn bộ file, điền cột "Người dùng chọn" ở mục 10.
  2. Chạy trong terminal:  node scripts/approve.js <slug>
     (lệnh tự ghi Status, Approved-by, Approved-date, Approved-hash — KHÔNG sửa tay)
  3. Commit file này.
Agent KHÔNG được sửa 5 dòng header ở trên.
Sửa nội dung sau khi duyệt sẽ làm lệch hash, các bước sau tự bị chặn cho đến khi duyệt lại.
-->

- Slug: `<slug>`
- Discovery: `./01-discovery.md`

## 1. Mục tiêu & phạm vi
- Trong phạm vi:
- Ngoài phạm vi:
- Giả định:

## 2. Các phương án
| | Phương án A | Phương án B |
|---|---|---|
| Mô tả | | |
| Ưu | | |
| Nhược | | |
| Khớp pattern hiện có | | |
| Rủi ro | | |

**Chọn:** ... — **Lý do:** ...

## 3. Thiết kế chi tiết
### 3.1 Thành phần & tầng
| Tầng | Thành phần | Mới/Sửa | Trách nhiệm |
|---|---|---|---|

### 3.2 Luồng xử lý
1. ...

### 3.3 Interface / API / hợp đồng
```
// chữ ký hàm, DTO, endpoint, event...
```

### 3.4 Dữ liệu & migration
- Thay đổi schema:
- Tương thích ngược / rollback:

### 3.5 Lỗi, bảo mật, hiệu năng, log
-

## 4. Tuân thủ rule kiến trúc
| Rule (AGENTS.md) | Thiết kế tuân thủ thế nào |
|---|---|

ADR cần thiết: không / `docs/adr/NNNN-...md`

## 5. Phạm vi file (Implementation chỉ được đụng các file này)
| File | Tạo/Sửa | Lý do |
|---|---|---|

Dependency mới: không / tên + phiên bản + lý do

## 6. Kế hoạch test
| Loại | Test | Kiểm tra gì |
|---|---|---|

## 7. Tiêu chí chấp nhận (bước Verification sẽ đối chiếu)
- [ ] AC1:
- [ ] AC2:

## 8. Các bước triển khai
1. ... (build & test được sau bước này)
2. ...

## 9. Rủi ro & giảm thiểu
-

## 10. Quyết định chờ duyệt
| # | Câu hỏi | Đề xuất | Người dùng chọn |
|---|---|---|---|

## 11. Phản hồi phản biện (bước 2c điền)
<!-- Mỗi nhận xét A-xx BLOCKER/MAJOR của Codex phải có 1 dòng. Cột "Xử lý" chỉ nhận:
  ACCEPTED  đã sửa thiết kế theo nhận xét
  REJECTED  bác bỏ, có lý do + bằng chứng
  DEFERRED  chưa xử lý, để người dùng chấp nhận rủi ro khi duyệt (được ghi vào commit duyệt)
  Thiếu dòng hoặc giá trị khác → approve.js và CI từ chối duyệt. -->
| Mã | Mức độ | Xử lý | Lý do / thay đổi |
|---|---|---|---|

## 12. Lịch sử revision
| Rev | Ngày | Thay đổi |
|---|---|---|
| 1 | | Bản nháp đầu |
