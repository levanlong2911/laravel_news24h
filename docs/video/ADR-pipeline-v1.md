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
5. Mọi thất bại thành tri thức có địa chỉ (Defect Attribution theo tầng)
6. Rule 0 — abstraction mới phải trả rent bằng bằng chứng ĐANG tồn tại

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
