---
name: code-review
description: Bước 5 — review độc lập code do Claude triển khai, đối chiếu với thiết kế đã duyệt và rule của project, ghi 05-review.md. Dùng khi người dùng gọi $code-review <slug>.
---

# Bước 5 — Code review (Codex)

Bạn là **reviewer độc lập**. Bạn kiểm tra xem code có đúng thiết kế đã duyệt và đúng rule của project hay không.

## Xác định slug
Lấy `<slug>` từ câu lệnh của người dùng. Không có slug thì liệt kê các task trong `docs/work/` có `04-implementation.md` và hỏi.

## Cổng vào
- Chạy `node scripts/check-clean.js --capture docs/work/<slug>/.review-baseline.json`. Cho phép code chưa commit; lỗi chụp baseline thì dừng và báo lý do.
- Chạy `node scripts/check-approval.js <slug>`. Báo lỗi nghĩa là code được viết khi thiết kế chưa duyệt.
  Ghi đó là BLOCKER `R-00` rồi vẫn review tiếp.
- Task **đủ** (có `02-architecture.md`): cần `02-architecture.md` và `04-implementation.md`.
- Task **rút gọn** (có `00-lite.md`, nhánh `chore/` hoặc `hotfix/`): cần `00-lite.md`. Không có thiết kế để đối chiếu, nên kiểm tra thêm: thay đổi có thật sự nhỏ và đúng như `00-lite.md` mô tả không, có đổi hành vi công khai không. Thấy không còn là task nhỏ thì ghi BLOCKER: "cần chuyển sang quy trình đủ".

## Xác định phạm vi thay đổi
- Đang ở nhánh `feat/<slug>`, `chore/<slug>` hoặc `hotfix/<slug>`: dùng `git diff origin/main...HEAD` (hoặc nhánh gốc ghi trong `.github/workflow-gate.json`).
- Nếu không: dùng `git diff` và `git diff --staged`, kèm các commit được liệt kê trong `04-implementation.md`.
- Ghi rõ lệnh diff đã dùng vào đầu file review.
- Trong mọi trường hợp, đọc thêm `git diff`, `git diff --staged` và các file mới từ `git ls-files --others --exclude-standard` trong phạm vi task. Không chỉ review HEAD khi còn code chưa commit.

## Làm
1. Đọc `AGENTS.md`, thiết kế đã duyệt, `04-implementation.md`.
2. Đọc TOÀN BỘ diff, và cả file xung quanh khi cần ngữ cảnh.
3. Kiểm tra theo 4 lớp:
   - **Đúng thiết kế**: các bước triển khai đã làm đủ chưa? Có file sửa ngoài phạm vi? Sai lệch có được ghi lại và hợp lý không?
   - **Đúng kiến trúc**: AGENTS.md mục 4. Chạy `php vendor/bin/pest tests/Arch` để lấy bằng chứng. Soát thêm phần máy không bắt được: logic nằm trong Controller/Model/Blade, thiếu Policy, thiếu transaction, N+1, gọi API ngoài trực tiếp, JS gọi `fetch` ngoài `resources/js/api/`.
   - **Đúng quy ước**: AGENTS.md mục 5 và 7. Không được có test bị skip hay lint bị nới lỏng.
   - **Đúng chất lượng**: bug logic, edge case, xử lý lỗi, hiệu năng, race condition.
   - **Queue** (chỉ khi project đã bật queue): job có `ShouldQueue`, `$tries`/`backoff`/`$timeout` hợp lý, chạy lại không gây trùng (idempotent, `ShouldBeUnique` hoặc khóa), lỗi API ngoài có retry và ghi log, không giữ transaction trong lúc gọi API ngoài, payload chỉ chứa ID chứ không chứa model lớn.
   - **Bảo mật Laravel**: mass assignment (`$fillable`/`$guarded`), thiếu `authorize`, IDOR (truy cập bản ghi của người khác), `DB::raw`/`whereRaw` nối chuỗi, `{!! !!}` trong Blade, upload file không validate, lộ dữ liệu qua Resource.
   - **Test**: test có assert kết quả thật không (DB, response, event, job)? Có case lỗi validation và không có quyền không? API ngoài đã `Http::fake()` chưa?
4. Ghi `docs/work/<slug>/05-review.md` theo template. **CI đọc file này bằng máy**, nên giữ đúng định dạng:
   - Dòng `Verdict:` đứng riêng, giá trị `PASS` / `PASS_WITH_MINOR` / `FAIL`.
     Còn BLOCKER hoặc MAJOR ở trạng thái `OPEN` thì Verdict phải là `FAIL`.
   - Bảng nhận xét: `| R-xx | MỨC ĐỘ | TRẠNG THÁI | file:dòng | vấn đề | rule | đề xuất |`.
5. Review lại (file đã có từ lần trước): thêm mục `## Lần N — ngày` ở cuối, có Verdict mới.
   Chép lại toàn bộ bảng nhận xét cũ và cập nhật cột Trạng thái **sau khi tự kiểm tra lại code**:
   - `FIXED`: chỉ khi bạn đã xem code và thấy đã sửa đúng. Không tin lời Claude trong 04-implementation.md.
   - `ACCEPTED`: chỉ khi **người dùng đã tự chạy** `node scripts/accept-risk.js <slug> R-xx --reason "…"`. Kiểm tra bằng `git log --grep "Review-Risk-Accepted: <slug> R-xx"`. Chưa có commit đó thì giữ `OPEN` và nhắc người dùng chạy lệnh. Lời nói trong chat không đủ, CI sẽ chặn.
   - `INVALID`: bạn rút lại nhận xét vì nó sai. Ghi lý do.
   - Còn lại giữ `OPEN`. Nhận xét mới dùng mã tiếp theo.

## Quy tắc
- KHÔNG sửa code, KHÔNG commit. Chỉ ghi `05-review.md`.
- KHÔNG gọi API, KHÔNG chạy chương trình (AGENTS.md mục 0b).
- Code mới gọi mạng trong test mà không `Http::fake()`, hoặc dùng Guzzle/curl trực tiếp để né `preventStrayRequests`: ghi BLOCKER.
- Có thể chạy lint/test để lấy bằng chứng, nhưng không sửa gì.
- Nhận xét phải cụ thể, có vị trí. Không viết chung chung kiểu "nên cải thiện code".

## Trước khi kết thúc (bắt buộc)
- Chạy `node scripts/check-clean.js --compare docs/work/<slug>/.review-baseline.json`. Phải khớp trạng thái đầu bước ngoài `docs/work/`. Nếu lệch thì dừng và kiểm tra lại; không reset/stash hoặc xóa thay đổi có sẵn.
- Khi người dùng muốn commit, file của bạn phải commit riêng:
  `git add docs/work/<slug>/05-review.md && git commit -m "docs(<slug>): 05-review"`

## Kết thúc
Trả lời người dùng bằng tiếng Việt:
- Kết luận: `PASS` / `PASS_WITH_MINOR` / `FAIL` (giống dòng Verdict).
- Số nhận xét theo mức độ và các BLOCKER.
- Bước tiếp theo: có BLOCKER/MAJOR thì quay lại Claude gọi `/fix-review <slug>`; đạt thì gọi `$verify <slug>`.
