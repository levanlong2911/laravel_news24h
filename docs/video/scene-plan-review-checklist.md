# Đọc duyệt một bản kế hoạch scene

Validator PHP giữ phần cấu trúc: schema, sàn/trần số scene, coverage mốc, thứ tự
mốc, mốc thuộc đúng pha, kiểu và độ dài từng trường, `camera_mode`, scene đầu là
hard cut. **Validator hiện tại chưa kiểm phần ngữ nghĩa**, và regex không đủ để
bảo đảm nó — các tiêu chí dưới đây cần người đọc.

Bằng chứng cho việc regex không đủ: lô 18 scene của revision 2 (2026-09-09) sinh
5 cảnh báo heuristic, và **cả 5 đều là báo động giả sau khi review** — `lower
hull`, `lower vessel body`, `internal framing`, `frames rise from` đụng từ vựng
đóng tàu, còn scene 1 bị báo thiếu camera trong khi delta viết rõ *"seen from a
close overhead three-quarter view"*. Đây là một lô, không phải kết luận cho mọi
lô.

## Đọc đủ bốn trường

Mỗi scene có **bốn** đoạn văn bản, phải đọc hết: `delta`, `video.action`,
`video.preserve`, `video.end_state`. Hai mâu thuẫn của revision 3 — scene 7 lấp
một lỗ mà `preserve` bảo giữ nguyên cái lỗ, scene 13 lắp kính mà `preserve` bảo
giữ ô kính đang mở — chỉ lộ ra khi đọc `preserve`. Bỏ một trường là bỏ một phần
tư hợp đồng.

## Đọc theo cặp scene liền nhau

| # | Tiêu chí | Câu hỏi khi đọc |
|---|---|---|
| 1 | Tiến độ | Scene sau có làm **mất** cấu kiện scene trước đã hoàn tất không? |
| 2 | Ảnh nguồn | Delta có giả định kết quả **clip** trước đã nằm sẵn trong keyframe nguồn không? Renderer không thấy clip. |
| 3 | Thời gian bị lược | Công việc bỏ qua giữa hai scene có được nói trong `purpose` không? |
| 4 | Hành động | Clip có lắp lại thứ keyframe đã cho thấy lắp xong không? |
| 5 | Camera | Từ góc đã chọn có **nhìn thấy** thao tác được tả không? Việc kỹ thuật kín mà giữ góc ngoại thất xa là hỏng. |
| 6 | Biểu diễn | Có cutaway, mặt cắt, thân tàu trong suốt, hay mở một lỗ chỉ để lộ việc bên trong không? |
| 7 | Nhận dạng | Thiết kế đã duyệt có bị đổi không? Xem `project_majesty_120_scale_decision`. |
| 8 | Bối cảnh | Bối cảnh có nhất quán với tiến độ không? Nếu vị trí **trên bờ / đã nổi** ảnh hưởng tính hợp lý của cảnh thì kế hoạch phải xác lập rõ. Cảnh cận nội thất không buộc phải cho thấy điều đó. |
| 9 | `preserve` | `video.preserve` có đóng băng đúng cái thuộc tính mà `video.action` đang đổi không? Giữ hình học khung là đúng; giữ "ô còn mở" trong khi lắp kính vào ô đó là sai. |
| 10 | `end_state` | `end_state` có được **trạng thái keyframe cộng hành động clip** cùng đỡ không, hay chỉ mình action? Một mảng nhỏ được chà không đủ đỡ `surface_faired` **nếu phần còn lại chưa được xác lập là đã hoàn tất**; nếu keyframe đã cho thấy phần còn lại xong thì mảng cuối là đủ. |
| 11 | Bằng chứng mốc | Mốc khai có bằng chứng phù hợp trong scene không? Phân biệt **đang thực hiện / hoàn tất / được lược giữa cảnh** — không ép mỗi keyframe hoàn thành mốc. |
| 12 | Tiêu đề | `title` có khớp cả ảnh lẫn clip không? |

## Đọc nhãn `basis`

`basis` mô tả **mốc**, không phải mọi chi tiết trong khung hình. `source_supported`
chỉ hợp lệ khi nguồn nói bước đó **đã xảy ra**; dàn dựng quanh nó — ai có mặt,
thiết bị gì, bố trí ra sao — luôn là của model.

Khi một scene khai **nhiều mốc**, `source_supported` phải đúng cho **tất cả** mốc
trong scene đó, không phải chỉ một.

Cách kiểm: đối chiếu với **bài viết gốc**. Phần `source_insights[].source_quotes`
của chặng inspiration là do model trích, nên nó là thứ **cần kiểm**, không phải
bằng chứng độc lập — đã có tiền lệ chặng này suy một đơn vị đo không có trong bài.

## Ba chỗ đã gặp, soi trước

1. **Đóng vỏ → lắp boong** — một scene khai một mốc nhưng keyframe phải làm xong
   hai. Mốc bị "phủ" bởi một scene mà keyframe của nó chỉ cho thấy **đang dở**.
2. **Lối đưa máy vào** — delta đòi một cửa máy, nhưng **chưa xác nhận** lối tiếp
   cận đó có trong keyframe nguồn hay không; keyframe chưa được render nên đây là
   rủi ro cần kiểm, không phải lỗi đã quan sát.
3. **Kính + nội thất** — cutaway trên một camera ngoại thất kế thừa.

## Camera không còn cảnh báo tự động

Hai chốt camera — "hard cut thiếu camera" và "continuation nhắc camera" — **đã bị
tắt**.

Các delta bị báo mà không cái nào sai: `eye-level`, `wide elevated side view`,
`broad side view`, `Frame it from` — chúng chính là câu đặt camera hợp lệ; còn
`Keep the camera unchanged` là câu **bảo toàn**, không phải đặt camera mới.

Số cảnh báo của từng nhánh chưa được thống kê tách bạch, nên không ghi con số
tổng ở đây.

Danh sách từ chỉ chứng minh được **có nhắc** camera, không chứng minh được
**không có** câu camera. Camera vì vậy nằm ở tiêu chí 5 — đọc tay.

## Cảnh báo số đếm

Máy có một cờ `may restate an identity fact`. Nó là **cờ rà soát**, không phải bộ
phân loại: `three deck panels` (số cấu kiện đang thao tác) cũng bị bắt, còn
`one deck panel` thì không vì `one` không nằm trong danh sách. Đọc rồi bỏ qua nếu
đó là số thao tác. **Không** dùng cờ này để từ chối một bản kế hoạch.

## Điều checklist này không làm

Nó không chứng minh kế hoạch **hay hơn**, chỉ giúp tìm lỗi logic. Và planning
sạch không chứng minh render sạch: chất lượng ảnh và chuyển động video là phép đo
riêng, chỉ trả lời được sau khi render thật.
