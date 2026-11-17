**ĐẶC TẢ XEM TRƯỚC — CUSTOM MOTORCYCLE → REFERENCE-TO-VIDEO 3 GIÂY**

Phiên bản tài liệu: 1.1 — 12/11/2026. Bổ sung phụ lục chốt kỹ thuật và làm rõ cách chọn prompt tương thích; không thay đổi yêu cầu sản phẩm.

Trạng thái: bản chốt đề xuất để chủ dự án xem trước; chưa phải xác nhận đã triển khai. Các trường và phiên bản mới dưới đây là yêu cầu thực hiện, không phải mô tả chúng đã tồn tại trong code.

**1. Mục tiêu và phạm vi**

Hệ thống tạo các concept xe custom có chủ đề và nhận diện mạnh, rồi kể quá trình hình thành, thiết kế, chế tạo, hoàn thiện và trải nghiệm qua BS01–BS12. Mỗi video shot dài 3 giây, quay liên tục, có camera di chuyển và diễn biến đọc được trong khung.

Ảnh mẫu xác định mức độ sáng tạo: chrome khắc, patina và da, kết cấu tổ ong, hình khối bánh/ghi đông/bình đặc trưng, đồ họa trang trí và bối cảnh có ánh sáng rõ. Mỗi dự án tạo một concept thống nhất; không trộn nhiều xe mẫu thành một bộ reference.

Các quyết định cố định:

| Nội dung | Quy định |
|---|---|
| Tổng thời lượng phim | Không đặt mục tiêu 30 giây hoặc tổng thời lượng cố định. |
| Thời lượng shot | Render 3000 ms; sử dụng toàn bộ khoảng 0–3000 ms. |
| Nguồn chuyện | Thiết kế được duyệt, đủ BS01–BS12. |
| Nhân vật | Sinh từ kịch bản và vai trò trong thiết kế; có ID ổn định. |
| Khuôn mặt/toàn thân | Được phép; cách xuất hiện theo shot và bảo hộ công việc. |
| Logo/tên hãng | Không xuất hiện trên xe, người, dụng cụ, bối cảnh, chữ chèn hoặc âm thanh của sản phẩm. |
| Nhận diện nhân vật | Dùng ảnh nhân vật của dự án được tạo theo kịch bản và đã duyệt. |
| Ảnh gửi video | Nguyên master đã duyệt; không tự chuyển sang crop từng ô. |
| Render hiện tại | Reference-to-Video bằng Omni 1.2 Flash. |
| Keyframe | Để sau; không là điều kiện để viết prompt hay render video của profile mới. |
| Camera | Di chuyển xuyên suốt một cú máy; không có điểm dừng có chủ ý hoặc cắt cảnh nội bộ. |
| Nhịp 0,5 giây | Dùng khi người dùng chốt cụ thể; không bắt mọi shot có sáu nhịp. |
| Lượt thử Omni ba shot | Không được duyệt; không nằm trong công việc phải thực hiện. |

Chưa làm: mở khóa thiết kế cũ; tự chuyển Scene đã sửa thành Shot không gọi AI; bộ ảnh xe trong bối cảnh 8 góc + 4–5 ảnh cận bắt buộc; tự gửi canary; tự sửa video bằng model; tự render lại khi nguồn đổi; nâng cấp các category khác.

**2. Luồng dữ liệu và người sở hữu quyết định**

```text
Brief sáng tạo + nguồn Phase 1
    → Phase 2: DNA
    → Phase 3: thiết kế + model sheet được duyệt
    → Phase 4: kịch bản, nhân vật, sự kiện
    → Phase 5: địa điểm và bối cảnh
    → Phase 6: scene và dàn cảnh
    → Phase 7: shot 3 giây, timeline hành động, camera path
    → Phase 8: master tham chiếu được duyệt
    → Phase 10: prompt video được duyệt → request → clip → duyệt clip
```

| Quyết định | Bước sở hữu |
|---|---|
| Chủ đề và định hướng thẩm mỹ | Phase 2. |
| Hình học, cấu tạo, vật liệu, đồ họa, trạng thái và vai trò | Phase 3. |
| Câu chuyện, nhân vật và thứ tự sự kiện | Phase 4. |
| Không gian, ánh sáng và vùng tiếp cận | Phase 5. |
| Bố trí người/vật, công việc, vật đỡ và liên tục giữa cảnh | Phase 6. |
| Khung đầu/cuối, thời gian hành động và đường camera | Phase 7. |
| Ảnh nào cung cấp nhận diện, hình học, bề mặt, nhân vật và môi trường | Phase 8 cùng bộ phân giải nguồn. |
| Diễn đạt thành prompt tiếng Anh hoàn chỉnh | Phase 10. |
| Kiểm JSON, ID, thời gian, nguồn, file và gửi request | Laravel. |
| Đánh giá tính hấp dẫn, đúng hình và diễn xuất | AI tự kiểm trong lượt viết; người dùng duyệt nội dung/ảnh/video. |

Không dùng PHP để viết hoặc sửa văn xuôi, suy luận việc cơ khí từ từ khóa, nhận dạng logo trong ảnh hoặc tự quyết định một hành động là đẹp/tự nhiên. Không thêm một lượt AI judge tự động có phí.

**3. Release, phiên bản và dự án cũ — làm trước các phase**

3.1. Giữ workflow hiện có `harley_custom_v1` làm khóa nội bộ. Tên này không được đưa thành chữ lên ảnh/video.

3.2. Thêm release kỹ thuật `moto-cinematic-3s-v1` cho dự án mới. Lưu tại `VideoProject.metadata_json.moto_pipeline_release`. Không thêm màn hình chọn release.

Release ghi: mã release, hợp đồng đầu vào/đầu ra và hash tương ứng, các phiên bản compiler/checker được hỗ trợ, chính sách model sheet/reference. Ghim hợp đồng khi bắt đầu Phase 2 lần đầu; không đổi ngầm giữa một dự án. Mỗi lượt chạy ghim manifest và revision prompt thực sự dùng. Không khóa dự án vĩnh viễn vào một revision prompt: lượt viết mới có thể dùng revision published mới hơn đã được khai tương thích với hợp đồng đó.

3.3. Dự án đã có lượt chạy không có release mới được xử lý theo hợp đồng legacy. Không tự chuyển Dark Rider hoặc dự án đã khóa sang hợp đồng mới. Concept mới chạy trên dự án mới theo chức năng tạo dự án hiện có; không tự tạo dự án trong bước triển khai.

3.4. Bổ sung lớp chọn hợp đồng theo dự án, dự kiến `MotoPipelineReleaseService`. Tận dụng manifest và `PhaseResolver::fromManifest()` hiện có. Dữ liệu cũ đọc theo manifest đã lưu; lượt viết mới chọn revision published mới nhất trong danh mục tương thích với hợp đồng dự án, không chỉ lấy is_current toàn cục. Danh mục tương thích phải khai phase ID/hash, input_format và schema output tương ứng; cùng output schema chưa đủ chứng minh cùng quy tắc đầu vào. Khi legacy thiếu manifest cho một phase chưa chạy, dùng bản đồ release legacy được định nghĩa trong gói phát hành. Không tái dùng manifest của một dự án khác hoặc sửa project_id trong manifest đã có hash.

3.5. Phiên bản compiler/envelope thay đổi phải có nhánh đọc dữ liệu cũ. Không chỉ tăng hằng số rồi khiến `fromManifest()` cũ bị từ chối. Không ghi lại raw response, hash hoặc trạng thái duyệt để ép tương thích.

3.6. Chính sách model sheet phải lấy từ snapshot/release của bộ ảnh. Cấu hình mới không được làm bộ cũ mất khả năng xem hoặc sử dụng chỉ vì hash chính sách global đã đổi. Giữ kiểm tính toàn vẹn file và nguồn thực sự.

3.7. Quy ước schema đề xuất cho release này:

| Schema | Phiên bản đề xuất | Lý do |
|---|---|---|
| MotoDna | 2.0.0 | Thêm nhận diện thẩm mỹ có cấu trúc. |
| MotoDesign | 2.0.0 | Vai trò có ID, thông tin đủ 12 giai đoạn, đồ họa và palette mở rộng. |
| MotoScript | 2.0.0 | Nhân vật động, sự kiện, không phân phối thời lượng phim. |
| MotoLocations | 2.0.0 | Dữ liệu không gian phục vụ camera chuyển động. |
| MotoScenes | 2.0.0 | Dàn cảnh dựa vào sự kiện và nhân vật động, bỏ timeline phim suy ra từ beat. |
| MotoShots | 2.0.0 | Timeline và camera path, shot đủ 3000 ms. |
| MotoReferencePrompt | Giữ 1.2.1 | Đầu ra review prompt của ô Part chưa đổi; tăng phiên bản đầu vào/compiler khi cần. |
| MotoClipPrompt | Giữ 1.0.0 | Giữ cấu trúc prompt/source_refs/gaps/conflicts/flags hiện có. |
| MotoClipPlan | 2.0.0 | Khai báo mode Reference-to-Video rõ ràng trong plan mới; giữ nhánh đọc 1.x. |
| Phase 9 | Giữ nguyên | Chưa triển khai keyframe cho release mới. |

Không thay nội dung schema/revision đã phát hành. Số revision prompt mới phải lớn hơn số lớn nhất trong cả repo và DB. Không coi tên file `_vN.php` là số revision trong DB. Không tự seed file rev 12 hiện có làm bản triển khai.

Đầu ra bước này: manifest release, schema mới, fixtures tương thích và resolver theo release. Chưa gọi model.

**4. Phase 2 — DNA tạo được concept táo bạo**

Đầu vào: nguồn Phase 1, brief sáng tạo, lịch sử concept và chính sách sản phẩm. Không sửa chức năng trích dẫn nguồn Phase 1 trong đợt này.

Brief bổ sung trên form hiện có: chủ đề mong muốn; hướng hình khối; vật liệu/bề mặt; họa tiết; mức thay đổi xe nền; bối cảnh trình diễn mong muốn. Trường người dùng để trống được AI phát triển trong phạm vi brief, không bắt người dùng thiết kế thay AI.

Sửa prompt:

- Bỏ quy định mọi concept phải là muscle bobber tối giản.
- Mở nguồn cảm hứng ngoài xe/xưởng, nhưng thể hiện thành thiết kế xe cụ thể.
- Cho phép chrome, patina, ghi đông cao, túi, đồ họa hoặc hình khối trang trí khi phục vụ concept.
- Công nhận bản sắc thẩm mỹ là giá trị sáng tạo; không buộc mọi concept phát minh cơ cấu mới.
- Giữ phân biệt dữ kiện nguồn, ý tưởng mới và tính khả thi chưa được kiểm chứng.
- Lịch sử ngăn lặp nguyên concept; không cấm mọi dự án cùng họ phong cách.
- Không thiết kế logo, tên hãng hoặc dùng nhân vật mẫu nổi tiếng để làm nhận diện.

Đầu ra giữ các nhóm brief/theme/emotion/departures/keep/features/silhouette/must_avoid/hero_moment. Giữ 1–3 signature features với ID F1–F3 để tránh mở rộng cơ cấu không cần thiết.

Thêm `visual_identity` gồm:

| Trường | Kiểu và nội dung |
|---|---|
| `motif` | String: nguyên tắc/họa tiết thẩm mỹ chủ đạo. |
| `material_direction` | String: cách phối vật liệu. |
| `finish_direction` | String: mạ, khắc, patina, sơn, đánh bóng… |
| `color_direction` | String: hướng phối màu. |
| `identity_cues` | Mảng 3–5 string: những dấu hiệu phải nhận ra xuyên góc nhìn. |

`identity_cues` là tiêu chí nhận diện, không tạo thêm ID F4/F5. Phase 3 cụ thể hóa chúng thành Part, surfaces, markings và identity_rules.

Laravel kiểm schema, ID nguồn, liên kết, giới hạn dữ liệu và hash. Việc concept có mới/đẹp/hợp chủ đề do AI tự kiểm và người dùng đánh giá, không thêm bộ từ khóa phong cách chặn kết quả.

Code chính: `DesignDnaService`, `MotoDnaCheck`, `MotoContract`, cấu hình brief và panel DNA hiện có.

Nghiệm thu offline: dữ liệu có chrome/engraving/honeycomb/patina không bị từ chối chỉ vì phong cách; chữ tên hãng vẫn có thể tồn tại trong hồ sơ nguồn nội bộ, không bị hiểu là chỉ thị để vẽ lên sản phẩm.

**5. Phase 3 — thiết kế, 12 giai đoạn và model sheet**

5.1. Thiết kế giữ Parts, systems, connections, features, rider interface, configurations, limits và các quan hệ đang có. Giữ các phép kiểm ID, hình học và quan hệ số học có căn cứ trong dữ liệu.

5.2. Thêm `data.participant_roles` là danh mục:

```json
[
  {"id":"ROLE01","name":"Lead designer","description":"Leads the concept and design review."},
  {"id":"ROLE02","name":"Workshop fabricator","description":"Makes and assembles the custom parts."}
]
```

Đây là ví dụ, không phải danh sách vai cố định. AI tạo vai phù hợp với thiết kế. `participant_roles` trong từng BS là mảng ID ROLE của danh mục; giao diện hiện tên vai qua ID.

5.3. Mỗi BS01–BS12 bắt buộc có `text`, `refs`, `setting_brief`, `participant_roles`, `outcome`. BS01–BS05 tiếp tục giữ model_medium/scale khi có. BS06–BS12 tiếp tục giữ đầy đủ surfaces/in_progress/exceptions/temporary/surface và vòng đời Part.

Thông tin công việc ở thiết kế nêu điều cần thực hiện trong giai đoạn. Việc dàn dựng công cụ đi từ đâu tới đâu, Part ở trên xe hay ngoài xe trong một thao tác cụ thể thuộc Scene/Shot; không dùng các trường mới để ghi đè vòng đời Part.

5.4. Bỏ giới hạn lốp sau 180–240 mm như công thức phong cách chung. AI chọn thông số phù hợp concept và khai vào trường kích thước; kiểm tương quan hình học tiếp tục dùng các giá trị thực sự đã khai.

5.5. Palette mới giữ tối thiểu một màu, bỏ trần ba màu; ID dùng C1…Cn với pattern `^C[1-9][0-9]*$`. Cập nhật đồng bộ color_id, các refs và regex phân loại ID trong PHP. Màu, vật liệu và finish là các thuộc tính riêng; không tạo một màu sơn cho mỗi vật liệu kim loại.

5.6. Mở rộng `motorcycle.markings` để ghi đồ họa/bề mặt trang trí có chủ đích. Mỗi mục có:

```text
part: ID Part
kind: graphic | engraving | inlay | surface_pattern | lettering | number
description: mô tả hình ảnh
technique: phương pháp thể hiện
placement: vị trí/mặt của Part
coverage: vùng và mức bao phủ
color_ids: các ID màu liên quan, có thể rỗng
from_state: BS khi chi tiết này xuất hiện
shown_in_model_sheet: boolean
```

Không dùng `lettering` để vẽ tên hãng hoặc biến chữ thành logo. Họa tiết hình học/trang trí không bị cấm chỉ vì nằm trên thân xe. Trường description không được chép lại toàn bộ surfaces; surfaces sở hữu vật liệu/finish nền, markings sở hữu hình trang trí và cách đặt nó.

5.7. Bỏ kiểm chặn mọi `shown_in_model_sheet=true` trong `MotoDesignCheck`; giữ kiểm tham chiếu Part/màu/BS. Luật cấm logo/tên hãng được đưa vào prompt và bước duyệt hình; không tuyên bố schema có thể chứng minh ảnh không chứa logo.

5.8. `MotoSheetPrompt` phải đọc và biên dịch markings được hiển thị, cũng như các identity_rules liên quan, gồm cả điều phải giữ và điều không được xuất hiện. Prompt mô tả hình học/hoa văn bằng lời, không dùng tên hãng làm lối tắt nhận diện.

5.9. Model sheet mặc định giữ bố cục tám ô đang có. Cập nhật chính sách trình bày để cho phép đồ họa trên xe nhưng tiếp tục cấm logo/tên hãng, watermark, nhãn và chữ ngoài vật thể. Không bắt buộc tăng thành 12–13 ảnh mới. Chi tiết cần đọc rõ được bổ sung vào Parts Detail theo nhu cầu Shot ở Phase 8.

5.10. Duyệt model sheet và khóa thiết kế theo cơ chế hiện có. Không mở khóa thiết kế cũ. Chỉ coi ảnh là nguồn chuẩn sau khi người dùng xác nhận hình dáng, họa tiết và các góc nhất quán.

Code chính: `MotoDesign`, `MotoDesignCheck`, `MotoSheetPrompt`, `ModelSheetService`, cấu hình `video.moto.model_sheet`, release schema thiết kế.

**6. Phase 4 — kịch bản theo sự kiện, nhân vật động**

Đầu vào: thiết kế được duyệt đã bỏ model_sheet và tọa độ, brief, film_facts và lịch sử nếu đang bật. Không đưa DNA thành nguồn thiết kế song song; không đưa ảnh/camera của model sheet vào văn bản kịch bản.

Brief thời gian mới:

```json
{"timing_policy":"per_shot","shot_duration_ms":3000,"target_film_duration_ms":null}
```

Bỏ yêu cầu nhập tổng độ dài phim. Không gán mọi beat dài 3 giây; beat là đơn vị nội dung, Shot mới là đơn vị render.

`characters` chuyển thành mảng:

```json
[
  {"id":"CH01","role_ids":["ROLE01"],"description":"An original adult character; specify face, hair, build, clothing and distinguishing traits."}
]
```

Một người có thể đảm nhiệm nhiều vai; một vai có thể có nhiều người khi câu chuyện cần. Không tự ghép tất cả vai vào một builder; không buộc có rider nếu nội dung không cần. Khi một use moment yêu cầu người lái, thiết kế/kịch bản phải khai vai tương ứng rõ ràng.

Mỗi beat giữ ID B1…Bn, act, BS, time_jump, purpose, visual, location, time_light, reveal, design_refs và liên kết kể chuyện. Bỏ duration_s bắt buộc và timeline phim được tính từ nó.

Thêm `events` với ID EV01… toàn kịch bản. Một event chứa: id, action, result, character_ids, design_refs, operating_start, operating_end. Beat chứa các event theo thứ tự. Mô tả trạng thái vận hành cho phép máy tắt→nổ, chân chống xuống→lên; không biến đổi hình học thiết kế.

`operating_start/end` dùng dữ liệu có cấu trúc: trạng thái chuyển động xe, hoạt động động cơ khi có, configurations và đèn theo ID, hỗ trợ giữ xe. Tận dụng kiểu dữ liệu situation/configuration đang có; bổ sung cặp đầu/cuối, không tạo một danh sách trạng thái tự mâu thuẫn ở bước sau.

Người dùng có chỉ định mốc thời gian cụ thể thì AI ghi thành `data.shot_directives` trong kịch bản: mỗi mục có id DIR01…, beat_ids, event_anchors (event_id/start_ms/end_ms), required_first_frame và required_end_frame. Mục này chỉ có khi brief có chỉ dẫn tương ứng; người dùng thấy nó khi duyệt kịch bản. Không tự trích số từ văn xuôi bằng regex để phân phối thời gian. Phase 7 nhận nguyên các chỉ dẫn được duyệt và Laravel kiểm các mốc số đã được cấu trúc đó.

Mỗi BS01–BS12 có nội dung kể chính được bao phủ; hook/ending không thay thế việc kể một giai đoạn. Cho phép hook lộ toàn xe. Hero vẫn thực hiện moment được đánh dấu hero trong thiết kế, với configs và giới hạn tương ứng.

Nhân vật có thể hiện mặt và toàn thân. Giữ bảo hộ đúng công việc; không thêm nhân vật nổi tiếng hoặc lấy hình nhân vật từ ảnh xe mẫu làm nguồn nhận diện.

Text cards: giữ loại card và nguồn thông số phù hợp; tắt BASE card trong release mới để không đưa tên xe/hãng lên phim. Giữ DISCLAIMER nguyên văn `Fictional concept. No manufacturer affiliation or endorsement.` và END. Script khai nội dung card, nguồn và beat/event cần gắn; thời gian cụ thể đặt ở Shot. Không bắt Script tính mật độ card theo một tổng thời gian chưa biết.

Code chính: `MotoScript`, `MotoScriptCheck`, `ScriptWriterService`, `ScriptEvaluation`, form brief và script panel. Bỏ kiểm tổng duration_s bằng video_length và luật cấm toàn xe trước hero cho release mới.

Đầu ra nghiệm thu: nguồn đủ 12 BS, vai có ánh xạ tới nhân vật, chuỗi sự kiện có đầu/cuối, không còn object bắt buộc builder/rider hoặc tổng phim 30 giây.

**7. Phase 5 — địa điểm và bối cảnh phục vụ camera chuyển động**

Giữ location/background ID và nguồn ánh sáng/nền hiện có. Đầu vào nhận beat/event không phụ thuộc duration_s.

Bổ sung mỗi địa điểm: `spatial_layers` (foreground/midground/background), `subject_zone`, `entry_routes`, `camera_access`, `fixed_landmarks`. Mỗi trường mô tả không gian cụ thể, không yêu cầu AI bịa kích thước chính xác khi nguồn không có.

Background ảnh vẫn là môi trường trống; không chứa xe/người để thay thế nguồn nhận diện. Tạo và duyệt ảnh bằng thao tác hiện có khi người dùng thực hiện, không tự gọi model sau khi viết xong Locations.

Nền phải dùng được cho vị trí xe và đường camera dự kiến: mặt nền tiếp xúc, hướng sáng, kiến trúc/horizon và các vật cố định có quan hệ nhất quán. Macro được dùng nền mờ; không rút ảnh bối cảnh thành chỉ một màu hoặc một hướng sáng.

Code chính: `MotoLocations`, `MotoLocationsCheck`, `MotoLocationPlanner`, `LocationWriterService`, `LocationReferenceService`.

**8. Phase 6 — scene và dàn cảnh**

Đầu vào: kịch bản/sự kiện, Locations và thiết kế được duyệt. Gom các sự kiện cùng địa điểm, cùng thời gian liên tục và trạng thái chế tạo phù hợp thành Scene.

Scene có: id, beat_ids, event_ids, state, location/background_ids, character_ids, purpose, set_brief, blocking, work, continuity_start/end và transition. `blocking` nêu vị trí và tương tác của người/vật, đường tiếp cận, đồ gá và vật đỡ cần thiết.

Không phát sinh start_ms/end_ms của phim tại bước này. Giao diện hiển thị thứ tự cảnh và sự kiện. Sau khi có Shot, thời gian Scene được tổng hợp từ các Shot đã chọn.

Giữ work item và trạng thái Part trên/ngoài xe ở đầu/cuối thao tác. Ví dụ: động cơ đúng vị trí trong khung, đai chưa thành truyền động hoàn chỉnh khi chưa có bánh, bánh đã lắp nhưng được dolly hỗ trợ phải được phân biệt rõ trong dữ liệu.

Nhân vật tham chiếu CHxx thay enum builder/rider. Bảo hộ theo nhân vật và công việc. Scene có thể có nhiều vai; bố trí số người hiện trong từng Shot được quyết định ở Phase 7, không xóa vai khỏi câu chuyện để vừa một request.

continuity_start/end là điều kiện dàn cảnh, không được chép đè lên first_frame của Shot ở bước viết prompt.

Code chính: `MotoScenes`, `MotoScenesCheck`, `MotoScenePlanner`, `MotoWork`, `SceneWriterService`, Scenes panel.

**9. Phase 7 — kế hoạch quay 3 giây: hợp đồng quan trọng nhất**

9.1. AI lập số Shot phù hợp với các sự kiện. Không khóa 12 Shot, 21 Shot hoặc một Shot cho mỗi BS. Một BS có thể cần nhiều Shot; mỗi Shot tập trung vào một diễn biến đọc được.

9.2. Bất biến thời gian:

```json
{"mode":"reference_to_video","render_duration_ms":3000,"used_start_ms":0,"used_duration_ms":3000}
```

Không lấy thời lượng beat để ghi đè used_duration_ms. `total_ms = số Shot × 3000` là kết quả tính toán; không dùng total_ms làm mục tiêu chia ngược về các beat.

9.3. Mỗi Shot chứa:

| Trường | Quy định |
|---|---|
| `id`, `scene`, `state` | ID ổn định trong một bản kế hoạch. |
| `coverage` | Beat và event_ids được thể hiện; thay cách chia whole/opening/middle/closing theo thời lượng cũ. |
| `first_frame` | Mô tả đúng thời điểm 0 ms; đây là text, không yêu cầu ảnh keyframe. |
| `operating_start/end` | Trạng thái xe/thiết bị đầu và cuối. |
| `action_timeline` | Các khoảng thời gian và hành động theo thứ tự. |
| `camera` | Lens, bố cục, đường đi và các mốc theo thời gian. |
| `parts`, `work_items`, `configurations` | Nguồn Part và thao tác vật lý. |
| `people` | Nhân vật cùng framing và tình trạng thấy mặt. |
| `environment` | ID nền/địa điểm. |
| `reference_needs` | Yêu cầu về các chủ thể cần tham chiếu. |
| `end_frame`, `result` | Khung cuối và kết quả phải đọc được. |
| `cards` | ID card từ kịch bản cùng start_ms/end_ms trong Shot. |

`people` dùng `{character:"CH01", framing:"full_body", face_visibility:"visible"}`. Enum framing: hands/close/half_body/full_body. Enum face_visibility: visible/occluded/out_of_frame. Không gắn visibility thành danh tính cố định của người.

9.4. Một phần tử action_timeline có `id`, `start_ms`, `end_ms`, `event_ids`, `actor_ids`, `action`, `result`. Đây là các nhịp chính tuần tự; các hoạt động đồng thời của người khác được mô tả trong cùng nhịp.

9.5. `camera` giữ lens_mm và dữ liệu góc/vị trí có căn cứ; thêm continuous=true và path. Mỗi path segment có start_ms/end_ms, move, description, look_at, framing. move dùng push/pull/slide/pan/tilt/orbit/crane/track/drone; không dùng static trong release mới. Nhiều kiểu chuyển động có thể nối trong một đường đi liên tục.

Không đổi góc bằng cắt cảnh. Không coi rung máy hoặc ảnh phản chiếu chuyển động là thay thế cho chuyển động camera. Không bắt mọi shot phải wide→close→wide hoặc phải orbit 360°.

9.6. Ví dụ ràng buộc người dùng đã nêu, giữ đúng khi áp dụng vào Shot tương ứng:

| Thời gian | Diễn biến |
|---|---|
| 0–500 ms | Thấy xe trên chân chống, máy tắt. |
| 500–1000 ms | Người đàn ông tiếp cận và ngồi lên xe. |
| 1000–1500 ms | Gạt chân chống, giữ xe thẳng. |
| 1500–2000 ms | Nhìn xuống bình/khu vực đọc nhiên liệu. |
| 2000–2500 ms | Thực hiện thao tác điều khiển khởi động mà thiết kế cho phép. |
| 2500–3000 ms | Toàn xe và người ngồi được đọc rõ; động cơ đã nổ. |

Camera được AI lập đường đi phù hợp chuỗi trên. Chiều cao 1,82 m chỉ áp dụng nhân vật có mô tả đó. Không tự thêm chìa khóa nếu thiết kế chỉ có nút khởi động; muốn chìa khóa phải được quyết định tại thiết kế/kịch bản trước.

Không dùng ví dụ này làm mẫu hành động bắt buộc cho mọi Shot. Không giấu bước dài bằng teleport hoặc tự kéo clip thành 8–10 giây. Rủi ro thực hiện chuỗi quá dày được ghi rõ cho người duyệt, không tự thay ràng buộc người dùng.

9.7. Laravel kiểm: ID tồn tại và duy nhất; quan hệ nguồn; mọi khoảng nằm trong 0–3000; start<end; nhịp chính liền nhau từ 0 tới 3000; camera path phủ cùng khoảng; continuous=true và không có segment static; actor/Part/BS/config tồn tại; card nằm trong Shot và không chồng nhau. Kiểm này không chứng minh diễn xuất và đường máy sẽ đẹp trong video.

9.8. PHP chỉ cộng timeline phim và Scene từ danh sách Shot. Coverage thể hiện sự kiện, không dùng để phân chia lại 3000 ms.

9.9. Kế hoạch Shot được người dùng duyệt trước bước viết prompt. Thay action_timeline/camera/first_frame tạo revision Shot mới và đánh dấu prompt liên quan cần cập nhật theo nguồn; không tự gọi AI.

Code chính: `MotoShots`, `MotoShotsCheck`, `MotoShotPlanner`, `MotoShotPrompt`, `ShotWriterService`, `ShotSourceService`, phần kế hoạch Shot trong Scenes panel. Giữ nhánh timing v1 cho legacy, bổ sung timing v2 cho release mới.

**10. Phase 8 — master tham chiếu, nhân vật và dùng lại**

10.1. Tạo bộ phân giải nguồn dùng chung, dự kiến `MotoReferenceSourceService`, từ phần đang dùng chung qua `MotoKeyframeSourceService`. Màn video dùng trực tiếp bộ này; không yêu cầu Phase 9, prompt keyframe hoặc keyframe được duyệt.

10.2. Tận dụng ModelSheetService, PartsDetailService, MotoCharacterPlanner và MotoCharacterReferenceService. Không viết một hệ thống ảnh song song.

10.3. Parts Detail chỉ tạo các Part/biểu diễn thực sự được Shot yêu cầu. Dùng lại khi design, Part, presentation, bề mặt và góc yêu cầu giống nhau. shot_id không thuộc khóa nhận diện ảnh. Các trạng thái drawing/raw metal/painted/finished khác nhau không dùng một ảnh như thể chúng giống nhau.

10.4. Mỗi nhân vật có một master hiện hành được duyệt, gồm các góc cần dùng, vùng nhận diện mặt và các look/bảo hộ cần thiết. Giữ tám góc C01–C08 và thêm một ô chân dung FACE01 để đọc rõ nhận diện; ô chân dung là loại riêng, không đổi quy ước góc camera của C01–C08. Giữ cơ chế master nhiều ô, duyệt cả sheet và thu hồi duyệt. Số nhân vật phát sinh từ kịch bản; không còn cố định hai lượt render Builder/Rider.

Khuôn mặt master được sinh từ mô tả nhân vật trong kịch bản. Mọi ô thể hiện cùng một người. Không đưa đồ bảo hộ của công việc này vào mọi look của người đó. Cập nhật các câu cấm mặt và map views cũ. Sử dụng lại master qua nhiều Shot; chỉ tạo revision ảnh mới khi nguồn/look/bố cục thực sự thay đổi.

10.5. Giữ cắt ảnh theo cơ chế xử lý master hiện có để xem chi tiết và lưu artifact; việc crop tồn tại không đổi quy định gửi nguyên master. Không gửi ảnh lưới đầu vào mới. Không tự tạo bộ ảnh riêng từng góc.

10.6. Mỗi ảnh trong danh sách gửi có ít nhất: asset_id, master_artifact_id, sha256, position, kind, role, phạm vi part_ids/character_id, build_state hoặc presentation nếu có, và thông tin ô mục tiêu khi ảnh là master.

Role phân biệt identity / part_detail / environment / character. Bộ phân giải xác định phạm vi hình học/bề mặt từ dữ liệu được duyệt, không đoán ảnh nào đúng bằng từ khóa trong tên file. Nếu master Parts Detail có Part không liên quan, prompt chỉ định rõ phạm vi sử dụng. Nếu có mâu thuẫn ảnh–thiết kế, hiển thị để xử lý nguồn, không âm thầm chọn bản gần giống.

10.7. Một master phục vụ nhiều Part chỉ gửi một lần. Người dùng vẫn có thể bỏ/chọn ảnh theo chức năng hiện có. Không tự loại ảnh nhân vật/nền để vừa giới hạn rồi tiếp tục render như đủ nguồn. Kiểm tổng số ảnh và dung lượng theo registry/provider contract.

Không đặt maxItems=2 cho số người của toàn bộ kịch bản hay Scene. Đặc tính hỗ trợ tham chiếu nhân vật của model phải được tính trong kế hoạch Shot và hiển thị rủi ro; không tuyên bố giữ được ba danh tính chỉ vì có thể gửi ba ảnh. Có thể phân bố các tương tác qua nhiều Shot mà vẫn giữ đủ vai của giai đoạn.

10.8. Nguồn ảnh nguyên tấm giữ theo chính sách đang có: không giới hạn cạnh dài khi nằm trong dung lượng cho phép; không crop/stretch theo tỷ lệ video. Ghi kích thước, MIME, dung lượng và hash ảnh thực sự gửi. Nếu cần dẫn xuất để đạt giới hạn thì giữ ảnh gốc, ghi rõ dẫn xuất và không đổi ngầm master đã duyệt.

Đầu ra nghiệm thu: đúng bộ ảnh cho mỗi Shot, ảnh trùng được dùng lại, face identity/look nhất quán và không có phụ thuộc keyframe.

**11. Phase 10 — viết prompt video và gửi request**

11.1. Nguồn chính là Shot đã duyệt. Đầu vào bổ sung đầy đủ action_timeline, camera.path, operating_start/end, card intent khi liên quan, metadata reference và nhân vật. `opening.first_frame` lấy từ Shot; Scene chỉ cung cấp bối cảnh/dàn cảnh đã được Shot sử dụng.

11.2. Bổ sung design_context: markings của Part được dùng, identity_rules liên quan, features/modes/use sequence liên quan, surfaces theo BS/biểu diễn, connections và limits. Whole-bike shot nhận đủ các dấu hiệu nhận diện concept. Không chỉ truyền name/silhouette/palette/materials.

11.3. Đầu vào vẫn có danh mục nguồn Sxx. Mọi trường mới cần được đưa vào payload và các nguồn thực sự, không chỉ thêm schema nhưng bỏ qua trong whitelist. Ghim hash nội dung Shot, thiết kế, bộ reference và chính sách âm thanh.

11.4. Meta-Prompt mới yêu cầu AI viết một prompt tiếng Anh hoàn chỉnh:

1. Nêu một cú máy liên tục dài 3 giây, không cắt; hình ảnh mang diện mạo phim người thật quay bằng máy quay.
2. Nêu các ảnh là reference, không phải các khung hình hoặc một chuỗi cảnh.
3. Gán đúng vai trò ảnh theo thứ tự gửi; dùng master để lấy nhận diện, không tái tạo lưới, nền studio hoặc nhiều bản sao.
4. Mô tả khung mở đúng first_frame và operating_start.
5. Mô tả bối cảnh có mặt thực sự trong khung, ánh sáng và quan hệ không gian.
6. Diễn đạt camera.path bằng ngôn ngữ quay phim, chuyển động liền mạch và bố cục có chủ đích.
7. Viết hành động theo các mốc action_timeline. Không tự phân phối lại thời gian hoặc mở cảnh trước first_frame.
8. Thêm chi tiết sống động có căn cứ: tiếp xúc, lực, vải, ánh phản chiếu, rung sau khi nổ máy… Không mặc định bụi/tia lửa/đèn nhấp nháy.
9. Đạt end_frame/result và operating_end.
10. Một dòng Sound design theo chính sách hiện hành, kết bằng No music. No dialogue.

Cho phép AI lựa chọn cách diễn đạt và các cử động nhỏ không thay đổi sự kiện/trạng thái. Quyết định làm thay đổi nội dung cần đưa về Shot để người dùng duyệt.

11.5. Giữ output MotoClipPrompt 1.0.0: prompt, source_refs, gaps, conflicts, flags và status. Giữ kiểm envelope/ID/hash/cấu trúc Sound design. Không thêm bộ từ khóa PHP để kết luận cảnh đang động/tĩnh hoặc hành động có tự nhiên.

11.6. Vẫn dùng thao tác viết prompt, duyệt prompt, Render video, duyệt clip hiện có. Bản chưa được duyệt không tự gửi. Không gọi AI tự sửa khi status needs_review. Ghi chú AI phải hiện ở màn hình.

11.7. Fingerprint prompt là nội dung nguồn; không gồm model/quality/resolution render khi chúng không làm đổi Shot hoặc ảnh thực sự dùng. Hash request render riêng ghi model và toàn bộ controls. Thay model hợp lệ không tự làm prompt mất duyệt; đổi ảnh/nguồn/Shot thì có ảnh hưởng tương ứng. Mỗi request lưu đúng snapshot đã gửi.

11.8. ClipPlan 2.0.0 khai mode reference_to_video, input_mode references, keyframe null, duration 3000, used interval 0–3000. Builder mới không gọi kiểm clipProblem chỉ dành cho keyframe/first-last. Giữ reader/builder legacy theo schema đã ghim. Cập nhật render_capabilities đầu vào Phase 7 và kiểm mode trong builder để nhận mode mới; không chỉ đổi nhãn giao diện. `reference_to_video` là mode nội bộ được ánh xạ sang nhánh references/task=null, không tự thêm thành trường API nếu adapter không dùng trường đó.

11.9. Adapter dùng nhánh Reference-to-Video đã có. Request gồm model, danh sách image theo thứ tự, prompt nguyên văn bản duyệt và response_format video, duration 3s, tỷ lệ/độ phân giải theo registry. Tiếp tục cơ chế task=null đang có cho nhánh references; không gửi first/last-frame task. Không chuyển các master thành video frames.

11.10. Giữ model viết prompt theo config/env đang có; không chuyển model writer sang DB trong đợt này. Model ảnh/video tiếp tục chọn qua cơ chế hiện hành. Không tự nâng model hoặc mở thêm chế độ edit/extend.

11.11. Kiểm file đầu ra theo controls thực tế và chính sách media được ghim: video stream duration, kích thước, khả năng giải mã và khoảng sử dụng. Không kiểm clip 3 giây theo mục tiêu canary 10 giây. Không tự tăng tốc, đóng băng khung hoặc render lại để che sai số.

Code chính: `MotoClipPrompt`, `ClipPromptWriterService`, `MotoClipSourceService`, `MotoClipPlanBuilder`, `MotoClipPlanService`, `MotoClipRenderService`, `SceneClipDispatchService`, `GeminiOmniVideoClient`, registry và view Render video.

**12. Giao diện phải phản ánh đúng dữ liệu**

Không thêm màn hình riêng. Cập nhật các màn hiện có:

| Màn | Hiển thị/thao tác |
|---|---|
| Brief/DNA | Định hướng sáng tạo và nhận diện thẩm mỹ. |
| Thiết kế | 12 BS đủ text/refs/setting/roles/outcome, Part và đồ họa liên quan. |
| Kịch bản | Danh sách nhân vật theo vai; sự kiện; không nhập tổng thời lượng cố định. |
| Locations | Không gian và ảnh nền theo trạng thái duyệt hiện có. |
| Scenes/Shot | Shot 3 giây, timeline hành động, camera path, trạng thái đầu/cuối, nhân vật, Part và kết quả. |
| Reference | Master nhân vật động và Parts Detail dùng lại; giữ thao tác duyệt cả sheet. |
| Render video | Planning đầy đủ → prompt duyệt → ảnh nguyên master → clip/candidates và duyệt. |

Không đặt nút viết prompt/video ở trạng thái thiếu nguồn chỉ vì chưa có keyframe. Thiếu thực sự phải chỉ rõ ảnh hoặc ID nào thiếu. Không hiện lỗi thuộc hợp đồng legacy lên dữ liệu release mới.

Controller/Request validation và các map/whitelist phải nhận CHxx, framing/face_visibility và trường v2 mới. Rà `VideoProjectsController`, route nhân vật, các projection và bộ lọc trường trong MotoScript/Locations/Scenes/Shots/ClipPrompt; tránh trường đã có trong schema bị bỏ mất khi truyền sang phase tiếp theo. Giới hạn độ dài mô tả nhân vật áp dụng chung theo hợp đồng mới, không còn các khóa riêng builder/rider.

Khi không còn Part thiếu, thu gọn vùng tạo master theo luồng hiện tại; vẫn xem được ảnh đã duyệt/lịch sử. Không render lại ảnh chỉ vì người dùng mở trang.

**13. Duyệt, thay nguồn và dùng lại**

- Thay nguồn được chọn thì chỉ báo rõ phần phụ thuộc cần cập nhật; không xóa lịch sử hoặc tự bỏ duyệt toàn bộ tài nguyên.
- Đổi model render không làm đổi nội dung nguồn của prompt; kiểm khả năng model đáp ứng các controls trước khi gửi.
- Duyệt/revoke master dùng cơ chế hiện có; revoke gỡ đúng binding của master đó.
- Việc một Part/nhân vật có ảnh đủ ID không chứng minh hình đúng; người dùng xem và duyệt ảnh.
- Giữ idempotency/operation ID, project lock và queue lease hiện có, tránh hai click tạo hai lượt ngoài ý muốn.
- Luồng mới không tự viết lại dữ liệu cũ hoặc tự thu phí để đạt trạng thái ready.

**14. Phase 11 — phần nối tương thích tối thiểu**

Không mở rộng thành dự án hậu kỳ mới. Cập nhật đọc plan mới và sử dụng trọn 0–3000 ms mỗi clip. Tổng phim cộng từ các clip được chọn. Không cắt về used_duration_ms 1,3/1,5/2 giây của beat legacy.

Cards dùng vị trí đã được Shot duyệt. Giữ renderer chữ và chính sách xuất hiện hành phù hợp; không đưa nhãn kỹ thuật hoặc ID vào video. Không cam kết mọi chuyển cảnh là liền mạch chỉ vì mỗi clip là một cú máy liên tục.

**15. Danh sách công việc theo thứ tự và đầu ra bàn giao**

| Task | Công việc | Đầu ra bắt buộc |
|---|---|---|
| T01 | Chụp baseline, release và tương thích | Manifest release, fixtures legacy, chọn hợp đồng theo dự án. |
| T02 | Schema và parser v2 | Schemas, ID/ref rules, adapters; chưa có request thật. |
| T03 | Phase 2 | Brief sáng tạo, prompt DNA và visual_identity. |
| T04 | Phase 3 | Roles, đủ 12 BS, palette/markings, compiler model sheet có phiên bản. |
| T05 | Phase 4 | Characters/events/cards mới, bỏ tổng thời gian phim. |
| T06 | Phase 5–6 | Không gian, scene/work/continuity không phụ thuộc duration beat. |
| T07 | Phase 7 | Timeline/camera/first-last state, timing v2, UI duyệt Shot. |
| T08 | Reference | Shared resolver, master nhân vật có mặt, tái sử dụng Part/nhân vật. |
| T09 | Phase 10 | Đầu vào đủ nguồn, Meta-Prompt mới, ClipPlan v2, request references 3s. |
| T10 | UI + Phase 11 tối thiểu | Đúng Planning/nguồn/trạng thái, dùng đủ clip. |
| T11 | Kiểm thử và rà diff | Test offline, danh sách file sửa và điều chưa kiểm chứng bằng video. |
| T12 | Gói phát hành | Diff prompt, schemas/hash/links, hướng dẫn seed; chưa seed hoặc gửi model nếu chưa được yêu cầu. |

Không làm T09 bằng cách chỉ seed rev 12 trong khi T05–T07 chưa đổi. Có thể soạn prompt/schema song song khi triển khai nhưng chỉ phát hành release đầy đủ sau khi kiểm liên kết.

**16. Kiểm thử bắt buộc, không gọi API thật**

1. Dự án legacy đọc được design/shot/master/clip đã duyệt và không bị nâng release ngầm.
2. Model-sheet policy mới không gây lệch policy của bộ cũ.
3. DNA có chrome/engraving/patina/họa tiết hợp lệ; features vẫn F1–F3, visual_identity có đủ cue.
4. Palette C4 trở lên hợp lệ theo schema/ID checker v2; refs sai màu bị từ chối.
5. Markings trang trí được compiler đưa vào prompt; ID Part/BS/màu sai bị từ chối.
6. Đủ 12 BS và role IDs; Script có nhiều nhân vật CHxx ánh xạ đúng ROLE, không tự gom vào builder/rider.
7. Person framing full_body và face_visibility visible hợp lệ; bảo hộ vẫn giữ theo work.
8. Script/Scene không cần tổng 30 giây; nhiều Shot cho một BS hợp lệ.
9. Mỗi Shot render/used đều 3000; nhịp/path ngoài khoảng, hở/chồng hoặc static bị từ chối theo trường cấu trúc.
10. Dùng first_frame của Shot dù Scene có câu mở khác; Phase 10 không tự chép Scene đè vào. shot_directives được duyệt truyền tới Phase 7 và các event anchor giữ nguyên.
11. Reference master trùng chỉ gửi một lần; cùng Part/look được tái sử dụng; trạng thái bề mặt khác không dùng nhầm.
12. Thiếu keyframe không chặn nhánh references; thiếu master thực sự thì hiển thị đúng ID.
13. Thay ảnh/Shot làm nguồn prompt thay đổi; thay model/resolution hợp lệ không tự thu hồi duyệt prompt.
14. HTTP fake xác nhận thứ tự ảnh, prompt nguyên văn, duration 3s, MIME và task omitted; không có keyframe/first-last semantics.
15. Duyệt/revoke/idempotency giữ đúng liên kết và lịch sử; không double-dispatch do bấm lặp.
16. ClipPlan v2 sang Phase 11 sử dụng đủ 3000 ms; nhiều clip cộng đúng tổng.
17. Tất cả thử nghiệm fail nếu có kết nối ngoài HTTP fake/stub; không gọi writer/image/video provider thật. Có phép thử đi xuyên chuỗi projection để xác nhận roles/events/markings/visibility/timeline không bị whitelist bỏ mất.

Nội dung cần xem trên ảnh/video sau này: hình học và họa tiết đúng, gương mặt nhất quán, không có logo/tên hãng, diễn xuất/tiếp xúc có trọng lượng, camera liên tục, bối cảnh tham gia rõ và kết quả cuối đọc được. Test JSON/HTTP giả không thay thế các tiêu chí này.

**17. Cách phát hành và sử dụng sau khi triển khai**

Dev bàn giao diff, nguyên văn prompt, schemas và phiên bản liên kết, kết quả test offline và giới hạn chưa nghiệm thu hình ảnh. Không đưa chi phí thử nghiệm ba Shot vào phần nghiệm thu bắt buộc.

Sau khi chủ dự án yêu cầu phát hành mới seed đúng release. Không tự chuyển dự án cũ. Người dùng tạo dự án concept mới rồi lần lượt thao tác DNA → thiết kế/model sheet → kịch bản → Locations → Scenes/Shot → Reference → prompt video → render và duyệt clip. Mỗi lượt có phí chỉ diễn ra theo thao tác được yêu cầu.

Gói này đủ để triển khai cấu trúc và luồng nghiệp vụ. Chất lượng phim thực tế được đánh giá ở những lượt ảnh/video do người dùng thực hiện sau đó; không ghi nhận đã nghiệm thu chất lượng Omni khi chưa có video mới.

**18. Phụ lục chốt kỹ thuật trước khi thực hiện các task**

18.1. Các tên format và đầu vào dùng trong release mới:

| Phase | input_format mới | format đầu ra / payload |
|---|---|---|
| 2 | moto-dna-input-v2 | moto-dna-v2 / dna |
| 3 | moto-design-input-v2 | moto-design-v2 / data |
| 4 | moto-script-input-v4 | moto-script-v2 / data |
| 5 | moto-locations-input-v2 | moto-locations-v2 / data |
| 6 | moto-scenes-input-v3 | moto-scenes-v2 / data |
| 7 | moto-shots-input-v5 | moto-shots-v2 / data |
| 8 | moto-reference-input-v3 | Giữ moto-reference-prompt-v3 |
| 10 writer | moto-clip-prompt-input-v4 | Giữ moto-clip-prompt-v1 |
| 10 plan | PHP biên dịch, không gọi writer riêng | moto-clip-plan-v2 |

Đây là tên mới cho hợp đồng triển khai; giữ namespace/schema key hiện có và thêm version, không ghi đè file/schema 1.x. Giữ envelope/status của từng loại đáp án hiện có nếu cấu trúc đó không đổi; không đổi partial thành needs_review ở các phase chỉ vì Phase 10 dùng needs_review.

18.2. Danh tính và phạm vi ID:

- ROLE01…: duy nhất trong thiết kế; các BS tham chiếu danh mục đó.
- CH01…: duy nhất trong bản kịch bản; cùng người qua các scene/shot/master giữ cùng ID.
- EV01…: duy nhất trong bản kịch bản; scene/coverage/timeline tham chiếu cùng ID.
- DIR01…: chỉ dẫn dàn dựng được duyệt trong kịch bản.
- SC01… và SC01_SH01…: duy nhất trong kế hoạch tương ứng.
- ID nhịp hành động và segment camera: duy nhất trong một Shot, không giả làm ID Part/feature.
- Part/feature/configuration/light/color giữ namespace của thiết kế. Trường actor chỉ nhận CH, không nhận ROLE. Kết nối từ ROLE sang CH phải hiện trong dữ liệu; không suy ra bằng tên người.

Không dùng chỉ số hiển thị hay tên chức danh làm khóa liên kết. Một ROLE có thể được nhiều CH đảm nhiệm khi kịch bản khai rõ; một CH có thể có nhiều ROLE. ID trong bản sửa không đổi chỉ vì sắp xếp lại danh sách.

18.3. Chuẩn hóa operating_start/end:

```text
vehicle_motion: stationary | moving | not_applicable
engine: null hoặc {part_id, state: off | starting | idling | running}
configurations: mảng configuration ID của thiết kế
lights: mảng {light_id, state: off | on}
support_refs: mảng tham chiếu người/Part/work item/vật đỡ có trong nguồn
```

Các object khai đủ khóa; null dùng cho thuộc tính không áp dụng, mảng rỗng dùng khi không có mục nào. Không bỏ khóa để ngầm diễn đạt unknown. Giá trị nguồn chưa xác định cần ghi vào flags/source issue để người duyệt thấy, không tự đổi thành off hoặc stationary.

Support có ID thì tham chiếu ID. Vật temporary chưa có ID dùng source_ref tới đường dẫn trong snapshot nguồn được ghim; resolver kiểm đường dẫn tồn tại trong đúng nguồn. Không tự tạo một Part của xe để hợp thức hóa đồ gá xưởng. Shot vẽ bản phác có vehicle_motion=not_applicable, engine=null và các mảng không áp dụng rỗng.

Trạng thái khởi động/đèn/chân chống nằm trong các khoảng timeline tương ứng. Nội dung first_frame phải thể hiện operating_start; end_frame phải thể hiện operating_end. AI tự kiểm sự phù hợp ngữ nghĩa; PHP kiểm ID/trường/quan hệ cấu trúc.

18.4. Quy tắc phân giải reference theo thứ tự:

1. Lấy nguồn từ đúng kế hoạch Shot được chọn và thiết kế mà kế hoạch đó ghim.
2. Lấy environment đúng background ID của Shot hoặc lựa chọn đã được lưu cho Shot đó; không chọn ảnh nền đầu tiên của dự án.
3. Lấy identity master được duyệt của đúng thiết kế.
4. Với Part cần chi tiết, lấy master được duyệt có binding đúng Part/presentation/surface; không tự chọn first()/latest() khi có nhiều nguồn mâu thuẫn.
5. Lấy character master của mọi CH xuất hiện trong Shot/timeline, kể cả người đi vào sau 0 ms; không lọc người chỉ theo khung đầu.
6. Khử trùng theo master artifact ID; gộp phạm vi sử dụng khi một master phục vụ nhiều Part.
7. Giữ thứ tự đã lưu trong lựa chọn reference; nếu chưa có lựa chọn, mặc định identity → part_detail → environment → character theo CH ID. Gán Image 1… sau khi đã khử trùng và lọc lựa chọn.
8. Kiểm approval, file/hash, giới hạn số ảnh/dung lượng. Thiếu hoặc mâu thuẫn phải hiện rõ, không lấy ảnh gần giống và không tự bỏ bớt nguồn để gửi.

Việc chọn lại ảnh tạo một lựa chọn nguồn mới, không làm biến đổi artifact cũ. Khi render, request lưu cùng thứ tự Image 1… mà lượt viết/duyệt prompt sử dụng. Kích thước/MIME/hash bytes gửi đi được ghi riêng với hash master gốc.

18.5. Nguồn Phase 10 và dấu vân tay:

- Giữ các nhóm S01–S15 trong reader legacy. Writer mới bổ sung S16=shot.action_timeline, S17=shot.camera.path, S18=shot.operating_start, S19=shot.operating_end. S13 chứa design_context đã bổ sung; S15 mô tả chính xác danh sách ảnh. Source refs trong đáp án chỉ được trỏ các nhóm thực sự được cấp.
- source_fingerprint bao phủ token nguồn Shot được duyệt, nội dung Shot liên quan, dữ liệu thiết kế/trạng thái/nhân vật được dùng, master IDs/hash/phạm vi/thứ tự và chính sách âm thanh.
- Không đưa model/effort của writer, model render, quality/resolution render hoặc revision prompt thuần diễn đạt vào fingerprint nội dung nguồn khi chúng không đổi nguồn.
- Aspect ratio/bố cục đã được Shot chốt thuộc nội dung nguồn; đổi nó không được coi là chỉ đổi độ phân giải.
- Hash request render riêng bao phủ prompt đã duyệt, bytes ảnh thực sự gửi, model và controls, duration, mode và policy file. Cùng prompt có thể có nhiều render attempt, mỗi attempt giữ request riêng.
- Revision prompt mới tương thích không tự thu hồi prompt đã duyệt. Kế hoạch Shot được chọn mới tuân theo binding nguồn hiện có: hiển thị các prompt/clip bị ảnh hưởng, giữ lịch sử, không tự chuyển duyệt hay viết lại.

18.6. Các cổng thao tác và xử lý lỗi:

| Thao tác | Điều kiện đủ |
|---|---|
| Viết kịch bản | Thiết kế/model sheet nguồn đã duyệt và đọc được theo hợp đồng dự án. |
| Lập Shot | Scene/kịch bản nguồn đã duyệt, ID/event/BS hợp lệ; chưa cần Parts Detail hoặc keyframe để sáng tác kế hoạch. |
| Viết prompt video | Shot đã duyệt, đủ master được chọn và hợp lệ; không cần keyframe. |
| Duyệt prompt | Đúng cấu trúc/nguồn hiện hành; người dùng đọc nội dung; trạng thái đáp án đáp ứng chính sách duyệt hiện có. |
| Render video | Prompt được duyệt còn khớp nguồn, ảnh hợp lệ, model/controls hỗ trợ references 3s. |
| Duyệt clip | File đạt media check và người dùng xem nội dung. |

Tận dụng các mã lỗi hiện có như references_missing, reference_problem, references_limit, prompt_missing, prompt_source_changed, duration_unsupported và mode_unsupported. Chỉ thêm mã mới vào schema plan v2 khi thiếu trường hợp thực sự. Thông báo chỉ rõ Shot/asset/trường bị lỗi. Một sự cố HTTP hoặc response không rõ kết quả không được tự biến thành lượt gửi mới có phí; giữ thông tin attempt/provider interaction để kiểm tra.

Đo thời lượng bằng video stream và chính sách media đã ghim. Khoảng video dùng được phải bao phủ 0–3000 ms; sai số metadata/audio không được dùng để tuyên bố video thiếu đoạn cuối đã đạt. Nếu không đáp ứng, đánh dấu lỗi/cần xem lại; không tự nối khung đứng yên, đổi tốc độ hoặc gửi render lại.

18.7. Kiểm thử đi xuyên chuỗi và hồ sơ bàn giao T02:

Dev phải tạo JSON Schema đầy đủ cùng fixtures hợp lệ/không hợp lệ trong T02, trước khi nối các writer thật. Ví dụ trường rời trong tài liệu không thay thế fixture hoàn chỉnh.

Bộ fixture tối thiểu gồm: một thiết kế đủ 12 BS có đồ họa và hơn ba màu; một kịch bản có ít nhất ba CH và quan hệ ROLE; scene tương ứng; Shot trình diễn xe, Shot có người vào sau khung đầu, Shot công việc xưởng; danh sách master và ClipPlan. Các fixture hoạt động cùng nhau bằng ID, không phải các ví dụ rời không thể nối nguồn.

Đáp án writer trong test là dữ liệu giả theo từng schema; HTTP fake chỉ xác nhận request. Có fixture legacy riêng để chứng minh việc đọc bản đã duyệt, lựa chọn prompt và policy không bị thay theo release mới.

T02 bàn giao: schema files, input payload samples, valid/invalid fixtures, bảng nối phase-schema-input_format, test chứng minh tính liên thông. T03 trở đi bàn giao nguyên văn prompt mới và payload snapshot cho từng phase. Không seed hoặc gọi provider thật chỉ để hoàn thành các đầu ra này.

18.8. Phạm vi quyết định đã đủ:

Không còn câu hỏi nghiệp vụ bắt buộc nào đang chờ người dùng. Các task trên là việc thiết kế kỹ thuật/viết schema/code/test của dev, không phải yêu cầu người dùng tự viết schema hoặc duyệt thêm chức năng. Các bổ sung trong phụ lục không cho phép render thử ba Shot, gọi AI tự động hoặc chuyển dự án cũ sang release mới.

**19. Ghi chú triển khai — 12/11/2026 (code đã viết, chưa seed, chưa commit, chưa gọi model)**

19.1. Chỗ làm khác tài liệu, có lý do:

| Mục | Tài liệu ghi | Đã làm | Lý do |
|---|---|---|---|
| 3.4 | Class `MotoPipelineReleaseService` | `App\Video\Moto\MotoPipelineRelease` (hàm tĩnh) + khoảng revision theo release trong `config/video.php` (`contracts.workflows.harley_custom_v1.releases`) | Không cần trạng thái; `PhaseResolver` đọc khoảng revision, workflow không khai `releases` (yacht) giữ nguyên `is_current`. |
| 3.2 | Ghim release khi bắt đầu Phase 2 | Ghim ở lượt chạy DNA đầu tiên; dự án đã có lượt DNA mà chưa ghim là legacy; dự án mới theo `MOTO_PIPELINE_RELEASE` (mặc định vẫn legacy) | Bật release mới bằng biến môi trường sau khi seed, không đổi ngầm. |
| 15 T02 | Schema/fixture trong `database/releases/<release>/` | Prompt + schema ở `resources/ai/moto/releases/moto-cinematic-3s-v1/`; dữ liệu seed ở `database/seeders/data/release_moto_cinematic_3s_v1.php` | Theo quy ước "prompt + schema lên DB bằng seeder"; file dữ liệu cố ý không khớp `ai_phases*.php` nên chưa bị seed. |
| 10.1 | Class `MotoReferenceSourceService` | Giữ `MotoKeyframeSourceService` làm bộ phân giải chung | Nhánh references của nó không cần keyframe hay Phase 9; tách class chỉ đổi tên. |
| 18.1 | Phase 8 `moto-reference-input-v3` | Không có revision Phase 8 mới; release mới dùng revision hiện hành | Đầu vào review prompt ô Part không đổi; hoạ tiết được thêm bằng dòng `Ornament:` do PHP ghép. |
| 9.4 | ID nhịp/đoạn không nêu dạng | `STEP01…`, `SEG01…`, card `CARD01…` | Không trùng dạng với ID màu `C1…` hay Part. |
| 6 | `operating_start/end` thay situation | Beat giữ `situation` + `configurations` (trạng thái cuối beat, để kiểm limit như cũ); event mang `operating_start/end` | Tái dùng nguyên bộ kiểm limit hiện có; PHP kiểm event nối tiếp nhau và khớp trạng thái cuối beat. |

19.2. Sáu điểm chỉnh sau bản 1.1 đã áp dụng: giữ hành vi yacht của `PhaseResolver`; fingerprint prompt giữ nguyên (đã đúng sẵn); `clipProblem()` giữ kiểm thời lượng và thêm kiểm `action_timeline` phủ trọn clip; master nhân vật giữ nhóm góc, đồ bảo hộ, look và thêm `FACE01` cho kế hoạch shot mới (cấu hình master cũ không đổi); schema Phase 2–7 đi theo seeder.

19.2a. Sửa sau lượt rà soát thứ hai (12/11):

- Phase 11 đọc card của kế hoạch shot cố định từ `placed_cards` của Shot, đối chiếu CARD ID với kịch bản ghim, đặt trên timeline phim bằng `tl_in_ms` + mốc trong shot; nhánh `offset_s`/`sec` giữ cho legacy.
- `MotoFilmPlanService::guard()` khoá và đối chiếu revision mà resolver chọn cho dự án theo release đã ghim, không còn lấy `is_current` toàn cục.
- Release mới chọn revision bằng danh mục tương thích trong `config/video.php`: mỗi phase ghi `input_format` và từng revision theo `id` + `sha256`; revision cao hơn không tự được dùng. Legacy giữ khoảng revision, Phase 10 tới rev 11.
- File nháp Phase 10 rev 12 chuyển sang `database/seeders/data/drafts/` để seeder không quét.
- Bộ kiểm shot: các nhịp phải diễn sự kiện theo thứ tự coverage. Bộ kiểm kịch bản: nhân vật trong beat phải giữ ít nhất một vai mà BS của beat liệt kê. `support_refs` chỉ nhận ID Part, ID nhân vật hoặc tên vật đỡ `temporary` của build state, và được kiểm tồn tại.
- Prompt Phase 10: đổi mili giây sang giây chính xác, tối đa ba chữ số thập phân, không làm tròn.
- Mốc card khớp khung hình (lượt rà soát thứ ba): bước lập shot đọc fps của profile xuất từ chính sách Phase 11 của dự án, ghim vào ngữ cảnh kiểm (`card_fps`) và gửi cho AI `film.fps` + `film.card_step_ms`. Bộ kiểm shot từ chối mốc card không rơi đúng khung, nêu CARD ID, khoảng thời gian và bước mili giây hợp lệ; không làm tròn. Hệ quả: release mới cần Phase 11 sẵn sàng trước khi lập shot.

19.3. Chưa làm:

- Giữ prompt/clip của shot không đổi khi kế hoạch shot có revision mới (hiện vẫn mất hiệu lực cả kế hoạch).
- Kích hoạt seed (file `ai_phases_*.php` nạp file dữ liệu release) và đặt `MOTO_PIPELINE_RELEASE`.
- Chưa có lượt model thật nào; chất lượng ảnh/video chưa được nghiệm thu.


### 19.4 Phase 3 revision 10 — thiết kế bỏ model_sheet.final_prompt (14/11)

Quyết định của người dùng sau lượt thiết kế thật đầu tiên: AI không viết prompt ảnh model sheet nữa; prompt đó do Laravel ghép từ dữ liệu thiết kế (việc Laravel vốn đã làm). `display_configuration` và `views` MS1–MS8 vẫn do AI viết.

- Prompt `phase-3-rev10.md`, schema `MotoDesign@2.1.0`, đầu vào giữ `moto-design-input-v2`, format đầu ra `moto-design-v2`. Revision 9 giữ làm lịch sử.
- Bỏ khỏi thiết kế: chỉ `model_sheet.final_prompt`.
- Thiết kế không có `final_prompt` thì cổng kiểm ghép thử prompt model sheet và báo lỗi ngay (quá `max_chars`, gọi tên hãng/dòng xe, ID, tên trường).
- Tên dòng xe (`base.family`) bị cấm trong prompt ghép ở mọi kiểu hoa/thường; prompt cấm dùng tên dòng xe, model, dòng máy ngoài `motorcycle.base`, kể cả trong tên part, và cấm dùng cả từ thường trùng tên dòng xe (Touring, Street, Sport, Cruiser).
- Bản thiết kế revision 9 đã PASS vẫn được giữ dùng; bản Colophon FAIL vì nội dung (part tên "Touring frame", prompt ghép 29.058 ký tự vượt trần 27.000).

### 19.5 Bản sửa T01–T09 sau lượt thiết kế thật đầu tiên (14/11)

- **T01 — giới hạn render:** model sheet moto chỉ gửi qua `POST /v1/images/generations` (OpenAI). Tài liệu `developers.openai.com/api/reference/resources/images/methods/generate` (đọc 14/11/2026): prompt "maximum length is 32000 characters", không phân biệt model; danh sách model có `gpt-image-2`, `gpt-image-2.6-flare`, `gpt-image-2.6-sunburst`. Đơn vị: ký tự.
- **T02:** mặt `both_sides` lấy điểm của sườn camera đang đứng (trái hoặc phải); mặt nhìn nghiêng đúng 90° có điểm 0 nên cận cảnh không lấy nhầm.
- **T03:** `partCamera` chọn trong nhóm đầu tiên có ứng viên (visible → partial → mọi view), kể cả khi không view nào thấy mặt nào.
- **T04:** trần `max_chars` của model sheet moto là 32.000, cùng số và cùng cách đếm (`mb_strlen`) với lúc gửi. Thông báo quá dài nêu bốn khối dài nhất.
- **T05:** trần độ dài không còn nằm trong mã băm chính sách. Bộ mở trước đó được nhận bằng trần đã ghim 27.000; bộ chỉ khác trần (cùng thiết kế, prompt, view, bố cục, quy tắc ảnh) được dùng tiếp, không mở bộ mới. Lỗi chặn được chấm lại theo giới hạn hiện hành khi xem và trước khi gửi; kết quả ghi lúc mở bộ giữ nguyên.
- **T06:** prompt revision 10 cấm các tên mà thiết kế chép vào `motorcycle.base` xuất hiện ở trường khác, không có danh sách từ chung. Thông báo lỗi liệt kê đúng các trường đã đưa tên vào prompt.
- **T07 — Colophon (không sửa dữ liệu):** prompt ghép 29.218 ký tự (dưới trần), P26 có mô tả mặt bên ở bốn panel, P06 → MS3, P09 → MS2. Còn chặn vì "Touring" ở `motorcycle.silhouette`, `motorcycle.engineering_notes`, `parts.0.name` (P01), `KEEP-SILHOUETTE`, `KEEP-CHASSIS`. Kết quả kiểm đã lưu vẫn là FAIL.
- **T08:** revision 10 chưa có trong DB lúc bàn giao. Seeder nạp revision gốc trước revision con dù file của revision con được đọc trước; chỉ lưu không kèm `based_on_id` khi revision gốc không có trong file dữ liệu nào và cũng không có trong DB.
- **Sau lượt rà soát:** nguồn của tên bị chặn được ghi ngay khi bộ ghép lấy từng đoạn chữ (hoa văn không hiện trên sheet và vị trí không được trưng bày không còn bị liệt kê nhầm); thêm hai ca kiểm hai worker cùng mở bộ model sheet bằng tiến trình thứ hai trên DB test.

### 19.6 Bản vá Scene và bản phát hành v2 (14/11)

**Nhánh A — dự án đang làm (bản `moto-cinematic-3s-v1`)**
- Phase 6 revision 8 (`phase-6-rev8.md`): `continuity_from` là liên kết liền mạch vật lý; hai scene ở hai build state khác nhau thì là null kể cả khi beat khai `time_jump: none`; cách vào scene kể ở `transition_in`; `continuity_start` viết từ nguồn của chính scene. Đầu vào, đầu ra, schema và cổng kiểm không đổi.
- Danh mục v1 của phase-6 liệt kê cả revision 7 và 8; lượt mới lấy bản mới nhất. Quay lại: bỏ revision 8 khỏi danh mục rồi nạp lại cấu hình, không xoá dòng trong DB.
- Kết quả thật: lượt "Chia lại scene" của Rolling Folio dùng revision 8, PASS, 0 lỗi. Bốn mâu thuẫn nội dung của kịch bản (xe tự đứng ở EV52/EV53, công tắc EV90, ánh sáng B18/B19, mũ găng chưa nhất quán) không được sửa trong bản vá này.

**Nhánh B — dự án mới (bản `moto-cinematic-3s-v2`)**
- v2 khai `extends` v1: phase và action nào v2 không nêu là của v1, cùng id và mã băm. v2 chỉ nêu phase-4 revision 14, phase-6 revision 8 và action `spec.script` với đầu ra `MotoScript@2.0.1`.
- Revision 13 (cùng chữ prompt, gắn schema 2.0.0) đã seed nhưng không nằm trong danh mục nào; giữ làm lịch sử. Revision 14 gắn `MotoScript-2.0.1.schema.json`, chỉ khác 2.0.0 ở mô tả `support_refs` để khớp với prompt.
- Phase 4 revision 14 (`moto-cinematic-3s-v2/phase-4.md`): xe thật đứng yên luôn khai vật giữ; xe đang chạy hoặc bản vẽ/mô hình thì không bắt buộc; ánh sáng các beat nối nhau cùng nơi, cùng build state phải khớp; đồ đã mặc giữ nguyên tới khi có sự kiện đổi; công tắc theo thiết kế; nguồn thiếu thì báo ở `flags`.
- Chính sách kiểm `stationary_requires_support` được ghi vào `context.policy` của từng lượt chạy thuộc v2. Chấm lại luôn dùng ngữ cảnh đã lưu của lượt, nên kịch bản của v1 và của bản cũ không nhận luật mới.
- Beat kết (schema bắt `situation: null`) vẫn có thể giữ một cấu hình bị giới hạn. Cổng trước khi lập shot và cổng kiểm shot dùng `MotoScriptCheck::situationFor()`: lấy tình huống beat tự khai; không có thì đọc trạng thái vận hành tại đúng thời điểm đang kiểm (đầu shot = `operating_start` của sự kiện đầu shot phủ, cuối shot = `operating_end` của sự kiện cuối; trước khi lập shot = trạng thái cuối cùng còn giữ cấu hình đó). Xe đứng yên, máy và vị trí chân chống đọc thẳng từ trạng thái ấy (chân chống theo cấu hình của part `side_stand` trong thiết kế). Mặt đường, độ nghiêng và thủ tục chỉ lấy từ beat liền trước khi mạch không đứt: `time_jump: none`, cùng địa điểm, cùng build state, và từ cuối beat trước tới thời điểm kiểm xe luôn đứng yên, cùng `support_refs`, chân chống không đổi vị trí, cấu hình đang kiểm được giữ suốt. Thiếu căn cứ thì để chưa xác định và giới hạn tính là chưa đạt. Áp dụng cho cả v1 và v2, không cần revision mới.
- Phase 7 revision 7 (`phase-7-rev7.md`, 14/11): AI chia shot lần lượt từng scene trong một lượt gọi và trả một kế hoạch hoàn chỉnh; gom các sự kiện kế tiếp thành một cảnh quay liên tục 3 giây khi cùng vị trí thao tác, cùng nền, không hở thời gian và camera theo được; ranh giới beat không tự bắt mở shot mới; có bước AI tự kiểm trước khi trả. Không có trần sự kiện hay chỉ tiêu số shot; mọi sự kiện vẫn phải có shot. Schema `MotoShots@2.0.0`, đầu vào và việc lưu `document`/`derived` không đổi; Laravel không gom, tách hay sắp lại shot. Danh mục phase-7 của v1 liệt kê revision 6 và 7 (v2 kế thừa); quay lại đầy đủ hành vi cũ cần làm cả hai việc rồi nạp lại cấu hình: bỏ revision 7 khỏi danh mục phase-7 của v1, và gỡ `policy.shots` khỏi cả hai bản 3 giây. Chỉ bỏ revision 7 thì lượt mới dùng prompt revision 6 nhưng vẫn bị ba kiểm gom mới chấm. Lượt đã chạy giữ chính sách nó đã ghim; không xoá dòng nào trong DB.
- Chính sách gom `shot_grouping_policy = scene-continuous-v1` (`MotoShotsCheck::GROUPING`) nằm ở `policy.shots` của hai bản 3 giây và được ghim vào ngữ cảnh từng lượt lập shot. Với shot phủ hơn một beat, cổng kiểm đòi: `environment.mode` là `background` và nền thuộc giao các nền của mọi beat; beat được chạy sang khai `time_jump: none`; `operating_end` của sự kiện cuối beat trước bằng `operating_start` của sự kiện đầu beat sau (so bằng `MotoScriptCheck::canonical()`). Lượt không ghim chính sách chấm như cũ; giá trị lạ thì không chấm.
- Lượt thật của revision 7 (`a33933eb`, 14/11) ra 53 shot và FAIL 127 lỗi: 113 lỗi do AI chép nguyên cấu hình của trạng thái vận hành vào `configurations` của shot, 6 lỗi do bộ kiểm chấm shot giữa beat bằng tình huống và cấu hình cuối beat, 8 lỗi nội dung.

### 19.8 Không còn beat hook mở đầu — phase 4 revision 15, chỉ bản v2 (15/11)

- Quyết định của chủ dự án: vài giây hook mở đầu không còn là một beat/scene/shot riêng; hook sẽ được cắt từ phim đã dựng xong.
- Phase 4 revision 15 (`moto-cinematic-3s-v2/phase-4-rev15.md`): cung truyện là idea → fabrication → finish → experience → hero rồi ending; phim mở bằng phần ý tưởng; không viết beat hook, teaser hay flash-forward mở đầu. Các luật khác giữ nguyên revision 14.
- Schema `MotoScript@2.0.2` (`moto-cinematic-3s-v2/MotoScript-2.0.2.schema.json`): `act` không còn `hook`, `music_brief.hits.moment` không còn `hook`. Kịch bản có beat hook bị schema từ chối.
- Chỉ bản `moto-cinematic-3s-v2` dùng: danh mục phase-4 của v2 là revision 15, đầu ra `spec.script` của v2 là 2.0.2. Bản v1 giữ revision 12 có hook, nên Rolling Folio và mọi dự án đã ghim v1 không đổi.
- Dự án mới chỉ nhận revision 15 khi `MOTO_PIPELINE_RELEASE=moto-cinematic-3s-v2`. Chức năng cắt hook từ phim cuối chưa làm.

### 19.7 Kế hoạch shot chọn lọc trong ngân sách — phase 7 revision 8 (14/11)

**Hợp đồng**
- Prompt `phase-7-rev8.md`, đầu vào `moto-shots-input-v6` (thêm `film.shot_budget` và `origin` của từng part), đầu ra `MotoShots@2.1.0` (thêm `data.omissions` bắt buộc), format vẫn `moto-shots-v2`. Danh mục phase-7 của v1 chỉ liệt kê revision 8 (v2 kế thừa); revision 6, 7 và schema 2.0.0 nằm lại DB làm lịch sử.
- Chính sách ghim vào ngữ cảnh từng lượt, lấy từ `policy.shots` của hai bản 3 giây: `shot_grouping_policy = scene-continuous-v1`, `shot_editing_policy = editorial-20-v1`, `shot_budget = {max: 20, target: 20}`. Lượt không ghim chính sách biên tập vẫn phải dựng mọi sự kiện và mọi công việc.

**Luật**
- Tối đa `shot_budget.max` shot, mỗi shot 3000 ms; `target` không phải số tối thiểu. Trước khi gọi AI, số scene vượt ngân sách thì dừng.
- AI chọn sự kiện tiêu biểu; mọi beat có ít nhất một sự kiện lên hình. Sự kiện và công việc không lên hình phải nằm trong `omissions` (scene, `before_shot`, `event_ids`, `work_ids`, `reason`, `evidence`): mỗi sự kiện và mỗi công việc của nguồn ở đúng một nơi — hoặc trong shot, hoặc trong omissions. `before_shot` là shot mà phần lược đã xong trước 0 ms của nó; null là sau shot cuối của scene.
- Không lược sự kiện gắn thẻ chữ hoặc chỉ dẫn người dùng. Tính năng `Fx` và khoảnh khắc `Mx` mà kịch bản nêu phải có part trong khung ở ít nhất một shot của phim; các `design_refs` khác không còn bắt buộc theo từng beat.
- Công việc lược trước một shot được tính vào trạng thái mở shot (`MotoWork::shotPlaces`): part đã lắp ngoài hình thì khung đầu phải ghi nó đã lắp. Laravel chỉ tính từ ID nguồn đã khai, không tự thêm công đoạn.
- `configurations` của shot chỉ ghi cấu hình của part có trong `parts`; trạng thái cả xe nằm ở `operating_start`/`operating_end`.
- Ở build state kiểu `handover`, linh kiện nền (`origin: base`) mà beat của shot nêu được ghi `awaiting_install`; mọi part khác vẫn chỉ là bản vẽ hoặc mô hình.

**Revision 9 (`phase-7-rev9.md`, cùng đầu vào v6 và schema 2.1.0)** — danh mục liệt kê revision 8 và 9, lượt mới lấy revision 9; revision 8 chưa có lượt chạy thật. Thêm ba luật mà bộ kiểm đối chiếu:
- Công việc đi cùng sự kiện của nó. Sự kiện của một công việc là sự kiện kịch bản mà `evidence` của công việc trong bản chia scene trỏ tới (`MotoShotsCheck::workEvents()`). Shot thể hiện công việc thì phải diễn sự kiện đó; công việc chỉ được lược cùng mục với sự kiện đó, và không bao giờ khi một shot diễn sự kiện đó; sự kiện bị lược thì công việc của nó lược cùng mục. Công việc không trỏ tới sự kiện nào thì không lược được.
- `event_ids` và `work_ids` của mỗi mục lược ghi theo thứ tự nguồn.
- Phần lược trước một shot được gửi cho AI viết prompt video ở `continuity.completed_before_shot` (sự kiện, công việc và nơi công việc để lại từng part), tách khỏi `timing.coverage` là phần shot diễn. Phase 10 revision 14 (`phase-10-rev14.md`, cùng đầu vào v4 và hai schema, danh mục liệt kê revision 13 và 14) nói rõ: mọi thứ trong khoá này đã xong trước 0 ms của clip, chỉ giải thích trạng thái khung đầu đã cho thấy, không dựng lại, không viết thành hành động của clip, không nêu ID.
- Giới hạn vận hành được kiểm ở mọi trạng thái sự kiện mà shot đi qua, không chỉ đầu và cuối shot: part trong khung ở một cấu hình bị giới hạn tại bất kỳ mốc sự kiện nào bên trong shot mà chưa đạt điều kiện thì bị báo "giữa shot…". Mở rồi đóng trong cùng một shot không che được vi phạm ở giữa.

**Bộ kiểm đọc đúng thời điểm (áp cho mọi lượt có sự kiện)**
- Tình huống beat khai chỉ áp cho đúng trạng thái cuối cùng của beat, xác định bằng vị trí trong chuỗi sự kiện; một trạng thái sớm hơn có cùng giá trị (mở → đóng → mở) không được hưởng tình huống đó, và dữ liệu ở cuối beat không làm đổi kết quả của thời điểm trước.
- Hệ quả đã đo trên kịch bản Rolling Folio: chỉ hai thời điểm bị coi là chưa đạt — B17, cuối EV77 và đầu EV78, nắp bình xăng mở (`P16-K02`, giới hạn L03 cần `park`, `switch_engine_off`, `lift_flap`). Shot cho hiện nắp bình mở ở hai thời điểm đó sẽ FAIL; AI phải lược hai sự kiện này hoặc để P16 ngoài khung.
- **Bản xác nhận thủ tục (14/11).** Tên thủ tục của giới hạn là chữ tự do, nên bằng chứng cho từng thủ tục được khai riêng theo dự án bằng lệnh `php artisan video:moto-procedure-evidence {project} --set=thủ_tục=loại --reason=… [--apply]`. Loại bằng chứng: `stationary`, `engine_off`, `side_stand_down` (trạng thái đang đúng tại thời điểm kiểm, không cần diễn lại thao tác) và `enters_configuration` (thủ tục chính là việc đưa part vào vị trí bị giới hạn: có sự kiện của beat đưa nó vào và nó được giữ tới thời điểm kiểm). Thủ tục không được khai vẫn chỉ có căn cứ ở tình huống mà nguồn khai. Bản xác nhận lưu ở `metadata_json.moto_procedure_evidence` của dự án, gắn với mã băm của giới hạn thiết kế và trạng thái sự kiện của kịch bản (`MotoShots::evidenceSource()`); nguồn đổi thì bản xác nhận hết hiệu lực. Lượt mới ghim nó vào ngữ cảnh và gửi cho AI ở `procedure_evidence`; lượt đã lưu mà chưa ghim thì được chấm lại với bản xác nhận khi hiển thị và khi duyệt, kết quả cũ trong `output_json.check` giữ nguyên, và kết quả chấm có dòng cảnh báo nêu bản xác nhận đã dùng. Phase 7 revision 10 (`phase-7-rev10.md`) mô tả đúng cách này. Rolling Folio: `park = stationary`, `switch_engine_off = engine_off`, `lift_flap = enters_configuration`; lượt `a3398e2f` (20 shot) chấm lại PASS.
- **Bản xác nhận dạng bản ghi (`moto-procedure-evidence-v2`).** Mỗi bản ghi chỉ áp cho một giới hạn: `--set=L03:park=stationary`. Bản ghi `enters_configuration` còn nêu sự kiện: `--set=L03:lift_flap=enters_configuration@B17/EV77`; lệnh đọc từ kịch bản bộ phận, cấu hình rời đi và cấu hình đi vào (`P16`, `P16-K01 → P16-K02`) và từ chối sự kiện không thực hiện đúng chuyển đó. Khi chấm, thủ tục chỉ được nhận tại đúng beat, đúng sự kiện, đúng hai cấu hình đã ghi, và từ lúc sự kiện kết thúc tới khi cấu hình còn giữ. Mỗi bản xác nhận có mã băm (`MotoShots::evidenceHash()`: định dạng, nguồn, các bản ghi); mọi bản từng lưu nằm ở `metadata_json.moto_procedure_evidence_history`. Kết quả chấm mang `procedure_evidence` (mã băm + bản ghi) và quyết định duyệt lưu lại đúng khối đó trong `metadata_json`, nên sau này thay bản xác nhận vẫn truy được căn cứ lúc duyệt. Rolling Folio hiện dùng bản `sha256:e6c3f101…` với ba bản ghi của L03; bản v1 theo tên thủ tục nằm trong lịch sử.
- ID của sự kiện và công việc trong `completed_before_shot` nằm trong danh sách mã nội bộ của lượt viết prompt video: prompt nêu các mã này thì bị từ chối như mã của sự kiện shot diễn.
- Ở thời điểm khác, đứng yên, máy và chân chống đọc từ trạng thái sự kiện tại thời điểm đó; mặt đường lấy của beat. Thủ tục được xác nhận từng cái: `stop` khi các trạng thái của beat cho thấy xe đi từ chạy sang đứng yên, `lower_stand` khi chân chống đi từ gập sang hạ, `switch_engine_off` khi máy đi từ nổ sang tắt, và điều đó còn giữ tới thời điểm kiểm. Thủ tục không trạng thái nào thể hiện được (mở chốt, mở nắp…) chỉ có căn cứ ở tình huống mà nguồn khai, nên giới hạn cần nó tính là chưa đạt ở giữa beat.
- Luật "cuối shot ở vị trí beat không nêu" không áp cho shot có timeline; cuối shot đã được đối chiếu với `operating_end` của sự kiện nó hoàn tất.

**Quay lại revision 7:** đưa revision 7 trở lại danh mục phase-7 thay cho revision 8 với `input_format` `moto-shots-input-v5`, đặt đầu ra `spec.shots` về `MotoShots@2.0.0`, và bỏ `shot_editing_policy` cùng `shot_budget` khỏi `policy.shots` của cả hai bản (giữ `shot_grouping_policy`); rồi nạp lại cấu hình. Ba thứ phải đổi cùng lúc, nếu không prompt, schema và đầu vào lệch nhau. Lượt đã chạy giữ chính sách đã ghim; không xoá dòng nào trong DB.

**Chưa kiểm chứng bằng lượt thật:** số shot, việc vừa trần token, và chất lượng dàn dựng.

- Lập shot: trần output nâng từ 64.000 lên 128.000 token (`MOTO_SHOTS_MAX_TOKENS`). Lượt `a338ea88` của Rolling Folio bị cắt ở 64.000 khi mới viết tới SC11/13.
- **Từ 16/11 không còn biến môi trường `MOTO_PIPELINE_RELEASE`.** Bản mặc định cho dự án moto mới ghi thẳng trong `config/video.php` (`default_release`) là `moto-cinematic-3s-v2`; các dòng trong tài liệu này nhắc tới biến đó là lịch sử. Dự án đã ghim bản nào giữ bản đó, dự án đã có lượt chạy mà chưa ghim vẫn là legacy.
- (Lịch sử) Kích hoạt: đặt `MOTO_PIPELINE_RELEASE=moto-cinematic-3s-v2` trong `.env`. Dự án đã ghim v1 giữ v1; dự án chưa chạy DNA nhận bản mặc định mới.

### 19.9 Parts Detail thống kê từ `shot.parts` — phase 7 revision 11, phase 10 revision 15 (15/11)

Áp cho mọi dự án Moto, cũ và mới; category khác không đổi. Không sửa kế hoạch shot đã duyệt.

**Nguồn thống kê**
- Mỗi part có trong `parts` của một shot đã duyệt là một ô Parts Detail, dù shot có ghi `reference_needs` loại `design` hay không (`MotoReferencePlanner::partUses()`, dùng chung cho bộ lập ô và nguồn theo shot).
- Nhu cầu ảnh part mang mã `SHOT#part:Pxx`. Nhu cầu identity, nền, continuity giữ mã `SHOT#số`. Mục `design` AI ghi trong kế hoạch cũ chỉ còn góp câu `purpose`; không tạo ô, không quyết định part có ảnh.
- Part ID không có trong thiết kế, hoặc một part khai hai lần với hai `presentation` trong cùng shot: báo lỗi nêu shot và part, không bỏ qua.
- Part không còn shot nào dùng thì rời danh sách đang cần; ảnh và lịch sử duyệt giữ nguyên.

**Giữ ảnh đã duyệt**
- Fingerprint của ô chỉ theo nội dung ảnh (thiết kế, model sheet, góc nhìn, chữ của ô, `PART_VERSION`, `PART_PROMPT_VERSION`). Cách thống kê có phiên bản riêng `PART_USAGE_VERSION`, nằm trong `source_manifest.usage`, không vào fingerprint.
- Góc nhìn của ô phụ thuộc các shot dùng part, nên thêm shot có thể đổi góc. Vì vậy part đã có ảnh thì dùng lại góc của ô mà ảnh được tạo từ đó, đọc từ kế hoạch Reference mà master được render (`PartsDetailService::pinnedCameras()`), chừng nào model sheet còn view đó ở đúng góc đó. Không còn thì chọn lại bằng thuật toán, fingerprint đổi và màn Reference ghi "cần kiểm lại" kèm lý do.
- Rolling Folio: 22 part trong kế hoạch 20 shot, 7 ô đã duyệt giữ nguyên (P29 vẫn MS1), 15 ô còn thiếu.

**Nguồn theo shot (Keyframes, Render video)**
- Thẻ ảnh part của shot lấy từ `shot.parts`: gom theo master, mỗi master một thẻ và gửi nguyên master một lần; part chưa có master đã duyệt nằm ở một thẻ không ảnh nêu rõ part thiếu và chặn gửi.
- Màn Render video hiện số ảnh trên giới hạn của model, part của từng master kèm `presentation`, và part nào mất nguồn chi tiết khi bỏ master đó. Vượt giới hạn thì chặn, không tự bỏ ảnh.
- Áp cho cả dự án bản legacy: không còn nhánh nào chọn part theo nhu cầu `design` AI ghi. Keyframe chỉ nhận 4 ảnh, nên màn Keyframes có nút "Bỏ master này" / "Gửi lại master này" trên từng thẻ Reference master (`sources.design_off` trong lựa chọn của shot); master bị bỏ vẫn hiện kèm các part mất nguồn chi tiết, không được gửi, và không tính vào giới hạn. Lựa chọn này chỉ áp cho keyframe: clip dựng từ ảnh tham chiếu vẫn nhận master đó và có lựa chọn bỏ ảnh riêng ở màn Render video, theo giới hạn riêng của nó.
- Ảnh đã duyệt mà không đọc lại được góc nhìn của ô nó được tạo từ đó (kế hoạch Reference của master không còn hoặc không khớp) thì không được coi là đã duyệt, kể cả khi ô dựng lại có cùng fingerprint; màn Reference ghi lý do ở "cần kiểm lại".
- Khi kế hoạch Reference chưa lưu cho nguồn hiện hành, màn Reference hiện bảng tác động theo shot (`MotoClipSourceService::impact()`): master thêm/bớt so với bản lưu gần nhất, part chưa có ảnh, số ảnh đang gửi và ít nhất khi đủ nguồn trên giới hạn của cách render, shot vượt giới hạn, và trạng thái của prompt ảnh và prompt video đã duyệt lấy từ chính bộ kiểm của màn Keyframes và màn Render video. Master thêm/bớt tính theo master mà shot thật sự gửi, gồm cả master người dùng đã đổi hoặc bỏ.
- Phase 10 revision 15: ảnh loại `design` là bảng chi tiết part, chỉ cho hình dáng; part đứng thế nào trong shot do `shot.parts[].presentation` quyết định.
- Phase 7 revision 11: AI không viết nhu cầu `design`; schema `MotoShots@2.1.0` không đổi nên kế hoạch cũ vẫn đọc được.

**Nhân vật mặc trang phục kịch bản khi nguồn không nêu bảo hộ (15/11)**
- Bản chia scene ghi `items: null` khi beat không nói gì về bảo hộ; bộ lập ô gặp `null` thì không tạo ô nào cho người đó. Dự án có thể kê những nhân vật được dùng trang phục gốc trong kịch bản ở các shot như vậy: `php artisan video:moto-character-outfit {project} --character=CH01 --character=… --reason=… [--apply]`, lưu ở `metadata_json.moto_character_script_outfit` (bản cũ vào `…_history`).
- Nhân vật được kê có đủ 8 góc, chân dung và một ô cho mỗi cách xuất hiện; ô đó đánh dấu `outfit: script_outfit`, có mã riêng khác ô "nguồn xác nhận không có bảo hộ", và màn hình ghi "nguồn chưa nêu bảo hộ bổ sung — dùng trang phục kịch bản làm ảnh nhận diện". Nguồn giữ nguyên `null`; nhân vật không được kê giữ nguyên trạng thái cần kiểm.
- Mỗi quyết định gắn với dấu vân tay nguồn (`MotoCharacterPlanner::outfitFingerprint()`): mô tả nhân vật và, ở từng shot nhân vật xuất hiện mà nguồn không nêu bảo hộ, cách xuất hiện cùng scene, build state, khung đầu, hành động, kết quả và công việc của shot. Nguồn đó đổi thì quyết định không còn được áp, liên kết quay về "cần xác nhận lại"; đổi máy quay hay shot khác thì không ảnh hưởng. Bản lưu là `moto-character-script-outfit-v2` (`records`); danh sách không có dấu vân tay không được áp.
- Lệnh xem trước so với kế hoạch đang dùng: ô thêm, ô rời nhu cầu, ô đổi nội dung và từng liên kết đổi; khi lưu, lệnh dựng lại bản xem trước trong giao dịch và từ chối nếu nguồn đã đổi.
- Ảnh nhân vật của ô `script_outfit` mang `protection_source: not_stated` bên cạnh `items: []` trong manifest ảnh của shot và trong đầu vào của lượt viết prompt video; ảnh của nhân vật khác không có trường này.
- Rolling Folio: đã kê CH01, CH06, CH07 (11, 10, 11 ô, lưới 3 × 4). Ô, fingerprint, liên kết và ảnh đã duyệt của CH02–CH05 không đổi. CH02, CH03, CH04 còn 8 liên kết chưa phân giải, ngoài phạm vi đợt này.

**Master nhận diện của nhân vật và Phase 10 revision 16 (15/11)**
- Chỉ áp cho clip dựng từ ảnh tham chiếu của bản cinematic. Keyframe của dự án legacy, yacht và đầu vào `moto-clip-prompt-input-v3` không đổi.
- Một người của shot có ô đúng cách xuất hiện đã duyệt thì gửi như cũ (master nguyên tấm kèm mã ô). Không có ô đó — nguồn không nêu bảo hộ, hoặc master chưa có ô cho cách xuất hiện này — thì shot nhận **master nhận diện** của nhân vật (`MotoCharacterReferenceService::identityMaster()`): master mà lựa chọn hiện có (`moto_character_selection.cells`) đang giữ cho các ô góc và chân dung. Fingerprint của các ô này chỉ theo mô tả nhân vật và cấu hình, không theo tập ô trang phục, nên thêm shot hay thêm cách xuất hiện không làm master mất hiệu lực. Không có nơi lưu thứ hai và mở trang không ghi gì.
- **Dấu vân tay nhận diện tách khỏi cấu hình ô trang phục.** Mỗi ô góc và chân dung có thêm `identity`: mã băm của nhân vật, mô tả, góc và các cấu hình chụp người (`style`, `same_person`, `rules`, `angle_convention`, `angle_show`, `portrait`). Lựa chọn của ô lưu `identity` khi master được duyệt; master nhận diện hợp lệ theo `identity`, nên đổi cách tả bàn tay, ô che mặt hay ô đồ bảo hộ không loại master. Lựa chọn lưu trước đó (chưa có `identity`) vẫn đọc theo fingerprint cũ; `php artisan video:moto-character-master {project} --stamp --apply` ghi `identity` cho các lựa chọn đang hợp lệ. Fingerprint của ô và ảnh đã duyệt không đổi.
- **Chỉ bản cinematic.** Nhánh master nhận diện bật theo `MotoPipelineRelease::cinematic()`; dự án legacy giữ nguyên cách chọn ảnh nhân vật ở cả keyframe lẫn clip từ ảnh tham chiếu.
- **Chọn master khi có nhiều.** `php artisan video:moto-character-master {project} --choose=NHÂN_VẬT=MÃ_ẢNH [--apply]` lưu `moto_character_selection.identity[nhân vật]`; chỉ nhận master đã duyệt của dự án đang giữ ô nhận diện của nhân vật đó, `NHÂN_VẬT=` để bỏ chọn. Không cần thu hồi ảnh nào. Lựa chọn không còn hợp lệ thì báo riêng, không tự rơi về master khác.
- Lỗi hiển thị tách theo loại: chưa có master đã duyệt; master tạo từ mô tả hoặc cấu hình khác; master đã chọn không còn hợp lệ; nhiều master cùng chứa ảnh nhận diện (cần chọn, không tự lấy bản mới nhất); master thiếu chân dung và ô chính diện cho shot lộ mặt. Thiếu dữ kiện trang phục không còn bị báo thành thiếu ảnh.
- Ảnh nhận diện gửi cho AI mang `reference_mode: identity_master`, `master_cells` (mọi ô master thật sự có, kèm hàng và cột, đọc từ bố cục đã lưu), `items` đúng như nguồn (`null` khi nguồn không nêu) và `protection_source`.
- Đầu vào mới `moto-clip-prompt-input-v5`: thêm `shot.assumptions` (mã nguồn S20) và `forbidden_prompt_ids` — chính danh sách mã mà cổng kiểm dùng để từ chối prompt. Fingerprint của mọi lượt viết đổi theo, nên prompt đã duyệt theo đầu vào v4 phải viết hoặc duyệt lại; lượt cũ vẫn đọc được trong lịch sử.
- Phase 10 revision 16 (`phase-10-rev16.md`): cách đọc master nhận diện; trang phục lấy theo shot, `items: null` là nguồn chưa nêu; người không bao giờ được gọi bằng mã, AI chọn cách gọi từ mô tả và tự rà toàn bộ `video_prompt` theo `forbidden_prompt_ids`; `support_refs` là vật đỡ xe, không phải vật đỡ chi tiết rời. Cổng kiểm mã nội bộ của PHP giữ nguyên; không có vòng tự gọi AI sửa lỗi. Schema đầu ra `MotoClipPrompt@1.0.0` giữ nguyên (phần mô tả của nó vẫn chỉ nhắc keyframe).
- Kế hoạch clip vẫn dựng được khi một shot vượt giới hạn ảnh: clip đó bị chặn `references_limit` và không liệt kê ảnh nào; trước đây 7 ảnh hợp lệ làm cả kế hoạch không đạt schema.
- Rolling Folio sau thay đổi: 18 shot chờ viết prompt video, SC01_SH01 cần viết lại (nguồn đổi), SC06_SH01 có 7/6 ảnh — cần bỏ bớt một ảnh ở màn Render video.

**Phim cuối dựng từ các clip đã duyệt, bao nhiêu cũng được (16/11)**
- `MotoFilmPlanBuilder` không còn chặn khi shot thiếu clip đã duyệt. Phim gồm các clip đã duyệt theo đúng thứ tự shot, ghép liền nhau từ 0 ms; shot chưa có clip (chưa duyệt, mất file, sai mã băm, lệch kế hoạch) bị bỏ qua và được nêu trong `warnings` của kết quả với mã `shot_left_out`. Danh sách này nằm ngoài bản kế hoạch phim vì schema `MotoFilmPlan@1.1.0` chỉ nhận các mã cảnh báo cũ.
- Clip đầu của phim không có chuyển cảnh vào. Card chữ của kịch bản được dời theo shot nó nằm trên; phần card rơi vào shot bị bỏ thì bỏ theo, hai phần kề nhau sau khi ghép thì nối lại thành một.
- Vẫn chặn: không có clip nào được duyệt (`no_clips`), kế hoạch shot tự lệch thời gian, clip đã duyệt không khớp phần phim giữ, chuyển cảnh ngoài chính sách, card chồng nhau.
- Màn Final Composition không còn khối "Kế hoạch phim" và bảng "Chặn / cảnh báo"; lý do chưa dựng được hiện ngay dưới nút Render Final.

**Việc người dùng làm sau khi cập nhật:** vào màn Reference lưu lại kế hoạch (danh sách nhu cầu đổi nên mã kế hoạch đổi), chọn lưới, render và duyệt master bổ sung. Shot có part chưa có ảnh bị chặn gửi clip cho tới khi master bổ sung được duyệt.

**Bản phát hành `moto-cinematic-3s-v3` — luật Scene/Work mới (16/11)**
- Áp cho dự án mới và cho dự án được chuyển riêng bằng lệnh. Dự án đã ghim `moto-cinematic-3s-v1`, `-v2` hay legacy giữ nguyên prompt, schema, bộ kiểm và luật work của mình, kể cả khi viết lại một bước.
- Bộ hợp đồng: Phase 6 revision 10 (`moto-cinematic-3s-v3/phase-6-rev10.md`, thay revision 9), đầu vào `moto-scenes-input-v4`, đầu ra `moto-scenes-v3`, schema `MotoScenes@3.0.0`, bộ kiểm `moto-scenes-check-v7`, bộ dẫn xuất `moto-scenes-plan-v3`; Phase 7 revision 13 với đầu vào `moto-shots-input-v8` (thay revision 12 / `moto-shots-input-v7`; schema `MotoShots@2.1.0` không đổi). `v3` mở rộng `v2` và ghi lại đủ `policy` (script, scenes, shots) vì policy không kế thừa.
- Luật `moto-work-tracking-v2` (`MotoWork::traceConnected()`), tách khỏi luật cũ (`MotoWork::trace()`, không sửa):
  - Mức chế tạo và vị trí là hai dữ kiện riêng. `make`, `finish`, `paint`, `assemble` không đổi vị trí; công việc đầu tiên xác lập vị trí theo sự kiện, part đang chế tạo có thể nằm trên xe. Vị trí đã xác lập giữ nguyên trong build state cho tới `test_fit`, `install`, `reinstall` hoặc `remove`.
  - Part chỉ đi theo part khác qua kết nối đã khai ở `connection_changes` (`part`, `parent`, `change: attach|detach`, `evidence`) của cùng build state. Cây thiết kế, cùng vị trí và `with` không phải bằng chứng đã gắn; part trong `with` chỉ cùng di chuyển trong chính thao tác đó.
  - Bị chặn, kèm tên công việc, part và bằng chứng: gắn hai part đang ở hai chỗ khác nhau; gắn part đang gắn vào part khác; đưa part đang gắn rời khỏi part nó gắn vào mà không `detach`; kết nối không nêu part nào của công việc.
  - Bổ sung sau review 16/11 (Phase 6 revision 10 nói rõ các điều này với AI; revision 9 chưa từng chạy, giữ làm lịch sử):
    - Hai đầu của một thay đổi kết nối phải có ở build state đang thao tác (`present` hoặc `in_progress`); có mã trong thiết kế là chưa đủ.
    - Vị trí của mỗi đầu khi gắn được xác định theo thứ tự: nơi công việc gần nhất trong build state để nó lại → nơi công việc đầu tiên của nó trong build state bắt đầu → trên xe nếu build state ghi `present` và không `in_progress` → chưa xác lập. Chưa xác lập thì không gắn được; hai đầu khác chỗ thì không gắn được.
    - Tách khỏi part khác với part đang gắn là mâu thuẫn và không được ghi nhận; kết nối thật giữ nguyên. Tách một kết nối chưa từng được work ghi thì được nhận theo bằng chứng sự kiện.
    - Thay đổi kết nối chỉ vào bản ghi (`items[].connections`) sau khi hợp lệ.
  - Bổ sung sau review thứ hai 16/11 (prompt revision 10 không đổi):
    - Part đã được work tách thì được ghi nhớ là "không gắn vào part nào" cho tới khi gắn lại; tách thêm lần nữa, dù khỏi cùng part hay part khác, là mâu thuẫn. Chỉ part chưa có lịch sử gắn/tách nào mới được tách theo bằng chứng sự kiện.
    - Snapshot của scene và beat đọc vị trí cùng một cách với bộ kiểm kết nối: part có công việc đầu tiên của build state nằm ở beat hoặc scene sau được giữ, ngay từ đầu build state, ở nơi công việc đó bắt đầu, kèm tên công việc đó. Nhờ vậy `detached_parts` và `unknown_location_parts` chuyển sang Shot khớp với dữ kiện đã dùng để chấp nhận kết nối, và hai scene liên tiếp cùng build state khớp nhau ở ranh giới.
- AI không còn viết `detached_parts`. Code suy ra ở bản dẫn xuất `continuity_start|end.detached_parts` của từng scene từ work hợp lệ (`being_made`, `removed`, `awaiting_install`; part trên xe không liệt kê; `[]` là đã xác lập không có; `null` khi scene chưa có xe thật hoặc chuỗi work mâu thuẫn) và `unknown_location_parts` — part đang chế tạo mà chưa công việc nào xác lập vị trí.
- Bằng chứng của work và `connection_changes` là ID sự kiện của chính beat: `{"path":"script.beats.B11.events.EV55.id","value":"EV55"}`. Sự kiện phải tồn tại và thuộc beat đó; `action`, `result` do code đọc từ kịch bản để hiển thị. `quote` ở transition và conflict vẫn phải nguyên văn.
- Ghim theo chuỗi: lượt Scene mới ghim `release`, `input_format`, `work_tracking_version`, `check_version`, `planner_version` trong ngữ cảnh kiểm; bản dẫn xuất mang `work_tracking_version`. Shot đọc luật từ bản chia scene đã chọn (`MotoWork::follow()`); bản không ghi luật thì theo luật cũ. Bản chia scene khác luật với bản phát hành của dự án thì không lập shot.
- Ghim release khi tạo dự án (`VideoProjectRepository::findOrCreateByArticleId()`); mặc định dự án mới là `v3`.
- Lệnh `php artisan video:moto-release` (xem trước, thêm `--apply` để ghi; không gọi model, không xoá lượt nào):
  - không kèm dự án: ghim mọi dự án moto chưa ghim về hành vi cũ — legacy nếu đã chạy Phase 2, ngược lại `previous_default_release` (`v2`). **Chạy một lần trên server ngay sau khi triển khai**, trước khi mở dự án cũ chưa ghim.
  - `{project} --to=moto-cinematic-3s-v3 --reason=…`: chuyển một dự án sang bản kế tiếp trực tiếp khi bản mới chỉ đổi Scene/Shot, dự án chưa chọn bản Scene/Shot nào và không có lượt Scene/Shot đang chờ hay đang chạy. Thay đổi lưu ở `moto_pipeline_release_history`.
- **Kế hoạch shot chỉ kiểm ID, không kiểm nội dung (16/11, theo yêu cầu chủ dự án; áp cho mọi dự án moto).** Đã gỡ khỏi `MotoShotsCheck` và `MotoShots::precheck()`:
  - phán đoán "tình huống của beat đã đạt giới hạn vận hành / thủ tục chưa" ở đầu, cuối và giữa shot (`… chịu L01 nhưng tình huống … chưa đạt`). Vẫn kiểm: mã cấu hình có trong thiết kế, part của nó có trong khung và có ở build state, không hai cấu hình cho một part.
  - so `value`/`quote` của bằng chứng trong `conflicts` và `omissions`. Vẫn kiểm đường dẫn bằng chứng trỏ tới thứ có trong đầu vào đã ghim (`MotoScenesCheck::evidencePathProblem()`).
  - Người duyệt tự đánh giá hai nội dung này. Lệnh `video:moto-procedure-evidence` đã bị xoá ở Đợt 3 (xem dưới).
- **Gỡ "chấm và kiểm nội dung" khỏi Laravel — Đợt 1 (16/11, áp cho mọi dự án moto).** Cổng PHP giữ schema, ID, liên kết nguồn và giới hạn kỹ thuật; không đọc câu chữ.
  - `MotoReferenceCheck`: bỏ soi từ chỉ người/dụng cụ/góc máy, màu, vật liệu, số, vị trí, finish và phép so số giữa dòng viết lại với dòng nháp; `changes[].unsupported` luôn rỗng. Còn kiểm: cell ID, fact ID, dòng nháp, mục hợp lệ, độ dài, và mã part/build state/work lạ lọt vào dòng (`idIssues()`).
  - `MotoDnaCheck`: bỏ dò tên nguồn, cụm tự nhận mới lạ, lặp theme/feature/motif, dấu hiệu nhận diện trùng, bắt `keep` chứa tên dòng máy, đếm `must_avoid` và số departure của nguồn mỏng. Còn kiểm: `from_id`, đánh số ID, chép đúng brief đã cho.
  - `MotoDesignCheck`: bỏ từ cấm, tên nguồn, tên hãng trong chữ và trong prompt model sheet ghép. Còn kiểm ở prompt ghép: độ dài, dòng panel, ID, tên trường, chỗ trống.
  - `MotoScenesCheck`, `MotoLocationsCheck`: bằng chứng chỉ kiểm đường dẫn có thật; không so `quote`/`value`. Bỏ cảnh báo tên nguồn/tên hãng và thiết bị tạm ở Locations.
  - `MotoShotsCheck`: bỏ so chuỗi `motif` và các cảnh báo nội dung (bề mặt, lặp góc, camera rộng, hơn hai người). `MotoScriptCheck`: bỏ cảnh báo beat ngắn, thiếu link, hero ngắn, hero moment.
- **Phase 1 `SourceCheck` (nhánh moto, `source-check-moto-v2`).** Không còn chấm điểm nguồn và so trích dẫn. Kết quả kỹ thuật: STOP khi không có đối tượng hoặc không phải xe máy; UNSCORED khi đối tượng mơ hồ, nhận diện mâu thuẫn, sai cấu trúc hay đường dẫn `issues`; còn lại RUN. `score_min`/`score_max` là null, `criteria` rỗng; flags chỉ còn `SOURCE_CONFLICT`, `NOT_HARLEY_SOURCE`. Màn Anchor hiện "Đạt kiểm kỹ thuật — nội dung nguồn do người duyệt đánh giá". Nhánh yacht không đổi.
- **Đợt 2 (16/11) — bỏ phán xét quy trình, trạng thái, hình học.**
  - `MotoScriptCheck`: bỏ tiến trình build state theo act, hero moment, mật độ card, luật xe đứng yên phải có vật đỡ, nối trạng thái vận hành giữa các sự kiện, vai sân khấu. Còn: beat/hero beat tồn tại, mã thiết kế có thật và có ở build state, `in_use ⊂ design_refs`, cấu hình có thật và một cấu hình cho mỗi part, đủ beat cho BS01–BS12.
  - `MotoScenesCheck`: bỏ luật bằng chứng của `match_cut`/`time_jump`, luật trạng thái `detached`, đèn, so khớp continuity giữa hai scene. Còn: ID, thứ tự, phủ beat, gom nhóm theo nơi/build state/thời gian, mã part và đèn có thật, nhân vật bảo hộ có trên hình.
  - `MotoWork`: lỗi ID (beat ngoài scene, thứ tự work, part không có trong thiết kế) tách khỏi mâu thuẫn nội dung. Mâu thuẫn không chặn và không xoá dữ kiện độc lập: part bị nghi ngờ vào `unknown_location_parts`, không vào `detached_parts`; bản dẫn xuất ghi `work_tracking_sound` và `work_contradictions`.
  - `MotoShotsCheck`: bỏ time-jump/nối state khi gom beat, luật camera (target, azimuth), presentation so với work, reveal, vị trí cấu hình. Còn: ID, thứ tự, phủ sự kiện, số học timeline và card, nền chung của take nhiều beat, work ↔ sự kiện/omission, cấu hình ⊂ trạng thái vận hành, ngân sách shot.
  - `MotoLocationsCheck`: bỏ luật mặt đất khô/ướt, kích thước sàn, cảnh báo render. `MotoDesignCheck`: bỏ so hình học giữa các hộp, so với DNA, logic giới hạn vận hành, hoạ tiết theo build state, đèn/feature/ngoại lệ theo state. Còn: ID, tập ID feature/departure của DNA, luật chủ sở hữu, số cấu hình hiển thị, `until_state` sau `from_state`, camera ghim, cấu trúc prompt.
- **T07 — bộ schema kỹ thuật `moto-technical-schema-v1`.** `MotoContract::technicalSchemas()` dẫn xuất từ schema đã phát hành một bản chỉ để kiểm câu trả lời, bỏ các số đếm nội dung: DNA (`identity_cues`, `departures`, `features`, `must_avoid`), Design (`systems`, `connections`, `features`, `signature_moments`, `palette`, `materials`, `parts`, `contact_points`, độ dài `name`, độ dài mô tả identity rule), Script (số beat tối thiểu, số card tối thiểu, số loop), Locations (`palette`, `must_show`). Schema đã phát hành và mã băm không đổi; AI vẫn nhận schema gốc. Giữ nguyên: `required`, `enum`, `pattern`, kiểu, 12 build state, 8 view, `maxItems` của `beats`/`events`/`camera.path`/`action_timeline`/ảnh của clip, `profile_curve`.
- **Đợt 3 (16/11).**
  - T06 `MotoReferenceSurface` (`moto-reference-surface-v3`): bỏ danh sách từ chỉ kim loại và phép dò tên finish trong câu chữ. Bề mặt chỉ lấy từ giá trị của thiết kế (nhóm bề mặt của build state, `kind`, `model_medium`, `in_progress`); không có thì `needs_check`. Hàm này chỉ còn được kế hoạch reference theo shot cũ và test gọi; Parts Detail đang dùng lấy bề mặt nguyên văn nên ảnh và lựa chọn đã duyệt không đổi.
  - T08: bản chia scene giữ bản dẫn xuất đã lưu lúc chạy. Khi bộ dẫn xuất hiện tại cho nội dung khác (không tính nhãn phiên bản và khoá mới thêm), hệ thống dùng bản đã lưu và hiện ghi chú, không tự thay. Shot đã có cơ chế này từ trước (`derived_problem`).
  - T09: xoá lệnh `video:moto-procedure-evidence` và mọi hàm chấm thủ tục (`situationFor`, `unmet`, `proceduresShown/Confirmed`, `evidenceFor/Problem`, `MotoShots::evidenced/evidenceSource/evidenceHash`). Lượt mới không gửi `procedure_evidence`; lượt cũ đã ghim khoá này vẫn đọc được, khoá bị bỏ qua; metadata `moto_procedure_evidence` của dự án cũ giữ nguyên.
  - T10: nhãn Scene là "ĐÃ CHỌN THEO KIỂM KỸ THUẬT" kèm "Nội dung: người duyệt đánh giá". Khi kết quả lưu lúc chạy khác kết quả hiện tại: "Lúc chạy: FAIL — bộ kiểm phiên bản cũ (…). Kiểm hiện tại: Đạt kỹ thuật (…)". Shot vẫn do người duyệt.
  - Dọn code chết: `figureIssues` và hằng hình học của `MotoDesignCheck`, `VALUE_KEYS`/`LINKED`, `MAKE_WORDS`/`SUPPORT_WORDS`/`ENCLOSED_KINDS`/`same()`/`texts()`, `NOVELTY_CLAIM`/`BASE_RELATIONS`, `OFF_MOTORCYCLE`/`WHOLE_SCALES`/`REVEALS`, `namableAt`.
- **Sửa sau review 16/11 (4 điểm).**
  - Mã part không có trong thiết kế (`part`, `with`, hai đầu của `connection_changes`) được kiểm trước mọi nhánh bỏ qua theo build state, ở cả hai luật work. Trước đó work đặt ở beat chưa có xe thật lọt được mã lạ. Việc beat chưa có xe thật vẫn chỉ là mâu thuẫn nội dung, không chặn.
  - Bản dẫn xuất đã lưu của bản chia scene chỉ được giữ khi xác minh được: lượt Scene mới lưu `derived_hash` cạnh `derived`. Bản đã lưu khác bản tính lại mà không có mã băm, hoặc mã băm không khớp, là lỗi toàn vẹn — không dùng, không tự thay, `approvedSource()` từ chối. Khớp mã băm thì giữ bản đã lưu kèm ghi chú. Hệ quả ở dữ liệu thật: Scene rev 1 của Dark Rider (bản legacy trong lịch sử, không phải bản đang chọn) nay báo không dùng được.
  - Hành vi giữ bản dẫn xuất chỉ áp cho `MotoScenes`; adapter khác đi đường cũ.
  - Tăng phiên bản các bộ kiểm đã đổi luật: DNA `v3`, Design `v3`, Script `v3`, Locations `v3`, Reference `v7`, Shots `v7`, Scenes `v8` (luật cũ) và `v9` (luật work v2). Kết quả lịch sử giữ nhãn cũ. Kết quả kiểm của DNA, Design, Script, Locations ghi thêm `technical_schema: moto-technical-schema-v1`. Vì `check_version` nằm trong đầu vào đã ghim của Phase 3, thiết kế đã có hiện "đầu vào khác lượt hiện tại" và màn thiết kế đọc kết quả kiểm đã lưu; model sheet đã duyệt không đổi.
- **Design moto cũ được đọc bằng bộ kiểm kỹ thuật hiện tại (sau review thứ hai 16/11).** `DesignApprovalLock::checkOf()` kiểm lại một bản thiết kế moto từ `raw_text` của nó và nguồn lượt chạy đã ghim (revision prompt, lượt DNA, manifest hợp đồng); không gọi AI, không ghi DB, kết quả lưu lúc chạy giữ nguyên làm lịch sử. Màn thiết kế, cổng duyệt (`sourceProblem()`) và bước mở model sheet dùng chung kết quả này, kể cả khi đầu vào không còn khớp lượt hiện tại. Thiết kế yacht vẫn đọc kết quả đã lưu. Thiết kế moto không kiểm lại được (thiếu manifest, thiếu câu trả lời gốc, mất revision prompt đã ghim, không đọc được DNA đã ghim, hoặc bộ kiểm báo lỗi) là `UNSCORED` kèm lý do: không mở model sheet, không duyệt mới; kết quả lúc chạy chỉ hiện ở dòng "Lúc chạy" làm lịch sử (`stored_check`), không bao giờ thay cho kết quả hiện tại. Trên dữ liệu thật: Design rev 1 của Rolling Folio (lưu FAIL vì tên hãng trong `final_prompt`) nay đạt; Design rev 3 của Dark Rider vẫn FAIL vì sai schema; mã băm thiết kế của mọi bản không đổi.
- **Màn Reference nhận đủ nhân vật của kịch bản (16/11).** `MotoCharacterPlanner::plan()` lập ô nhận diện (các góc và chân dung) cho mọi nhân vật có mô tả trong kịch bản, kể cả khi không shot nào dùng hoặc shot không nêu bảo hộ. Diện mạo theo shot giữ nguyên luật cũ: `null` vẫn là "cần kiểm", code không tự gán trang phục. ID, mã và dấu vân tay của các ô đang có không đổi, nên master đã duyệt vẫn hợp lệ.
- **Beat đứng trên bối cảnh của nhiều nơi (17/11).** Bộ bối cảnh Phase 5 có thể gán một beat vào bối cảnh của hai địa điểm (hành động đi từ không gian này sang không gian kế). Đây là dữ liệu hợp lệ ở mọi bước sau:
  - `MotoScenesCheck` không còn báo lỗi "beat dùng nhiều nơi"; vẫn giữ luật mọi beat của một scene phải có ít nhất một nơi chung. Scene mang đủ các nơi và bối cảnh của beat.
  - Phase 6 revision 11 (`moto-cinematic-3s-v3/phase-6-rev11.md`, dùng cho v3, v4, v5; đầu vào và schema không đổi): giữ nguyên mọi liên kết bối cảnh của beat; chỉ gom beat đó với beat liền kề khi cả scene có một nơi chung, không thì để thành scene riêng; không khai xung đột chỉ vì một beat dùng nhiều nơi. Revision 10 giữ nguyên chữ, chỉ mất cờ hiện hành.
  - Phase 7 nhận từng phần của beat kèm bối cảnh của nó; mỗi shot chọn một bối cảnh trong các bối cảnh của beat nó phủ.
  - `php artisan video:scenes-revise {project} {stage} {patch}` nhận thêm `remove_conflicts` và `remove_flags` (danh sách chỉ số của `data.conflicts` và `flags`). Gỡ conflict thì `status` được tính lại từ phần còn lại: còn xung đột chặn (của câu trả lời hoặc của bộ bối cảnh đã duyệt) là `partial`, hết là `ok`; bản sửa không nhận `status`. Vẫn xem trước, áp đúng mã, lưu thành revision mới không gọi AI, lượt cũ giữ nguyên.
  - `php artisan video:scenes-revise {project} {stage} --select [--apply]`: kiểm lại một lượt scene đã lưu như màn hình làm và, với `--apply`, chọn nó theo đúng luật của một lượt vừa chạy xong (đạt và `status` là `ok`, nguồn còn hợp lệ). Mở trang không tự chọn lượt nào.
- **Kế hoạch shot đạt kiểm thì tự duyệt (17/11, theo yêu cầu chủ dự án; thay cho "Shot do người duyệt" ở T10).** Lượt lập kế hoạch shot vừa chạy xong (trực tiếp hoặc qua hàng đợi) mà đạt kiểm, `status` là `ok`, bản dẫn xuất khớp bản đã lưu và nguồn còn hợp lệ thì được chọn ngay (`ShotSourceService::approvePassed()`); quyết định ghi `reviewer_id` rỗng và `basis: rule_pass`. Trang chuyển sang màn Reference với thông báo "Đã duyệt kế hoạch shot thành công". Lượt không đạt ở lại màn Scenes kèm kết quả kiểm. Lượt mới đạt thay luôn bản đang dùng, nên reference, keyframe, prompt và clip làm theo bản cũ chuyển sang cần cập nhật. Nút duyệt tay vẫn còn cho lượt chưa được chọn (ví dụ chọn lại một lượt cũ).
- **T11 — Phase 7 revision 13, đầu vào `moto-shots-input-v8` (chỉ bản v3).** Đầu vào thêm `motorcycle.signature_moments`: nguyên bản ghi của thiết kế (`id`, `hero`, `description`, `parts`, `configurations`, `limits`, `time_light`). Prompt revision 13 (`moto-cinematic-3s-v3/phase-7-rev13.md`) mô tả trường này và bỏ `procedure_evidence`; revision 12 giữ nguyên chữ và mã băm, chỉ mất cờ hiện hành. Kế hoạch shot đã lập theo revision 12 vẫn đọc theo hợp đồng nó ghim.
- Trạng thái local: Dark Rider ghim legacy; Rolling Folio giữ `v1` (kết quả kiểm, bản dẫn xuất, lựa chọn và đầu vào shot giống hệt trước khi đổi); Deadwood đã chuyển `v2 → v3`, lượt Scene rev 1 FAIL giữ nguyên trong lịch sử với 9 lỗi của nó. Chưa gọi AI: người dùng bấm tạo scene ở màn Scenes.
- **Kế hoạch shot phủ đủ sự kiện trong tối đa 20 shot (17/11, bản v4 và v5).** Một hợp đồng, nâng cấp tại chỗ: chính sách `full-coverage-budget-v1` thay `editorial-20-v1` ở bản v4 và v5, không thêm release nào. Dự án v4/v5 chỉ cần lập lại kế hoạch shot là ra bản mới; bản v1, v2, v3 và mọi kế hoạch shot đã lưu vẫn đọc theo chính sách chúng đã ghim.
  - Phase 7 chỉ viết cách quay scene. `MotoShots@2.3.0` (`moto-cinematic-3s-v6/MotoShots-2.3.0.schema.json`): shot gồm `camera` và `camera.path`, `composition`, `action_timeline`, `event_progress` (mỗi sự kiện `whole`, hoặc `start` → `continue` → `finish` qua các shot liền nhau của cùng scene), `first_frame`, `end_frame`, trạng thái vận hành và các ID liên kết (scene, beat, sự kiện, part, nhân vật, bối cảnh, card). Không có `work`, `shot_role`, `motion_plan`, `visibility_requirements`, `result` cấp shot; không có `render_duration_ms`, `mode`, `used_start_ms`, `used_duration_ms` — code đặt 3000 ms `reference_to_video` ở bản dẫn xuất, câu trả lời AI lưu nguyên. Mỗi bước timeline diễn tối đa một sự kiện; `data.omissions` phải rỗng.
  - Phase 7 revision 15 (`phase-7-rev15.md`, đầu vào `moto-shots-input-v9` có `film.coverage_policy`); Phase 10 revision 18 (`phase-10-rev18.md`, đầu vào `moto-clip-prompt-input-v7`): prompt video nhận `composition`, `event_progress`, khối `scene` (kèm vị trí của shot trong scene) và khối `events` — nguyên văn từng sự kiện shot diễn lấy từ kịch bản đã duyệt (`action`, `result`, nhân vật, `design_refs`, trạng thái vận hành) kèm tiến trình. Kịch bản quyết định điều xảy ra, shot quyết định cách quay và thời điểm; Phase 10 không chia lại shot. Revision 14, 17 và `MotoShots@2.2.0` (bản đầy đủ trường, chưa từng chạy) giữ nguyên làm lịch sử, không release nào dùng. Thư mục `moto-cinematic-3s-v6` chỉ là nơi chứa file của hợp đồng này, không phải tên một release.
  - Prompt video đã duyệt theo đầu vào cũ của dự án v4/v5 hiện là "nguồn đã đổi" và phải viết lại sau khi có kế hoạch shot mới.
  - Một nguồn tiến trình duy nhất là `event_progress`. Công việc của scene hoàn tất ở shot hoàn tất (`whole` hoặc `finish`) sự kiện cuối cùng gắn với nó; code tự suy ra (`MotoShotsCheck::completedWork`), AI không khai. Shot mới `start` hoặc `continue` một sự kiện không làm part đổi trạng thái.
  - Bộ kiểm: quá ngân sách, thiếu sự kiện, sự kiện ngoài scene, sai thứ tự, hoàn tất hai lần, chuỗi tiến trình không hợp lệ, id có trong `coverage` nhưng không bước nào diễn — đều là lỗi. Code không kiểm câu chữ của timeline hay bố cục. Trước khi gọi AI: kịch bản nhiều sự kiện hơn 20 shot × 6 bước, công việc không gắn sự kiện nào, chỉ dẫn chốt quá 6 mốc cho một shot thì dừng và báo.
  - Vượt 20 shot là xung đột chặn: kế hoạch không được chọn; không lược sự kiện, không ép tốc độ.
  - Thẻ shot ở màn Scenes hiện thêm tiến trình sự kiện và bố cục.
  - `php artisan video:moto-shot-coverage {project} [--stage=] [--json]`: chỉ đọc; theo từng scene in sự kiện nguồn, shot diễn từng sự kiện và chuỗi tiến trình, sự kiện thiếu/thừa/sai thứ tự/hoàn tất hai lần, công việc với sự kiện gắn và shot hoàn tất, số shot và thời lượng; mã thoát 0 khi phủ đủ, khác 0 khi còn lỗi.
  - Trạng thái local: đã seed. Dự án `a33cf1d4` (v4) qua được bước chuẩn bị lượt shot với revision 15 và schema 2.3.0 (13 scene, 48 sự kiện, 25 công việc đều gắn sự kiện); kế hoạch shot rev 2 cũ của nó vẫn PASS theo chính sách cũ cho đến khi có rev 3. Chưa gọi AI: người dùng bấm lập lại kế hoạch shot.
