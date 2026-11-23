# Hợp đồng dữ liệu Phase 5 — bộ hồ sơ địa điểm (moto-locations-v3)

Áp cho bản phát hành `moto-cinematic-3s-v7`. Prompt: Phase 5 revision 7 (revision 6 giữ làm lịch sử; revision 7 chỉ nói rõ mọi trường bắt buộc đều phải ghi, `ground` là `null` khi beat không nêu mặt đường). Schema: `MotoLocations@3.0.0`. Đầu vào: `moto-locations-input-v4`. Bộ lập kế hoạch: `moto-locations-plan-v2`. Các bước nhận bàn giao: Phase 6 revision 13, Phase 7 revision 19, Phase 10 revision 21 (`moto-clip-prompt-input-v10`). Bản cũ (`moto-locations-v2`, revision 5 trở về trước) giữ nguyên cách kiểm, cách ghép prompt và mã băm.

Nhánh mới được chọn theo định dạng đã ghim trong ngữ cảnh của lượt chạy (`context.format`), không đoán theo việc JSON có trường mới hay không.

## Bản cũ và việc chuyển sang hợp đồng mới

- Một bộ địa điểm đã lưu luôn được kiểm lại, lập kế hoạch và ghép prompt theo hợp đồng chính lượt chạy của nó đã ghim (manifest, ngữ cảnh, định dạng), không theo định dạng bản phát hành đang viết. `MotoLocations::formats()` khai các định dạng một bản phát hành còn đọc: bản phát hành dùng bộ hồ sơ đọc cả `moto-locations-v3` và `moto-locations-v2`.
- Bộ `moto-locations-v2` đang dùng của một dự án v7 vẫn là bộ đang dùng: cùng kết quả kiểm, cùng kế hoạch, cùng prompt và mã băm, không ảnh nền nào thành lỗi thời. Các bản scene, shot đã lập từ nó vẫn hợp lệ.
- Không có gì tự chuyển dự án sang hợp đồng mới. Dự án chỉ chuyển khi người dùng lập một bộ hồ sơ địa điểm mới và duyệt nó làm bộ đang dùng.
- Lượt mới của Phase 5 luôn viết theo hợp đồng hiện hành của bản phát hành.
- **Giới hạn còn giữ:** bộ cũ được đọc, kiểm, giữ làm bộ đang dùng và phục vụ mọi thứ đã lập từ nó, nhưng không chạy được mọi thao tác. Lượt chia scene mới và lập shot mới của bản phát hành dùng bộ hồ sơ cần một bộ `moto-locations-v3`; gặp bộ `moto-locations-v2` thì dừng trước khi gọi model và nói rõ phải lập, duyệt bộ địa điểm mới. Muốn bộ cũ chạy tiếp cả lượt mới thì phải tách bản phát hành riêng; đợt này không làm.

## Thứ tự nguồn

- Thiết kế quyết định xe có gì, hình dáng và khả năng vận hành.
- Kịch bản quyết định hành động, người tham gia, thứ tự và thời gian câu chuyện.
- Phase 5 quyết định môi trường trong các giới hạn đó. Hai nguồn mâu thuẫn thì khai xung đột; giả định sản xuất không đổi dữ kiện nguồn.

## Đầu vào do PHP dựng

| Nhóm | Nội dung | Nguồn |
|---|---|---|
| `variables` | `aspect_ratio`, `platform`, `visual_style` (phong cách code áp cho mọi ảnh nền) | Kịch bản đã ghim; cấu hình `video.moto.locations.reference` |
| `story` | `theme`, `emotional_goal`, `hero_beat`, `loops` | Kịch bản |
| `characters` | `id`, `role` (tên vai) | Kịch bản và thiết kế |
| `beats` | `id`, `act`, `state`, `checkpoint_start`, `checkpoint_end`, `temporary`, `time_jump`, `location`, `time_light`, `visual`, `purpose`, `reveal`, `link`, `sfx`, `on_screen`, `events` (`id`, `action`, `result`, `character_ids`, `vehicle_motion`), `situation`, `subjects` (kèm kích thước), `operations`, `conditions`, `limits` | Kịch bản; kích thước và giới hạn tính từ thiết kế |
| `build_states` | Hai giai đoạn thiết kế và từng checkpoint của giai đoạn vật lý | Thiết kế |
| `motorcycle` | `size` (dài, rộng, cao từ hộp bộ phận) | Thiết kế |
| `source_flags` | Các `flags` kịch bản còn để ngỏ | Kịch bản |
| `avoid_names` | Tên nguồn không được dùng | Phase 1 |

## Đầu ra do AI viết

Ký hiệu nơi dùng: **Ảnh** = prompt ảnh nền do PHP ghép; **P6** = đầu vào Phase 6; **P7** = đầu vào Phase 7; **Video** = bước viết prompt video; **Màn** = màn Địa điểm.

### `locations[]` — đặc điểm cố định

| Trường | Kiểu | Rỗng | PHP kiểm | Nơi dùng |
|---|---|---|---|---|
| `id` | `LOCnn` | không | không trùng | Ảnh, P6, P7, Màn |
| `name`, `kind` | chữ, danh sách | không | — | P6, P7, Màn |
| `function` | chữ | không | — | P6, Màn |
| `source_beats` | danh sách beat | ít nhất một | beat tồn tại; lệch với ảnh nền thì ghi chú | Màn |
| `setting` | `interior`, `exterior`, `mixed` | không | — | Ảnh (chọn phong cách), P6, P7, Màn |
| `provenance` | `kind` + `source` | `source` null trừ địa điểm thật | địa điểm thật bị chặn vì đầu vào không có nguồn | Màn |
| `space`, `surfaces`, `fixed_equipment`, `palette` | chữ, danh sách | `fixed_equipment` có thể rỗng | — | Ảnh, P7, Màn |
| `dimensions` | ba số đo, mỗi số có `basis` (`source`, `production_assumption`, `unknown`) | `value` null khi `unknown` | `value` khớp `basis` (null khi `unknown`, số lớn hơn 0 trong các trường hợp khác); vùng không vượt số đo; từng chủ thể không cao hơn trần; phòng có chủ thể đứng phải khai chiều cao | Ảnh, P7, Màn |
| `spatial_layers` | ba lớp chữ | không | — | Ảnh, P7, Màn |
| `fixed_landmarks` | danh sách chữ | ít nhất một | mốc của ảnh nền phải lấy nguyên văn từ đây | P6, P7, Màn |
| `coordinate_system` | gốc, trục x, trục y | null khi không có sơ đồ số | có `footprint` thì bắt buộc có | P7, Màn |
| `zones[]` | `id` `Znn`, `name`, `kind`, `purpose`, `position`, `footprint`, `subject_axis` | `footprint` và `subject_axis` có thể null | không trùng mã; `footprint` có `x_m`, `y_m` từ 0 trở lên và hai cạnh lớn hơn 0; số đo vừa địa điểm; vùng chủ thể không chồng vùng chặn | Ảnh (vị trí vùng chủ thể), P6, P7, Màn (kèm sơ đồ) |
| `access_routes[]` | `id` `Rnn`, `name`, `kind`, `leads_to`, `zone`, `clear_width_m`, `used_by` | `zone` có thể null | không trùng mã; `zone` tồn tại; `clear_width_m` khớp `basis`; lối xe dùng không hẹp hơn xe và phải có bề rộng ở nơi có beat cho thấy xe | P6, P7, Màn |
| `camera_access` | chữ | không | — | P6, P7, Màn |
| `assumptions` | danh sách | có thể rỗng | — | Màn |

### `connections[]` — quan hệ giữa các địa điểm

| Trường | Kiểu | Rỗng | PHP kiểm | Nơi dùng |
|---|---|---|---|---|
| `id` | `CNnn` | không | không trùng | P6, P7, Màn |
| `from`, `to` | mã địa điểm | không | tồn tại, khác nhau | P6, P7, Màn |
| `relation` | `same_site`, `adjacent`, `separate`, `unknown` | không | — | P6, P7, Màn |
| `route` | chữ | null khi chỉ cắt cảnh | — | P6, P7, Màn |
| `used_by` | `people`, `vehicle`, `both`, `cut_only` | không | — | P6, P7, Màn |
| `basis`, `evidence` | `script` kèm câu trích, hoặc `assumption` | `evidence` null khi là giả định | câu trích có nguyên văn trong trường của beat | Màn |
| `note` | chữ | có thể null | — | Màn |

### `backgrounds[]` — ảnh nền và điều kiện sử dụng

| Trường | Kiểu | Rỗng | PHP kiểm | Nơi dùng |
|---|---|---|---|---|
| `id`, `location`, `uses` | mã, danh sách beat | không | mã tồn tại; mọi beat được phủ | Ảnh, P6, P7, Màn |
| `sharing`, `composition` | chữ | không | — | Ảnh (`composition`), P7, Màn |
| `subject_zone` | mã vùng | null khi khung không có chủ thể | tồn tại và là vùng `subject`; từng chủ thể của các beat vừa vùng theo trục | Ảnh (vị trí vùng), P6, P7, Màn |
| `landmarks` | danh sách chữ | có thể rỗng | nguyên văn từ `fixed_landmarks` | Ảnh, P7, Màn |
| `time_of_day` | chữ | có thể null | — | Ảnh, P6, P7, Video, Màn |
| `light` | `description`, `direction`, `quality`, `evidence`, `reason` | `direction`, `quality` có thể null | câu trích có nguyên văn | Ảnh, P6 (mô tả), P7, Màn |
| `weather`, `atmosphere` | chữ | có thể null | — | Ảnh, P6 (`weather`), P7, Video, Màn |
| `ground` | `dry`, `wet` | có thể null | — | Ảnh, P6, P7, Màn |
| `ambient_sound` | chữ | có thể null | — | Video (chỉ vào dòng âm thanh), Màn; không vào prompt ảnh nền, không vào prompt keyframe |
| `support` | danh sách thiết bị tạm | có thể rỗng | nằm trong `temporary` của từng beat | Ảnh, P6, P7, Màn |
| `details`, `must_show`, `avoid` | danh sách | có thể rỗng | câu trích của `details` có nguyên văn | Ảnh, P7 (`details`, `must_show`), Màn |
| `shot_changes`, `assumptions` | danh sách | có thể rỗng | beat thuộc ảnh nền | P6, P7 (`shot_changes`), Màn |

`unplated`, `conflicts`, `continuity` giữ cấu trúc cũ. Đường dẫn bằng chứng của xung đột nhận thêm sự kiện (`beats.Bn.events.EVnn.…`), checkpoint (`build_states.BS0n-CPnn.…`), kích thước xe (`motorcycle.size.…`) và `source_flags.n`.

## Đường đi của điều kiện ảnh nền tới bước viết prompt video

1. Phase 5 lưu `time_of_day`, `weather`, `atmosphere`, `ambient_sound` trong từng ảnh nền.
2. Lượt lập shot ghim các điều kiện này theo mã ảnh nền trong ngữ cảnh của nó (`plate_conditions`, `MotoShots::plateConditions`), tách khỏi nhóm `backgrounds` mà prompt keyframe đọc.
3. Phase 10 (`moto-clip-prompt-input-v10`) gửi nhóm `environment` kèm mã nguồn `S25`. Điều kiện luôn là của ảnh nền có trong hình, để ảnh và điều kiện không bao giờ chỉ tới hai nền khác nhau:
   - Có ảnh nền gửi kèm: lấy điều kiện của đúng ảnh nền đó (`environment.image` ghi nhãn ảnh, ví dụ `Image 3`), kể cả khi người dùng đã chọn nền khác với nền trong kế hoạch shot. Ảnh gửi kèm không rõ là nền nào thì mọi điều kiện là null, không lấy của nền trong kế hoạch.
   - Clip dựng từ ảnh tham chiếu mà không gửi ảnh nền: lấy điều kiện của nền kế hoạch shot ghi; `image` là null.
   - Clip dựng từ keyframe: bước này không được cho biết keyframe dùng nền nào, nên không gửi điều kiện.
   - Shot không đứng trên ảnh nền nào, hoặc kế hoạch shot lập trước bộ hồ sơ: mọi giá trị là null. Code không tự viết điều kiện.
4. Prompt revision 21 dùng buổi, thời tiết, khí quyển như dữ kiện của hình; `ambient_sound` chỉ vào dòng âm thanh, đứng trước tiếng của các sự kiện.

## Số đo hợp lệ

Bộ kiểm cấu trúc chung không xét giới hạn số, nên Phase 5 kiểm riêng trước khi tính độ vừa:

- Số đo (`dimensions`, `clear_width_m`): `basis` là `unknown` thì `value` phải null; các `basis` khác thì `value` là số lớn hơn 0.
- `footprint`: `x_m`, `y_m` từ 0 trở lên; `size_x_m`, `size_y_m` lớn hơn 0.

Số không hợp lệ làm cả bộ chưa đủ điều kiện dùng, và không được đem tính độ vừa (coi như chưa khai).

## Kết luận về độ vừa không gian

PHP chỉ so các con số đã khai, không mô phỏng chuyển động (`MotoLocationsCheck::fit`). Với mỗi ảnh nền có beat cho thấy chủ thể, từng phép so dưới đây kết thúc theo một trong ba cách và không phép so nào bị bỏ qua trong im lặng: đã so và đạt (ghi ở "Đã kiểm"), đã so và mâu thuẫn, hoặc thiếu số nên chưa xác minh.

| Phép so | Số cần có |
|---|---|
| Ảnh nền có vùng chủ thể dùng được | `subject_zone` là vùng `subject`, có `footprint` hợp lệ và `subject_axis` |
| Vùng chủ thể nằm trong địa điểm | `dimensions.length_m`, `dimensions.width_m` (ngoài trời không khai thì không xét) |
| Vùng chủ thể không chồng vùng không dùng được | `footprint` của từng vùng `blocked` |
| Từng chủ thể vừa vùng theo trục | chiều dài, chiều rộng của chủ thể |
| Từng chủ thể đứng lọt dưới trần | `dimensions.height_m`, chiều cao của chủ thể (ngoài trời không khai chiều cao thì không xét) |
| Xe đi lọt từng lối xe dùng, ở ảnh nền có beat cho thấy xe | `clear_width_m` của lối, bề rộng xe |

| Kết luận | Khi nào | Hệ quả |
|---|---|---|
| Phù hợp theo dữ liệu đã khai | Có ít nhất một phép so đã làm, không phép so nào mâu thuẫn hay chưa xác minh | Không |
| Mâu thuẫn với dữ liệu đã khai | Một phép so cho kết quả trái nhau | Giữ các ảnh nền bị ảnh hưởng; Phase 6 nhận như xung đột chặn |
| Chưa đủ dữ liệu để xác minh | Một phép so thiếu số | Không bao giờ ghi là phù hợp; màn hiện phần chưa xác minh và phần đã kiểm được; có giữ ảnh nền hay không theo bảng dưới |

Thiếu số thì giữ ảnh nền khi hành động bắt buộc của kịch bản không thể chứng minh là làm được nếu thiếu số đó. Số thuộc Phase 5 hay thuộc thiết kế đều xét như nhau: Phase 5 không tự đặt số của thiết kế, và cũng không cho qua.

| Số còn thiếu | Giữ ảnh nền khi | Chỉ báo khi |
|---|---|---|
| `subject_zone` của ảnh nền có beat cho thấy chủ thể; `footprint` hoặc `subject_axis` của vùng đó | địa điểm giới hạn chỗ đặt chủ thể: có tường hoặc mái (`interior`, `mixed`), hoặc tự khai sơ đồ số (`coordinate_system`) | nền ngoài trời không có sơ đồ số |
| Chiều dài hoặc chiều rộng của địa điểm | địa điểm có tường hoặc mái | — (ngoài trời không xét) |
| Chiều cao của địa điểm | chủ thể đứng trong phòng (`interior`) | địa điểm chỉ có mái một phần (`mixed`) |
| `footprint` của một vùng không dùng được | — | luôn chỉ báo: vị trí của nó chỉ tả bằng chữ |
| Bề rộng lối xe dùng, hoặc bề rộng xe | địa điểm có beat cho thấy xe: việc xe đi qua lối là hành động bắt buộc | không beat nào ở địa điểm đó có xe |
| Chiều dài, chiều rộng của **xe hoặc mô hình** (build state đặt nó vào vùng chủ thể) | địa điểm giới hạn chỗ đặt chủ thể | nền ngoài trời không có sơ đồ số |
| Chiều cao của **xe hoặc mô hình** | chủ thể đứng trong phòng (`interior`) | địa điểm không phải phòng kín |
| Kích thước của **bộ phận được nêu tên** | — | luôn chỉ báo: đầu vào không nói bộ phận nằm trên xe hay để rời, nên chưa có hành động bắt buộc nào cần số đó. Kích thước đã có thì vẫn được so như mọi chủ thể |

**Beat tái hiện.** Khi kịch bản ghi tình huống của beat là `representation` (`situation.mode`), bộ phận được nêu tên là bản vẽ, màn hình hoặc mô hình của chính nó, không phải vật đặt trong địa điểm. Kích thước thật của nó không được đem so với gì, và màn ghi rõ ở mục "Không so" những bộ phận nào bị bỏ ra. Xe và mô hình vật lý luôn được so. Bộ phận xuất hiện cả ở một beat không phải tái hiện của cùng ảnh nền thì vẫn được so. Ảnh nền mà mọi chủ thể đều chỉ là bản vẽ thì không có kết luận độ vừa.

Luật này có từ bộ kiểm `moto-locations-check-v5`, sau lượt thật thứ hai của Razor: ảnh nền studio bị giữ vì ghi đông vẽ trên giấy rộng hơn vùng "tường treo bản in" 2 cm.

Ba mức kiểm tách riêng trên màn: cấu trúc (schema), dữ kiện (điều kiện để dùng ở bước sau), nội dung (ghi chú cho người duyệt). PASS cấu trúc không phải duyệt nội dung.
