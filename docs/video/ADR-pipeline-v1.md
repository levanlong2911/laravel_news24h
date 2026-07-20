# ADR v1.0 — Auto Video AI Pipeline (PHƯƠNG ÁN TỐI ƯU CUỐI CÙNG)

> Chốt 2026-07-19 sau Sprint 1–3 (mọi quyết định đã trả rent bằng render thật, ~$3).
> Mục tiêu: kỹ sư ngành gật (quy trình đúng) · đạo diễn documentary gật (ngôn ngữ đúng)
> · người xem tin là footage thật. Chi phí ≤ $2/video đầu, ≤ $0.7/video sau cùng chủ thể.

## Sơ đồ 7 tầng

```
1. KNOWLEDGE   Truth Layer (facts có bằng chứng) + Production Ontology (domain = DATA)
2. CREATIVE    Producer + Director Notes + Review (Claude 2 call ~$0.04) → RenderPlan FROZEN
3. DESIGN      DAM: brief → candidates → NGƯỜI pick → FREEZE; Master Sheet (view×state×
               representation); semantic lock immutable(hull)/mutable(weather,workers,sky)
4. SPEC        Frame Specification + Motion Specification (8 lớp + weight) — prompt là
               OUTPUT của compiler, spec là INPUT; motion_frameworks/*.json versioned
               (mượn schema prompt_frameworks đã chạy thật ở khâu viết bài)
5. RENDER      Router theo GIÁ TRỊ CHUYỂN ĐỘNG: Kontext PRO (đổi state chủ thể) / dev
               (đổi môi trường) / i2i ≤0.45 (cell đúng) / Kling+negative (shot chủ đạo)
               / ảnh+micro-shake (shot phụ) / CROP $0 (B-roll). 1-hop từ anchor.
6. HẬU KỲ      Code cũ tái dùng: speed-ramp, TTS Kokoro, music library, mux, thumbnail,
               YouTube upload, analytics 48h
7. FEEDBACK    render_meta (hash·version·cost·score) → rubric → Defect Attribution theo
               TẦNG → knowledge/failures+wins (failure count = learning priority)
```

## Motion Specification — 8 lớp thứ tự ưu tiên (AI đọc đầu prompt trước)

| # | Lớp | Luật |
|---|---|---|
| 1 | objective | shot đang HOÀN THÀNH việc gì — 1 câu, đứng đầu tuyệt đối |
| 2 | primary (w=0.7) | hành động chính; KHÔNG để sparks/khói mở đầu |
| 3 | secondary (w=0.2) | verb-có-vai: "riggers guide" — cấm "workers walk" |
| 4 | environment (w=0.1) | bắt buộc CÓ NGUỒN: "smoke rises FROM the seam" |
| 5 | physics | trọng lượng/quán tính/lực căng: "settles under its own weight" |
| 6 | camera | MỘT đường liên tục + TARGET: "keeping the weld seam centered" |
| 7 | style | khoá thẩm mỹ, chống orange-teal |
| 8 | negative | gửi RIÊNG cho Kling (fal Kling có hỗ trợ negative_prompt) |

Cấm: "then/sau đó" (Kling không thi hành timeline), đổi góc máy giữa clip.
Bão hoà hiệu quả ~400–700 chars; Kling limit 2500 (cắt tại ranh giới câu).

## Nguyên tắc bất biến

1. Truth ⊥ Creative ⊥ Design ⊥ Render — Claude không viết prompt, renderer không thiết kế
2. Design-first + Freeze — consistency là hệ quả kiến trúc, không phải may mắn của prompt
3. Prompt sinh từ Specification — tri thức trong data, compiler càng "ngu" càng sống lâu
4. Trả tiền theo giá trị chuyển động
5. **Frame is Ground Truth** (Visual Truth precedes Motion Truth) — xem §Frame–Text
   Coherence bên dưới; đúng với mọi model image-to-video, không riêng Kling
6. Mọi thất bại thành tri thức có địa chỉ (Defect Attribution theo tầng)
7. Rule 0 — abstraction mới phải trả rent bằng bằng chứng ĐANG tồn tại

## Kinh tế (24 shot ngữ pháp documentary: establish→medium→close→insert)

Video đầu ~$2.0 · video sau cùng chủ thể ~$0.5–0.7 · nếu A/B model i2v rẻ pass: ~$1.3/$0.4.
Kling = 85% giá thành → đòn bẩy tối ưu luôn nằm ở tầng video, không phải tầng ảnh.

## RESERVED (điều kiện kích hoạt rõ — KHÔNG build trước)

Decision Layer · Shot Graph · Budget Planner (sau 50–100 video, thiết kế từ dữ liệu thật) ·
Knowledge Graph (khi có bộ suy luận tiêu thụ) · Semantic/VLM QA · quality_target đa nền tảng ·
auto prompt-scoring · DB+UI cho motion_frameworks (khi có người vận hành thứ 2 hoặc >5 domain
— copy pattern PromptFrameworkController) · LoRA-on-sheet (khi Kontext PRO không đạt) ·
3D/CAD (đích 10 năm) · nhân vật chính (identity mặt người).

## Lộ trình

1. ($0) MotionComposer + motion_frameworks versioned + harness metadata + kling negative
2. ($0.28) A/B beat 3: Composer vs layered
3. ($0) Dry-run domain 2 (car_production) — chứng minh đa chủ đề TRÊN GIẤY
4. (~$1.5–2) Áp 6 beat + benchmark model rẻ + bản 11s v2 full hậu kỳ
5. (~$2) Video end-to-end từ bài báo thật (Idea → Publish)

Đa chủ đề: thêm chủ đề = 3 file data (ontology + motion_frameworks + identity/brief);
engine dùng chung 100%. Giá trị tích luỹ = chất lượng ontology từng ngành (moat).

---

## AMENDMENT v1.1 — FREEZE (2026-07-19)

**ADR ĐÓNG BĂNG từ thời điểm này.** 80% thời gian = xây + render thật; 20% = chỉnh ADR
từ dữ liệu vận hành. Thay đổi lớn chỉ chấp nhận khi: 50–100 video đã render, có số liệu
retention/chi phí/lỗi, hoặc một vấn đề lặp lại không giải được bằng kiến trúc hiện tại.
KHÔNG thêm tầng mới nếu nó chỉ trả lời lại câu hỏi của tầng cũ.

### Rule 1–3 (hàng rào chống phình, bổ sung cạnh Rule 0)

- **Rule 1:** abstraction chỉ tồn tại khi ≥2 domain dùng chung (ShipPlanner ✗; ActionComposer
  dùng bởi Ship+Car ✓).
- **Rule 2:** module chỉ được sinh khi có CONSUMER rõ ràng (input→process→output→consumer;
  thiếu consumer = không tồn tại; cấm module làm đẹp sơ đồ).
- **Rule 3:** mọi field trong ontology phải có ≥1 Compiler/Renderer/QA thực sự đọc
  (field mồ côi = xoá).

### Điều chỉnh spec (5 điểm nhỏ, là DATA không phải module)

1. Ontology: `Stage → Task → Action` (Task = business unit: install_hull_block;
   Action = motion unit: lift/align/weld/inspect) — mở đường progress/schedule/inspection.
2. Frame Spec thêm `composition` (rule_of_thirds, hero_position, leading_lines,
   negative_space) — đạo diễn quyết, không để model tự chọn.
3. Motion Spec: `physics` → `micro_physics` (cloth, cable, hook, dust, reflection,
   water ripple) — thứ làm footage "thật".
4. `style` tách `visual_style` ⊥ `camera_style` (industrial documentary ⊥ Discovery handheld).
5. Feedback thêm `confidence` per composer output — planner sau này biết chỗ nào cần A/B.

**Cấm vĩnh viễn (trừ khi Rule 1–3 thoả):** Worker/Machine/Lens/Lighting/Environment/Weather
Planner — tất cả là DATA trong ontology/spec. Chỉ được thêm: Ontology, Motion Framework,
Prompt Framework, Provider.

---

## AMENDMENT v1.2 — Frame–Text Coherence (Core Invariant, 2026-07-20)

> **Visual Truth precedes Motion Truth.** Motion không được phép làm tăng độ phức tạp
> ngữ nghĩa của một frame — nó chỉ được làm lộ ra, biến đổi, hoặc chuyển động hoá những
> gì frame ĐÃ chứa. Đây là bất biến kiến trúc, đúng với mọi model image-to-video hiện
> tại và tương lai (Kling, Veo, Pika, Runway...), không phải "mẹo prompt" của một provider.

**Frame is Ground Truth.** Trong image-to-video, frame là nguồn chân lý DUY NHẤT. Motion
Specification chỉ được mô tả sự tiến hoá của thực thể ĐÃ tồn tại trong frame. Prompt
không được tạo ra thực thể mới. Cần thực thể mới → quay lại Frame Specification, không
viết thêm text.

### Animate vs Create

**Motion prompts may animate. Frame prompts may create.** Một câu, ranh giới tuyệt đối:
- Được: "worker walks" — nếu worker đã có trong frame
- Không được: "four workers appear" — nếu frame chỉ có 1 người

### Entity Budget

Motion Composer không được vượt số lượng thực thể mà frame thật sự chứa. Ví dụ frame có
2 workers + 1 crane + 1 yacht → Motion Spec giới hạn `workers ≤ 2, crane = 1, hero = 1`.
`crowd` field (đã code) là hiện thân đầu tiên của Entity Budget — con số phải khớp frame
đã duyệt, không phải con số mong muốn.

### Visibility & Occlusion

`camera_target` và mọi entity trong `secondary`/`hierarchy` phải là thứ **nhìn thấy được**
trong frame — không nhắm vào "engine room" (bên trong thân tàu, không thấy được) hay
"weld seam" chưa tồn tại. Reserved (chưa code, thêm khi có ca cần): `occlusion_level`
(none/partial/hidden) — metadata cho entity bị che một phần (vd worker đứng sau container)
để Motion Composer biết không thể "worker waving" nếu tay bị che.

### Motion Complexity Budget

Reserved (chưa code) — cấp độ `simple/medium/rich` giới hạn số nhóm chuyển động đồng
thời (`rich ≤ 4 moving groups`). Hôm nay budget thực tế đến từ chính Entity Budget + độ
dài prompt (bão hoà ~700–2000 chars); formalize thành enum khi có bằng chứng cụ thể
(Rule 0) — ví dụ một shot vượt budget mà vẫn render tốt, hoặc ngược lại.

### QA Checklist (trước khi duyệt một shot — hôm nay mắt người, tự động hoá khi có VLM)

```
✓ hero visible trong frame          ✓ camera_target visible (không occluded/bên trong)
✓ mọi secondary visible trong frame ✓ action vật lý khả thi (không đòi hỏi thứ frame không có)
✓ crowd count khớp số người trong frame  ✓ không có thực thể "hứa suông" (hallucinated)
✓ environment (sparks/nước/khói) khớp trạng thái frame (đã hàn/đang hạ/chưa chạm nước...)
```

### Escalation Rule (đỡ tốn tiền — sửa rẻ nhất trước)

```
1. Simplify motion      (bớt entity trong spec, $0)
2. Adjust motion spec   (đổi câu chữ khớp frame, $0)
3. Regenerate frame     (Kontext derive lại, ~$0.04)
4. Redesign shot        (đổi cell/view/state trong Design Sheet, tốn nhất)
```
Không nhảy thẳng bước 3–4 khi bước 1–2 chưa thử — bằng chứng 2026-07-20: sửa data
($0) trước, chỉ derive frame mới khi xác nhận frame thật sự thiếu chủ thể.

### Co-design Matrix — thay đổi nào sửa ở đâu

| Thay đổi muốn có | Sửa ở |
|---|---|
| Thêm người/vật mới | **Frame** Specification |
| Người/vật đã có chuyển động | **Motion** Specification |
| Thêm cần cẩu/thiết bị mới | **Frame** |
| Cần cẩu/dây cáp lắc | **Motion** |
| Thêm khói/lửa/nước mới xuất hiện | **Frame** (hoặc FX riêng nếu có) |
| Khói/nước đã có trong frame chuyển động | **Motion** |
| Camera đổi góc/hướng | **Motion** (`camera_path`/`camera_continuity`) |
| Thời tiết đổi | **Frame** |

### Bằng chứng thực nghiệm (2026-07-20, ~$0.60)

Spec Motion giàu field (hero_subject, crowd=4, causal_chain...) render trên frame KHÔNG
có rigger/block → 0 người xuất hiện, gần như tĩnh — model không "Create" được. Derive
frame mới (Kontext PRO, $0.04) có rigger+block → render lại → 2 người rõ nét, chuyển động
thật. Nhưng text vẫn nhắc "2 welder + inspector" không có trong frame → model bỏ qua
lặng lẽ (đúng dự đoán "Animate vs Create"). Chỉ khi crowd/text cắt khớp CHÍNH XÁC frame
(4→2, xoá sparks/smoke vì block chưa hàn, sửa camera_target khỏi "weld seam" chưa tồn
tại) → prompt và render nhất quán 100%. Xem `project_sprint2_result.md`,
`project_motion_prompt_formula.md` (memory).
