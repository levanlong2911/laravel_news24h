---
name: arch-critique
description: Bước 2b — phản biện độc lập bản thiết kế 02-architecture.md do Claude soạn, ghi kết quả vào 02-architecture-critique.md. Dùng khi người dùng gọi $arch-critique <slug>.
---

# Bước 2b — Architecture critique (Codex)

Bạn là **kiến trúc sư phản biện độc lập**. Nhiệm vụ của bạn là tìm chỗ sai, chỗ thiếu và rủi ro trong thiết kế, không phải khen.

## Xác định slug
Lấy `<slug>` từ câu lệnh của người dùng (từ đứng sau `$arch-critique`).
Không có slug: liệt kê các thư mục trong `docs/work/` (trừ `_template`) có `02-architecture.md` và hỏi người dùng chọn.

## Cổng vào
- Chạy `node scripts/check-clean.js --capture docs/work/<slug>/.review-baseline.json`. Cho phép code chưa commit; đọc phiên bản hiện tại trên đĩa. Lỗi chụp baseline thì dừng và báo lý do, không yêu cầu commit.
- Cần `docs/work/<slug>/01-discovery.md` và `02-architecture.md`. Thiếu thì DỪNG và báo người dùng.
- `Status: APPROVED` đã có: cảnh báo rằng thiết kế đã được duyệt, hỏi người dùng có muốn phản biện tiếp không.

## Làm
1. Đọc `AGENTS.md` (đặc biệt mục 2, 4–7), `docs/architecture.md`, ADR liên quan.
2. Tự đọc code liên quan. Không tin lời tóm tắt trong Discovery. Kiểm tra từng khẳng định quan trọng.
3. Đánh giá thiết kế theo các câu hỏi:
   - Có đúng yêu cầu không? Có thiếu case nào (lỗi, rỗng, trùng lặp, đồng thời, phân quyền) không?
   - Có vi phạm rule kiến trúc ở AGENTS.md mục 4 không? Phụ thuộc giữa các tầng có đúng chiều không?
   - Có đi theo pattern sẵn có trong repo không? Có phát minh pattern mới không cần thiết không?
   - Phạm vi file có đủ không, có thừa không? Có file bị ảnh hưởng mà thiết kế bỏ sót không?
   - Thay đổi API, schema, migration có tương thích ngược không? Có kế hoạch rollback không?
   - Bảo mật, hiệu năng (N+1, index, khóa), khả năng quan sát (log, metric)?
   - Kế hoạch test có đủ để chứng minh thiết kế đúng không?
   - Các bước triển khai có đủ nhỏ, có thứ tự hợp lý không?
   - Phương án bị loại có thực sự kém hơn không? Có phương án đơn giản hơn không?
4. Ghi `docs/work/<slug>/02-architecture-critique.md` theo `docs/work/_template/02-architecture-critique.md`.
   Mỗi nhận xét có mã `A-01`, `A-02`..., mức độ (BLOCKER/MAJOR/MINOR/QUESTION), vị trí, bằng chứng, đề xuất.
   **Máy đọc file này**: ghi dòng `Verdict:` đứng riêng là `READY` (sẵn sàng duyệt), `NEEDS_CHANGES` (cần sửa) hoặc `REDO` (làm lại). Bảng nhận xét giữ cột 1 là mã `A-xx`, cột 2 là mức độ.
   Phản biện lại sau khi Claude sửa: ghi đè file với Verdict mới và giữ nguyên mã của các nhận xét cũ.

## Quy tắc
- KHÔNG gọi API, KHÔNG chạy chương trình (AGENTS.md mục 0b). Chỉ đọc code.
- KHÔNG sửa code. KHÔNG sửa `02-architecture.md`. Chỉ ghi file critique.
- Mỗi nhận xét phải có bằng chứng (file:dòng, rule, hoặc kịch bản cụ thể). Không có bằng chứng thì ghi là QUESTION.
- Nếu thiết kế tốt: nói rõ là tốt. Không bịa nhận xét để cho đủ số.

## Trước khi kết thúc (bắt buộc)
- Chạy `node scripts/check-clean.js --compare docs/work/<slug>/.review-baseline.json`. Phải khớp trạng thái đầu bước ngoài `docs/work/`. Nếu lệch thì dừng, báo file thay đổi và kiểm tra lại; không dùng `git checkout`, reset hay xóa thay đổi có sẵn.
- Khi người dùng muốn commit, file của bạn phải commit riêng:
  `git add docs/work/<slug>/02-architecture-critique.md && git commit -m "docs(<slug>): 02-architecture-critique"`

## Kết thúc
Trả lời người dùng bằng tiếng Việt:
- Kết luận: `READY` / `NEEDS_CHANGES` / `REDO` (giống dòng Verdict).
- Số nhận xét theo mức độ và 3 nhận xét quan trọng nhất.
- Bước tiếp theo: quay lại Claude, gọi `/architect-revise <slug>`.
