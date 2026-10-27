---
name: architect-revise
description: Bước 2c — chốt thiết kế sau khi Codex phản biện. Đọc 02-architecture-critique.md và cập nhật 02-architecture.md. Chỉ chạy khi người dùng gọi /architect-revise.
argument-hint: "<slug>"
disable-model-invocation: true
allowed-tools: Read, Grep, Glob, Write, Edit, Bash(git diff:*)
---

# Bước 2c — Architecture revise (Claude)

Slug: **$ARGUMENTS**

## Cổng vào
- Cần cả `docs/work/$ARGUMENTS/02-architecture.md` và `02-architecture-critique.md`.
- Thiếu file critique thì DỪNG và bảo người dùng chạy `$arch-critique $ARGUMENTS` trong Codex.

## Làm
1. Đọc từng nhận xét trong critique (mã `A-xx`). Tự kiểm tra lại trong code, không mặc định Codex đúng.
2. Với mỗi nhận xét, chọn một trong ba:
   - **Chấp nhận**: sửa thiết kế.
   - **Bác bỏ**: nêu lý do kèm bằng chứng (file:dòng, rule).
   - **Cần người dùng quyết**: đưa vào mục "Quyết định chờ duyệt".
3. Cập nhật `02-architecture.md`:
   - Tăng `Revision`, giữ `Status: DRAFT`.
   - Điền bảng mục 11 "Phản hồi phản biện": **mọi** nhận xét BLOCKER/MAJOR một dòng, cột "Xử lý" ghi đúng một trong `ACCEPTED` (đã sửa thiết kế), `REJECTED` (bác bỏ, có bằng chứng) hoặc `DEFERRED` (cần người dùng chấp nhận rủi ro). approve.js và CI đọc bảng này bằng máy: thiếu dòng hoặc ghi giá trị khác thì không duyệt được.
4. Còn BLOCKER chưa xử lý: nói rõ với người dùng. Không coi là sẵn sàng duyệt.

## Quy tắc
- Không sửa code. Không sửa các dòng `Status`/`Approved-*`, không chạy `scripts/approve.js`.
- Thay đổi lớn (đổi phương án, thêm > 3 file vào phạm vi): đề nghị người dùng chạy lại `$arch-critique` lần nữa.

## Kết thúc
Báo người dùng:
- Số nhận xét đã chấp nhận, bác bỏ, chờ quyết.
- Danh sách quyết định cần người dùng chọn.
- Bước 3: người dùng đọc `02-architecture.md`, điền cột "Người dùng chọn" ở mục 10, rồi TỰ chạy trong terminal: `node scripts/approve.js $ARGUMENTS`. Sau đó gọi `/implement $ARGUMENTS`.
