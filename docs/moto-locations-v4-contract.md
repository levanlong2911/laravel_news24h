# Hợp đồng dữ liệu Phase 5 — thiết kế bối cảnh (moto-locations-v4)

Áp cho bản phát hành `moto-cinematic-3s-v8`, kế thừa `moto-cinematic-3s-v7`. Prompt: Phase 5 revision 8. Schema: `MotoLocations@4.0.0`. Đầu vào: `moto-locations-input-v5`. Bộ lập kế hoạch: `moto-locations-plan-v3`. Bộ kiểm: `moto-locations-check-v7`. Các bước nhận bàn giao: Phase 6 revision 14 (`moto-scenes-input-v7`), Phase 7 revision 20 (`moto-shots-input-v13`), Phase 10 revision 22 (`moto-clip-prompt-input-v11`). DNA, thiết kế và kịch bản giữ revision của bản v7.

Nhánh đọc chọn theo định dạng đã ghim trong ngữ cảnh của lượt chạy, không đoán theo việc JSON có trường mới hay không. Bản `moto-locations-v3` và `moto-locations-v2` giữ nguyên cách kiểm, cách lập kế hoạch, cách ghép prompt và mã băm.

## Quy ước chung

Từ checker v7, `MotoLocationsAnswer` chuẩn hóa đúng một trường hợp: trong câu trả lời v4, nếu schema đã ghim cấm `objects[].colour` và giá trị AI trả về đúng là `null`, bản JSON sử dụng bỏ khóa đó trước khi kiểm cấu trúc và lập kế hoạch. `architecture[].colour`, mọi giá trị màu khác `null` và mọi trường dư khác giữ nguyên để schema kiểm; không xóa thông tin màu hoặc sửa nội dung bằng suy đoán. Mọi lượt mới được thêm quy định cấm trường này vào system text do code ghép, không sửa đè prompt đã phát hành. Quy định bằng chữ không bảo đảm AI luôn tuân thủ; lớp kiểm và chuẩn hóa bảo đảm bản JSON được dùng không chứa trường dư này.

Phản hồi AI gốc, kết quả kiểm lúc chạy và chi phí không bị ghi đè. `content_hash` tiếp tục kiểm toàn vẹn tài liệu gốc; `answer_preparation` ghi phiên bản `moto-locations-answer-v1`, từng đường dẫn đã bỏ, SHA-256 phản hồi gốc và hash riêng của tài liệu sử dụng. Lượt mới lưu phần này trong output; lượt cũ tính lại khi đọc, hiện thao tác trên màn, vẫn giữ FAIL lúc chạy làm lịch sử. Không gọi lại AI, không tự duyệt, không tự chọn ảnh, không thay nguồn hoặc schema.

- `null`: chưa biết, hoặc không áp dụng theo đúng định nghĩa của trường.
- `[]`: không có phần tử, hoặc không có thay đổi.
- Số `0` là giá trị thật, không thay cho "chưa biết".
- Mã của thành phần kiến trúc (`AR01…`), vật thể (`OB01…`), vùng (`Z01…`), lối (`R01…`) và nguồn sáng (`LS01…`) chỉ duy nhất trong phạm vi địa điểm hoặc ảnh nền khai nó. Ảnh nền chỉ tham chiếu thành phần của địa điểm nó đứng trên.
- **Mỗi dữ kiện một nguồn.** Địa điểm khai cái gì nó là và giữ; ảnh nền khai cách dùng và phần khác biệt. Mô tả chữ cho prompt ảnh, cho màn và cho các bước sau đều do PHP ghép từ trường cấu trúc; AI không viết lại lần thứ hai.

## Thứ tự nguồn

- Thiết kế quyết định xe có gì, hình dáng và khả năng vận hành.
- Kịch bản quyết định hành động, người tham gia, thứ tự và thời gian câu chuyện.
- Phase 5 quyết định môi trường trong các giới hạn đó. Hai nguồn mâu thuẫn thì khai xung đột; giả định sản xuất không đổi dữ kiện nguồn.

## Đầu vào do PHP dựng (`moto-locations-input-v5`)

Như `moto-locations-input-v4`, thêm `temporary_equipment`: mọi thiết bị tạm mà các checkpoint của thiết kế cho phép, theo thứ tự. Đây là danh sách duy nhất mà một vật thể đỡ chủ thể được phép nêu ở `support_kind`. Danh sách này cũng được ghim trong ngữ cảnh của lượt chạy.

Danh sách `subjects` của từng beat (`MotoLocations::subjects`) trong đầu vào này khác đầu vào cũ ở đúng một điểm:

| Điều kiện (theo trường cấu trúc, không dò tên hay câu chữ) | PHP xử lý |
|---|---|
| Beat có `situation.mode` là `representation` **và** bộ phận có `present: false` trong thiết kế | Không liệt kê bộ phận đó làm chủ thể — ở beat này nó chỉ là hình vẽ, không có vật nào đứng trong địa điểm |
| Bộ phận thuộc thiết kế, chỉ xuất hiện qua bản vẽ / màn hình | Liệt kê như cũ; prompt và bộ kiểm phân biệt hình biểu diễn với vật thật |
| Mô hình vật lý (`model`) | Giữ, với kích thước của mô hình |
| Beat không phải `representation` nhắc bộ phận `present: false` | Giữ, kích thước `unknown` — không lọc để việc dùng một bộ phận đã bỏ vẫn được thấy |
| Bộ phận có thật nhưng thiếu số đo | Giữ `null` / `unknown`, không tự đặt số |

Việc lọc không đụng `visual`, sự kiện, `link` hay `source_flags` của beat; toàn bộ `source_flags` của kịch bản được gửi nguyên. PHP không xoá flag theo từ khoá và không suy diễn câu chữ để đóng một xung đột.

Quy tắc trên chỉ áp dụng khi **dựng đầu vào mới** của v8. Khi đọc hoặc kiểm lại một lượt đã lưu, code dùng ngữ cảnh đã ghim của chính lượt đó (`LocationSourceService::evaluate`), không dựng lại đầu vào — các đầu vào `moto-locations-input-v4` trở về trước cho kết quả như cũ.

## Phân loại thông tin nguồn (prompt Phase 5 revision 8)

`source_flags` là điều kịch bản báo, **không tự thành xung đột**. AI đánh giá phạm vi ảnh hưởng của từng flag:

| Nội dung | Nơi thể hiện |
|---|---|
| Hai yêu cầu nguồn không thể cùng đáp ứng | `conflicts`, loại `source_conflict` |
| Thiếu dữ kiện cần cho bố trí hoặc hành động | `conflicts`, loại `missing_information` |
| Chuyển thời điểm có chủ ý, có nguồn giải thích | Điều kiện của từng ảnh nền và `continuity` |
| Phương án thiết kế đã bỏ, chỉ xuất hiện trên bản vẽ | Giữ ngữ cảnh nguồn; thiếu kích thước thật không phải xung đột |
| Lựa chọn môi trường do Phase 5 tự quyết | `assumptions` |

**Beat `representation`:** hình vẽ, bản in hay mô hình số của một bộ phận không chiếm chỗ theo kích thước thật của bộ phận đó. Chỉ bỏ kiểm kích thước thật của *thứ được biểu diễn*: vật thật mang hình (màn hình, bảng vẽ, tường hoặc bàn đặt bản in) vẫn là vật thể của set và được bố trí như mọi vật thể khác; mô hình vật lý vẫn là chủ thể, bố trí theo kích thước của mô hình; kích thước bản in mà nguồn không cho thì để `unknown`, không tự chọn. Ảnh nền vẫn là môi trường trống — nội dung bản vẽ, xe và người được đưa vào ở bước shot.

**Chuyển thời điểm (ví dụ ban ngày → blue hour) trong cùng địa điểm** chỉ là lựa chọn của kịch bản, không phải xung đột, khi đủ cả bốn điều: kịch bản giao các thời điểm đó cho các beat khác nhau; một trường của beat (`link`, `visual`, `purpose`, `time_light`) nói thời gian đã trôi qua hoặc thời điểm đổi; yêu cầu của thiết kế ở từng beat vẫn được đáp ứng; mỗi ảnh nền chỉ phục vụ beat của đúng thời điểm của nó. Khi đó: mỗi thời điểm một ảnh nền trong **cùng địa điểm**, giữ kiến trúc, vật thể và mốc nhận diện; `continuity` liệt kê `light` và `time_of_day` trong `may_change`, `basis` là `script`, `evidence` trích câu nói về sự thay đổi từ một beat của chính mục đó. Camera khác thì thêm `camera` và `same_camera` là `false` — cùng hướng nhìn không phải cùng camera. `same_layout: true` chỉ khi mọi vật thể thật sự đứng yên. Không có nguồn giải thích, hoặc hai yêu cầu trái nhau ở cùng một thời điểm, thì vẫn là xung đột: `may_change` không hợp thức hoá thay đổi mà nguồn không cho phép.

PHP kiểm phần cấu trúc của các quy định này: khác biệt ở giá trị cấu trúc ngoài `may_change` (`setDesignIssues.27`; khác câu chữ chỉ là ghi chú `setDesignIssues.30`), câu trích không nguyên văn hoặc trích beat ngoài mục (`quoteIssues.text`, `quoteIssues.beat`), bằng chứng của xung đột trỏ tới đường dẫn không có trong đầu vào đã ghim (`conflictIssues.5`). Việc AI phân loại đúng hay sai là nội dung, được xem trên lượt chạy thật.

## Dữ liệu chung của địa điểm và dữ liệu của ảnh nền

| Thuộc `locations` — thiết kế chung | Thuộc `backgrounds` — một lần dùng |
|---|---|
| Kiến trúc, vật liệu, màu riêng, độ cũ, độ sạch, bề mặt | Camera: vùng, vị trí, điểm, chiều cao, hướng nhìn, tiêu cự, khung |
| Vật thể: công dụng, vai trò, cố định hay không, vùng, vị trí, hướng, số đo, trạng thái chuẩn | Ánh sáng: nguồn, vùng sáng, vùng tối, độ sáng của chủ thể |
| Vùng, lối ra vào, vùng camera, hệ toạ độ | Buổi, thời tiết, khí quyển, mặt đất, âm thanh nền |
| Mốc nhận diện (`landmark`, `identity_rules`) | `set_state`: chỉ phần khác trạng thái chuẩn |
| | `visible`: phần của địa điểm mà khung này cho thấy |

## Đầu ra do AI viết

Ký hiệu nơi dùng: **Ảnh** = prompt ảnh nền do PHP ghép; **P6**, **P7**, **Video** = đầu vào Phase 6, 7, 10; **Màn** = màn Locations & Sets.

### `locations[]`

| Trường | Kiểu | Rỗng | PHP kiểm | Nơi dùng |
|---|---|---|---|---|
| `id`, `name`, `kind` | `LOCnn`, chữ, danh sách | không | mã không trùng | Ảnh, P6, P7, Màn |
| `function`, `source_beats`, `setting`, `provenance` | như v3 | như v3 | như v3 | P6, P7, Màn |
| `space` | chữ | không | — | Ảnh, P7, Màn |
| `dimensions` | ba số đo, mỗi số có `basis` | `value` null khi `unknown` | `value` khớp `basis`; vùng và vật thể không vượt số đo | Ảnh, P7, Màn |
| `coordinate_system` | gốc, trục x, trục y | null khi không có sơ đồ số | có `footprint` thì bắt buộc có; không có thì mọi `footprint` coi như chưa khai | P7, Màn |
| `zones[]` | như v3 | `footprint`, `subject_axis` có thể null | như v3 | Ảnh, P6, P7, Màn |
| `access_routes[]` | như v3 | `zone` có thể null | như v3 | P6, P7, Màn |
| `camera_access` | chữ | không | — | P6, P7, Màn |
| `set_design.architecture[]` | `id` `ARnn`, `kind`, `name`, `description`, `position`, `material`, `colour`, `finish`, `wear`, `cleanliness`, `surface`, `landmark` | `finish`, `surface` có thể null; ít nhất một phần tử | mã không trùng | Ảnh, P7, Màn |
| `set_design.objects[]` | `id` `OBnn`, `kind`, `name`, `purpose`, `role`, `support_kind`, `fixed`, `zone`, `position`, `footprint`, `facing`, `height_m`, `material`, `identity`, `present`, `condition` | `support_kind`, `zone`, `footprint`, `facing`, `identity`, `condition` có thể null | mã không trùng; `zone` tồn tại; `footprint` là hình chữ nhật và cần `coordinate_system`; `height_m` khớp `basis`; `support_kind` chỉ ở vật `role = support` và phải có trong `temporary_equipment` | Ảnh, P6, P7, Màn (kèm sơ đồ) |
| `set_design.identity_rules[]` | danh sách chữ | ít nhất một | — | Ảnh, Màn |
| `assumptions[]` | danh sách | có thể rỗng | — | Màn |

Các trường `surfaces`, `fixed_equipment`, `palette`, `spatial_layers`, `fixed_landmarks` dạng chữ của v3 **không còn**: mọi dữ kiện đó nằm trong `set_design`. Mốc nhận diện suy ra từ `architecture[].landmark` và vật thể có `role = landmark`.

### `backgrounds[]`

| Trường | Kiểu | Rỗng | PHP kiểm | Nơi dùng |
|---|---|---|---|---|
| `id`, `location`, `uses`, `sharing` | như v3 | không | như v3 | Ảnh, P6, P7, Màn |
| `composition` | chữ | không | — | Ảnh, P7, Màn |
| `subject_zone` | mã vùng | null khi khung không có chủ thể | như v3 | Ảnh, P6, P7, Màn |
| `camera` | `zone`, `position`, `point`, `height_m`, `look_at`, `lens_mm`, `framing`, `basis` | `zone`, `point`, `height_m`, `lens_mm`, `look_at.ref` có thể null | `zone` là vùng `camera` của địa điểm; `height_m`, `lens_mm` là số lớn hơn 0 hoặc null; `point` cần `coordinate_system` và toạ độ từ 0, và nằm trong footprint của vùng camera đã khai khi vùng có footprint; `look_at.ref` tồn tại trong địa điểm | Ảnh, P7, Màn |
| `visible` | danh sách mã `AR`/`OB` | ít nhất một | mã tồn tại trong `set_design` của địa điểm; vật thể phải hiện diện ở trạng thái của chính ảnh nền (vật `set_state` đã bỏ thì không được nêu) | Ảnh, P7, Màn |
| `lighting_plan.sources[]` | `id` `LSnn`, `kind`, `role`, `position`, `direction`, `quality`, `intensity`, `tone`, `kelvin`, `in_frame`, `fixture`, `basis` | `kelvin`, `fixture` có thể null; ít nhất một nguồn | mã không trùng; `in_frame` thì `fixture` phải là thành phần của địa điểm và vật thể đó phải hiện diện ở trạng thái của ảnh nền; `kelvin` là số lớn hơn 0 hoặc null | Ảnh, P7, Video, Màn |
| `lighting_plan.bright_areas`, `dark_areas`, `subject_exposure` | danh sách vùng, enum | có thể rỗng / null | cấu trúc | Ảnh, P7, Video, Màn |
| `lighting_plan.evidence`, `reason` | câu trích hoặc lý do | một trong hai | câu trích có nguyên văn trong beat của ảnh nền | Màn (P7 không nhận) |
| `set_state` | `name`, `changes[]` | `changes` có thể rỗng | mỗi thay đổi trỏ đúng mã của địa điểm; vật cố định không di chuyển, không thêm, không bỏ; kiến trúc chỉ đổi `condition`; không đổi gì thì báo ghi chú; beat phải là beat của ảnh nền; không đổi một thứ hai lần | Ảnh, P6, P7, Video, Màn |
| `time_of_day`, `weather`, `atmosphere`, `ground` | như v3 | có thể null | — | Ảnh, P6, P7, Video, Màn |
| `ambient_sound` | chữ | có thể null | — | Video (chỉ dòng âm thanh), Màn |
| `avoid`, `shot_changes`, `assumptions` | như v3 | có thể rỗng | như v3 | Ảnh (`avoid`), P7 (`shot_changes`), Màn |

`light.description`, `support`, `landmarks`, `details`, `must_show` của v3 **không còn là trường AI viết**: PHP dựng chúng từ `lighting_plan`, từ `set_state` (vật `role = support` đang hiện diện) và từ `visible` (`MotoSetDesign::normalize`).

### `continuity[]`

| Trường | Ý nghĩa | PHP kiểm |
|---|---|---|
| `backgrounds`, `beats` | các ảnh nền và beat bị ràng buộc | mã tồn tại |
| `same_layout` | giữ đúng chỗ của mọi vật thể (chỉ nói về chỗ đứng, hướng và sự hiện diện — không nói về tình trạng) | ảnh nền của hai địa điểm khác nhau thì không được khai; đã khai thì vật thể lệch vị trí, hướng hay sự hiện diện luôn bị báo (mã 23), kể cả khi `may_change` có `objects` |
| `same_camera` | hai ảnh nền là cùng một khung | giá trị camera lệch thì báo kèm cả hai giá trị; giá trị mà một bên bỏ trống thì báo riêng — hai giá trị null không chứng minh trùng khung; vừa `same_camera` vừa cho đổi `camera` là mâu thuẫn |
| `may_change` | những gì được phép khác: `camera`, `light`, `time_of_day`, `weather`, `atmosphere`, `ground`, `objects` | thứ gì khác mà không có trong danh sách thì báo. **Hai mức** (`moto-locations-check-v6`): khác ở **giá trị cấu trúc** — enum, số, cờ, mã, và thay đổi set mà ảnh nền đã khai — thì chặn dùng (`.27`); khác ở **câu chữ** thì chỉ là ghi chú cho người duyệt (`.30`), kèm đường dẫn và hai giá trị, ghi "chưa xác minh được hai cách viết có nhất quán không" — code không biết hai câu có cùng nghĩa, nên không coi là giống, cũng không chặn. Câu chữ gồm: `time_of_day`, `weather`, `atmosphere`; `position` và `direction` của nguồn sáng, vùng sáng/tối; `position` và `look_at.description` của camera khi không có toạ độ / mã để so. Giá trị cấu trúc gồm: `ground`; vùng, toạ độ, chiều cao, tiêu cự, `look_at.ref` của camera; `kind`, `role`, `quality`, `intensity`, `tone`, `kelvin`, `in_frame`, `fixture` và số nguồn sáng, `subject_exposure`; vật thể và kiến trúc. Câu chữ của ánh sáng chỉ được so khi giá trị cấu trúc đã khớp. Xét cả `camera` và `objects` khi mục không khai `same_camera` / `same_layout` (camera lệch thì báo kèm hai giá trị). `objects` là toàn bộ trạng thái của set: sự hiện diện, vị trí, hướng và **tình trạng** (`condition`) của vật thể, cùng tình trạng của thành phần kiến trúc. Tình trạng khác nhau mà không có `objects` thì báo `objects.condition (…)`, dù có khai `same_layout` hay không. Mọi cặp ảnh nền của một mục đều được so (thứ tự liệt kê không đổi kết quả); ảnh nền của hai địa điểm chỉ so điều kiện sử dụng (`ground` chặn; buổi, thời tiết, khí quyển ghi chú). Camera không được liệt kê thì hai ảnh nền phải khai cùng dữ liệu camera — một bên khai, bên kia bỏ trống là khác nhau; cả hai bỏ trống là cùng dữ liệu. Khai `same_camera` thì chiều cao, tiêu cự, `point` và `look_at.ref` phải được cả hai nêu và bằng nhau; một hoặc cả hai bỏ trống là chưa đủ dữ liệu xác minh (`.25`), câu chữ vị trí / hướng nhìn không thay được số hay mã — nơi không có hệ toạ độ thì không khai được cùng camera. Vật thể không có mặt ở cả hai ảnh nền coi là cùng trạng thái. Chữ được so sau khi gộp khoảng trắng và bỏ dấu chấm cuối (`MotoSetDesign::comparable`); khác chữ hoa có tính. Ánh sáng so bằng `MotoSetDesign::look` theo hai mức: hai ảnh nền **cùng một khung** (mục khai `same_camera`, hoặc dữ liệu camera trùng nhau kể cả câu chữ) so giá trị `kind`, `role`, `quality`, `intensity`, `tone`, `kelvin`, `in_frame`, `fixture` của từng nguồn, số nguồn và `subject_exposure`, rồi câu chữ `position`, `direction`, vùng sáng/tối (`MotoSetDesign::wording`); **hai khung khác nhau** chỉ so phần thuộc địa điểm — giá trị `kind`, `quality`, `intensity`, `tone`, `kelvin`, số nguồn, rồi câu chữ `position` — vì `role`, `direction`, `in_frame`, `fixture`, vùng sáng/tối và `subject_exposure` do schema định nghĩa theo khung, nhìn từ camera khác thì khác dù ánh sáng không đổi. Mã nguồn, thứ tự khai, `basis`, `evidence`, `reason` không tính — cùng ánh sáng với câu trích khác nhau không phải xung đột. Trước khi so, mỗi nguồn và mỗi vùng sáng/tối được đưa về một dạng chuẩn (khóa xếp theo một thứ tự, số nguyên và số có phần thập phân coi như nhau) rồi danh sách mới được sắp — thứ tự khóa trong một object và thứ tự phần tử trong danh sách không tạo ra khác biệt |
| `requirement`, `basis`, `evidence` | yêu cầu, cơ sở, câu trích | `basis = script` thì phải có câu trích nguyên văn |

Giữ cùng địa điểm **không** đồng nghĩa giữ cùng camera.

## Dựng trạng thái bối cảnh

`MotoSetDesign::state()` lấy trạng thái chuẩn của địa điểm cộng các thay đổi hợp lệ của ảnh nền, không ghép hai câu mô tả bằng suy đoán:

- Trường nào `null` thì giữ giá trị chuẩn.
- `present: false` là vật thể không hiện diện; `true` là thêm vào.
- Đổi vị trí thì bỏ `footprint`, vì số đo chuẩn không còn định vị nó. Trạng thái giữ `base_footprint` (số đo chuẩn) và đánh dấu `unplaced` để phép so độ vừa biết vật đã có số đo nhưng nay không còn — không coi là vừa.
- Thay đổi mà mọi trường đều bằng giá trị chuẩn được coi là rỗng, không áp dụng.
- Vật thể không hiện diện ở trạng thái của ảnh nền bị loại khỏi phần "trong khung", mốc nhận diện và vật đỡ mà PHP dựng cho ảnh nền đó; danh sách mốc của địa điểm (`MotoSetDesign::landmarks`) vẫn là trạng thái chuẩn.
- Thay đổi không hợp lệ (mã lạ, vật cố định bị di chuyển hay thêm/bỏ, kiến trúc bị di chuyển hay bỏ) **không được áp dụng** và hiện ở mục "Thay đổi không áp dụng" trên màn, kèm một điều kiện chưa đạt.

## Độ vừa không gian

Giữ nguyên ba kết luận và cách giữ ảnh nền của `moto-locations-v3` (xem `moto-locations-v3-contract.md`), kể cả luật beat tái hiện không so kích thước thật của bộ phận. Hợp đồng mới thêm các phép so dùng số của vật thể, xét theo trạng thái của từng ảnh nền (`MotoLocationsCheck::objectFit`); vật đã bỏ không xét:

| Phép so | Kết quả |
|---|---|
| Vật thể có số đo nằm trong chiều dài và chiều rộng của địa điểm | vượt là mâu thuẫn, giữ ảnh nền |
| Vật thể không phải vật đỡ chủ thể không chiếm vùng chủ thể | chiếm là mâu thuẫn, giữ ảnh nền |
| Vật thể không chiếm vùng `work`, `route`, `camera` nào không phải vùng của chính nó | chiếm là mâu thuẫn, giữ ảnh nền |
| Vật đỡ chủ thể được phép đứng trong vùng chủ thể | ghi ở mục "Đã kiểm" |
| Vật thể bị dời bằng chữ (mất số đo) mà ở trạng thái chuẩn chiếm vùng chủ thể hoặc vùng `work` / `route` / `camera` không phải của nó, ở địa điểm có tường, mái hoặc sơ đồ số | chưa xác minh, giữ ảnh nền — không bao giờ ghi là vừa |
| Vật thể bị dời bằng chữ mà ở trạng thái chuẩn không cản gì | chưa xác minh, không giữ ảnh nền |

Thay đổi của `set_state` chỉ đổi được `present`, `position`, `facing`, `condition` — không có `footprint` mới, nên vật đã dời luôn dừng ở "chưa xác minh". Vì vậy prompt Phase 5 rev 8 yêu cầu thiết kế trạng thái chuẩn sao cho vùng chủ thể, lối đi và vùng thao tác đã trống, không dựa vào việc dời vật để dọn chỗ.

`RULE: PASS` chưa đủ để kết luận bộ dùng được: bàn giao còn kiểm các điều kiện chưa đạt và xung đột chặn.

## Đường bàn giao

| Bước nhận | Dữ liệu |
|---|---|
| Prompt ảnh nền | kiến trúc và bề mặt, vật thể theo trạng thái của ảnh nền, mốc nhận diện, phần trong khung, vùng chủ thể, vật đỡ, phần khác trạng thái chuẩn, bố cục, camera, khung, buổi, ánh sáng (nguồn, nguồn ngoài khung, vùng sáng, vùng tối, độ sáng chủ thể), thời tiết, khí quyển, mặt đất, cần tránh. Không có âm thanh nền |
| Phase 6 | địa điểm với mốc và vật thể (tên, loại, vai trò, cố định, vùng, vị trí, hiện diện — không gửi `material`, `identity`; hai trường này đi vào prompt ảnh nền), vùng, lối, quan hệ; ảnh nền với vùng chủ thể, buổi, thời tiết, khí quyển và trạng thái bối cảnh; continuity nguyên văn |
| Phase 7 | toàn bộ `set_design`, hệ toạ độ, vùng, lối; ảnh nền với camera, `lighting_plan` (không gửi `evidence`, `reason`), `visible`, trạng thái bối cảnh, điều kiện; `set_continuity` |
| Phase 10 | điều kiện của đúng ảnh nền có trong hình, kèm ánh sáng — mỗi nguồn với `kind`, `role`, `position`, `direction`, `quality`, `intensity`, `tone`, `kelvin`, `in_frame` — và trạng thái bối cảnh (`MotoSetDesign::conditions`) |

### Khi thời gian trôi qua giữa hai beat của cùng một địa điểm

`continuity.may_change` chỉ nói hai ảnh nền được phép khác nhau ở điều gì; nó **không** nói các beat có liền mạch về thời gian hay không. Việc đó do từng bước xử lý:

| Bước | Quy định | Phần code kiểm |
|---|---|---|
| Phase 6 (rev 14) | Đổi camera hay ánh sáng trong cùng một thời điểm không buộc tách scene; kịch bản cho biết thời gian đã trôi qua thì tách scene dù cùng địa điểm, cùng build state và `time_jump: none`. Scene mở sau khoảng thời gian đó có `continuity_from: null`, không dựng lại hành động hay thêm việc để lấp thời gian đã lược. `transition_in` loại `time_jump` chỉ dành cho beat có `time_jump` khác `none`; trường hợp này dùng `cut`, mô tả thời gian đã trôi và dẫn nguồn (ví dụ `script.beats.<beat>.link`). `time_jump` của kịch bản giữ nguyên | Chặn dùng ở bước sau: `continuity_from` nêu scene không phải scene ngay trước (`sceneIssues.5`); scene không phải scene đầu thiếu `transition_in` (`sceneIssues.transition_missing`). Chỉ ghi chú, không chặn: đường dẫn bằng chứng không có trong đầu vào đã ghim (`sceneIssues.7`). Việc có tách scene hay không, loại transition và câu trích có đúng nội dung hay không là nội dung AI viết — PHP không phán |
| Phase 7 (rev 20) | Các beat ở hai bên khoảng thời gian đã trôi qua không bao giờ là một shot liên tục | Shot không vượt ranh giới scene (`coverageIssues.2`); shot phủ nhiều beat phải đứng trên ảnh nền chung của các beat đó (`groupingIssues.2`) — hai thời điểm không có ảnh nền chung |
| Phase 10 (rev 22) | Một clip ở đúng một thời điểm của ảnh nền nó dùng; không viết thời điểm hay ánh sáng chuyển sang thời điểm khác trong clip | Điều kiện gửi đi là của đúng ảnh nền có trong hình (`MotoSetDesign::conditions`) |

## Render ảnh nền

Bộ theo hợp đồng này render theo cơ chế **ảnh chuẩn chung của địa điểm** (`loc-shared-reference-v1`). Việc dựng riêng từng ảnh nền từ prompt như hợp đồng cũ vẫn bị giữ (`MotoLocations::renderHold`); lệnh giữ chỉ được gỡ **theo từng ảnh nền**, qua các điều kiện của kế hoạch ảnh dưới đây. Không đọc được kế hoạch ảnh thì lệnh giữ còn nguyên — code không quay về cách dựng của hợp đồng cũ. Bộ `moto-locations-v1/v2/v3` vẫn render từng ảnh nền riêng như trước: prompt, mã băm, khóa định danh request, manifest và bộ chọn của chúng không đổi (định dạng do **lượt chạy đã ghim** quyết định, không phải định dạng bản phát hành đang viết).

Đợt này không thêm prompt, schema, seeder hay migration, và không chạy lại Phase 5.

### Kế hoạch ảnh (`MotoLocationImagePlan`)

- Mỗi địa điểm có **một ảnh nền chuẩn**; ảnh đang dùng của ảnh nền đó là ảnh tham chiếu của địa điểm. Mọi ảnh nền khác của địa điểm (**biến thể**) được dựng **kèm file ảnh đó**. Không có ảnh "master" nào ngoài danh sách ảnh nền, không có ảnh nhìn từ trên xuống hay ảnh toàn cảnh riêng, mỗi biến thể chỉ một ảnh nguồn.
- Số địa điểm và số ảnh nền lấy từ dữ liệu của bộ, không ghi cứng.
- **Ảnh nền nào là ảnh chuẩn — do code suy ra, không ai phải chọn** (đổi ngày 22/11 theo yêu cầu của người dùng: bỏ bước "chọn ảnh chuẩn"):
  - Địa điểm có **một** ảnh nền: ảnh nền đó (`basis = single_plate`).
  - Địa điểm có **nhiều** ảnh nền: **ảnh nền đầu tiên của địa điểm trong tài liệu mà không bị xung đột chặn** (`first`, `basis = first_plate`). Ví dụ: `BG01` và `BG02` cùng `LOC01` → render `BG01`, duyệt ảnh, rồi `BG02` dựng từ ảnh đó. Không đếm mã trong khung, không so tiêu cự, không có gợi ý.
  - Mở trang không ghi gì vào DB; ảnh chuẩn suy ra không lưu ở đâu cả.
  - Chỉ khi mọi ảnh nền của một địa điểm đều bị xung đột chặn thì địa điểm đó mới không có ảnh chuẩn (`no_standard_plate`) và không ảnh nào của nó được dựng.
- **Màn hình không có bước hay nút nào để chọn / đổi ảnh chuẩn.** Phần backend đổi ảnh chuẩn sang ảnh nền khác của địa điểm (`basis = chosen`, lưu ở `standards` — xem dưới) vẫn còn và có test, nhưng không nút nào trên màn gọi tới; không ai gọi thì không có gì được lưu.
- Sau khi render, *artifact nào* của một ảnh nền là ảnh đang dùng vẫn do người dùng chọn (bước duyệt ảnh sẵn có). Biến thể chỉ mở khi ảnh chuẩn **đã có artifact được chọn** (gọi API thành công chưa đủ).
- Vai của một ảnh nền (`standard` / `variant`) **luôn do backend suy từ kế hoạch**, không nhận từ trình duyệt.

### Ai quyết định gì khi dựng biến thể

| Nguồn | Quyết định |
|---|---|
| `set_design` của địa điểm | kiến trúc, vật liệu, nhận diện, trạng thái chuẩn |
| Ảnh chuẩn đang dùng | **căn phòng thật**: kiến trúc, bề mặt, và mọi đồ vật đứng đúng chỗ, đúng số lượng, đúng hướng như trong ảnh. Chỗ nào mô tả và ảnh lệch nhau ở thứ không được phép đổi thì ảnh đúng |
| Ảnh nền **đích** | khung hình mới: camera và phần trong khung; và những thứ `continuity` cho phép đổi |
| `continuity` của cặp (ảnh chuẩn, ảnh đích) | **chỉ những thứ nào được đổi** (`may_change`) và câu `requirement`, gửi nguyên văn |

Biến thể là một lượt **sửa ảnh** (edit) theo đúng mẫu trong hướng dẫn GPT Image (`docs/video/imagegen/references/prompting.md`, mục "Constraints and invariants": *change only X; keep Y unchanged*) — như ví dụ đổi quần áo mà giữ nguyên người: ở đây giữ nguyên căn phòng, chỉ đổi camera. Lượt render thật đầu tiên (22/11, BG01 → BG02 của Razor) dùng câu chỉ dẫn kiểu "ảnh chỉ để tham khảo vẻ ngoài, mọi thứ khác lấy từ mô tả" đã cho ra bàn xoay hướng khác, thừa một ghế và nắng gắt; câu đó đã bị thay.

Thứ được phép đổi lấy từ dữ liệu, code không tự viết:

- Có mục `continuity` nêu cả ảnh chuẩn lẫn ảnh đích (mục đầu tiên như vậy): dùng `may_change` của mục đó, và gửi kèm `requirement` nguyên văn. Ví dụ Razor BG01 → BG02: `may_change = ["camera"]` → "Change only the camera (…)" và giữ ánh sáng của ảnh.
- Không mục nào nêu cả hai (Razor BG09 → BG11): dùng danh sách `unstated` trong config (camera, ánh sáng, buổi, thời tiết, không khí, mặt nền), thêm `objects` nếu một trong hai ảnh nền có thay đổi trạng thái bối cảnh; không gửi câu `requirement` nào.
- Ánh sáng của ảnh chỉ bị giữ ("Match the lighting, shadows and colour temperature of the original image.") khi cả `light` lẫn `time_of_day` đều không được đổi — nên ảnh chuẩn ban ngày không biến ảnh nền chạng vạng thành ban ngày (BG09 → BG10).

Trạng thái bối cảnh của ảnh đích là trạng thái chuẩn của địa điểm cộng thay đổi của chính ảnh đích (`MotoSetDesign::state`), không cộng dồn thay đổi của ảnh chuẩn. Nội dung Phase 5 không bị sửa để tạo liên kết.

- **Ảnh chuẩn** dựng từ chính prompt đã duyệt của ảnh nền đó, đúng như nó được mô tả — không ép góc cao, góc rộng, camera khác, đủ mọi vật thể hay buổi / ánh sáng thuận tiện hơn. Không gửi file nào.
- **Biến thể** gửi: **byte của file** ảnh chuẩn đang dùng (không phải tên hay đường dẫn trong prompt); **chỉ dẫn sửa ảnh** của cặp (`MotoLocationImagePlan::instruction`); rồi **nguyên văn prompt đã duyệt** của ảnh nền đích. Thiếu ảnh nguồn thì dừng — không bao giờ lùi về dựng từ chữ.
- Chỉ dẫn ghép từ các câu cố định ở `config/video.php`, `video.moto.locations.shared_reference`: `instruction` (`keep`, `change`, `no_change`, `keep_light`, `frame`, `continuity`), `changes` (chữ cho từng giá trị `may_change`) và `unstated`; thiếu câu nào thì không render (`MotoLocations::sharedReference`). Khối này nằm cạnh các câu chữ render khác của Phase 5 nhưng **ngoài** khối `reference`: nó thuộc mã băm của request, không thuộc mã băm prompt đã duyệt, nên đổi nó không làm prompt nào thành cũ.

### Model theo vai

Ảnh chuẩn cần mục registry khai `generate`; biến thể cần mục khai `edit_sources` và `max_sources` đủ cho số ảnh nguồn (`MotoLocationImagePlan::modelProblem`). Backend kiểm lại trước mọi lần gọi provider. Không tự đổi model, provider hay quality; không model nào hợp thì báo rõ lý do và không tạo ô ảnh.

### Lưu lựa chọn ảnh chuẩn (chỉ khi người dùng đổi ảnh chuẩn)

Ảnh chuẩn mặc định (ảnh nền đầu tiên) không lưu gì. Chỉ khi người dùng đổi sang ảnh nền khác mới có mục dưới đây. Metadata do code ghi, không có trường nào do AI viết, không đổi schema, không migration. Nằm trong bộ chọn sẵn có của dự án (`video_projects.metadata_json.backgrounds_selection`), khóa `standards`, theo địa điểm:

```json
"standards": {
  "LOC01": {
    "mechanism": "loc-shared-reference-v1",
    "stage_id": "<stage Phase 5>",
    "content_hash": "sha256:…",
    "location": "LOC01",
    "plate": "BG01",
    "selection_version": 12,
    "chosen_by": "<admin id>",
    "chosen_at": "2026-11-22T10:00:00+07:00"
  }
}
```

- Một mục chỉ được đọc khi đúng cơ chế, đúng stage, đúng mã băm nội dung, đúng địa điểm và ảnh nền thuộc địa điểm đó; mục không đọc được thì địa điểm quay về ảnh nền đầu tiên. Bộ đang dùng đổi sang stage khác thì `standards` bị bỏ; mục của địa điểm một ảnh nền không bao giờ được đọc (code suy ra). Bộ không có lựa chọn nào thì không ghi khóa này.
- **Không có nguồn sự thật thứ hai cho artifact chuẩn**: ảnh chuẩn đang dùng chính là artifact đã chọn cho ảnh nền chuẩn trong `backgrounds_selection.images` (lượt chọn ảnh từng ảnh nền sẵn có). Kế hoạch chỉ nêu tên ảnh nền.
- Đổi ảnh chuẩn (`LocationSourceService::chooseStandard`, kiểm phiên bản bộ chọn, ghi người chọn) chỉ lưu kế hoạch ảnh: không chọn artifact, không thay cho việc duyệt nội dung, không đổi mã băm nội dung hay bộ prompt đã duyệt. Nêu đúng ảnh nền đang là ảnh chuẩn (kể cả ảnh chuẩn mặc định) thì trả `unchanged` và không ghi gì.
- Khi bộ đang xem **chưa là bộ đang dùng** (hoặc bộ prompt của nó đã đổi), thao tác đổi ảnh chuẩn trên màn đưa bộ đó vào dùng trước — đúng như nút Render làm (`approveText` với mã băm nội dung, mã băm bộ prompt và phiên bản bộ chọn mà trang đã hiện) — rồi mới lưu lựa chọn, trong **một** request và một transaction: một trong hai bị từ chối thì không ghi gì. Mở trang không ghi gì.

### Dấu vết lựa chọn đã gỡ (`released[]`)

Mỗi lần kế hoạch ảnh gỡ một lựa chọn khỏi `backgrounds_selection.images` (chọn artifact khác cho ảnh nền chuẩn, hoặc đổi ảnh nền chuẩn), một bản ghi được thêm vào `backgrounds_selection.released`, cũ trước mới sau:

```json
"released": [
  { "location": "LOC01", "plate": "BG02", "image_id": "…", "artifact_id": "…",
    "state": "source_changed", "cause": "standard_image",
    "selection_version": 14, "at": "2026-11-22T10:05:00+07:00", "by": "<admin id>" }
]
```

`state` là trạng thái lúc gỡ (`source_changed`, `role_changed`, `no_standard_image`, `no_standard_plate`); `cause` là `standard_image` (đổi ảnh của ảnh nền chuẩn) hoặc `standard_plate` (đổi ảnh nền chuẩn). Chỉ là metadata do code ghi; ảnh, lượt render và chi phí không bị đụng tới. Bộ đang dùng đổi sang stage khác thì `released` bị bỏ cùng `standards`; bộ chưa gỡ lựa chọn nào không có khóa này (bộ v1–v3 lưu y như trước).

### Dấu vết của mỗi request (`prompt_spec_json`)

| Trường | Nội dung |
|---|---|
| `reference.mechanism` | `loc-shared-reference-v1` |
| `reference.role` | `standard` / `variant` |
| `reference.location`, `reference.plate`, `reference.standard_plate` | địa điểm, ảnh nền đích, ảnh nền chuẩn |
| `reference.standard_basis`, `reference.choice_version` | `single_plate` / `chosen`, phiên bản bộ chọn của lựa chọn |
| `reference.instruction`, `reference.instruction_sha256` | chỉ dẫn cố định đã gửi (chỉ với biến thể) |
| `reference.request_sha256` | mã băm của **request thật**: vai, chỉ dẫn, mã băm prompt đã duyệt, danh sách nguồn |
| `sources[]` | `position`, `role`, `plate`, `candidate_id` (ô ảnh), `artifact_id`, `sha256` — rỗng với ảnh chuẩn, đúng một mục với biến thể |
| trường sẵn có | `request_id`, `locations_stage_id`, `locations_content_hash`, `prompt`, `prompt_sha256` (prompt **đã duyệt**), `provider`, `model`, `size` / `quality` / `aspect_ratio` / `image_size`, `variations`, `attempt` (mã thao tác) |

`prompt_sha256` (nội dung đã duyệt) và `reference.request_sha256` (request thật) là hai mã băm khác nhau. Khóa định danh request (`DesignImageStore::createLocationReference`) của bộ theo cơ chế gồm thêm `reference` và `sources`: cùng prompt nhưng khác nguồn, khác vai hay khác cơ chế là request khác. Lượt render ghi vào `video_renders.request_json` thứ **đã thật sự gửi**: dấu vết và từng nguồn kèm SHA-256, số byte, tên tệp.

### Khi nào ảnh hết dùng được

Chỉ khi nguồn **đang dùng** thật sự đổi:

- Render thêm một ảnh cho ảnh nền chuẩn, một ô lỗi, một ảnh chưa chọn, hay chọn lại đúng artifact cũ: không đổi gì.
- Chọn **artifact khác** cho ảnh nền chuẩn: lựa chọn của các biến thể **thuộc địa điểm đó** được gỡ khỏi bộ chọn, ảnh của chúng mang trạng thái `source_changed` — vẫn giữ nguyên file, lượt render, chi phí; không chọn lại được, không bàn giao; địa điểm khác không đụng tới; không xóa gì, không tự render lại.
- File nguồn mất hoặc lệch SHA-256: các ảnh phụ thuộc bị chặn (`source_unusable`); lựa chọn của chúng không bị gỡ.
- **Đổi ảnh nền chuẩn** là một thao tác riêng trên kế hoạch (cùng `chooseStandard`): mọi ảnh được giữ; lựa chọn chỉ còn ở ảnh nền nào có ảnh được dựng đúng vai và đúng nguồn theo kế hoạch mới. Ảnh dựng làm biến thể không bao giờ trở thành ảnh chuẩn, nên không có vòng phụ thuộc.

### Một phép kiểm nguồn dùng ở mọi cổng

`LocationSourceService::referenceOf` (kết luận ở `MotoLocationImagePlan::verdict`) được gọi khi: tạo request, gửi lại ô ảnh, job trong hàng đợi ngay trước khi gọi provider, chọn artifact làm ảnh đang dùng, lập manifest bàn giao. Điều kiện: bộ và nội dung vẫn là bộ đang dùng và dùng được; ảnh nền thuộc đúng địa điểm của tài liệu; vai khớp kế hoạch hiện tại; ảnh chuẩn thuộc cùng dự án, stage, địa điểm; đã được chọn, file còn và khớp SHA-256; prompt và thiết lập còn hợp lệ; model khai đúng năng lực; ảnh nền không bị chặn.

- Nguồn đổi **trước khi gửi**: dừng trước HTTP, ô ảnh ghi lý do. Nguồn mới không bao giờ được lắp vào ô cũ.
- Nguồn đổi **trong lúc provider đang xử lý**: kết quả và chi phí vẫn được lưu kèm nguồn đã gửi; ảnh mang trạng thái `source_changed`, không tự được đưa vào dùng.
- Không giữ khóa DB trong lúc chờ API; lease của ô ảnh và chốt chống gọi trùng giữ nguyên.

### Bàn giao

Ảnh có file hợp lệ của đúng ảnh nền vẫn chưa đủ nếu nó dựng từ ảnh chuẩn không còn dùng, hoặc ảnh chuẩn nó dựa vào không còn dùng được. Có **hai nơi** thực thi điều này, cùng một phép kiểm (`LocationSourceService::judge` → `MotoLocationImagePlan::verdict`), không có bản sao luật thứ hai:

| Nơi | Ai đọc | Thực thi |
|---|---|---|
| `LocationSourceService::manifest($text, $bytes)` / `approvedSource()` | bàn giao cả bộ (`approvedSource()`, `$bytes = true`) và màn Phase 5 (`manifest($text, false)` + `approvedSet()`) | Mỗi hàng của bộ theo cơ chế có thêm `reference`: vai, ảnh nền chuẩn, nguồn mà ảnh đã chọn **thật sự dùng**, trạng thái và có còn khớp kế hoạch hay không; phần bàn giao mang `reference` (cơ chế, vai, địa điểm, ảnh nền chuẩn, nguồn). Thiếu ảnh hợp lệ thì không bàn giao. `approvedSource()` **đọc và băm file** (mỗi file chỉ mở một lần mỗi lượt). **Màn Phase 5 không mở file ảnh**: trang tính manifest đúng một lần mỗi request ở chế độ chỉ kiểm file còn hay không, rồi dựng cả phần tóm tắt bộ lẫn các hàng ảnh nền từ kết quả đó — trang báo được file bị mất, không báo byte bị đổi (áp dụng cho mọi định dạng, kể cả v1–v3) |
| `LocationSourceService::handedImages()` | các bước sau Phase 5 lấy ảnh nền đã chọn: Phase 7 (`MotoShots::chosenPlates` → `resources()`), keyframe (`MotoKeyframeSourceService::plates`), lệnh `video:probe-omni` | Bộ theo cơ chế: chỉ trao ảnh còn thuộc kế hoạch — ảnh của ảnh nền chuẩn khi nó được dựng làm ảnh chuẩn; ảnh của biến thể **chỉ khi** nó dựng từ đúng artifact đang chọn cho ảnh chuẩn và artifact đó còn dùng được. Biến thể có ảnh chuẩn mất file, lệch mã băm đã lưu, hoặc prompt ảnh chuẩn đã cũ thì **không được trao** dù lựa chọn vẫn còn; ảnh chuẩn và địa điểm khác vẫn được trao. Không đọc được bộ đang dùng thì không trao ảnh nào. **Đọc và băm file ảnh chuẩn** mà các ảnh được trao phụ thuộc, mỗi file tối đa một lần mỗi lượt gọi: ảnh chuẩn bị ghi đè byte khác dưới cùng hàng dữ liệu thì biến thể của nó không được trao |

- Bộ đang dùng theo cơ chế được nhận ra từ chính bộ chọn: `backgrounds_selection.text.mechanism = loc-shared-reference-v1`, ghi lúc đưa bộ vào dùng (`approveText`). Bộ v1–v3 không có khóa này, nên `handedImages()` trả đúng `images` của bộ chọn — cùng một truy vấn như trước, không thêm truy vấn hay lần đọc file nào.
- Chi phí của `handedImages()` với bộ theo cơ chế: đọc lại bộ đang dùng một lần (`approvedText()`), cộng hai truy vấn theo danh sách id (ô ảnh và artifact đã chọn) và một lần đọc file cho mỗi ảnh chuẩn có ảnh được trao; số truy vấn không tăng theo số ảnh nền đã chọn. `MotoShots::chosenPlates()` tìm mọi artifact đã chọn bằng một truy vấn `whereIn`.
- Nơi có đọc byte: render và retry / hàng đợi (`sendable`), chọn ảnh (`choose`), `approvedSource()`, `handedImages()`. Nơi không đọc byte: dựng trang (`imagePlan`, `manifest($text, false)`).
- **Gỡ lựa chọn theo nguồn đang dùng**: hễ artifact đang chọn cho ảnh nền chuẩn không phải nguồn mà ảnh của một biến thể đã dùng thì biến thể đó là `source_changed` và lựa chọn của nó bị gỡ (ghi `released[]`) — kể cả khi ảnh chuẩn lúc đó không dùng được vì lý do khác (prompt cũ, mất file). `source_unusable` chỉ dành cho trường hợp ảnh biến thể dựng từ **đúng** artifact đang chọn mà artifact đó không dùng được: khi đó biến thể bị chặn, lựa chọn được giữ.
- Artifact và ô ảnh đã chọn được đọc một lần theo danh sách id rồi tra trong bộ nhớ. Phép kiểm mã băm phản hồi của provider và chuẩn hóa `colour: null` (`MotoLocationsAnswer`) không đổi.

### Dữ liệu cho màn

`LocationSourceService::imagePlan($text)` (không mở file ảnh, hai truy vấn bất kể số ảnh nền) trả theo từng địa điểm: danh sách ảnh nền, ảnh nền đầu tiên dựng được (`first`), lựa chọn đổi ảnh chuẩn nếu có (`choice`), ảnh nền chuẩn và căn cứ (`basis`: `single_plate`, `first_plate`, `chosen`), artifact chuẩn đang dùng, trạng thái (`no_standard_plate`, `no_standard_image`, `source_unusable`, `ready`), và theo từng ảnh nền (`rows`): vai, năng lực model cần, có render được không và vì sao (`can_render`, `reason`; `plan_problem` là lý do riêng của kế hoạch ảnh), ảnh đã chọn còn khớp không, và — khi ảnh nền chưa có ảnh được chọn — lựa chọn gần nhất đã bị gỡ (`released`); `images` cho từng ô ảnh của bộ (kể cả `source_changed`).

Màn Phase 5 đọc `shown.image_plan`: kế hoạch của **bộ đang xem** — của bộ đang dùng khi hai bộ là một, còn không thì cùng hình dạng đó dựng từ bộ đang xem với bộ chọn ảnh rỗng (chưa có ảnh nào được chọn cho nó). `null` với bộ không theo cơ chế; khi đó màn vẽ y như trước. Ngoài ra: `image_plan` (bộ đang dùng), `shown.image_places` (kế hoạch thuần của bộ đang xem) và `media.roles` (id model theo vai).

### Màn Locations & Sets với bộ theo cơ chế

Chỉ bộ theo cơ chế mới đổi cách hiện; bộ v1–v3 hiện y như trước.

- Các hàng ảnh nền được **xếp theo địa điểm**, ảnh nền chuẩn đứng trước các biến thể của nó. **Không có khối riêng cho địa điểm** (khối `#place_LOCxx` với nhãn trạng thái, ảnh thu nhỏ và nút đổi ảnh chuẩn đã gỡ ngày 22/11 theo yêu cầu của người dùng — nó chỉ lặp lại điều các hàng đã nói). Không có bước chọn, không có gợi ý, không có số đếm mã trong khung.
- Luồng trên màn: hàng ảnh nền chuẩn có nút Render ngay → duyệt một ảnh → hàng biến thể mới hiện nút Render (trước đó hiện lý do: "Ảnh chuẩn BG01 của LOC01 chưa có ảnh được duyệt — render BG01 rồi duyệt một ảnh trước…"). Ảnh chuẩn mất file hay prompt cũ thì lý do hiện ngay trên hàng của ảnh nền chuẩn và trên từng biến thể.
- Từng ảnh nền có dòng "Kế hoạch ảnh": vai (`ẢNH CHUẨN` / `BIẾN THỂ`), biến thể sẽ dựng kèm ảnh nào, và lựa chọn cũ đã gỡ nếu có. Từng ảnh đã render ghi nguồn nó thật sự dùng ("Nguồn: BGxx · mã ô" hoặc "Dựng từ prompt"); ảnh không còn khớp kế hoạch mang nhãn **Nguồn đã đổi** / **Khác kế hoạch ảnh**, không có nút Duyệt, và ô lỗi của nó không có nút Render lại.
- Ô thiết lập render chỉ hiện khi kế hoạch cho phép; chưa render được thì hiện lý do (ảnh chuẩn chưa có ảnh được duyệt, ảnh chuẩn không dùng được, mọi ảnh nền của địa điểm bị giữ). Danh sách model lọc theo vai từ `media.roles`: ảnh chuẩn — mọi model khai `generate`; biến thể — chỉ model khai `edit_sources`. Không model nào hợp thì nói rõ. Trang không gửi vai; backend luôn suy vai từ kế hoạch và bỏ qua mọi trường `role` / `mode` gửi lên.
- Đổi ảnh chuẩn — **chỉ còn route, không có nút trên màn**; xong thì về `#ref_BGxx`: `POST admin/video-projects/{id}/locations/standard` (`video-projects.locations-standard`, cùng nhóm route, xác thực admin và CSRF với các thao tác Phase 5 khác; `VideoProjectsController::chooseLocationStandard`) với `location`, `plate`, `stage_id`, `content_hash`, `prompts_hash`, `selection_version`, `operation_id`. Từ chối kèm lý do khi: trang cũ (phiên bản bộ chọn hoặc mã băm không còn khớp), ảnh nền không thuộc địa điểm, địa điểm chỉ có một ảnh nền, ảnh nền bị xung đột chặn, bộ không dùng được. Chọn lại đúng ảnh nền đang là ảnh chuẩn thì báo "không đổi gì" và không ghi.
- Dựng trang không truy vấn theo từng ảnh nền cho nguồn hay ảnh chuẩn và không mở file ảnh để vẽ kế hoạch; số truy vấn của trang không tăng theo số ảnh đã chọn.

## Dữ liệu cũ

- Bộ v1, v2, v3 tiếp tục được đọc, kiểm, lập kế hoạch và ghép prompt theo hợp đồng lượt chạy của chính nó; mã băm không đổi.
- Không có gì tự chuyển dự án sang hợp đồng mới; bộ đang dùng chỉ đổi khi người dùng duyệt bộ khác.
- Lượt chia scene mới và lập shot mới của bản v8 cần một bộ `moto-locations-v4`; gặp bộ cũ thì dừng trước khi gọi model và nói rõ các bản đã lập vẫn dùng được.
- Revision 7, 13, 19, 21 của bản v7 giữ nguyên nội dung và vẫn `published`, nên lượt chạy đã lưu dưới chúng vẫn đọc được.
