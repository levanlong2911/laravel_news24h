# Video Pipeline — Kiến trúc chốt (Production Grade v1.0)

> **Trạng thái:** 🔒 **FROZEN** — 2026-07-17
> **Nhánh:** `production-grade` (nhánh duy nhất; `feature/video-AI` không dùng nữa)
> **Thay thế:** toàn bộ FilmOS trên `feature/video-AI`

Nguồn sự thật duy nhất cho pipeline video. Mọi thay đổi kiến trúc phải sửa file này **trước** khi sửa code. Mục đích: thực hiện tuần tự, không lộn xộn như lần trước.

---

## 0. Rule 0 — Every abstraction must pay rent

> **Mỗi tầng phải chứng minh nó xoá được trùng lặp ĐANG TỒN TẠI, hoặc tiết kiệm code ĐANG TỒN TẠI.**
> Chưa chứng minh được → không được tồn tại.

**Rent phải trả bằng trùng lặp thật, không phải trùng lặp dự đoán.** "Sau này sẽ có 3 provider cùng cần CameraRig" là một lời hứa, không phải tiền thuê. Chính những lời hứa như vậy đã dựng nên kiến trúc 16 tầng bị vứt bỏ ngày 2026-07-17.

Luật này đứng trên mọi luật khác trong tài liệu. Khi phân vân, tầng đó **không** được build.

### Architecture Maturity

Mỗi tầng phải mang một nhãn. Không có nhãn = không được viết code.

| Maturity | Nghĩa | Được viết code? |
|---|---|---|
| **Stable** | Đã trả rent. Có trong build order. | ✅ |
| **Reserved** | Đã chốt tên + vị trí trong luồng. Chưa trả rent. **Seam, không phải implementation.** | ❌ |
| **Future** | Ý tưởng. Chưa có vị trí. | ❌ |

| Tầng | Maturity | Điều kiện thoát |
|---|---|---|
| Fact, Event, World, Story, Scene, Timeline, Continuity | **Stable** | — |
| VideoIR, ProviderIR, Compiler Passes | **Stable** | — |
| **NarrativeGraph** | **Reserved** | ≥3 use case đã kiểm chứng thật (hiện có 2: Moonrise, iPhone) |
| **RenderIR** | **Reserved** | ≥3 provider **đang chạy thật** cùng cần CameraRig/AnimationRig/PhysicsRig |
| DirectorAI, PhysicsEngine, SemanticOptimizer | **Future** | chưa có vị trí trong luồng |

---

## 1. Bất biến gốc

> **Laravel không biết Prompt Language tồn tại.**

Mạnh hơn "Laravel không sinh prompt" — và kiểm chứng được bằng máy (xem §8).

Laravel chỉ biết: `Subject`, `CameraIntent`, `LightingIntent`, `PhysicsIntent`, `MotionIntent`, `Emotion`, `Composition`, `Entity`, `Relation`, `Event`.
Laravel hoàn toàn mù về prompt.

| | Laravel | Python |
|---|---|---|
| **Là gì** | **Semantic OS** — hiểu nội dung | **Compiler + Runtime** |
| **Biết** | bài báo nói gì | model AI cần chữ gì |
| **KHÔNG biết** | prompt, AI model, provider, kỹ thuật render tồn tại | sự thật nào có trong bài báo |
| **Output** | `RenderPlan.json` | video |

**Hệ quả bắt buộc:**
- Thêm chủ đề (yacht → Tesla → sư tử → chiến tranh): chỉ mở rộng **dữ liệu** semantic ở Laravel. Python không sửa một dòng.
- Thêm provider (Kling → Veo → GPT Video → Runway Gen5): chỉ sửa **ProviderPass + Adapter**. VideoIR không đổi. Laravel không biết provider tồn tại.

Nếu một thay đổi buộc sửa cả hai bên → ranh giới đã vỡ. Dừng, sửa kiến trúc, không sửa code.

### Laravel emit Intent — Python quyết định Implementation

Đây là dạng tổng quát của bất biến trên.

`content_type` (`informational|visual|visual_image`) của kiến trúc cũ **bị xoá hoàn toàn.** Nó không thuộc Laravel, cũng không thuộc Python — **nó không được tồn tại.** Đó là ngôn ngữ của implementation.

Thay bằng `motion_intent`:

```
Laravel:  motion_intent = NONE     ("cảnh này bản chất gần như tĩnh")
              │
Python:       ├── + budget → Ken Burns
              ├── + budget → Flux Animate
              └── + budget → Kling
```

Laravel không bao giờ được nói "dùng Ken Burns" — Ken Burns là kỹ thuật render, mà Laravel không được biết kỹ thuật render tồn tại. Laravel chỉ trả lời: *"cảnh này có cần chuyển động không?"* Đó là semantic.

### Truth Layer ⊥ Planning Layer

Bên trong Laravel còn một ranh giới nữa, sắc không kém ranh giới Laravel/Python:

```
              Verified World Graph
          (TRUTH LAYER — bắt buộc có Evidence)
                       │
   ════════════════════╪════════════════════
                       │
                Planning Layer
         (INTENT / DECISION / STRATEGY)
                       │
                RenderPlan.json
```

| | Cần Evidence? | Ví dụ |
|---|---|---|
| **Truth** — *thế giới LÀ gì* | ✅ **bắt buộc** | `length_m: 101`, `hull_color: grey`, `e1: construction`, `r1: successor_of` |
| **Decision** — *quay THẾ NÀO* | ❌ **không** | `ORBIT`, `GOLDEN_HOUR`, `CENTERED`, `MAJESTIC`, `SLOW` |

**Evidence gác Truth, KHÔNG gác Intent.** Không bài báo nào ghi góc máy — áp evidence lên Decision thì Scene Planner không sinh nổi một scene hợp lệ nào. Camera/lighting/emotion là **lựa chọn đạo diễn**; bịa ra chúng là đúng việc.

Ràng buộc duy nhất của Decision:

> **Decision MUST NOT contradict the Verified World Graph.**

Không cần được bài báo nhắc tới. Chỉ cần không mâu thuẫn.

### Rule: Evidence never crosses the boundary

> Evidence is an internal concern of the Semantic OS. RenderPlan is post-verification. No evidence, quote, span, offset, provenance level, or Gatekeeper metadata is allowed to cross the Laravel → Python boundary. Python receives only verified semantic truth plus planning intent. Any request to re-verify or repair semantics is out of scope for the runtime and must be sent back to the Semantic OS.

Ranh giới là **Verified World Graph**. Từ đó trở đi mọi thứ là **Trusted Truth** — không provenance, không quote, không offset, không span.

Lý do không chỉ là Rule 0 (evidence không trả rent nào ở runtime). Lý do chính là **trách nhiệm**: nếu Python thấy evidence, nó sẽ có cám dỗ `if evidence weak → repair`. **Python không có quyền nghi ngờ semantic** — nó không đọc bài báo, nên "sửa" của nó chỉ là bịa. Muốn debug: `plan_id` → Laravel → Verified World Graph → Evidence. Python chỉ giữ `plan_id`.

---

## 2. Luồng tổng thể

```
                ARTICLE / DATA / SCRIPT
                          │
                          ▼
    ┌───────────── LARAVEL — Semantic OS ─────────────┐
    │                                                 │
    │  ── TRUTH LAYER (Evidence required) ─────────   │
    │     Normalizer  →  Evidence Index               │
    │                         ↓                       │
    │     LLM Extractor  (Hypothesis Generator)       │
    │                         ↓                       │
    │     Candidate World Graph                       │
    │                         ↓                       │
    │     Evidence Gatekeeper   (deterministic,       │
    │                            KHÔNG gọi AI)        │
    │                         ↓                       │
    │  ═══ Verified World Graph ═══ Trusted Truth ═   │
    │                         ↓                       │
    │  ── PLANNING LAYER (no evidence needed) ─────   │
    │     Editorial Interpreter  ← trạm đầu, §12       │
    │     Story Graph  │  Asset Knowledge             │
    │     Scene Graph  │  Character / Vehicle / Style │
    │     Timeline     │  Camera / Physics Intent     │
    │     Continuity   │                              │
    │     Business Rules                              │
    └─────────────────────┬───────────────────────────┘
                          ▼
                  RenderPlan.json
        (post-verification — KHÔNG mang evidence)
    ══════════════ RANH GIỚI HỆ THỐNG ══════════════
                          │
                          ▼
                  Video IR Builder
                          │
                          ▼
              Compiler Pass Pipeline
       SubjectPass → CameraPass → LightingPass
     → PhysicsPass → MaterialPass → WeatherPass
     → EnvironmentPass → MotionPass → FXPass
     → AudioPass → ContinuityPass → ProviderPass
                          │
                    ┌─────┴─────┐
                    │ [RenderIR]│  ← Reserved seam, chưa thi công
                    └─────┬─────┘
                          ▼
                     Provider IR      ← hết semantic từ đây
                          │
                          ▼
                   Prompt Compiler
                          │
                          ▼
                  Provider Adapter
     Kling · Veo · Runway · Pika · Flux · SDXL · Wan · Hunyuan
                          │
                          ▼
                Image / Video Runtime
                          │
                          ▼
                      Renderer
                          │
                          ▼
                    FINAL VIDEO
```

**Seam đã chừa (Reserved — KHÔNG thi công):**

```
Laravel:  Facts → Events → [NarrativeGraph] → Story Graph → Scene Graph
Python:   VideoIR → [RenderIR] → ProviderIR
```

Vị trí đã chốt. Khi trả được rent thì chèn vào đúng chỗ này — không phá ranh giới nào.

> ### ⚠️ Nửa PYTHON của sơ đồ trên là THIẾT KẾ, không phải đường đang chạy
>
> Kiểm bằng import thật (2026-07-30): nửa Laravel (tới `RenderPlan.json`) đúng
> 100% với code. Nửa Python (`VideoIR Builder → 12 Pass → ProviderIR → Prompt
> Compiler → Provider Adapter`) **chưa bao giờ trở thành đường chạy thật** —
> `tools/session_runner.py` không import `media_runtime.compiler` lần nào.
>
> Đường Python THẬT, ngắn hơn nhiều:
> ```
> RenderPlan.json → session_runner.py → director/motion.py (MotionComposer)
>                 → compiled_prompt → NGƯỜI DUYỆT → render_queued_shots.py → Veo
> ```
> `MotionComposer` làm đúng phần việc Syntax mà Pass pipeline được giao (§18.7),
> nên **ranh giới §1 vẫn nguyên vẹn**. Xem khối chi tiết ở §9.

---

## 3. Ontology chung — điểm sống còn

Muốn "render được mọi chủ đề" thì **Semantic phải hoàn toàn độc lập với domain**.

**KHÔNG tồn tại** `YachtPlanner`, `CarPlanner`, `AnimalPlanner`, `SportsPlanner`.
Chỉ có Planner tổng quát chạy trên ontology chung.

### Entity types (enum đóng)

| type | ví dụ |
|---|---|
| `human` | Jan Koum, cầu thủ, công nhân |
| `living_object` | sư tử, ngựa, cây |
| `vehicle` | Moonrise, Tesla Model S, xe tăng |
| `building` | nhà máy Tesla, Eiffel Tower, xưởng đóng tàu |
| `landscape` | đại dương, savannah, sa mạc |
| `physical_object` | robot, tấm thép, pin |
| `event` | vụ mua bán, trận đánh, hạ thủy |
| `effect` | khói, nổ, tia lửa |

Một bài báo **chỉ là tập hợp Entity và Relation.**

```
Moonrise      → vehicle          Tesla Factory → building
Lion          → living_object    Eiffel Tower  → building
Explosion     → effect
```

### Luật chống switch(domain)

**Cấm** `switch($topic)` / `if ($topic === 'yacht')` ở bất kỳ đâu trong `app/Video/`.

Câu hỏi tự nhiên: *nếu không có domain planner, ai quyết định yacht thì có act "construction" còn sư tử thì có act "hunt"?*

> **Act = một node hoặc edge của World Graph được chọn để kể.**
> node = `Entity` | `Event` · edge = `Relation`

Story Graph suy ra từ **World Graph**, không suy ra từ topic. Cùng một đoạn code cho mọi chủ đề:

| Act | Nguồn | Moonrise | Sư tử |
|---|---|---|---|
| exposition | **Entity** | thân tàu, nội thất, 19.5 knots | bờm, savannah |
| happening | **Event** | đóng tàu, vụ bán | đi săn |
| comparison | **Relation** | `2025 successor_of 2020` | sư tử vs linh cẩu |

**Sửa 2026-07-17 (phát hiện khi dựng golden fixture):** bản freeze đầu ghi "Story ← Events" — sai, quá hẹp. Dựng thử 6 act của bài Moonrise thì chỉ 2/6 là Event (construction, sale); Introduction/Luxury/Performance là Entity, Comparison là Relation. Nếu giữ luật cũ, người viết Story Planner sẽ bí ở act "Luxury" và cách chữa cháy dễ nhất là `if (topic === 'yacht')` — đúng cái chết mà ontology sinh ra để tránh.

Đây là lý do **Fact/Event/Relation Extractor là thành phần quan trọng nhất ở Laravel.** Nó yếu thì cả hệ thống suy sụp, và cám dỗ sẽ là nhét `if (topic)` vào để chữa cháy — đó là lúc kiến trúc chết.

Domain knowledge được phép tồn tại, nhưng **chỉ dưới dạng dữ liệu** trong Asset Knowledge (Character/Vehicle/Style Library), **không bao giờ dưới dạng nhánh code.**

---

## 4. Identity — hai quyết định ở hai bên

Vấn đề: bỏ hết tên riêng khỏi ProviderIR thì mất Named Entity Recognition. `Titanic → ship, black hull, 4 funnels` là mất mát thật — model **biết** Titanic.

Nhưng: nếu Laravel gắn nhãn "model biết Ferrari F40", **Laravel đang khẳng định tri thức về model AI** → phá bất biến §1.

Cái bẫy cụ thể: `Titanic`, `Burj Khalifa`, `Statue of Liberty` model biết thật. **`Moonrise` thì không** — du thuyền 2025, quá mới và quá tối nghĩa. Tệ hơn, `Moonrise` là cụm danh từ thông thường: nhét vào prompt thì Flux vẽ **mặt trăng đang mọc** 🌕, không phải du thuyền. Entity chủ đạo của dự án chính là ca render_identity tệ nhất.

Nên tách làm hai quyết định, mỗi bên giữ phần mình có quyền biết:

| Bên | Quyết định | Câu hỏi |
|---|---|---|
| **Laravel** | `identity.visual_referent: bool` | *Tên này có ghim xuống một hình dạng cụ thể không?* `Titanic` ✓ · `Jan Koum` ✗ — **semantic thuần** |
| **Python** | ProviderPass **allowlist theo từng provider** | *Provider CỦA TÔI có biết tên này không?* — **tri thức về model** |

```
Laravel: { name: "Moonrise", visual_referent: true }
              ↓
Python ProviderPass allowlist (kling):
   Titanic ✓  ·  Burj Khalifa ✓  ·  Ferrari F40 ✓  ·  Moonrise ✗
              ↓
   ✗ → fallback về attributes: "grey hull, 101m, vertical bow…"
```

Allowlist xử lý cả hai rủi ro cùng lúc — model có biết không, và tên có bị hijack không — vì chỉ được thêm vào **sau khi test render thật**. Đây là **optimization, không phải bắt buộc**: allowlist rỗng thì hệ thống vẫn chạy đúng, chỉ kém tối ưu.

---

## 5. Hai tầng IR

```
Scene  →  VideoIR  →  ProviderIR  →  Prompt
```

- **VideoIR** — trung lập, không biết provider nào tồn tại. Thêm Veo: VideoIR **không đổi**.
- **ProviderIR** — đã tối ưu cho một provider cụ thể.
- **RenderIR** — *Reserved seam giữa hai tầng trên.* Xem §0.

### Luật: Provider không bao giờ biết Semantic

**ProviderIR chỉ chứa thuộc tính vật lý render được. Không bao giờ chứa danh tính hay nguồn gốc** (trừ tên đã qua allowlist §4).

```
✅ ProviderIR:  vehicle, 101m, grey hull, vertical bow,
                integrated satellite, no domes, long swim platform
❌ ProviderIR:  "Jan Koum", "Feadship", "sold for €325M"
```

### Luật: một Pass một trách nhiệm

**Không** có `PromptCompiler.py` 5000 dòng — nó sẽ thành monster. Kiến trúc compiler kiểu LLVM:

| Pass | Trách nhiệm |
|---|---|
| `SubjectPass` | entity → mô tả vật lý |
| `CameraPass` | CameraIntent → ngôn ngữ máy quay |
| `LightingPass` | LightingIntent → ánh sáng |
| `PhysicsPass` | PhysicsIntent → chuyển động vật lý |
| `MaterialPass` | vật liệu, bề mặt |
| `WeatherPass` | thời tiết |
| `EnvironmentPass` | bối cảnh |
| `MotionPass` | MotionIntent → chuyển động chủ thể |
| `FXPass` | hiệu ứng |
| `AudioPass` | âm thanh |
| `ContinuityPass` | enforce invariants/prohibitions |
| `ProviderPass` | **tầng duy nhất** biết Kling/Veo/Runway + giữ allowlist §4 |

---

## 6. RenderPlan contract v1.0

Ranh giới duy nhất. Versioned. JSON Schema gác cổng cả hai đầu.

> **Ví dụ dưới đây đã được VALIDATE bằng chính `contracts/renderplan/v1.0/schema.json`**
> (2026-07-30). Bản trước đó KHÔNG hợp lệ — nó còn `scene.emotion`/`scene.composition`
> ở cấp scene (đã bị §13 dời vào `aesthetic{}`), mà schema đặt
> `additionalProperties: false`, nên ví dụ chuẩn của tài liệu sẽ bị chính schema
> từ chối. Sửa ví dụ này thì **phải validate lại**, đừng sửa tay rồi tin mắt.

```jsonc
{
  "plan_version": "1.0",
  "plan_id": "3f2a1b4c-5d6e-4f70-8a9b-0c1d2e3f4a5b",
  "article_id": "a25f63f3-e22a-42e2-8b8b-21aeb109e72b",
  "generated_at": "2026-07-30T10:00:00+00:00",

  "story": { "title": "Moonrise sold for EUR 325M", "language": "en", "target_seconds": 60 },

  "world": {
    "entities": [
      {
        "id": "moonrise2025",
        "type": "vehicle",                 // ontology chung — KHÔNG phải "superyacht"
        "attributes": {                    // vật lý, render được → xuống ProviderIR
          "length_m": 101,
          "hull_color": "grey",
          "bow": "vertical",
          "amenities": ["beach club", "spa"]   // một tên MANG NHIỀU giá trị là hợp lệ
        },
        "identity": {
          "name": "Moonrise",
          "visual_referent": true,         // semantic: tên ghim một hình dạng cụ thể
          "semantic": { "builder": "Feadship" }   // KHÔNG bao giờ xuống ProviderIR
        }
      },
      { "id": "harbor", "type": "landscape", "attributes": { "weather": "clear skies" } }
    ],
    "relations": [
      { "id": "r1", "from": "moonrise2025", "to": "moonrise2020", "type": "successor_of" }
    ],
    "events": [
      { "id": "e1", "type": "construction", "entity_id": "moonrise2025" }
    ]
  },

  // Fact môi trường CẤP VIDEO (§13). OPTIONAL — vắng khi Truth im lặng hoặc khi
  // có ≥2 Landscape entity (không đoán cái nào ứng với cảnh nào).
  "world_environment": { "weather": "CLEAR", "medium": "WATER", "location": "the harbour" },

  // Nhận dạng thị giác BỊA CÓ CHỦ ĐÍCH, cấp VIDEO (§18.17). OPTIONAL.
  // Hai trạng thái vòng đời; compiler Python chọn theo scene (§18.20).
  "creative_identity": {
    "construction": { "visual_identity": "hull still bare grey steel, no paint, no name markings" },
    "final": { "visual_identity": "dark navy metallic hull, white superstructure, three decks" }
  },

  "facts": [
    { "id": "f1", "claim": "measures 101 metres", "entity_id": "moonrise2025",
      "visual_hint": "vertical bow, grey hull" }
  ],

  // Act = node|edge của World Graph. Đúng MỘT trong 3 ref.
  "acts": [
    { "id": "a1", "ordinal": 1, "source": "ENTITY",   "entity_ref": "moonrise2025" },
    { "id": "a2", "ordinal": 2, "source": "EVENT",    "event_ref": "e1" },
    { "id": "a3", "ordinal": 3, "source": "RELATION", "relation_ref": "r1" }
  ],

  "scenes": [
    {
      "id": "scene_1", "ordinal": 1, "act_id": "a1",
      "purpose": "REVEAL",
      "subjects": ["moonrise2025"],
      "motion_intent": "LOW",              // NONE|LOW|HIGH — thay content_type

      "camera":    { "framing": "WIDE", "movement": "ORBIT", "speed": "SLOW", "target": "moonrise2025" },

      // Editorial taste — BẮT BUỘC, không phụ thuộc chủ đề (§13).
      // CHÚ Ý: emotion/composition nằm Ở ĐÂY, KHÔNG ở cấp scene.
      "aesthetic": { "emotion": "MAJESTIC", "composition": "CENTERED", "light_intensity": "SOFT", "light_grade": "GOLDEN" },

      // World facts từ Truth — TẤT CẢ optional, vắng khi Truth im lặng (§13).
      "world":     { "medium": "WATER", "time_of_day": "GOLDEN_HOUR" },

      // "Tại scene này người xem cần nhận được điều gì" — HAI nguồn hợp lệ (§18.18).
      "objective": "Reveal the scale of the vessel against open water.",

      "fact_refs": ["f1"],
      "asset_refs": ["as_moonrise2025"],

      // Director chọn trong candidates do EditorialInterpreter sinh (§18.4).
      "director_notes": {
        "hero": "moonrise2025",
        "composition_note": "The vessel fills the frame from bow to stern, low horizon behind.",
        "micro_physics": ["A widening wake lengthens continuously astern."],
        "avoid": ["exposed radomes"]
      }
    }
  ],

  "timeline": [ { "scene_id": "scene_1", "start_sec": 0, "end_sec": 5 } ],
  "assets":   [ { "id": "as_moonrise2025", "kind": "structure", "entity_id": "moonrise2025", "required": true } ],

  "continuity": {
    "invariants": [
      { "entity_id": "moonrise2025", "attribute": "hull_color", "value": "grey", "scope": "always" }
    ],
    "prohibitions": [
      { "entity_id": "moonrise2025", "attribute": "domes", "value": true,
        "reason": "integrated satellite receivers instead of exposed radomes (2025 refit)" }
    ]
  },

  // Narrative cấp phim (§18.1). OPTIONAL — vắng khi chạy không có Producer.
  "producer": {
    "target_audience": "readers who follow luxury and design",
    "core_conflict": "a record price against an unseen owner",
    "visual_promise": "Viewers will see a 101-metre vessel move through open water at golden hour.",
    "emotional_curve": ["CALM", "MAJESTIC"]
  }
}
```

**Field OPTIONAL ở cấp root** (vắng hẳn key, KHÔNG emit rỗng): `world_environment`,
`creative_identity`, `producer`. **Ở cấp scene**: `world`, `objective`,
`fact_refs`, `asset_refs`, `director_notes`.

### Enum đóng

Điểm đồng bộ bắt buộc **duy nhất** giữa hai hệ thống. Thêm enum = sửa tài liệu này trước, sửa hai bên sau.

| Trường | Giá trị |
|---|---|
| `entity.type` | `human · living_object · vehicle · building · landscape · physical_object · event · effect` |
| `act.source` | `ENTITY · EVENT · RELATION` |
| `scene.motion_intent` | `NONE · LOW · HIGH` |
| `camera.framing` | `WIDE · MEDIUM · CLOSE · DETAIL · AERIAL` |
| `camera.movement` | `STATIC · ORBIT · PUSH_IN · PULL_OUT · PAN · TRACK` |
| `camera.speed` | `SLOW · MEDIUM · FAST` |
| `scene.purpose` | `REVEAL · ESTABLISH · PROCESS · DETAIL · ACTION · COMPARISON · RESOLUTION` |
| `aesthetic.emotion` | `MAJESTIC · TENSE · CALM · DRAMATIC · TRIUMPHANT · SOMBRE` |
| `aesthetic.composition` | `CENTERED · RULE_OF_THIRDS · SYMMETRICAL · LEADING_LINES` |
| `aesthetic.light_intensity` | `SOFT · NEUTRAL · HARSH` |
| `aesthetic.light_grade` | `WARM · COOL · NEUTRAL · GOLDEN · NOIR` |
| `world.medium` *(opt)* | `AIR · WATER · GROUND · SPACE` |
| `world.time_of_day` *(opt)* | `DAWN · MORNING · MIDDAY · GOLDEN_HOUR · DUSK · NIGHT` |
| `world.weather` *(opt)* | `CLEAR · CLOUDY · RAIN · SNOW · FOG · STORM · INDOOR` |
| `world.light_source` *(opt)* | `NATURAL · ARTIFICIAL · MIXED` |

`purpose` dùng `PROCESS` chứ không phải `CONSTRUCTION` — `CONSTRUCTION` là domain, `PROCESS` là ontology (hợp cả với sư tử đang rình mồi).

---

## 7. Cấu trúc thư mục

### Laravel — `app/Video/` (namespace `App\Video`)

> **Hai cây dưới đây liệt kê thư mục CÓ THẬT** (đối chiếu `ls`, 2026-07-30).
> Bản trước ghi `Contracts/` và `Knowledge/` (chưa bao giờ tồn tại), liệt kê
> `Editorial/` hai lần, bỏ sót 4 thư mục đang chạy, và mô tả `media_runtime/
> compiler/` là *"phần đang thiếu"* trong khi nó đã tồn tại. Sửa cây này thì
> đối chiếu `ls`, đừng viết theo trí nhớ.

```
app/Video/
│   ── TRUTH LAYER — xem §11 ──
├── Article/         ArticleNormalizer
├── Evidence/        EvidenceIndex, Evidence, ProvenanceLevel, Value/*Normalizer
├── Extraction/      Extractor (interface), ClaudeExtractor, CandidateGraphParser,
│                    CandidateWorldGraph, SemanticClaimPrecisionAnalyzer
├── Gatekeeper/      EvidenceGatekeeper, GatekeeperReport, Rejection   ← TRÁI TIM
├── World/           VerifiedWorldGraph, Entity, Relation, Event, Identity, EntityType
│
│   ── PLANNING LAYER — trạm đầu là Editorial, xem §12 ──
├── Editorial/       EditorialPolicy (data) + EditorialInterpreter (generic code)
│                    — taste, prohibitions, candidates, environment, duration weight
├── Story/           StoryPlanner, StoryGraph, Act, CreationArcPlanner (§18.16)
├── Scene/           ScenePlanner, SceneGraph, SemanticScene, ScenePurpose
├── Intent/          IntentPlanner, IntentScene, CameraIntent    ← camera+motion (P3)
├── Timeline/        TimelinePlanner, TimedScene, TimeRange       ← scheduler cơ học (P4)
├── Producer/        ProducerOutput, ClaudeProducer, FakeProducer ← narrative cấp phim (§18.1)
├── Director/        ClaudeDirector, ActionSelection              ← chọn trong candidates (§18.4)
├── Llm/             LlmClient, ClaudeWriterAdapter, GatedLlmClient,
│                    CostCeilingGate, DenyByDefaultGate           ← cổng chi phí
├── Analysis/        BenchmarkRunner, ConfidenceAnalyzer,
│                    RenderPlanQualityReport (§18.19)             ← quan sát, không chặn
├── RenderPlan/      RenderPlanAssembler, RenderPlanMeta          ← projection + validate (P5)
└── Pipeline/        VideoPlanningPipeline, VideoPipelineFactory
# KHÔNG tồn tại (đừng tạo lại): Contracts/ · Knowledge/ · Asset/ · Continuity/ · Rules/
# Knowledge library vẫn là DATA — hiện nằm ở config/video.php, không phải class.
```

### Python — `media_runtime/`

```
media_runtime/
├── director/          ★ ĐƯỜNG CHÍNH hiện tại — motion.py (MotionComposer, MotionSpec,
│                      entity_identity_facts, identity_visible, creative_identity_for),
│                      notes.py, expander.py
├── compiler/          contract.py (load/validate RenderPlan), video_ir.py, builder.py
│   └── passes/        base.py · subject.py · camera.py · aesthetic.py ·
│                      canonicalization.py · validation.py · prompt_compiler.py
├── design/            asset.py (DAM), router.py, ontology.py, data/*.json   ← §17
├── identity/          package.py (IdentityPackage)                          ← §16
├── providers/         luật FAL/Kling/Veo
├── render/            compositor, ffmpeg_builder
├── assets/            cache, downloader, uploader
├── core/              job_manager, scheduler, metrics
├── models/            data models dùng chung
├── afos/              di sản kiến trúc cũ — KHÔNG dùng cho đường mới
└── api/               fetch RenderPlan
# Điểm vào thật của pipeline: tools/session_runner.py (compose) và
# tools/render_queued_shots.py (render) — KHÔNG nằm trong media_runtime/.
```

**Danh sách 13 pass ở bản trước là KẾ HOẠCH, không phải thực tế.** `lighting`/
`physics`/`material`/`weather`/`environment`/`motion`/`fx`/`audio`/`continuity`/
`provider` chưa bao giờ được viết thành pass riêng — phần lớn logic đó hiện nằm
trong `director/motion.py` (`lighting_phrase()`, `camera_phrase()`,
`motion_environment_from_world()`, `motion_negative_from_scene()`). Đây là trạng
thái thật, không phải nợ cần trả: tách 13 pass khi chưa có trùng lặp là vi phạm
Rule 0.

---

## 8. Architecture Tests — CI fail, không phải code review

Không phải convention. Không phải review. **Test thật, CI đỏ.**

> **Tất cả nằm trong MỘT file: `tests/Video/Architecture/ArchitectureTest.php`** —
> quét bằng PHP tokenizer trên `app/Video/`, **bỏ qua comment** (nên viết lý do
> bằng từ bị cấm trong comment là hợp lệ). Bản trước đặt tên 8 class riêng
> (`LaravelIsPromptBlindTest`, `NoDomainBranchingTest`…) — **không class nào tồn
> tại**; nội dung kiểm thì đúng, tên thì bịa.

| Method (tên thật) | Kiểm |
|---|---|
| `test_laravel_is_prompt_blind` | `app/Video/` không chứa `prompt`, `cinematic`, `photorealistic`, `8k`, `mm lens`… (§1) |
| `test_no_domain_branching` | không chứa `yacht`, `feadship`, `switch ($domain)`, `$topic ===` (§3) |
| `test_no_render_technique_or_provider` | không chứa `kling`, `flux`, `veo`, `runway`, `ffmpeg`, `ken burns`, `content_type`, `image_to_video` (§1) |
| `test_contract_keeps_identity_separate_from_attributes` | `identity.semantic` không lẫn vào `attributes` (§4/§5) |
| `test_gatekeeper_never_calls_ai` | `app/Video/Gatekeeper/` không tham chiếu `claude`/`llm`/`Http::`/`rand`/`now()` (§11) |
| `test_the_word_derived_is_banned` | từ `derived` bị cấm — dùng `NORMALIZED_VALUE` (§11) |
| `test_planning_layer_cannot_reach_truth_provenance` | `Story/ Scene/ Intent/ Timeline/ Editorial/` không chạm `->evidence`, `->quote`, `->offset`, `EvidenceIndex`, `RawArticle` (§1) |

**Phía Python** (`tests/`, chạy bằng `.venv`): không có architecture test tương
đương. `tests/test_renderplan_contract.py` validate RenderPlan bằng
`jsonschema` — đó là kiểm **contract**, không phải kiểm **ranh giới kiến trúc**.
Ba test từng ghi ở bản trước (`test_topic_swap_tesla`, `test_topic_swap_lion`,
`test_provider_swap_veo`) **chưa bao giờ được viết**.

---

## 9. Build order (tuần tự — không nhảy cóc)

| # | Việc | Xong khi |
|---|---|---|
| **0** | ✅ RenderPlan JSON Schema + golden fixture Moonrise + Architecture Tests | ✅ 16 PHP + 13 Python xanh; hai bên đọc chung 1 file schema |
| **1** | ✅ Laravel: **Truth Layer** — Normalizer → Evidence Index → LLM Extractor → Gatekeeper → Verified World Graph (§11) | ✅ Moonrise dựng từ bài thật; Precision 94%; Gatekeeper deterministic |
| **2** | ✅ Laravel: Story Graph → Scene Graph | ✅ Act = node\|edge, importance = centrality; Scene = decomposition ngữ nghĩa |
| **3** | ✅ Laravel: Intent Planner — camera + motion | ✅ camera suy từ ScenePurpose, ranh giới đóng bằng type (không thấy EntityType) |
| **4** | ✅ Laravel: Timeline Planner | ✅ scheduler cơ học, TimeRange, gapless, phủ kín target |
| **~~4b~~** | ~~Asset Planner~~ — **BỎ** (Rule 0): `subject_ids → assets[]` chỉ là projection; dedup/cache là provider optimization thuộc Python. `assets[]` emit thẳng ở Assembler. AssetOptimizer (nếu có) đứng SAU RenderPlan bên Python, không chen giữa semantic pipeline. | — |
| **5** | ✅ Laravel: **Editorial Interpreter** (§12) → **RenderPlanAssembler** → emit | ✅ Editorial fill chỗ Truth im lặng; Assembler ráp Truth+Story+Scene+Intent+Timeline+Producer+Director → RenderPlan pass schema. Thêm `Producer`/`Director` (§18.1/§18.4) và `CreationArcPlanner` (§18.16) sau khi bảng này viết |
| **6-8** | ⚠️ Python: VideoIR Builder → Pass pipeline → ProviderIR + Prompt Compiler | **ĐÃ ĐI ĐƯỜNG KHÁC** — xem khối cảnh báo bên dưới |
| **9** | ✅ Render end-to-end | ✅ Đã render thật nhiều lần: Tequila yacht 8 scene (§18.9), Creation Arc v2 6 scene $1.08 (§18.17). ⚠ vẫn tốn phí — vẫn cần approval từng lần |

**Gate:** mỗi phase test xanh trước khi sang phase sau. Phase 0 cứng nhất — sai contract thì hai bên sai theo.

> ### ⚠️ Phase 6-8 KHÔNG diễn ra như kế hoạch — đọc trước khi sửa Python
>
> Bảng này (viết 2026-07-17) dự kiến đường
> `RenderPlan → VideoIR → 13 Pass → ProviderIR → Prompt`. **Đường đó chưa bao
> giờ trở thành đường chạy thật.**
>
> Kiểm bằng import thật (2026-07-30): `tools/session_runner.py` và
> `tools/render_queued_shots.py` **không import `media_runtime.compiler` một
> lần nào**. Consumer duy nhất của `compiler/` là `media_runtime/identity/
> package.py` — cũng nằm ngoài đường chạy.
>
> **Đường THẬT đang chạy:**
> ```
> RenderPlan.json
>     → tools/session_runner.py            (đọc scene, gate identity/environment/continuity)
>     → media_runtime/director/motion.py   (MotionComposer: enum + field → câu prompt)
>     → POST /api/render-plans             (Laravel lưu shot + compiled_prompt)
>     → NGƯỜI DUYỆT  ← §18.19 RenderPlanQualityReport hiển thị ở đây
>     → tools/render_queued_shots.py       (Veo 3.1 Lite)
> ```
>
> `MotionComposer` giữ đúng vai trò mà kế hoạch giao cho Pass pipeline —
> **Syntax thuần** (§18.7): dịch enum/field đã quyết bên Laravel thành câu, không
> tự nghĩ. Ranh giới §1 **không bị phá**; chỉ là nó được thi hành bằng một module
> thay vì mười ba.
>
> **Đây KHÔNG phải nợ phải trả.** Tách 13 pass khi chưa có trùng lặp nào là đúng
> thứ Rule 0 cấm. Ghi lại để: (a) không ai đi sửa `compiler/passes/` rồi ngạc
> nhiên vì prompt không đổi; (b) khi thật sự cần tách pass thì biết vị trí đã
> chốt sẵn.
>
> **Còn nợ thật từ Phase 8:** allowlist tên riêng theo provider (§4) — chưa bao
> giờ thi công, và §18.16 đã ghi rent đã đủ để làm.

---

## 10. Quyết định đã chốt

- **2026-07-17** — Bỏ toàn bộ FilmOS ở `feature/video-AI` (891 tests, 4 freeze). Viết mới trên `production-grade`, một nhánh duy nhất. *Chi phí đã nêu và được chấp nhận.*
- **2026-07-17** — Prompt compiling rời Laravel sang Python. Phá freeze "Prompting Layer ở Laravel". Lý do: prompt là chuyện của model, không phải của nội dung.
- **2026-07-17** — Bất biến nâng cấp thành **"Laravel không biết Prompt Language tồn tại"** — kiểm chứng bằng Architecture Test.
- **2026-07-17** — **`content_type` bị xoá, không thuộc về ai.** Thay bằng `motion_intent: NONE|LOW|HIGH`. Laravel emit Intent, Python quyết định Implementation.
- **2026-07-17** — **Identity tách hai quyết định:** `visual_referent` (semantic, Laravel) + allowlist theo provider (model knowledge, Python). Lý do: Laravel không được khẳng định model AI biết gì. `Moonrise` → 🌕 là ca thực tế chứng minh.
- **2026-07-17** — **Ontology chung thay domain planner.** Không `YachtPlanner`. Domain knowledge chỉ tồn tại dưới dạng dữ liệu.
- **2026-07-17 (sửa cùng ngày, phát hiện khi dựng golden fixture)** — **Act = node|edge của World Graph**, không chỉ Event. Bản đầu ghi "Story ← Events" là sai: 6 act của bài Moonrise chỉ có 2 là Event, còn lại là Entity (Introduction/Luxury/Performance) và Relation (Comparison). Giữ luật cũ thì Story Planner sẽ bí ở act "Luxury" → cám dỗ `if (topic)`. Thêm `act.source: ENTITY|EVENT|RELATION`.
- **2026-07-17** — **Rule 0: Every abstraction must pay rent.** Rent trả bằng trùng lặp đang tồn tại, không phải dự đoán. Mọi tầng phải có nhãn Maturity.
- **2026-07-17** — **NarrativeGraph = Reserved seam**, chưa thi công. Cần ≥3 use case thật (đang có 2).
- **2026-07-17** — **RenderIR = Reserved seam**, chưa thi công. Cần ≥3 provider đang chạy thật cùng cần CameraRig/AnimationRig/PhysicsRig.
- **2026-07-17** — Providers Python bảo toàn, không rewrite. Lý do: đã encode 5 luật FAL/Kling trả giá mới có.
- **2026-07-17** — **LLM là Hypothesis Generator, không phải Fact Extractor.** Semantic OS quyết định cái gì thành sự thật, không phải LLM. Gatekeeper deterministic, KHÔNG gọi AI.
- **2026-07-17** — **Truth ⊥ Intent.** Evidence gác Truth (thế giới LÀ gì), KHÔNG gác Decision (quay THẾ NÀO). Không bài báo nào ghi góc máy — áp evidence lên Decision thì Scene Planner bất khả thi. Decision chỉ cần *không mâu thuẫn* Verified World Graph.
- **2026-07-17** — **Evidence never crosses the boundary.** RenderPlan là post-verification. Python không có quyền nghi ngờ semantic; thấy evidence là sẽ có cám dỗ `if weak → repair`, mà "sửa" của Python chỉ là bịa vì nó không đọc bài báo. Debug qua `plan_id`.
- **2026-07-17** — **LLM không được cấp offset.** LLM trả `evidence_quote` nguyên văn; Gatekeeper tự `find()` trong Evidence Index để sinh offset. LLM đếm ký tự rất tệ và sẽ bịa offset trông hợp lý — tin nó là mất tính deterministic ở một chỗ kín đáo. Cách này bắt luôn ca LLM bịa cả câu trích.
- **2026-07-17** — **Từ `DERIVED` bị cấm trong code**, đổi thành `NORMALIZED_VALUE`. Lý do: `DERIVED` mời gọi diễn giải rộng, `INFERRED` sẽ chui vào qua cửa đó.
- **2026-07-17** — **Data Classification (§13): World fact ⊥ Editorial taste ⊥ Model prior.** Đổi contract: scene tách thành `aesthetic{}` (required, Editorial) và `world{}` (optional, Truth). `physics`/`environment`/`lighting.time_of_day` từ required → optional trong `world`. Lý do: `physics.medium=WATER` là world fact không phải taste; World Graph chưa có location (recall gap) nên Editorial không được bịa. Vắng thì để trống, provider tự điền bằng model prior. Bỏ Asset Planner (projection, không trả rent) — `assets[]` emit ở Assembler.
- **2026-07-17 (do TEST ĐỎ phát hiện, không do tranh luận)** — **`prohibitions` đổi nguồn sinh: Fact Extractor → Editorial Interpreter.** `domes: false` không qua nổi Gatekeeper vì nó là editorial interpretation, không phải verified fact. KHÔNG nới Gatekeeper. Editorial = trạm đầu của Planning Layer, **không phải tầng mới** (chi phí kiến trúc ≈ 0). Đúng **một** abstraction: `EditorialPolicy` (data) + `EditorialInterpreter` (generic code) — không chia 5 khái niệm cho 1 use case. Ba luật: knowledge là data không phải code · interpreter generic · read-only over World Graph (đã được type system bảo đảm miễn phí). Xem §12.

---

## 11. Truth Layer — Evidence Gatekeeper (Phase 1)

> **Bất biến: "Không có bằng chứng → không tồn tại."**

Đây là **trái tim của Semantic OS**. LLM ngày càng mạnh và sẽ bị thay; ontology sẽ mở rộng; provider sẽ đổi. Nhưng nếu Gatekeeper giữ được bất biến trên, thì mọi tầng phía sau — Story Planner, Scene Planner, Continuity, Python Compiler — đều được quyền tin rằng Verified World Graph là nguồn sự thật duy nhất.

### Luồng

```
Article (HTML)
    ↓  ArticleNormalizer      — clean HTML, giữ cấu trúc
    ↓  EvidenceIndex          — span map: body + headline + caption + table + metadata
    ↓  LlmExtractor           — HYPOTHESIS GENERATOR (Claude)
    ↓  CandidateWorldGraph    — chưa là sự thật
    ↓  EvidenceGatekeeper     — DETERMINISTIC, code thuần, KHÔNG gọi AI
    ↓
VerifiedWorldGraph            — Trusted Truth
```

Ba thành phần **độc lập**. Mai đổi Claude sang GPT-6 → chỉ thay `LlmExtractor`. Gatekeeper không đổi.

### Evidence ≠ chỉ body

Evidence có thể đến từ: `body span` · `headline` · `caption` · `table` · `metadata`. Không bắt buộc phải nằm trong thân bài.

### LLM trả gì

```jsonc
{
  "claim": "length_m",
  "value": 101,
  "evidence_quote": "101 metres",   // nguyên văn — KHÔNG offset
  "confidence": 0.92                // CHỈ để observability
}
```

- **LLM không bao giờ cấp offset.** Gatekeeper tự `find()` quote trong Evidence Index. Không thấy → **Reject** (trích dẫn bịa).
- **Confidence không tham gia quyết định.** Gatekeeper chỉ dùng Evidence. Confidence chỉ để quan sát.

### Gatekeeper — deterministic 100%

```
Candidate → span tồn tại? → ontology hợp lệ? → enum hợp lệ? → reference hợp lệ? → Verified
```

Không gọi AI. Không gọi Claude. Không gọi GPT. Code thuần.

### Provenance Level

| Level | Nghĩa | Ví dụ | Nhận? |
|---|---|---|---|
| `DIRECT` | span nguyên văn | `"101 metres"` | ✅ |
| `NORMALIZED` | khác format | `Grey` → `grey` | ✅ |
| `NORMALIZED_VALUE` | **hàm thuần của riêng span đó, không dùng tri thức ngoài** | `"101 metres"` → `101.0, unit=m` · `"€325M"` → `325000000` | ✅ |
| `INFERRED` | LLM đoán | `Feadship` → `country=NL` | ❌ **Reject** |

Ranh giới `NORMALIZED_VALUE` / `INFERRED`: cần **bất kỳ** tri thức ngoài span → INFERRED. `"Feadship"` → `Netherlands` cần knowledge base ⇒ Reject.

Từ `DERIVED` **bị cấm trong code** (Architecture Test canh) — nó mời gọi diễn giải rộng.

### Relation và Event cũng cần Evidence

- `successor_of` chỉ tồn tại nếu bài báo thật sự có `successor` / `replaces` / `based on` / `updated from`. LLM tự suy luận → **Reject**.
- Event `construction` không sinh ra vì entity là `vehicle`. Phải có `built` / `construction` / `shipyard` / `delivered` / `launched` → mới thành Event.

### Hai trạng thái, không phải một

`Candidate Entity` → `Verified Entity`. Kiểu dữ liệu **khác nhau**, không phải cùng một class với cờ boolean — để không thể lỡ tay dùng Candidate như thể nó là sự thật.

### Hệ quả với fixture Phase 0

`contracts/renderplan/v1.0/fixtures/moonrise.json` hiện khẳng định `builder: Feadship`, `owner: Jan Koum`, `price_eur` **không kèm mẩu evidence nào** → nó **sẽ không qua nổi Gatekeeper**.

Nó là **Golden Fixture *Architecture*** (hand-written, để chốt contract ở Phase 0), **không phải Golden Fixture *Extraction***. Phase 1 phải **sinh lại** nó từ bài báo thật; lúc đó fixture trở thành *output* của pipeline, không còn là input gõ tay.

---

## 12. Editorial Interpreter (Phase 5)

### Lỗi đã đẻ ra nó

Test `test_negative_boolean_facts_do_not_survive_extraction` chứng minh: **`domes: false` không qua nổi Gatekeeper — và đúng ra là vậy.**

Bài báo nói *"integrated receivers **instead of** radomes"*. Hiểu câu đó ⇒ `domes = false` là **suy luận**, không phải trích xuất. Không normalizer thuần nào đọc ra được.

**KHÔNG được nới Gatekeeper để cứu ca này.** Nới hôm nay thì ngày mai nó giữ luôn `"very large"` → `length > 80m`, rồi `"luxury yacht"` → `expensive = true`, và Truth Layer chết.

Nhưng contract v1.0 có `continuity.prohibitions` mà không thành phần nào sinh được nó. Đó là **rent thật** — layer này sinh ra từ một test đỏ, không từ suy đoán kiến trúc.

### Vị trí: trạm đầu của Planning, KHÔNG phải tầng mới

Editorial không sinh Truth mới — nó chỉ sinh **Decision**. Mà Decision thì vốn đã thuộc Planning Layer. Nên nó không thêm tầng nào; nó **đặt tên cho thứ vốn đã nằm trong Planning**:

```
Truth Layer      Evidence → Verified World Graph        (deterministic)
═════════════════════════════════════════════════════
Planning Layer   Editorial Interpreter   ← trạm đầu
                 Story → Scene → Timeline → Continuity  (được phép sáng tạo)
```

Chi phí kiến trúc ≈ **0**. `prohibitions` đã nằm sẵn trong contract từ đầu — chỉ là nguồn sinh bị gán sai.

### Nguồn sinh của prohibitions

```
❌ CŨ:  Fact Extractor → Prohibition

✅ MỚI: Verified World Graph  ─┐
                               ├→ Editorial Interpreter → Prohibitions
        Editorial Policies    ─┘
```

Ví dụ:

| | |
|---|---|
| **Truth** (có evidence) | `satellite = integrated` |
| **Editorial Policy** (world knowledge, là dữ liệu) | integrated receivers ⇒ không có radome lộ |
| **Decision** | `prohibit: domes = true` |

World Graph **không hề bị sửa**. Mai có ảnh chứng minh vẫn có dome → sửa Editorial Policy, **Truth không đổi**. Đó là Separation of Concerns.

### Đúng một abstraction

`EditorialPolicy` (**data**) + `EditorialInterpreter` (**generic code**). Không hơn.

Không tạo `VisualPolicy` / `DomainPolicy` / `ContinuityPolicy` / `StyleRule` — hiện chỉ có **một** use case thật (sinh prohibition). Chia năm khái niệm cho một use case chính là cái bẫy FilmOS, lần này khoác áo một lý do chính đáng.

Khi bài Ferrari đến: **không** tạo `FerrariPolicyEngine`, chỉ thêm một dòng **dữ liệu**. Interpreter chạy nguyên.

### Ba luật Editorial

Editorial là nơi **duy nhất được phép** dùng world knowledge. Nó cũng vì thế là **nơi ontology dễ chết nhất** — ai cũng sẽ nhét `if ($builder === 'Feadship')` vào đây, và lần này có kiến trúc bảo kê.

> **Rule #1 — Editorial knowledge chỉ tồn tại dưới dạng DỮ LIỆU, không bao giờ dưới dạng nhánh code.**
>
> ```yaml
> ✅  match:   { builder: Feadship }
>     prohibit: exposed_radomes
>
> ❌  if ($builder === 'Feadship')
> ```
>
> **Rule #2 — Interpreter phải hoàn toàn generic.** Nó chỉ biết `condition → action`. Nó không biết Ferrari, Lion, Tesla, Moonrise tồn tại. Interpreter chỉ được thao tác trên `EditorialPolicy` DTO, không được đọc literal domain. Review chỉ cần nhìn interpreter là biết có vi phạm ontology hay không.
>
> **Rule #3 — Editorial is read-only over the Verified World Graph.**
> Editorial chỉ sinh `recommendations` / `prohibitions` / `preferences` / planning decisions. **Không bao giờ** `entity.type = …`, `vehicle.length = …`, `builder = …`. Có quyền mutate Truth thì vài tháng nữa Truth sẽ bị "chữa cháy".
>
> *Bất biến này hiện đã được TYPE SYSTEM bảo đảm miễn phí:* `VerifiedWorldGraph` không có setter, `Entity::$attributes` là `readonly`, `$entities` private chỉ gán trong constructor. **Không viết nổi code vi phạm** — dạng mạnh nhất của một bất biến. Giữ nguyên tính bất biến đó khi sửa `app/Video/World/`.

### Editorial KHÔNG phải AI

Không gọi Claude. Không gọi LLM. Không inference. Nó là Rule Engine + Policies + Knowledge Base — **deterministic**.

Khác biệt với Gatekeeper: Gatekeeper **cấm** mọi external knowledge; Editorial **được phép** có. Nhưng cả hai đều deterministic, và cả hai đều không gọi AI.

---

## 13. Data Classification — World Fact ⊥ Editorial Taste ⊥ Model Prior

> Ranh giới quan trọng thứ ba, sau "Truth ⊥ Planning" (§1) và "Evidence never crosses boundary" (§1).

Mỗi trường mô tả một scene thuộc **đúng một** trong ba loại tri thức. Trộn hai loại vào một trường là lỗi kiến trúc.

| Loại | Nguồn | Trong RenderPlan | Vắng thì sao |
|---|---|---|---|
| **World fact** | Truth (có evidence) | `scene.world.*` — **optional** | **để trống**, KHÔNG default, KHÔNG suy luận |
| **Editorial taste** | Editorial policy (data) | `scene.aesthetic.*` — **required** | điền default thẩm mỹ |
| **Model prior** | world-knowledge của provider | *không xuất hiện* | provider tự điền lúc render |

**Bốn luật:**

1. **World fact chỉ sinh từ Truth** và có thể vắng. `world.medium`, `world.location`, `world.time_of_day`, `world.weather`, `world.light_source` — emit khi Truth có, omit khi không.
2. **Editorial chỉ sinh aesthetic metadata.** `aesthetic.emotion`, `aesthetic.composition`, `aesthetic.light_intensity`, `aesthetic.light_grade` — luôn có, default thẩm mỹ khi không có gì đặc biệt.
3. **Thiếu world fact KHÔNG được thay bằng suy luận hay mặc định.** "Missing" phải phân loại: missing aesthetic → fill; missing fact → leave missing. Không phải cứ thiếu là điền.
4. **Provider được phép dùng world-knowledge của chính nó khi world fact vắng** — đó là trách nhiệm của provider, không phải của semantic pipeline. Prompt "cinematic wide shot of a 99.95m grey-hulled vessel" không có "on water" vẫn ra du thuyền trên biển, vì Flux/Kling *biết* tàu thì nổi.

**Vì sao điều này quan trọng:** nó tách bạch *knowledge* (Truth), *taste* (Editorial), và *model priors* (provider). Editorial trở thành đúng nghĩa — chỉ làm đẹp, không bao giờ tạo/sửa/suy fact. Và nó tự giải quyết Backlog Recall: khi Extractor bắt thêm `location = Caribbean`, RenderPlan tự giàu hơn mà **Editorial không sửa một dòng code**. Nâng chất lượng Truth làm output giàu hơn, không làm Editorial phức tạp hơn — đó là dấu hiệu kiến trúc đúng.

**Editorial được phép:** thêm · làm đẹp · nhấn mạnh · giảm nhẹ.
**Editorial KHÔNG được phép:** tạo fact · sửa fact · suy fact.

---

## 15. Subject Consistency — một ảnh tham chiếu xuyên suốt (Rendering)

> ### ⚠️ MỤC NÀY ĐÃ BỊ THAY THẾ — đọc §18.15 và §18.20 trước
>
> Kết luận *"bắt buộc phải có ảnh tham chiếu → image-to-video. **Không có đường
> thứ ba**"* đúng với bằng chứng năm 2026-07-18, nhưng **kiến trúc hiện tại là
> text-to-video THUẦN** (chốt §18.14, cơ chế nhất quán ở §18.15/§18.20).
>
> Đường thứ ba hoá ra có thật, chỉ yếu hơn: **nhất quán bằng MÔ TẢ** — lặp lại
> cùng một câu nhận dạng (Truth `entity_identity_facts()` + Creative
> `creative_identity`) ở mọi scene, qua một cổng visibility chung (§18.20).
> Yếu hơn ảnh neo, nhưng không cần Flux/Kontext và không có bước i2v.
>
> Giữ mục này làm hồ sơ: nếu render thật cho thấy mức lệch identity vẫn không
> chấp nhận được thì đây là hướng đã biết hoạt động (§17 đã validate), đánh đổi
> bằng chi phí và độ phức tạp cao hơn.

> **Yêu cầu (2026-07-18):** trong một video, chủ thể (vd du thuyền) phải là CÙNG MỘT thiết kế xuyên suốt — từ ảnh tới video, từ cảnh thiết kế tới cảnh hoàn thiện.

**Ràng buộc kỹ thuật — không thể vừa T2V thuần vừa nhất quán:**
- **Text-to-video** (mỗi clip từ chữ, độc lập) → mỗi clip một chủ thể KHÁC. Không có cơ chế giữ nhất quán.
- **Nhất quán** → bắt buộc có **một ảnh tham chiếu (hero image)** dẫn dắt mọi render → **image-to-video** hoặc **image-to-image**. Không có đường thứ ba.

**Cơ chế:**
1. Sinh **hero image** MỘT lần (character sheet của chủ thể: định danh từ verified attributes — grey hull, vertical bow...).
2. Beat có chủ thể hoàn thiện → **image-to-video** từ cùng hero (`kling.py` `character_key` — đã có). Nhất quán MẠNH.
3. Beat giai đoạn khác (wireframe/hull thô/nội thất) → giữ **ngôn ngữ thiết kế** qua **image-to-image** từ hero (cần Flux img2img — `FluxAdapter` hiện chỉ text→image, là khoảng trống phải bổ sung).

**Bằng chứng:** render "Daybreak" 6 beat bằng T2V/Flux độc lập → 6 con tàu khác nhau về chi tiết. Xác nhận: consistency KHÔNG đến từ prompt (dù pin attributes), mà từ ảnh tham chiếu. Xem memory `project_render_evidence_moonrise`.

---

## 16. Design around Identity — mechanism là strategy hoán đổi

> **Thiết kế hệ thống quanh IDENTITY, không quanh Redux/LoRA.** Identity Package ổn định; cơ chế nhất quán (Redux / LoRA / i2v / model tương lai) là backend hoán đổi; một selector chọn backend.

Cùng pattern "IR ổn định, backend hoán đổi" đã dùng cho provider:

| Ổn định (hợp đồng) | Backend hoán đổi | Chọn backend |
|---|---|---|
| **Identity Package** | Redux / LoRA / i2v / future | Strategy selector |
| RenderPlan | Kling / Veo / Runway | Provider registry |

**Identity Package** (provider/mechanism-độc-lập — mô tả *subject LÀ AI*, không *render bằng gì*):
```
├── attributes verified  ← Truth Layer (ADN hình ảnh: grey hull, vertical bow...)
├── identity             ← VEntity.identity (name, visual_referent)
├── hero image(s)        ← ảnh tham chiếu render từ attributes
└── metadata             ← seed, mechanism, embedding
```
Truth Layer đã cho nửa định danh (attributes). Hero image neo vào đó. Package KHÔNG biết Redux/LoRA tồn tại.

**Strategy selector — RESERVED SEAM (chưa thi công):** không cần là "AI" ở v1 — như `provider registry` là DATA/rule: `mặc định Redux; subject >N cảnh + budget cao → LoRA`. Chỉ leo "AI selector" khi rule chứng minh không đủ. Cần ≥2 mechanism + 20-video benchmark trước khi build selector (Rule 0).

**Thứ tự (evidence-first):** Identity Package + Redux → validate MỘT subject → đủ tốt mới scale 20-video eval → LoRA chỉ khi Redux không đạt. Đừng dựng eval harness / selector trước khi mechanism chạy được một lần.

---

## 14. Rule 14 — RenderPlan is immutable

> **RenderPlan là artifact CUỐI CÙNG của Semantic Runtime. Sau khi validate thành công, nó KHÔNG BAO GIỜ được mutate.**
> Mọi optimization, normalization, provider adaptation, caching và prompt synthesis phải diễn ra trên **VideoIR** trong Media Runtime.

Đây là chiếc đinh cuối khoá ranh giới Laravel ↔ Python. Triết lý compiler: frontend sinh IR, IR bất biến ở đường biên, backend biến đổi trên bản sao runtime.

```
HTML → Semantic Runtime (Laravel) → RenderPlan.json
════════════════════ FREEZE ════════════════════
RenderPlan.json → VideoIR → [passes mutate VideoIR] → ProviderIR → prompt
```

**RenderPlan là document. VideoIR là runtime object.** Sau này `AssetOptimizer`, `ShotMerger`, `PromptOptimizer`, `CacheOptimizer`, `ProviderCapabilityResolver` — tất cả sửa **VideoIR**, không ai được sửa RenderPlan.

Kling đổi API → không sửa RenderPlan. Flux có feature mới → không sửa RenderPlan. Veo cần prompt khác → không sửa RenderPlan. Mọi thay đổi chỉ sau `RenderPlan → VideoIR`.

**Hệ quả — RenderPlan v1.0 FROZEN:** không thêm field nếu chưa thật sự bắt buộc. Ba thứ còn nợ (prohibitions, facts[].visual_hint, world facts từ Recall) là **enrichment**, không phải compile blocker — chúng làm RenderPlan giàu hơn qua chính các tầng đã có, không đổi structure. Làm sau khi Media Runtime chạy ổn.

---

## 17. Design Layer — Design-first, Master Design Asset (Sprint 2, VALIDATED)

> **Prompt-first chết ở consistency. Design-first: thiết kế sinh MỘT LẦN → NGƯỜI duyệt → FREEZE → mọi tầng sau chỉ THỰC HIỆN, không thiết kế lại.** Validated 2026-07-19: 6 beat Daybreak cùng MỘT con tàu từ sketch → blueprint → thi công → nội thất → hạ thủy → vận hành (~$0.80 học phí render).

**Logical Truth ⊥ Physical Truth** (đóng sổ tranh luận "spec làm render source"):
- **Logical Truth** = `brief.json` (facts + design intent + DNA + constraints + recipes + ontology). Hệ thống SỞ HỮU. Spec text KHÔNG pin được hình học (bằng chứng: 6 prompt cùng descriptors → 5 thiết kế) — spec là *công thức regen + checklist QA*.
- **Physical Truth** = anchor image ĐÃ ĐƯỢC NGƯỜI DUYỆT + sheet derive từ nó. Renderer chỉ hiểu Physical. Logical *compile* thành Physical qua t2i + human pick.

**DAM — Candidate → Approved → Frozen** (`media_runtime/design/asset.py`):
```
design/<subject_id>/
    brief.json      ← Logical Truth (design_id = identity_hash, content-addressable)
    candidates/     ← Industrial Design Session; NGƯỜI pick (design review)
    approved/       ← design.json + sheet.json + cells — nguồn DUY NHẤT cho pipeline sau
    history/        ← mọi cell bị thay được archive (provenance, rollback)
```
- Cell = **3 trục** `view__state__representation` (blueprint = đổi *representation*, construction = đổi *state* — không phải "loại view mới").
- **Freeze enforce bằng code**: `require_frozen()` chặn render film; `SheetFrozenError` chặn ghi cell sau freeze; `unfreeze()` phải có chủ đích. LoRA sau này train từ `approved/`, không từ candidates.
- Sheet **mở rộng đơn điệu**: view/state mới derive từ anchor đã freeze → không đổi cell cũ. Chỉ sinh cell CÓ BEAT TIÊU THỤ (Rule 0).

**Production Ontology — domain là DATA** (`design/data/*.json`): beat/cell lấy vocabulary THẬT của stage (`hull_erection`: dry dock, keel blocks, primer đỏ...) thay vì bịa ("workers welding" rỗng — beat 3 hỏng 3 lần trước khi có ontology). Domain mới = thêm file data, không thêm tầng. State axis của sheet lấy giá trị từ ontology.

**Render Router — luật mechanism trả rent bằng render** (`design/router.py`):

| Loại thay đổi so với cell nguồn | Mechanism | Bằng chứng |
|---|---|---|
| `none` (cell đúng state+scene) | i2i **strength ≤0.45** | i2i 0.6–0.9 tái sinh ảnh → trôi thiết kế mà vẫn không đổi được scene |
| `environment` (chỉ đổi bối cảnh) | Kontext dev | beat 5/6 đạt |
| `representation` (photo→technical...) | Kontext dev | blueprint đạt |
| `subject_state` (thi công, tháo dỡ) | **Kontext PRO** | dev trượt 3 lần liên tiếp; PRO đạt lần đầu |

- Mọi Kontext prompt **mở đầu bằng mệnh lệnh bảo toàn** (`PRESERVATION_PREFIX` — "Do not alter the subject...") TRƯỚC phần tả scene — đảo thứ tự là trượt.
- **Mỗi thế hệ sinh ảnh thêm một lớp trôi** → route 1-hop thẳng từ anchor khi có thể; tránh anchor→cell→beat 2-hop cho frame cuối.
- Negative prompt trên fal flux dev **không được hỗ trợ** — constraint phải vào positive phrasing hoặc backend có negative.

**Vai trò (đúng studio):** Industrial Designer đề xuất (t2i candidates) — **NGƯỜI là design review** (pick + QA 7 điểm: mũi/thân/nhịp cửa sổ/bridge/tỷ lệ boong/màu/nhận-ra-1-giây, 5/7 = freeze). Creative (Producer/Director) không được sửa design — chỉ được nói "reveal the scale", không được đổi bow. RESERVED: Industrial Designer AI đa-concept, Geometry QA tự động (CV/VLM), trục Representation đầy đủ, LoRA-on-sheet (leo khi Kontext PRO không đạt), 3D/CAD (đích 10-năm).

---

## 18. AMENDMENT — Hợp nhất Producer/Director, khép ranh giới Compiler (2026-07-21)

> **Bối cảnh:** phát hiện qua rà soát kiến trúc — `session_runner.py` (Python) và
> `MotionComposer`/`MotionSpec`/`motion_frameworks/*.json` (mô tả ở
> `ADR-pipeline-v1.md`) đang là **một pipeline THỨ HAI**, chạy song song với
> pipeline chính tài liệu này mô tả, tự quyết camera/lighting độc lập với
> `IntentPlanner`/`EditorialInterpreter` — không hề đọc `RenderPlan.json`. Hai
> nguồn chân lý cho cùng 1 semantic là lỗi kiến trúc, không phải chi tiết cài
> đặt. Amendment này chốt cách hợp nhất, có bằng chứng bằng code cho từng điểm.

### 18.1 Quyết định đã chốt VÀ ĐÃ CODE (Phase 1–2)

1. **Producer là nhánh song song, KHÔNG phải input của `StoryPlanner`.**
   `StoryPlanner::plan()` có bất biến **có Architecture Test canh** (chỉ đọc
   `VerifiedWorldGraph`, ranking bằng graph centrality thuần — xem §2, code
   `app/Video/Story/StoryPlanner.php` dòng 11-14). Nhét `ProducerOutput` vào
   chữ ký hàm này sẽ phá bất biến đó. Producer chảy thẳng vào
   `RenderPlanAssembler::assemble()` (tham số optional thứ 5), emit vào field
   `producer{}` **đã có sẵn trong `contracts/renderplan/v1.0/schema.json`**
   (trước đây được note "Validated bằng render 2026-07-19" nhưng chưa ai emit
   nó — `RenderPlanAssembler` chưa từng ghi field này).
   ```
                   VerifiedWorldGraph
                        │
           ┌────────────┴────────────┐
           ▼                         ▼
    StoryPlanner              Producer (LLM)
           │                         │
           ▼                         ▼
    StoryGraph              ProducerOutput
           └────────────┬────────────┘
                        ▼
               RenderPlanAssembler
   ```
   `ProducerOutput` **chỉ chứa narrative** (`target_audience`, `core_conflict`,
   `visual_promise`, `emotional_curve[]` — đúng tên field trong schema). KHÔNG
   chứa camera/lighting/action — đó là việc của tầng khác.

   Code: `app/Video/Producer/{ProducerOutput,ProducerInterface,ClaudeProducer,
   FakeProducer}.php` — đúng pattern `Extractor`/`ClaudeExtractor` (§11).
   Test: `RenderPlanAssemblerTest::test_producer_never_changes_acts_or_scenes`
   chứng minh bằng assertion, không chỉ bằng lời — cùng world, có/không có
   Producer thì `acts`/`scenes` giống hệt nhau (so JSON).

2. **`EditorialInterpreter::prohibitionsFor()` — hoàn thiện gap có bằng
   chứng.** `RenderPlanAssembler` trước đây hardcode `'prohibitions' => []`
   với comment "CHƯA xây engine policy" — không phải suy đoán, là code thật.
   Đã thêm `EditorialPolicy` (data, §12 Rule #1) + method mới
   `prohibitionsFor(VerifiedWorldGraph $world)` (generic, read-only — §12 Rule
   #2/#3), tiêm qua constructor `EditorialInterpreter(array $policies = [])`.
   Mặc định rỗng (không hardcode Feadship/domes vào code) — policy thật thêm
   khi có ca cần (Rule 0).

   Code: `app/Video/Editorial/EditorialPolicy.php`,
   `EditorialInterpreter::prohibitionsFor()`. 161/161 test xanh (7 test mới).

### 18.2 Camera/Lighting — một nguồn chân lý duy nhất (IntentPlanner)

`IntentPlanner::plan(SceneGraph $scenes)` (§7, code
`app/Video/Intent/IntentPlanner.php`) là **nguồn chân lý duy nhất** cho
`framing`/`movement`/`speed` — deterministic, suy từ `ScenePurpose`, khoá
bằng type system (hàm không nhận `VerifiedWorldGraph` nên **không làm được**
domain-branching, không phải "không nên làm"). `EditorialInterpreter::
aestheticFor()` tương tự cho `emotion`/`composition`/`light_intensity`/
`light_grade`.

**`DirectorNotes.camera_philosophy` (Python, `media_runtime/director/
notes.py`) nghỉ hưu khỏi vai trò quyết camera.** Field này có thể tiếp tục
tồn tại như **sắc thái phong cách** (PromptExpander dịch "stay below the
ship" → "low-angle shot" là lời văn thêm vào, KHÔNG ghi đè
`scene.camera.framing/movement/speed` đã quyết) — không xoá file, chỉ giới
hạn phạm vi.

### 18.3 "Semantic Scene Graph" đã tồn tại — chính là `VerifiedWorldGraph`

Không cần một tầng LLM trích xuất "entities/weight/equipment" mới. Truth
Layer (§11: `ClaudeExtractor` → `EvidenceGatekeeper` → `VerifiedWorldGraph`)
đã là nguồn duy nhất cho fact. Bất kỳ tầng nào (Candidate expansion, Director)
cần fact thật thì đọc `VerifiedWorldGraph`, KHÔNG trích lại từ bài báo — 2 lần
trích độc lập có thể ra 2 giá trị khác nhau, phá §1 "Evidence never crosses
the boundary".

### 18.4 Phase 3 — Candidate Expansion + Director (THIẾT KẾ, CHƯA CODE)

**Chưa implement — cần dry-run trước khi tin, đúng kỷ luật "render trước khi
tin" của dự án.** Ghi lại đây để không mất quyết định.

- `EditorialInterpreter` (hoặc sibling cùng 3 luật §12) thêm method
  `candidatesFor(Scene, VerifiedWorldGraph): array` — deterministic, đọc fact
  thật (vd `weight_tons`), sinh **tập hợp lệ** (`hero_candidates`,
  `primary_candidates`, `physics_candidates`) từ domain rule DATA — KHÔNG
  quyết trực tiếp nội dung cuối.
- Director (LLM, vai trò thu hẹp) **chỉ chọn** trong candidates — hero nào,
  emphasis nào, emotion/reveal gì — KHÔNG tự viết hành động từ đầu, KHÔNG
  quyết camera. Field output khớp `scene.director_notes{}` **đã có sẵn trong
  schema** (`narrative_goal`/`audience_emotion`/`reveal_strategy`/
  `visual_priority`/`camera_philosophy`/`avoid`/`style_shift`) — nhưng CHƯA rõ
  có đủ chỗ cho nội dung motion (`primary`/`secondary`/`micro_physics`) hay
  cần field mới; **quyết định khi implement, có dry-run**, không đoán trước.

**Lý do KHÔNG để Ontology tự sinh nội dung trực tiếp từ 1 label ngắn** (vd
`"install_hull_block"` → tự giãn `primary`/`secondary`): mất tính đặc thù bài
báo (số liệu, tên thiết bị cụ thể từ `VerifiedWorldGraph`), quay lại đúng vấn
đề "prompt sơ sài" đã khởi động toàn bộ cuộc rà soát này.

### 18.5 Phase 4 — Python đọc RenderPlan thay vì file tay viết (CHƯA CODE)

`MotionComposer` (`media_runtime/director/motion.py`) đã có sẵn hình dạng
input khớp gần 100% với RenderPlan.json:

| RenderPlan.json (đã có) | MotionComposer input (đã có) |
|---|---|
| `scene.camera.{framing,movement,speed,target}` | `lens_for_framing()`, `camera_phrase()` |
| `scene.aesthetic.{light_intensity,light_grade}` + `scene.world.{time_of_day,weather,light_source}` | `lighting_phrase()` — đúng 5 tham số |

Việc cần làm: đổi nguồn đọc trong `session_runner.py`/`motion.py` — nhận
`scene.camera`/`scene.aesthetic`/`scene.world`/(§18.4's output) từ
`RenderPlan.json` thay vì `MotionSpec.from_file(motion_frameworks/*.json)`.
Không đổi logic `compose()` đã validate bằng render thật (Sprint 1–3).

### 18.6 Đã cân nhắc và TỪ CHỐI — ghi lại để không đề xuất lại

| Đề xuất | Lý do từ chối |
|---|---|
| Cinematographer là LLM agent | `IntentPlanner` đã deterministic, đã ✅ (§9 Phase 3). Trùng rent đã trả. |
| Scene Planner là LLM agent | `ScenePlanner` đã deterministic, đã ✅ (§9 Phase 2). |
| Researcher (agent riêng) | Không có consumer khác Producer — vi phạm Rule 1/2. |
| Reviewer (agent riêng) | `ADR-pipeline-v1.md` v1.2 đã Reserved — QA thủ công ($0) đang đủ, chưa có bằng chứng cần tự động hoá. |
| Visual Story Analyst (module riêng) | Field trùng: `must_show`/`must_not_show` = `director_notes.visual_priority`/`avoid`; `continuity_objects` = `continuity.invariants` (đã code, deterministic, $0); `visual_risk` cần frame đã duyệt (chưa tồn tại ở giai đoạn này). |
| `semantic_density` trong bất kỳ contract nào | `ADR-pipeline-v1.md` v1.2 đã Reserved tường minh — "formalize khi có bằng chứng cụ thể". Chưa có render nào chứng minh cần. |
| Tái cấu trúc `scene.{camera,aesthetic}` thành `scene.visual{}` | Đổi tên thuần tuý, phá test đang xanh, vi phạm §14 "FROZEN — chỉ additive". |
| Đổi tên `Planner`→`Pass` đồng loạt, bỏ `Producer`/`Director` khỏi code | `Planner` (quyết định) và `Pass` (hạ cấp cơ học, chỉ dùng phía Python post-boundary) là 2 việc khác nhau thật — gộp tên sẽ xoá mất phân biệt đó. Model-independence (mục tiêu nêu ra) đã đạt qua interface `Extractor`/`LlmClient` (§11), không liên quan tên class gọi nó. |

### 18.7 Decision Placement Principle (meta-invariant, đúc kết 2026-07-21)

> Rút ra sau hơn 40 lượt phản biện quanh vai trò Producer/Director/Cinematographer/
> Scene Planner — quy tắc này giải thích được MỌI quyết định biên giới đã chốt ở
> trên, không riêng cho 1 role nào. Dùng nó để xử lý đề xuất mới, thay vì lặp lại
> toàn bộ chuỗi lập luận.

| Loại quyết định | Đặc điểm | Chủ thể | Ví dụ đã chốt |
|---|---|---|---|
| **Subjective** | Không có rule đúng tuyệt đối — cần phán đoán | **LLM** | Producer (core conflict/promise/emotion curve, §18.1); Director (hero emphasis/reveal, §18.4) |
| **Objective** | Business rule / world-knowledge xác định, không cần "gu" | **Rule Engine** (generic code + data, §12) | `EditorialInterpreter.prohibitionsFor()` (§18.1); `IntentPlanner` (ScenePurpose→camera, §7); candidate expansion (§18.4) |
| **Syntax** | Chỉ là biến đổi hình thức, không thêm/bớt ý nghĩa | **Compiler** | `MotionComposer`/`lighting_phrase()`/`camera_phrase()` (enum→câu, §18.5); `ProviderPass` (camera→cú pháp Kling/Veo, §7) |

**Hệ quả trực tiếp — mọi giới hạn ở §18.1–18.6 đều suy ra từ đúng 1 dòng này:**
- Camera bị rút khỏi Director → camera là Objective (grammar cố định theo ScenePurpose), không phải Subjective.
- Producer không được đụng `StoryPlanner` → ranking Act là Objective (graph centrality), LLM không được ghi đè.
- `EditorialInterpreter` không được tự sinh fact → fact là Truth (Evidence), không phải Objective-suy-luận-được.
- `MotionComposer` không được "nghĩ" → nó chỉ làm Syntax, không có tầng nào cho nó Subjective/Objective.

**Luật kèm theo:** vượt ranh giới này (vd để LLM quyết 1 việc Objective, hoặc Rule Engine quyết 1 việc Subjective) đòi hỏi **bằng chứng tường minh** (Rule 0) — không phải vì "kiến trúc đẹp hơn".

### 18.8 Duration có 2 tầng tách biệt — Editorial target ⊥ Renderer capability (2026-07-24)

> Rút ra từ render thật bài Tequila yacht: `TimelinePlanner`/`EditorialInterpreter::
> durationWeightFor()` (§12, Phase 5, "chia đều theo trọng số `ScenePurpose`") sinh
> ra số giây **bất kỳ** cho mỗi scene (1.39s, 5.3s, 10.6s...) — nhưng Kling (renderer
> thật) chỉ nhận **đúng 2 giá trị: 5s hoặc 10s**. Đây KHÔNG phải bug — là 2 tầng
> khác nhau, dễ nhầm nếu không tách rõ:

| Tầng | Vai trò | Sinh ra bởi | Đơn vị |
|---|---|---|---|
| **Editorial target** | "Cảnh này XỨNG ĐÁNG bao nhiêu giây trong bản dựng cuối" — mục tiêu biên tập, theo trọng số `ScenePurpose` (§12) | `TimelinePlanner` (Laravel, deterministic) | số thực bất kỳ |
| **Renderer capability** | Renderer thật (Kling) chỉ tạo được clip THÔ ở 1 trong 2 độ dài cố định | Provider constraint (fal.ai Kling: 5\|10 clamp) | enum đóng {5, 10} |

**Editorial target KHÔNG BAO GIỜ là lệnh gửi thẳng cho renderer.** Nó là mục tiêu
cho bước DỰNG (edit/retime) sau khi đã có clip thô — đúng vai trò biên tập viên
phim thật: quay dư (renderer tạo đủ 5s hoặc 10s), dựng ngắn (cắt/tăng-giảm tốc độ
khớp đúng Editorial target ở bước ráp cuối). `MotionComposer`/`camera_phrase()`
(§18.5, Syntax) không đổi vai trò gì — vẫn chỉ dịch enum→câu cho renderer, không
biết gì về Editorial target.

**Quy tắc chọn renderer capability khi cần tối thiểu hoá phí:** Editorial target
≤5s → xin Kling tier 5s (rẻ nhất); Editorial target >5s → xin tier 10s (đủ tư liệu
để retime, không phải "kéo giãn" clip ngắn thành chậm bất thường). Retime luôn là
bước SAU CÙNG, sau khi đã có clip thô đúng tier.

**Trạng thái (2026-07-24):** bước retime (ffmpeg speed-up/trim theo đúng Editorial
target) đã làm thủ công 1 lần (video Tequila yacht 11s, dùng lại clip đã render,
không tốn thêm phí Kling). Bước tự động hoá (chọn tier 5\|10 theo Editorial target
+ retime tự động trong `render_queued_shots.py`) — CHƯA CODE, chờ quyết định có
cần tự động hoá hay giữ thủ công.

### 18.9 Bài học viết prompt cho Kling — từ render thật, không phải đoán (2026-07-24)

> Rút ra sau khi render 8 scene thật (bài Tequila yacht) + 4 lần render thử
> nghiệm gộp scene (2 thành công, 2 thất bại) + tra cứu tài liệu Kling chính
> thức. Chia rõ "đã kiểm chứng bằng render thật" (đáng tin nhất) và "theo tài
> liệu ngoài, chưa tự kiểm chứng" — không trộn 2 loại.

**Đã kiểm chứng bằng render thật:**

1. **1 shot = 1 chủ thể chính.** Đa chủ thể trong 1 lần render Kling text-to-
   video KHÔNG hoạt động tốt — đã thử 2 cách (chuyển tiếp mượt bằng camera
   transition, và neo cả 2 chủ thể ngay đầu prompt) đều thất bại: model hoặc
   bỏ qua hoàn toàn chủ thể thứ 2, hoặc chỉ cho chủ thể đó xuất hiện như hình
   nền phụ, không trở thành trọng tâm. Đây là giới hạn model, không phải lỗi
   viết prompt — không nên tiếp tục thử biến thể câu chữ khác cho use case này.
2. **Chỉ gộp render khi 2 scene liền kề CÙNG chủ thể + CÙNG `camera.movement`.**
   Bằng chứng: scene 2+3 bài Tequila yacht (cùng Nas, cùng `PUSH_IN`) gộp
   thành công, mượt, không lẫn chủ thể. Scene 5+6 (khác chủ thể: yacht → Nas,
   cùng `TRACK`) gộp thất bại dù prompt viết đúng mọi nguyên tắc chính thức
   của Kling (subject neo đầu, camera cụ thể, temporal flow, dual-anchor).
   Quy tắc thực nghiệm (KHÔNG nâng thành kiến trúc cố định — chưa đủ mẫu qua
   nhiều loại cặp scene khác): merge chỉ an toàn khi cùng chủ thể.
3. **Chữ nhỏ (nhãn sản phẩm, banner sân khấu, chữ trên màn hình LED) luôn ra
   chữ giả/không đọc được** ở mọi model video AI hiện nay, không riêng Kling.
   Không có cách viết prompt nào sửa được — không nên yêu cầu Kling render
   chữ cụ thể cần đọc rõ; nếu cần chữ đọc được, phải làm ở hậu kỳ (overlay).
4. **Push-in liên tiếp trên khuôn mặt NGƯỜI ở framing=DETAIL dễ ra khung hình
   hỏng** — bằng chứng: scene 3 (kế thừa continuity push-in từ scene 2,
   target=Nas/Human, framing=DETAIL) bị cắt cúp chỉ còn 1 mắt + sống mũi,
   không dùng được. Scene 3→4 CÙNG chain push-in nhưng target là chai rượu
   (PhysicalObject) lại KHÔNG hỏng — nên vấn đề thật là "push-in liên tiếp
   TRÊN MẶT NGƯỜI", không phải "push-in liên tiếp nói chung". Đã sửa thành
   code — gate tại `tools/session_runner.py`: khi
   `movement=='PUSH_IN' and target_type=='human' and framing=='DETAIL'`, im
   lặng (không phát `camera_continuity`) thay vì cộng dồn zoom — tái dùng
   đúng `entity_type()` đã có (bug #5).
5. **`world_environment` (fact cấp video) chỉ nên áp cho scene có `camera.
   framing` đủ rộng (WIDE/MEDIUM/AERIAL, không phải CLOSE/DETAIL) VÀ
   `camera.target` là loại "nằm trong không gian" (Vehicle/Landscape/
   Building/PhysicalObject, không phải Human/Event).** Đã sửa thành code —
   `environment_visible_at()` + `environment_relevant_to()` trong
   `media_runtime/director/motion.py`, gọi tại `tools/session_runner.py`.
6. **`camera_continuity` giữa 2 shot liền kề chỉ nên khẳng định khi
   `camera.movement` (enum) 2 scene THẬT SỰ trùng nhau** — không suy đoán từ
   câu tiếng Anh đã biên dịch của shot trước (`derive_camera_continuity()` cũ
   làm vậy, gây câu tự mâu thuẫn: "continuing to push in" đứng cạnh "tracks
   laterally"). Đã sửa thành code — gate tại `tools/session_runner.py` bằng
   so sánh `movement == prev_movement` trước khi gọi
   `derive_camera_continuity()`.
7. **`objective` (Producer.visual_promise, bối cảnh cấp video) chỉ nên xuất
   hiện ở scene ĐẦU TIÊN** (`ordinal == 1`), không lặp lại nguyên văn ở mọi
   scene — lặp lại tốn "attention budget" của model cho nội dung không xuất
   hiện trong khung hình cụ thể của scene đó. Đã sửa thành code — gate tại
   `tools/session_runner.py` bằng `scene.ordinal`.

**Theo tài liệu Kling chính thức (fal.ai + kling.ai, chưa tự kiểm chứng riêng
nhưng đã áp dụng và không thấy bằng chứng ngược lại qua 8+ lần render):**

- Công thức: Subject + Subject Movement + Scene + (Camera + Lighting +
  Atmosphere) — chủ thể phải neo NGAY ĐẦU prompt. Pipeline hiện tại đã đúng
  hướng này từ trước (hero luôn đứng đầu câu qua
  `EditorialInterpreter`/Director), không cần sửa.
- Camera phải cụ thể ("slow dolly-in, low angle", không phải "cinematic
  movement" mơ hồ) — `camera_phrase()` (enum→câu cụ thể) đã đúng hướng này.
- Giới hạn cứng 2500 ký tự/prompt (đã biết từ trước, `_KLING_HARD_LIMIT`
  trong `motion.py`).

**Trạng thái (2026-07-24):** cả 4 bug (mục #4-7) đã sửa xong, 223/223 test
Python xanh (đã xác nhận qua render thật lại session Tequila yacht ở mục #5-7,
mục #4 xác nhận qua 2 test unit — chưa render lại để so trực quan).

### 18.11 Hành động tay có mục đích cụ thể — giới hạn cứng, đã kiểm chứng cả t2v lẫn i2v (2026-07-24)

> Bối cảnh: thử nghiệm hướng "creation-arc" (thiết kế→thi công→hoàn thiện,
> §18.10-style ý tưởng, CHƯA CODE) — trước khi xây bất kỳ Planner nào, test
> render thật để biết Kling render được loại nội dung nào. 5 lần render thật
> ($0.28-0.31/lần), không đoán.

**Đã kiểm chứng: KHÔNG có cách nào (t2v, hay i2v với ảnh khởi đầu chính xác)
khiến Kling animate được hành động tay có mục đích cụ thể** (vẽ 1 đường nét cụ
thể, sau này suy rộng ra: hàn, khâu da, đánh bóng):

1. **t2v thuần** ("hand sketches curved hull lines"): tay đứng yên gần như
   tuyệt đối suốt 5s, nét vẽ ra hình xoắn ốc trừu tượng, không phải hull —
   Kling không hiểu "sketch hull curve" là hình dạng kỹ thuật cụ thể.
2. **i2v với ảnh khởi đầu ĐÃ chính xác** (Flux tạo ảnh tay+bút+bản vẽ hull
   đúng hình, đặt đúng vị trí đang vẽ) + prompt yêu cầu tường minh "pencil tip
   moving steadily, adding new construction lines": **vẫn thất bại y hệt** —
   tay/bút/nét vẽ gần như không đổi giữa 8 frame lấy mẫu đều trên 5s. Ảnh khởi
   đầu chính xác KHÔNG cứu được — chứng minh đây là giới hạn của chính cơ chế
   animate của Kling, không phải do thiếu dữ liệu hay prompt chưa đủ tốt.

**Công thức đã kiểm chứng hoạt động tốt (2 lần thành công, khác nhau về nội
dung — không phải may mắn 1 lần):**

- **Flux tạo ảnh (nội dung chính xác, kể cả kỹ thuật: hull blueprint, nhiều
  người) → Kling image-to-video CHỈ yêu cầu camera chuyển động** (push-in,
  không yêu cầu chủ thể/tay làm gì cụ thể) → kết quả sạch, camera mượt, nội
  dung giữ chính xác xuyên suốt.
- Đã test: (a) bản vẽ hull kỹ thuật đơn (chính xác 100% qua các frame), (b) 4
  người quây quanh bàn xem bản vẽ, 1 người chỉ tay (danh tính/cử chỉ giữ
  nguyên, không trôi, không thừa chi) — nhóm nhiều người KHÔNG phải vấn đề,
  miễn không yêu cầu hành động tay/vật thể phức tạp.

**Quy tắc sản xuất rút ra:** với MỌI nội dung có "ai đó đang làm gì bằng tay"
(vẽ, hàn, khâu, đánh bóng, lắp ráp) — **không mô tả hành động đang diễn ra**,
chỉ mô tả **kết quả/bối cảnh tĩnh đã có sẵn** (vd "bản vẽ đã hoàn thành nằm
trên bàn" thay vì "tay đang vẽ"), rồi để camera tạo chuyển động. Nếu cần cảm
giác "hành động", dùng Ken Burns (ảnh tĩnh + pan/zoom FFmpeg thuần, xem ví dụ
`work/clips_s3/1_concept.mp4` — không qua Kling) thay vì kỳ vọng Kling
animate.

### Đánh giá bằng chứng cho kiến trúc "creation-arc" (lịch sử: bắt đầu từ đề xuất 5-phase Birth/Design/Engineering/Craftsmanship/Experience — xem quyết định chốt 4-phase bên dưới) — CHƯA ĐỦ CƠ SỞ CODE

**Trục rủi ro thật KHÔNG phải "phase nào"** — 5 cái tên Birth/Design/
Engineering/Craftsmanship/Experience chỉ là khung KỂ CHUYỆN (narrative), không
phải khung RỦI RO KỸ THUẬT. Trục rủi ro thật, cắt ngang qua cả 5 phase, là:

> **Beat này có bắt buộc mô tả hành động tay/vật thể đang diễn ra hay không?
> VÀ nội dung đó có cần khớp CHÍNH XÁC 1 hình dạng/quỹ đạo cụ thể, hay chấp
> nhận được dạng trừu tượng/nghệ thuật?**

- **Nội dung tĩnh/kết quả/bối cảnh, cần chính xác hình dạng** (bản vẽ đã
  xong, bề mặt đã hoàn thiện, người đứng quan sát) → an toàn nhưng cần Flux
  trước để đảm bảo đúng hình dạng, rồi Kling i2v chỉ lo camera — đã kiểm
  chứng 2 lần thành công (bản vẽ hull, team review).
- **Nội dung chuyển động trừu tượng, KHÔNG cần khớp chính xác 1 hình dạng cụ
  thể** (đường mô phỏng dòng chảy, hiệu ứng hạt, ánh sáng) → t2v thuần cho
  ĐÚNG phần chuyển động (mượt, thật sự động) — **nhưng nếu prompt còn 1 phần
  yêu cầu hình dạng cụ thể lồng bên trong** (vd "flow lines AROUND THE HULL
  SHAPE"), phần đó vẫn bị rớt y hệt nhóm dưới. Đã kiểm chứng 1 lần (CFD): line
  chuyển động đúng, nhưng hull biến mất hoàn toàn — **không tự động rẻ hơn
  Design, vẫn cần Flux nếu có hình dạng cụ thể cần giữ**.
- **Nội dung yêu cầu hành động tay có mục đích cụ thể, cần chính xác quỹ đạo**
  (đang vẽ, đang hàn, đang khâu, đang đánh bóng) → **luôn thất bại**, đã kiểm
  chứng cả t2v lẫn i2v — phải viết lại thành nội dung tĩnh/kết quả, không có
  ngoại lệ.

| Phase | Trạng thái bằng chứng | Rủi ro thật theo trục hành-động-tay |
|---|---|---|
| Birth (mood/ý tưởng) | 1 lần test, hỗn hợp — không khí/ánh sáng đẹp, nhưng test dùng nhầm nội dung có hành động ("architect sketches") lấn sang Design. Nếu viết đúng nghĩa gốc (mood thuần, không hành động) thì rủi ro thấp — chưa test lại đúng scope. |
| Design (bản vẽ, review) | **Có bằng chứng thật** — 3 lần render, 2 thành công (hull blueprint, team review) đúng công thức. | Thấp — nội dung vốn tĩnh (bản vẽ, review), dễ viết đúng. |
| Engineering (CFD, kết cấu, động cơ) | **Bằng chứng THẤT BẠI 1 PHẦN** (đính chính 2026-07-24, phát hiện qua xem lại kỹ frame thật) — t2v thuần cho camera push-in mượt + đường mô phỏng chuyển động đúng yêu cầu, NHƯNG **hoàn toàn thiếu hình dáng hull** mà prompt yêu cầu ("flow lines... around the hull shape") — chỉ ra đường trừu tượng trên nền xanh, không nhận diện được đây là mô phỏng quanh 1 con tàu. Đúng lỗi cũ tái diễn: phần chuyển động trừu tượng Kling làm tốt, phần hình dạng cụ thể (hull) bị rớt hoàn toàn, không phải "rẻ hơn Design" như kết luận vội trước đó. | Cần retest với Flux trước (vẽ đúng hull + overlay flow lines) rồi mới i2v — same recipe như Design, KHÔNG rẻ hơn như tưởng. |
| Craftsmanship (khâu da, đánh bóng, lắp ráp) | **CHƯA TEST**. | **Cao** — bản chất phase này LÀ hành động tay, không tránh được bằng cách đổi chủ thể như Design/Engineering. Bắt buộc viết dạng kết quả tĩnh ("da đã khâu xong, đường chỉ rõ nét") mới an toàn — cần test riêng để xác nhận vẫn giữ được "cảm giác craftsmanship" khi mô tả tĩnh. |
| Experience (cruising, sunset, lifestyle) | **Có bằng chứng thật gián tiếp** — toàn bộ nửa đầu session này (8 scene Tequila yacht) là nội dung loại Experience. | Thấp — đã kiểm chứng kỹ qua nhiều lần render thật. |

**Kết luận:** không phải "phase nào pass/fail" — mà là **2 trục quy tắc**
(tránh mô tả hành động tay có mục đích cụ thể; VÀ bất kỳ hình dạng cụ thể nào
cần giữ đúng — kể cả lồng bên trong nội dung trừu tượng như CFD — đều cần Flux
trước, không có ngoại lệ "nội dung trừu tượng thì rẻ hơn"). Chỉ **2/5 phase
có bằng chứng thật vững** (Design, Experience) — **Engineering cần retest**
(phần chuyển động OK, phần hình dạng hull bị rớt, đính chính 2026-07-24) —
**Craftsmanship** (rủi ro cao, bản chất LÀ hành động tay, chưa test) và
**Birth** (cần test lại đúng scope thuần mood, không lẫn hành động) vẫn CHƯA
có bằng chứng. CHƯA đủ cơ sở để code `CreationArcPlanner` cho cả 5 phase cùng
lúc.

### Chốt cấu trúc cuối cùng (2026-07-24, quyết định user): 4 phase, không phải 5-6

Sau khi cân nhắc, user quyết định:

- **Bỏ Birth** — trùng vai trò với bước lấy thông tin từ article đã có sẵn
  trong pipeline (Truth Layer/Extraction) — không cần 1 phase riêng chỉ để
  "gieo ý tưởng", article thật đã là nguồn ý tưởng.
- **Bỏ Engineering** — vừa test thất bại 1 phần (hình dáng hull bị rớt khỏi
  mô phỏng CFD), và không phải nội dung thiết yếu cho video quảng bá.
- **Giữ Construction** (thi công thô — mới tách ra khỏi Craftsmanship gốc) và
  **Craftsmanship** (hoàn thiện tinh xảo) làm 2 phase riêng biệt, đúng tương
  phản công nghiệp/thủ công của các video launch thật (Bugatti, Rolls-Royce).

**Cấu trúc CHỐT: `Design → Construction → Craftsmanship → Experience`**

| Phase | Trạng thái bằng chứng |
|---|---|
| Design | ✅ Vững — 2 lần thành công (bản vẽ hull, team review), công thức Flux+i2v camera-only |
| Construction | ⬜ Chưa test — dự đoán cần mô tả kết quả/hiệu ứng (tia lửa hàn, khung đã ráp) thay vì "công nhân đang hàn" |
| Craftsmanship | ⬜ Chưa test — rủi ro cao nhất, gần như mọi mô tả tự nhiên đều ngả về hành động tay |
| Experience | ✅ Vững — toàn bộ nửa đầu session (8 scene Tequila yacht) |

Thứ tự triển khai hợp lý nếu code tiếp: **Design trước** (đã chắc chắn) →
**Construction** (dự đoán rủi ro trung bình, cần 1 lần test xác nhận) →
**Craftsmanship** (rủi ro cao nhất, để sau cùng, cần thiết kế riêng để giữ
"cảm giác thủ công" khi mô tả dạng tĩnh).

### 18.12 Model mới hơn (Kling 2.6 Pro, Veo 3.1) giải quyết được giới hạn hành động tay — nhưng chỉ áp dụng cho MODEL, không phải toàn bộ pipeline (2026-07-24)

> Quan trọng: mọi giới hạn "không animate được hành động tay" ghi ở §18.11 là
> đo trên **Kling v1.6/standard** (model dùng xuyên suốt session tới thời
> điểm này). Test lại đúng prompt khó nhất (thợ hàn di chuyển mỏ hàn dọc
> đường hàn) trên 2 model mới hơn cho kết quả khác hẳn.

**Bằng chứng thật**: cùng 1 prompt hàn (yêu cầu tay+mỏ hàn di chuyển có mục
đích), test trên 3 model:

| Model | Giá/clip (5-6s, không audio) | Kết quả |
|---|---|---|
| Kling v1.6/standard (đã dùng cả session) | $0.28 | Thất bại hoàn toàn — tay/vật thể đứng yên |
| Kling v2.6/pro | ~$0.35-0.70 | Tay/găng tay hoàn hảo, không méo, tia lửa động thật — nhưng mỏ hàn CHƯA di chuyển rõ dọc đường hàn |
| **Veo 3.1 Lite** | **~$0.15-0.25 (rẻ hơn cả v1.6!)** | Tương đương Kling 2.6 Pro về chất lượng tay/vật thể, **rẻ hơn** |

**Kết luận: Veo 3.1 Lite vừa RẺ HƠN v1.6 đang dùng, vừa CHẤT LƯỢNG CAO HƠN** cho
nội dung có tay/công cụ — đáng cân nhắc làm model mặc định thay vì chỉ dùng
cho case đặc biệt. Cả 2 model mới đều CHƯA đạt phần khó nhất (mỏ hàn di
chuyển để lại vệt hàn mới, tiến triển theo thời gian) — cần thêm 1 vòng test
với prompt sửa theo nguyên tắc "observable behavior" bên dưới.

### Nguyên tắc viết prompt mới: "Observable/Measurable Behavior", không phải động từ diễn giải

> Rút ra từ 2 lần tự soi lại frame thật (bắt được cả 2 lần chính mình đánh giá
> sai lúc đầu — "torch di chuyển đều" và "spark giữ nguyên" đều KHÔNG khớp
> với 8 frame thật, phải tự sửa lại). Bài học phương pháp: **review video AI
> phải chỉ ra được TRẠNG THÁI THAY ĐỔI cụ thể giữa các frame, không dùng ấn
> tượng tổng quát ("motion looks good").**

**Vấn đề của động từ diễn giải** (guides, studies, works, inspects): mô tả Ý
ĐỊNH của hành động, không mô tả TRẠNG THÁI PHẢI THAY ĐỔI theo thời gian — model
dễ chỉ render 1 khung hình gần-tĩnh khớp với ý định đó mà không tạo tiến trình
thật.

**Cách viết thay thế — mô tả hành vi ĐO ĐƯỢC bằng hình ảnh:**

```
❌ "A welder guides a torch steadily along a seam"          (diễn giải ý định)
✅ "The torch tip advances across the seam — the contact     (hành vi quan sát được)
    point visibly progresses and never pauses. A weld bead
    continuously grows longer behind the moving torch."
```

Nguyên tắc: mỗi câu mô tả chuyển động phải trả lời được "**điều gì có thể đo/
đếm được đã đổi khác giữa frame đầu và frame cuối**" (dài ra, tiến lại gần
hơn, số lượng tăng/giảm) — không chỉ mô tả 1 hành động đang "diễn ra" chung
chung.

### Nguyên tắc viết prompt cho Veo 3 (tra cứu chính thức, chưa tự kiểm chứng riêng — trừ phần đã test ở trên)

- **Cấu trúc 5-7 phần**: Subject + Action + Scene/Context + Camera + Visual
  Style + Audio + Negative prompt — không cần đủ mọi phần mỗi lần.
- **Camera nên tách thành câu RIÊNG**, không nhúng vào câu mô tả hành động
  chủ thể — vd "The camera pulls back." đứng độc lập, thay vì lồng vào 1 câu
  dài mô tả cả hành động lẫn camera cùng lúc. Khác với Kling (đã kiểm chứng:
  camera đứng CUỐI cùng 1 câu ghép vẫn work tốt) — đây là điểm khác biệt giữa
  2 model, cần test riêng để xác nhận có thật khác hay không.
- **Veo hiểu thuật ngữ dựng phim**: "match cut", "jump cut", "establishing
  shot sequence", "montage", "dolly shot", "over-the-shoulder" — có thể dùng
  trực tiếp, không chỉ mô tả chuyển động camera thuần enum như Kling.
- **Vật lý mô phỏng tốt hơn** — Veo 3 được quảng cáo mô phỏng vật lý thật tốt
  hơn (vải, nước, vật thể) — khớp với lý do nên dùng "observable behavior"
  thay vì diễn giải ý định, vì Veo được tối ưu để MÔ PHỎNG thay đổi vật lý,
  không chỉ vẽ 1 khung hình đẹp.
- **Audio**: dùng dấu ngoặc kép cho lời thoại cụ thể, tiền tố "SFX:" cho hiệu
  ứng âm thanh — hiện dự án đang tắt audio (`generate_audio: false`), chưa
  dùng tới nhưng ghi lại cho sau này.
- Giới hạn 2500 ký tự (giống Kling) — chưa xác nhận Veo có giới hạn khác.

### 18.13 TỔNG KẾT — Chốt cấu trúc `Design → Construction → Craftsmanship → Experience` (2026-07-24)

> Tổng hợp toàn bộ bằng chứng render thật thu thập được qua tất cả các lần
> test creation-arc trong session này (§18.9-18.12). Đây là bản chốt cuối
> cùng — 4 phase, không phải 5 hay 6 như các đề xuất trước đó.

#### Design ✅ VỮNG

- **Công thức**: Flux tạo ảnh nội dung chính xác → Kling i2v chỉ yêu cầu
  camera chuyển động (không yêu cầu chủ thể/tay làm gì).
- **Bằng chứng**: 2/2 lần render thành công — bản vẽ hull kỹ thuật (hình dáng
  chính xác tuyệt đối xuyên suốt, camera push-in mượt), nhóm 4 người review
  bản vẽ (danh tính/cử chỉ giữ nguyên, không trôi, không thừa chi).
- **Rủi ro còn lại**: không có — đã kiểm chứng 2 lần độc lập, khác nội dung.

#### Construction ✅ ĐẠT YÊU CẦU SẢN XUẤT (dùng Kling 2.6 Pro/Veo 3.1 Lite, không dùng v1.6)

- **Bằng chứng**: test bằng Kling v1.6 (t2v) cho kết quả không đạt (góc quay
  lạ, cần cẩu không thực hiện đúng vai trò, camera gần như không di chuyển).
  Test lại với **Kling 2.6 Pro** và **Veo 3.1 Lite** (thợ hàn di chuyển mỏ
  hàn dọc đường hàn) cho chất lượng hình ảnh xuất sắc — tay/găng tay/mỏ hàn
  hoàn hảo, tia lửa động thật, không artifact — nhưng **mỏ hàn chưa di chuyển
  rõ rệt dọc đường hàn** như yêu cầu (phần "travel" chưa đạt, phần "chất
  lượng thị giác + tia lửa" đã đạt).
- **User đã chấp nhận mức này là đủ dùng** ("Construction test ổn rồi").
- **Phát hiện quan trọng nhất phase này**: giới hạn "không animate được hành
  động tay" ghi ở §18.11 chỉ đúng cho **Kling v1.6** — Kling 2.6 Pro và Veo
  3.1 Lite xử lý tay+công cụ tốt hơn NHIỀU, và **Veo 3.1 Lite còn RẺ HƠN
  v1.6 đang dùng** (~$0.15-0.25 so với $0.28/5s) — nên cân nhắc đổi model mặc
  định.

#### Craftsmanship ✅ ĐẠT YÊU CẦU SẢN XUẤT (dùng Veo 3.1 Lite) — chỉ thiếu tiến triển nếu cần kiểu time-lapse

- **Test**: prompt "benchmark v2" cực khó (4 thợ thủ công cùng lúc: khâu da +
  giữ căng da + đánh bóng (burnisher) theo sau đường may + soi đèn kiểm tra
  theo sau burnisher) qua Veo 3.1 Lite, cả bản 8s và định hướng 4s.
- **Kết quả**: hình ảnh/chất liệu da/ánh sáng đạt production-grade (9.6-9.8/10
  theo review chi tiết), bố cục 4 người đúng, không ghost character, không
  chồng tay. **NHƯNG hoàn toàn đóng băng — không có tiến triển nào xảy ra
  suốt 8 giây**: đường may không dài ra, kim không di chuyển, burnisher/đèn
  soi không "đi theo sau" gì (vì không có gì tiến triển để theo). Chuỗi nhân
  quả kim→chỉ→mũi khâu→burnisher→đèn soi **không được mô phỏng** (điểm
  "Causal manufacturing workflow" chỉ 4.5/10 theo review).
- **Kết luận rút ra**: dồn nhiều điểm khó cùng lúc (đa nhân vật + hành động
  tay chính xác ×4 + thứ tự thời gian nghiêm ngặt) khiến model chọn giải
  pháp an toàn — 1 bố cục tĩnh đẹp thay vì thực hiện toàn bộ yêu cầu chuyển
  động phức tạp. Đây là bằng chứng thật, không phải suy đoán.
- **Chưa test**: bản đơn giản hơn (1 thợ khâu da, không kèm 3 người khác) —
  đã thiết kế prompt (dùng nguyên tắc "observable state per second": "one
  additional visible stitch appears immediately behind the needle" thay vì
  "finishes exactly one stitch") nhưng CHƯA render để xác nhận bản đơn giản
  có khắc phục được vấn đề "đóng băng" hay không.
- **User quyết định dừng test ở đây, chấp nhận mức bằng chứng hiện tại**
  ("Craftsmanship test như vậy là ổn").

#### Experience ✅ VỮNG

- **Bằng chứng**: toàn bộ nửa đầu session này — 8 scene thật (bài Tequila
  yacht), nhiều lần render qua Kling v1.6, đã tìm và sửa 4 bug compiler
  (world_environment sai chủ thể, continuity sai enum, objective lặp lại,
  push-in liên tiếp trên mặt người). Đã render đủ 8/8 scene, ghép thành video
  hoàn chỉnh (bản 38.25s, bản 11s retime).
- **Chưa test lại** với Kling 2.6 Pro/Veo 3.1 Lite — khả năng cao sẽ cải
  thiện thêm (đặc biệt các scene có người — Nas biểu diễn, đám đông) dựa
  theo pattern đã thấy ở Construction/Craftsmanship, nhưng chưa có bằng
  chứng trực tiếp cho riêng phase này.

#### Bảng tổng kết cuối cùng (đã hiệu chỉnh theo đúng tiêu chí sản xuất B-roll, không phải tiêu chí benchmark lý thuyết)

> **Sửa lại 2026-07-24**: đánh giá ban đầu cho Construction/Craftsmanship
> ("⚠️ chưa hoàn hảo") tự mâu thuẫn với chính lập luận đã dùng để bác đề xuất
> test 4-người ("đó là bài kiểm tra giới hạn lý thuyết, không phải nhu cầu
> sản xuất"). Với 1 shot B-roll 5-8s trong video quảng cáo, khán giả xem
> **hình ảnh có chân thực/đẹp không**, không đo độ dài đường hàn hay đếm mũi
> khâu. "Tiến triển rõ theo thời gian" chỉ là yêu cầu riêng cho use-case
> time-lapse ("xem quá trình hoàn thành"), không phải tiêu chí chung cho mọi
> shot Construction/Craftsmanship.

| Phase | Model đã test | Trạng thái |
|---|---|---|
| Design | Flux + Kling v1.6 i2v | ✅ Đạt yêu cầu sản xuất |
| Construction | Kling v1.6 (fail) → **Kling 2.6 Pro / Veo 3.1 Lite (✅ đạt)** | ✅ Đạt yêu cầu sản xuất (Veo 3.1 Lite/Kling 2.6 Pro, KHÔNG dùng v1.6) |
| Craftsmanship | Veo 3.1 Lite (4 người, benchmark v2) | ✅ Đạt yêu cầu sản xuất (hình ảnh/chất liệu/ánh sáng production-grade) |
| Experience | Kling v1.6 (toàn bộ Tequila yacht) | ✅ Vững, đã sửa 4 bug thật |

**Giới hạn riêng (không chặn triển khai, chỉ áp dụng cho use-case time-lapse
"xem tiến triển"):** cả Construction lẫn Craftsmanship đều CHƯA thể hiện rõ
tiến triển đo được theo thời gian (mỏ hàn di chuyển dọc đường hàn, mũi khâu
tăng dần) — nếu 1 shot cụ thể BẮT BUỘC phải "cho thấy quá trình đang hoàn
thành" (không chỉ là B-roll khí quyển), cần thử nghiệm thêm với prompt
"observable state per second" cụ thể hơn (§18.12) trước khi dùng cho đúng
mục đích đó.

**Quyết định chốt (2026-07-24)**: kiến trúc creation-arc 4 phase
`Design → Construction → Craftsmanship → Experience` — **cả 4 phase đều đạt
yêu cầu sản xuất**. Nếu triển khai thành code (`CreationArcPlanner` hay
tương đương, category-gated theo `yacht/moto/cars` — xem thảo luận trước đó
về category CMS có sẵn): Design dùng Flux+Kling i2v; Construction/Craftsmanship
dùng **Veo 3.1 Lite** (rẻ nhất, chất lượng tốt) hoặc Kling 2.6 Pro, KHÔNG
dùng v1.6; Experience giữ nguyên pipeline hiện tại (Kling v1.6 đã kiểm chứng
kỹ, có thể thử Veo/Kling 2.6 sau để cải thiện thêm nhưng không bắt buộc).

### 18.14 Production ĐÃ ĐỔI sang Veo 3.1 Lite (2026-07-24) — không chỉ là khuyến nghị, đã code thật

> Khác với §18.9-18.13 (chỉ là kết quả thử nghiệm/khuyến nghị), mục này ghi
> lại **thay đổi code thật đã áp dụng cho toàn bộ pipeline production**
> (không riêng creation-arc) — quyết định: không có lý do gì để tiếp tục
> dùng Kling v1.6 làm renderer mặc định nữa, khi Veo 3.1 Lite vừa rẻ hơn vừa
> chất lượng cao hơn cho MỌI loại nội dung đã test (không chỉ Construction/
> Craftsmanship — bao gồm cả nội dung có/không có hành động tay).

**Đã sửa** (`D:\1. Work\8. Project auto video\AI VIDEO\`):

- `tools/render_queued_shots.py`: `_MODEL` đổi `fal-ai/kling-video/v1.6/
  standard/text-to-video` → `fal-ai/veo3.1/lite`. `render_clip()` đổi tham số
  API cho đúng schema Veo (`resolution: '720p'`, `generate_audio: False`
  thêm mới; `duration` đổi format số nguyên → chuỗi `"Ns"`, mặc định 5→6 vì
  Veo không có preset "5s", chỉ nhận "4s"/"6s"/"8s"). Đổi tên hằng số
  `_KLING_MAX_CHARS` → `_PROMPT_MAX_CHARS` (generic, không còn gắn tên
  provider cụ thể). Giá fallback mặc định 0.28 → 0.18 ($/clip 6s, 720p,
  không audio — đúng giá thật đã tra ở §18.12).
- `tools/session_runner.py`: `render_plan` metadata ở CẢ 2 hàm sinh shot
  (`_plan_shots()` hardcode cũ và `_plan_shots_from_render_plan()` đường
  chính) đổi `{'provider': 'kling', 'renderer': 'i2v', 'duration': 5,
  'cost_estimate': 0.28}` → `{'provider': 'veo', 'renderer': 't2v',
  'duration': 6, 'cost_estimate': 0.18}` — nhân tiện sửa luôn nhãn
  `renderer` từ `'i2v'` (đã sai từ trước — renderer thật luôn là text-to-
  video, chưa từng có bước sinh ảnh nền cho shot 'motion') thành `'t2v'`
  đúng thực tế.
- 223/223 test Python xanh sau khi sửa — không test nào hardcode giá trị
  provider/cost/duration cũ nên không cần cập nhật test.

**Chưa làm** (nằm ngoài phạm vi yêu cầu lần này): `media_runtime/director/
motion.py::_KLING_HARD_LIMIT` (dùng trong `validate_shot()` preflight check,
không phải lệnh gọi render thật) vẫn giữ tên/giá trị cũ — không ảnh hưởng
hành vi render, chỉ là ngưỡng cảnh báo QA, có thể đổi tên sau nếu cần nhất
quán thuật ngữ.

### 18.15 Identity Consistency — Design→Construction→Craftsmanship→Experience phải cùng mô tả MỘT vật thể (2026-07-26)

> Phát hiện khi chuẩn bị render thật Creation Arc lần đầu (bài "Nixie"): 4 pha
> là **4 lần gọi Veo độc lập, không có ảnh neo chung** (đã bỏ Flux/i2v, chốt
> text-to-video toàn bộ ở §18.14-phần creation-arc) — không có gì đảm bảo
> cùng 1 con tàu/xe được vẽ giống nhau xuyên 4 lần sinh. Đây KHÔNG phải lỗi
> Kling/Veo — đây là giới hạn của kiến trúc text-to-video thuần (không seed
> ảnh, không character-consistency). Rủi ro lớn nhất là đúng ranh giới
> Craftsmanship → Experience: pha cuối bịa và scene thật đầu tiên phải trông
> như CÙNG MỘT sản phẩm, nếu không cả 4-phase feature coi như thất bại.

**Đề xuất đã cân nhắc và BÁC**: kiến trúc "Product Bible / Identity Lock /
Design DNA / Lifecycle State / Manufacturing Planner / Experience Planner" (9
tầng mới, hand-authored YAML mô tả sản phẩm). Chẩn đoán của đề xuất này ĐÚNG
(tính nhất quán phải tới từ dữ liệu, không phải prompt tự bịa) nhưng quy mô
kiến trúc **vi phạm Rule 0** (rent phải trả bằng trùng lặp ĐANG có, không phải
dự đoán):

- "Product Bible" ≈ đã có sẵn `Entity.attributes` (Truth Layer, verified bằng
  chứng thật — vd `hull_color: grey`) — không cần bịa YAML tay mới, tệ hơn vì
  KHÔNG có evidence đứng sau.
- "Identity Lock" ≈ đã có sẵn `MotionSpec.static` (semantic lock, immutable).
- "Manufacturing/Experience/Scene/Shot Planner" tách riêng sẽ trùng lặp
  `StoryPlanner/ScenePlanner/IntentPlanner/TimelinePlanner` đã có — đúng loại
  đề xuất đã bị bác ở §18.6 (LLM scene planner, Reviewer/Researcher riêng).
- "Design DNA" (ngôn ngữ thiết kế thương hiệu) cần dữ liệu bài viết không hề
  có — làm ngay bây giờ là bịa, phạm "không bằng chứng → không tồn tại".

**Cơ chế thật đã code — Identity Consistency, quy mô tối thiểu:**

`media_runtime/director/motion.py::entity_identity_facts(entity_id,
world_entities)` — đọc `entity.attributes` (free-form, Truth Layer), CHỈ lấy
giá trị dạng **CHUỖI** (vd `hull_color='grey'`), bỏ số thuần (vd
`top_speed_knots=19.5`, `guest_capacity=16` — không giúp model vẽ hình, chỉ
thêm nhiễu prompt). Lọc theo **KIỂU dữ liệu**, không theo TÊN thuộc tính —
không đoán tên nào "thuộc visual" theo domain (nếu làm allowlist tên riêng
cho yacht/moto/cars sẽ vi phạm §1 no domain branching). Sinh câu dạng:

```
Known real details — hull color: pearl white, hull material: fiberglass.
```

Nối vào cuối `composition_note` của **MỌI scene** có `camera.target` trỏ tới
entity đó — trong `tools/session_runner.py::_plan_shots_from_render_plan()`,
đúng SAU khi `fields` đã gộp từ `motion_camera_lighting_from_scene()` +
`motion_content_from_scene()`. Đặt ở compiler (Python), KHÔNG đặt trong
`CreationArcPlanner` (Laravel) — vì compiler là nơi DUY NHẤT thấy TẤT CẢ scene
(thật lẫn bịa) cùng lúc; đặt trong `CreationArcPlanner` sẽ chỉ phủ 3 scene
Design/Construction/Craftsmanship, để lọt đúng ranh giới quan trọng nhất
(Craftsmanship → Experience, scene thật KHÔNG đi qua CreationArcPlanner).

**Giới hạn thật, không giấu**: đây là cơ chế NHẤT QUÁN VỀ MÔ TẢ (textual),
không phải nhất quán về ẢNH (không seed/reference image) — Veo/Kling vẫn có
thể vẽ ra 2 kết quả khác nhau dù nhận đúng 1 mô tả, vì không có character-
consistency provider feature. Đây là cơ chế tốt nhất khả thi ở tầng text-to-
video thuần; nếu render thật cho thấy mức lệch vẫn không chấp nhận được, quay
lại Master Design Asset (Flux ảnh neo + i2v — đã validated Sprint 2) là hướng
đã biết hoạt động, đánh đổi bằng chi phí + độ phức tạp cao hơn.

**Test**: `tests/test_motion.py` (6 test `entity_identity_facts`) +
`tests/test_session_runner.py` (`test_identity_facts_appended_to_every_scene_
targeting_the_same_entity`, `test_no_identity_facts_when_target_has_no_string_
attributes`) — 233/233 Python xanh. `check_frame_capacity()` cũng sửa cùng
đợt: so khớp substring khiến `'face'` khớp nhầm trong `'surface'` (bug thật
bắt được khi review prompt Design) — đổi sang so khớp biên từ bên trái
(`\bkeyword`, giữ được chia động từ như "smiles"/"gripping").

### 18.16 Creation Arc v2 — sửa theo tư liệu thật, Identity Anchor bằng ẢNH (2026-07-26)

> Sau lần render thật đầu tiên (§18.15) và sau khi đối chiếu với **9 ảnh tư
> liệu thật từ video đóng du thuyền** (nguồn Yachtory) mà người dùng cung cấp.
> Kết luận nặng: config Creation Arc v1 không chỉ *thiếu*, mà **mô tả sai
> ngành** ở 2/3 pha. Mục này ghi lại thiết kế v2 TRƯỚC khi sửa code.

#### Bằng chứng: config v1 sai ở đâu

| Pha | v1 viết | Tư liệu thật cho thấy |
|---|---|---|
| Design | tay cầm **bút chì** phác **thân tàu nhìn nghiêng** trên giấy can kem | tay cầm **bút** trên **bản vẽ General Arrangement** (nhiều mặt bằng boong + profile view), cạnh **mẫu da/vải**; hoặc **bút marker** tô phối cảnh nội thất |
| Construction | **mỏ hàn** trên đường hàn, tia lửa, **khung sườn thép trần lộ** | shot chủ lực là **cần trục hạ nguyên khối thượng tầng nhiều tầng** xuống thân ở bến nước; thứ hai là **hạ động cơ** bằng cần trục |
| Craftsmanship | tay đeo găng **đánh bóng vỏ tàu** | thợ trên **xe nâng cắt kéo**, **mài/fairing thân tàu ĐÃ SƠN TRẮNG** — cách giai đoạn khung thép trần cả năm |
| Experience | (chưa có — mượn scene thật) | **thành phẩm lúc hoàng hôn**, thân sẫm màu + thượng tầng trắng, rời nhà xưởng ra mặt nước, thủy thủ đứng mũi |

Lỗi nghiêm trọng nhất: v1 **gộp nhầm hai giai đoạn cách nhau cả năm** — hàn
trên sườn thép trần (đầu) và mài trên vỏ đã sơn (cuối) — vào cùng một mô tả.

#### Cấu trúc v2: 4 pha / 5 scene

```
Design            → 1 scene   image-first (Kontext → i2v)
Construction 1    → 1 scene   t2v   — cần trục hạ khối thượng tầng
Construction 2    → 1 scene   t2v   — cần trục hạ động cơ
Craftsmanship     → 1 scene   t2v   — mài/fairing vỏ đã sơn
Experience        → 1 scene   image-first (anchor → i2v)
```

Quyết định người dùng 2026-07-26: beat động cơ **gộp vào Construction** (không
thành pha thứ 5 riêng), nhưng **tách làm 2 scene** — vẫn là 4 pha về mặt kể
chuyện, 5 scene về mặt render.

#### Vì sao Construction dùng t2v, không dùng ảnh neo

Hai lý do độc lập, cả hai đều có bằng chứng:

1. **i2v khóa chết structural transformation.** Ảnh neo mạnh ở camera/
   micro-motion, yếu ở "dựng ra thứ chưa có". Shot cần trục hạ khối *bản chất*
   là structural: khối đi xuống, khớp vào, thân tàu dài thêm. Neo nó lại là
   làm nặng thêm đúng cái đang hỏng (§18.15: đường hàn không dài ra).
2. **Dung sai identity ở Construction vốn cao nhất.** Lúc còn là khối kim loại
   trần, con tàu *không* trông giống thành phẩm — cảnh quay thật cũng vậy
   (ảnh 1/2/4 so với ảnh 9). Người xem không đối chiếu silhouette ở giai đoạn
   đó. Chỗ i2v yếu nhất trùng đúng chỗ cần identity ít nhất.

Craftsmanship cũng t2v: cận cảnh vật liệu/thợ, không thấy silhouette.

#### Identity Anchor = một tấm ẢNH, không phải JSON

Đề xuất `hero_asset.json` (silhouette/bow/decks/windows/proportions) đã được
cân nhắc và **bác**, với bằng chứng từ chính bài Nixie: đọc hết 2328 ký tự
nguồn, bài **không có một chữ nào** về mũi tàu, số tầng, số cửa sổ, mast,
hay màu sắc. Viết chúng vào JSON là bịa 100% — vi phạm "không bằng chứng →
không tồn tại".

Quan trọng hơn: **thêm chữ cũng không giải quyết được vấn đề.** Ba clip ở
§18.15 đã nhận đúng cùng một câu nhận dạng (`Known real details — deck style
..., deck material: teak, vessel type: charter yacht`) mà vẫn ra ba con tàu
khác nhau. Chữ không đủ độ phân giải để khóa tỉ lệ và silhouette.

Quyết định người dùng 2026-07-26: **hình dáng/màu sắc ĐƯỢC PHÉP bịa**, ràng
buộc duy nhất là **bất biến xuyên 4 pha**. Vậy chúng là *lựa chọn editorial*,
không phải Truth claim — và artifact đúng để khóa chúng là một tấm ảnh.

```
Truth attributes (có bằng chứng: length, deck_style, deck_material, vessel_type)
        │  ràng buộc prompt
        ▼
   Flux → ẢNH NEO  (thành phẩm, side profile)   ← single source of truth về HÌNH
        │                        │
        │ Kontext (i2i)          │ i2v (camera-only)
        ▼                        ▼
  bản vẽ GA  → i2v          Experience
   (Design)
```

**Phương án (b) — một ảnh neo, bản vẽ DẪN XUẤT từ nó.** Phương án (a) (hai ảnh
Flux độc lập cho Design và Experience) đã bị loại vì không giải quyết được
chính vấn đề đang sửa: hai lần sinh độc lập lại ra hai con tàu.

Ảnh 6 trong tư liệu xác nhận đường này tự nhiên: bản vẽ GA thật **có profile
view** — tức bản vẽ *nhìn thấy được identity*, nên Kontext biến ảnh thành
phẩm → bản vẽ kỹ thuật là phép biến đổi giữ được hình dáng. (Ảnh 5 — phối
cảnh nội thất marker — bị loại vì chỉ có nội thất, không neo được silhouette.)

#### Câu hỏi thi công còn TREO — phải test thật, không quyết trên giấy

Design cần khung hình: *bàn làm việc, bản vẽ GA trải ra, bàn tay cầm bút,
mẫu vật liệu bên cạnh*. Kontext phải đi từ ảnh thành phẩm tới đó bằng cách nào?

- **B1 — một bước**: Kontext làm hết (ảnh tàu → nguyên cảnh bàn vẽ có tay).
  Ít bước, nhưng edit càng nặng thì identity càng trôi.
- **B2 — hai bước**: Kontext chỉ đổi ảnh tàu → bản vẽ kỹ thuật sạch; bối cảnh
  bàn/tay để i2v thêm vào lúc render.

Chưa có bằng chứng bên nào tốt hơn. **Không chốt trên giấy** — chạy thử cả hai
(chi phí Kontext ~$0.04/ảnh) rồi so identity trước khi ghi vào config.

#### Phases phải tách theo CATEGORY

"Cần trục hạ khối thượng tầng" vô nghĩa với ô tô (dập thân vỏ, robot hàn
body-in-white, buồng sơn) và với mô tô. Một bộ template không phục vụ được ba
domain. `config/video.php` đổi từ:

```
creation_arc.phases = { design, construction, craftsmanship }   ← dùng chung
```

thành phases **theo từng category**. Vẫn hợp lệ §1 (no domain branching):
kiến thức domain nằm ở **data**, code tra cứu vẫn tổng quát — không có
`if ($domain === 'yacht')` nào, không thêm class, không thêm tầng.

**Trạng thái bằng chứng**: chỉ **yacht/superyacht** có tư liệu thật (9 ảnh).
`cars` và `moto` **CHƯA có tư liệu** — viết template cho chúng lúc này là lặp
lại đúng sai lầm của v1 (mô tả sai ngành vì đoán). Hai category này để TRỐNG
cho tới khi có ảnh tham chiếu; category không có phases thì Creation Arc
không kích hoạt, RenderPlan giữ nguyên (hành vi đã có sẵn — xem
`CreationArcPlanner::mergeInto()` trả về nguyên `$renderPlan` khi `phases` rỗng).

#### Bí danh tên riêng — thi công cơ chế đã thiết kế từ lâu

"Nixie", "Lürssen", "RWD" đều là tên thật. Hiện "Nixie" được nhét thẳng vào
cả 4 prompt. Nhưng **Veo không biết Nixie là con tàu nào** — cái tên không
đóng góp gì cho hình ảnh, chỉ mang rủi ro nhãn hiệu. Đây đúng là cơ chế
"allowlist tên riêng theo provider ở Python" đã thiết kế từ đầu (bẫy Moonrise,
§4) nhưng chưa bao giờ thi công. Giờ rent đã trả đủ: rủi ro thật + bằng chứng
cái tên vô dụng. Thay bằng bí danh trung tính ở **tầng biên dịch prompt
(Python)**; RenderPlan vẫn giữ tên thật để truy vết.

#### Chi phí ước tính mỗi video (Creation Arc v2)

| Khoản | Số lượng | Đơn giá | Thành tiền |
|---|---|---|---|
| Flux ảnh neo | 1 | ~$0.03 | $0.03 |
| Kontext dẫn xuất bản vẽ | 1 | ~$0.04 | $0.04 |
| Veo 3.1 Lite 6s | 5 | $0.18 | $0.90 |
| **Tổng** | | | **~$0.97** |

So với v1 (4 clip t2v = $0.72): tăng ~$0.25/video, đổi lấy identity nhất quán
và một beat kể chuyện nữa. Vẫn rẻ hơn Kling v1.6 cho cùng số shot.

#### Thứ tự thi công

1. Viết lại `config/video.php` — phases theo category, chỉ yacht/superyacht.
2. State Transition 4 lớp cho Construction 1/2 và Craftsmanship (§18.12).
3. Test Kontext B1 vs B2 → chốt, ghi ngược vào mục "câu hỏi treo" ở trên.
4. Lưu ảnh neo cấp session (`render_queued_shots.py` hiện render từng shot độc
   lập, chưa có khái niệm artifact dùng chung giữa các shot — đây là phần code
   mới thật sự duy nhất).
5. Bí danh tên riêng ở tầng biên dịch.

### 18.17 CHECKPOINT — render v2 thất bại 4/6, thiết kế v3 đã chốt, MỘT câu hỏi kỹ thuật còn treo (2026-07-29)

> Mục này là **điểm dừng có chủ đích** để quay lại debug luồng. Ghi đủ để đọc
> nguội mà tiếp tục được: bằng chứng render thật, những gì đã chốt, những gì đã
> bác (kèm lý do), và câu hỏi duy nhất còn chặn việc viết code.

#### Bằng chứng render — session `art_a25f63f3_260729_062733`, bài "The Sixth Sense", 6 clip Veo 3.1 Lite, $1.08

| Scene | Kết quả | Quan sát trực tiếp từ frame |
|---|---|---|
| 1 design | ⚠️ | Bố cục đúng (bàn vẽ GA, mẫu da/vải). **Hai ngòi bút** — do prompt tả HAI tay làm hai việc. Bản in phối cảnh trên bàn cho tàu **TRẮNG** |
| 2 hạ khối | ❌ hỏng nặng | Render **nguyên một du thuyền HOÀN THIỆN** (sơn tên, sơn chống hà) treo trên **ụ nổi ngoài trời**. Frame 0.2s ≈ 5.8s — **khối không hạ xuống**, State Transition thất bại |
| 3 hạ động cơ | ❌ | Động cơ render đẹp, nhưng bên dưới là **tàu hàng gỉ màu teal**, không có khoang máy |
| 4 mài vỏ | ❌ | Vỏ **đã sơn bóng + đã có tên**, chỉ **1 thợ**. Sai trình tự (fairing xảy ra trước khi sơn) |
| 5 ngoại thất | ✅ | Thân tối màu, thủy thủ trên boong mũi, rời shed lúc hoàng hôn, cầu vòm. Đúng prompt |
| 6 trên boong | ✅ tốt nhất | Sàn teak, hồ bơi, ghế lounge, bàn ăn dưới mái che, nắng vàng |

**Nguyên nhân chung của 2/3/4**: mô tả **vật thể chưa hoàn thiện bằng từ ngữ của
vật thể hoàn thiện** ("multi-deck superstructure section", "white-painted hull",
"deck plating of {tên tàu}"). Veo mặc định hoàn thiện nốt. Cách chặn đã xác
định: **nói thẳng cái gì CHƯA có** — no paint, no windows, no railings, no
markings, raw cut edges.

**Phát hiện quan trọng — negative prompt KHÔNG chặn được text.**
`render_queued_shots.py::_DEFAULT_NEGATIVE` **đã chứa** `"watermark, text, logo"`.
Ba clip vẫn có "The Sixth Sense" sơn to lên thân tàu, chữ bị nhân đôi và méo.
Kết luận: **phải bỏ tên khỏi prompt dương**, không được trông cậy vào negative.

**Identity lệch, đã đo được**: bản in trong scene 1 cho tàu **trắng**, scene 5
cho tàu **navy sẫm**. Hai con tàu khác nhau.

#### Kết luận về đường ảnh neo (§18.16): HOÃN, chưa đáng code

Scene 2/3/4 hỏng vì Veo **hiểu sai nội dung**, không phải vì identity trôi. Có
ảnh neo thì vẫn ra con tàu treo lơ lửng. Sửa prompt trước, render lại, rồi mới
đánh giá identity có còn là vấn đề không.

#### Đã chốt cho v3 — sau khi rà 5 tài liệu đề xuất từ người dùng

**Kiến trúc**

- **Creative Identity đặt ở COMPILER (Python), KHÔNG ở `CreationArcPlanner`.**
  Lý do KHÔNG phải là "planner tương lai cũng cần" (suy đoán) mà là lý do đang
  xảy ra: bài Sixth Sense có **9 scene thật** sau 6 scene arc; đặt ở planner thì
  9 scene đó chỉ nhận được `"vessel type: motor yacht"` và con tàu sẽ trôi ngay
  tại ranh giới arc → nội dung thật. Đây đúng là sai lầm §18.15 đã ghi, suýt lặp lại.
- **Giữ `entity_identity_facts()`** (Truth) và **hợp nhất** với Creative Identity
  (config). Hai nguồn khác nhau, không thay thế nhau. **Truth THẮNG khi đụng
  nhau**; Creative chỉ điền chỗ Truth im lặng — cùng tinh thần §13.
- Key config: `identity.construction` / `identity.final` (không dùng `raw` —
  scene 2 đã có vỏ thép, khung, mối hàn, không còn "raw").
- **Design KHÔNG nhận identity nào**: `camera_target = design_drawing`, chủ thể
  là tờ giấy; bản vẽ kỹ thuật vốn không có màu.
- `construction_progress` (enum thứ tự + test đơn điệu, tái dùng
  `ontology.py::check_stage_sequence()` đã có sẵn): **RESERVED**. Rent chưa trả
  — mới có MỘT phase set (`vessel`), viết một lần. Làm khi có thêm `cars`/`moto`.

**Nội dung — 9 scene, mọi thứ trừ Design diễn ra trong MỘT nhà xưởng kín**

| # | Pha | Trạng thái con tàu (bậc thang hoàn thiện) |
|---|---|---|
| 1 | Design — GA + phác nội thất | (studio, không có identity) |
| 2 | Construction — hạ khối thượng tầng | vỏ thép hở nóc, **chưa có thượng tầng** |
| 3 | Construction — hạ động cơ | **thượng tầng đã yên vị**, khoang máy hở |
| 4 | Construction — thi công nội thất | vỏ **đã kín, kính đã lắp**, trong còn dây điện lộ |
| 5 | Craftsmanship — mài vỏ, nhiều thợ | lắp ráp xong, primer xám → **mảng navy đầu tiên xuất hiện** |
| 6 | Launch — hạ thủy | hoàn thiện, cửa xưởng mở |
| 7 | Experience — ngoại thất | hoàn thiện, trên biển |
| 8 | Experience — trên boong | — |
| 9 | Experience — nội thất | — |

Bậc thang này là câu trả lời cho phê bình đúng nhất trong 5 tài liệu: *"scene
2-5 đều là construction nhưng con tàu không thay đổi — người xem không thấy nó
đang lớn lên."* Chuyển primer→navy **diễn ra trên màn hình** là time cue mạnh
nhất, và đúng nguyên tắc Observable Behavior (§18.12).

**Màu vỏ: DARK NAVY METALLIC.** Bác `metallic silver` và `pearl white`. Lý do
quyết định không phải thẩm mỹ: trong xưởng vỏ tàu **vốn đã là kim loại xám**,
nên thành phẩm màu bạc sẽ khiến trạng thái đầu và cuối trông như nhau — giết
luôn cảm giác thời gian trôi. Navy cho tương phản tối đa với primer.

**Bỏ tên tàu khỏi mọi scene giai đoạn thi công.** Tên chỉ xuất hiện ở khâu dựng
(text overlay), ngoài phạm vi pipeline này.

#### Đã BÁC — ghi lại để không đề xuất lại

| Đề xuất | Lý do bác |
|---|---|
| `MilestonePlanner` | Không sinh dữ liệu mới — milestone *chính là* phase trong config. Tài liệu còn liệt kê "KEEP: State/Operation/Shot Planner", **không class nào tồn tại** (thật là StoryPlanner/ScenePlanner/IntentPlanner/TimelinePlanner) |
| Tầng `EMOTION` | Đã có `aesthetic.emotion` (6 enum) → `lighting_phrase()`. Cảm xúc tạo bằng ánh sáng, không bằng cách viết chữ "triumph" vào prompt |
| Khối `STYLE` dán mọi scene (`ARRI Alexa, anamorphic, shallow DOF, teal-and-amber, 24fps, 4K 16:9`) | Vi phạm `ArchitectureTest` (cấm `cinematic`/`8k`/`mm lens` trong `app/Video/`); dự án render **9:16 dọc** không phải 16:9; "shallow DOF" cứng **đánh nhau** với `lens_for_framing()` vốn cho WIDE = deep focus |
| Nối `NEGATIVE` vào chuỗi prompt | Negative đi qua **tham số API riêng**. Nối vào prompt dương là mô tả thứ mình không muốn. Và đã chứng minh không chặn được text |
| Thời lượng 8/9/10/12s | Veo 3.1 Lite chỉ nhận **4s/6s/8s** |
| One-take 4 phòng (salon→suite→dining→pool→bay ra ngoài) | Veo hỏng cả một cú **orbit đơn** (scene 5 yêu cầu orbit, nó chỉ đẩy tới). Sáu chuyển tiếp không gian là bất khả thi |
| "Hàng trăm công nhân" | Đám đông không kiểm soát = méo mặt/tay/nhân bản; compiler đã có `crowd` tự thêm negative đúng nhóm lỗi này |
| Mở rộng Experience thành 8 scene | Bùng nổ phạm vi (thành ~15 scene, 90s bịa) trong khi 6 scene hiện tại còn hỏng 4 |
| `Product Bible` / `Design DNA` / `Identity Lock` (9 tầng) | Đã bác §18.16 — `Entity.attributes` + `MotionSpec.static` đã là cơ chế tương đương |
| Điểm số 8.2/10, 7.5/10... | Không rubric, không đo được |
| Sound design, text overlay, tư liệu giả 1992 | Đúng và hay, nhưng **ngoài pipeline** — hệ này sinh RenderPlan → clip, không có tầng dựng, audio đang tắt |

> **Ghi chú phương pháp**: bốn trong năm tài liệu đề xuất **tự phá identity ngay
> trong lúc lập luận về identity consistency** — mỗi cái nói một màu vỏ khác
> nhau (silver → navy → pearl white). Đây là bằng chứng thực nghiệm cho chính
> nguyên tắc đang xây: màu phải nằm ở **một chỗ duy nhất** (config), không được
> viết tay lại trong ví dụ.

#### CÂU HỎI TỪNG TREO — ✅ ĐÃ CHỐT (2026-07-30): phương án 2

Compiler là **Python**, nó chỉ đọc `RenderPlan.json`; nó **không đọc được**
`config/video.php` của Laravel. Vậy chuỗi Creative Identity đi từ Laravel sang
Python bằng đường nào, khi **RenderPlan v1.0 đã đóng băng** (§14)?

1. ~~`continuity.invariants`~~ — **BÁC**: nó đang chứa dữ liệu Truth đã verify;
   trộn thông tin bịa vào khiến consumer không phân biệt được.
2. ✅ **Thêm field cấp video mới** — **ĐÃ CHỌN VÀ ĐÃ CODE.** Root field
   `creative_identity` (optional, `$defs/identity_state`), đúng tiền lệ
   `world_environment`. Additive nên không phá §14; `plan_version` vẫn `1.0`.
3. ~~Ghi sẵn vào `director_notes.composition_note`~~ — **BÁC**: planner-side
   injection, đúng thứ đã quyết định tránh.

#### Trạng thái code — cập nhật 2026-07-30

- `config/video.php`: `creation_arc` v2 — `categories` map, `phase_sets.vessel`
  **6 phase** với `camera_target`/`hero` override, **`identity`** (2 trạng thái
  `construction`/`final`), và **`objective` riêng cho từng pha** (§18.18).
  **Chưa có**: 9 phase, bậc thang hoàn thiện, phase set cho `cars`/`moto`
  (thiếu tư liệu ảnh — §18.16).
- `CreationArcPlanner`: `camera_target`/`hero` override, `mergeInto()`,
  `creative_identity` passthrough, `objective` theo pha.
- `RenderPlanQualityReport` (§18.19) — mới; nối vào trang duyệt session,
  tính động mỗi request, không lưu DB.
- `motion.py`: `entity_identity_facts()`, `creative_identity_for()`,
  `identity_variant()`, `identity_visible_at()` và **`identity_visible()`** —
  predicate hợp nhất (§18.20).
- `session_runner.py`: gate cả Truth lẫn Creative bằng `identity_visible()`;
  bỏ qua scene lỗi thay vì sập cả batch.
- **Test: PHP 328 (Video) + 10 (Feature) + 1 (Unit), Python 294.**
- DB: 1 session `art_a25f63f3_260729_062733` (15 shot: 6 arc đã render, 9 thật
  còn `draft`). RenderPlan đang lưu được sinh **TRƯỚC** các bản sửa §18.18/
  §18.20 — phải chạy lại pipeline trước khi render tiếp, không dùng lại plan cũ.

#### Dọn nợ kỹ thuật phía Laravel (2026-07-29, cùng ngày)

Rà `VideoSessionService` và sửa **3 bug production thật** + tách trách nhiệm.
Không đổi hành vi nghiệp vụ; toàn bộ có test bảo vệ.

**Bug 1 — transaction bao trùm cả cú gọi LLM.** `createFromArticleId()` mở
`DB::beginTransaction()` rồi mới gọi pipeline (11+ cú gọi Claude, bằng chứng
log thật: ~25 giây bài ít scene, 60-90 giây bài nhiều scene). Suốt thời gian
đó giữ một connection MySQL và khoá hàng `video_projects`; vài người bấm 🎬
cùng lúc là cạn connection pool hoặc dính lock wait timeout. Đã chuyển
`build()` ra **ngoài** transaction, transaction chỉ bao phần ghi DB.

**Bug 2 — ngược lại, `storeFromPython()` ghi N+2 lần mà KHÔNG có transaction.**
Hỏng ở shot thứ 5/15 để lại 4 shot đã ghi và `cost_estimate_total` không bao
giờ cập nhật. Đã bọc `DB::transaction()` — ở đây bọc là đúng vì toàn bộ là ghi
DB, không có cú gọi mạng nào bên trong.

**Bug 3 — log nuốt stack trace.** `'error' => $e->getMessage()` khiến không
biết hỏng ở Extractor, Producer, Director hay `applyCreationArc` — chính vì
vậy mới phải cắm `dd()` để mò. Đổi thành `'exception' => $e`.

**Tách `VideoRenderPlanService`** (Article → RenderPlan). `VideoSessionService`
đang gánh hai trách nhiệm: vòng đời session (8 method ngắn) và chạy pipeline AI
(kéo theo 6 import chẳng liên quan). Đây **không phải abstraction mới** — nó
soi gương đúng quy ước đã có phía CMS (`CLAUDE.md`: *"ArticlePipelineService::
run() is the single entry point for AI writing… The caller owns persistence"*).
Kết quả: 279 → 183 dòng, hết dính `ClaudeWriterService` của CMS.

Kèm theo: trần chi phí LLM từ hằng số trong code → `config('video.
llm_cost_ceiling_usd')`; quy tắc "hero = entity Vehicle đầu tiên" tách thành
`findHeroEntity()` có tên thay vì vòng lặp không tên; 14 magic string status →
`App\Enums\VideoSessionStatus` + `VideoShotStatus`; validate `shots.*.shot_code
/kind/beat` (ba key `storeFromPython()` truy cập trực tiếp, không có `??`).

**Đã bác, ghi lại để không đề xuất lại**: tách 5 service (Planning/Review/Queue/
Result/PythonImport) — `queueApproved()` 3 dòng, `approveSelectedShots()` 3
dòng, dựng class riêng cho method 3 dòng làm code khó đọc hơn; Domain Events
cho `queueApproved()` — thêm gián tiếp, không rent; `VideoPipelineFactoryInterface`
— seam để test đã có sẵn ở `LlmClient`; `MetadataFactory` cho `RenderPlanMeta`
— value object 5 field, bọc factory là nghi thức thừa.

### 18.18 `scene.objective` có HAI nguồn hợp lệ — video promise ⊥ scene intent (2026-07-30)

> Đây là **thay đổi nguồn-sự-thật**, không phải sửa lỗi nhỏ. Nó đảo một quyết
> định đã chốt 2026-07-22 ("`objective` chỉ có một nguồn: Producer") vì bằng
> chứng production đã phủ định giả định nền của quyết định đó.

**Giả định cũ và vì sao nó đúng lúc đó.** `RenderPlanAssembler::sceneDoc()` copy
`producer.visual_promise` vào MỌI scene, kèm ghi chú *"Video-level hiện tại,
chưa có bằng chứng thật cần khác nhau theo từng scene (Rule 0)"*. Đúng — khi mọi
scene đều mô tả **cùng một thành phẩm**, một lời hứa dùng chung là đủ.

**Bằng chứng phủ định (2026-07-30).** `RenderPlanQualityReport` chạy trên plan
thật `art_a25f63f3_260729_062733` báo 6/15 scene thiếu `objective` — đúng 6 scene
Creation Arc, vì arc chèn SAU assembler. Bản sửa đầu tiên là copy
`visual_promise` xuống arc. Đọc nội dung thật của promise thì thấy sửa vậy là sai:

> *"Viewers will get to see the gleaming 242-foot Sixth Sense **moving through the
> postcard-perfect waters of midcoast Maine**, dwarfing lobster boats…"*

Câu đó nói về con tàu **đã hoàn thiện, đang chạy trên biển**. Gán nó cho
`creation_design` (tờ bản vẽ trên bàn) hay `creation_construction_hull` (khối
thép trần trong xưởng) là **mô tả vật thể chưa hoàn thiện bằng từ vựng của vật
thể đã hoàn thiện** — đúng nguyên nhân gốc đã làm render v2 thất bại 4/6 scene
(§18.17). Thiếu tín hiệu còn tốt hơn tín hiệu mâu thuẫn.

**Kết luận: `objective` không phải một field có một nguồn, mà là chỗ giao của
HAI abstraction ở hai cấp khác nhau.**

**BẤT BIẾN — `objective` có MỘT ngữ nghĩa duy nhất, nhiều NGUỒN CUNG CẤP.** Đọc
kỹ chỗ này, vì cách diễn đạt đầu tiên của mục này đã sai và phải sửa lại:

> `scene.objective` luôn trả lời đúng MỘT câu hỏi —
> **"tại scene này, người xem cần nhận được điều gì?"**

Nó **không** đổi nghĩa theo nguồn. Cái đổi là ai đưa ra câu trả lời:

| Nguồn | Khi nào là câu trả lời ĐÚNG |
|---|---|
| `producer.visual_promise` (Producer, LLM) | Khi scene mô tả **cùng trạng thái** với thành phẩm mà promise nói tới — đa số scene thật |
| Phase template trong `config/video.php` (planner) | Khi scene mô tả **trạng thái khác** — Creation Arc, và mọi arc sau này |

Cách hiểu này giải thích được cả hai chiều, thứ mà cách diễn đạt "hai cấp" cũ
không làm được: copy `visual_promise` xuống 9 scene thật là **đúng** vì ở đó nó
tình cờ trả lời trúng câu hỏi trên; copy xuống 6 scene arc là **sai** vì ở đó nó
trả lời trật — không phải vì "sai cấp".

Hệ quả: thêm `DocumentaryArcPlanner`/`ReenactmentArcPlanner`/`ExplainerArcPlanner`
sau này **không** làm ngữ nghĩa field thay đổi, chỉ thêm một nguồn nữa. Contract
đứng yên.

**Luật kèm theo — áp cho mọi planner sau này.** Planner nào sinh scene mà scene
đó KHÔNG mô tả cùng trạng thái với `visual_promise` thì planner đó **phải** tự
cấp `objective`. Nếu không, nó sẽ lặp lại đúng bug này. (Cùng lý do §18.7: quyết
định "scene này phục vụ gì" là Subjective ở cấp scene — nơi biết câu trả lời là
nơi sinh ra scene, không phải nơi lắp plan.)

**Đã bác, ghi lại để không đề xuất lại:**

| Đề xuất | Lý do từ chối |
|---|---|
| Đổi tên `scene.objective` → `scene.intent`/`scene.visual_goal` | Tên hiện tại gây hiểu sai là thật, nhưng §14 FROZEN chỉ cho additive, và phía Python đang đọc đúng tên `objective` — đổi tên phá cả hai repo để đổi một nhãn. Cùng loại đã bị §18.6 từ chối (`scene.visual{}`). **Cách sửa đã dùng:** viết lại `description` trong schema, additive, không phá gì. |
| Bỏ trống `objective` cho arc và đổi cảnh báo thành "chủ ý" | Chấp nhận để trống một field quan trọng chỉ vì thứ tự pipeline không sinh ra được — không phải lý do thiết kế. |
| Truyền `visual_promise` vào `CreationArcPlanner::plan()` qua tham số | Sai tầng: `plan()` chỉ sinh scene từ config, không thấy plan thật. Và nếu truyền thì lại là copy promise — đúng thứ vừa bị dữ liệu bác. |

### 18.19 `RenderPlanQualityReport` — thông tin cho cổng duyệt đã có, KHÔNG phải tầng mới (2026-07-30)

Cổng chặn tiêu tiền **đã tồn tại** trên shot (`draft → approved | needs_revision | rejected`,
rồi `approved → queued → rendered | failed`; session đi riêng theo
`draft → composing → reviewing → rendering → done | failed`). Không đường render nào bỏ qua duyệt. Cái thiếu là người
duyệt bấm approve mà không biết plan nghèo dữ liệu tới mức nào. Class này chỉ
tính con số — không chặn, không đổi trạng thái. Deterministic, không LLM, đọc
RenderPlan (mảng) nên dùng được cả với `renderplan_json` đọc lại từ DB.

**Dừng ở CẢNH BÁO, không chặn** — không phải vì chưa biết ngưỡng, mà vì pipeline
CỐ Ý tách Truth/Creative: bài báo nghèo chi tiết thị giác là **bình thường** (bằng
chứng: bài `a25f63f3` có 0 từ về màu/vật liệu/hình dáng) và `creative_identity`
tồn tại đúng để lấp chỗ đó. Chặn theo độ giàu visual = nói "bài báo phải giàu
visual mới được render", trái với chính kiến trúc.

**Prototype trên plan thật TRƯỚC khi code đã loại 2/6 check dự kiến** — ghi lại
vì đây là tiêu chuẩn cho mọi check thêm sau này:

| Check bị loại | Lý do |
|---|---|
| `ANCHOR_ONLY_ENTITIES` (3/7 entity không có attribute) | `Entity::isAnchorOnly()` ghi rõ đây là **hoàn toàn hợp lệ** (Feadship là node thật, tồn tại để neo quan hệ). Cảnh báo = chống lại một quyết định đã ghi trong code. |
| `camera.target` treo | Bắn 4 lần, **4/4 là cố ý** (`design_drawing`/`marine_engine`/`hull_seam`/`upper_deck`, xem §18.16). Schema định nghĩa `camera.target` là `slug` chứ không ràng buộc entity. Ship = 4 báo động giả mỗi video. Giữ làm **metric**, có test khoá để người sau không "sửa" thành warning. |

**Tiêu chuẩn:** một QA tốt không phải cái báo nhiều lỗi nhất, mà cái có tỷ lệ
báo động giả gần 0 — để khi nó báo thì người duyệt tin là đáng xem. Ngưỡng
`minDescriptiveAttributeNames` mặc định 3 là **phỏng đoán từ N=1**, tiêm qua
constructor, và docblock nói thẳng là chưa hiệu chuẩn.

**Đo độ giàu thị giác bằng KIỂU DỮ LIỆU, không bằng tên attribute** — lọc theo
tên (`hull_color`, `material`…) là nhồi kiến thức ngành vào code, đúng thứ §1
cấm. Giá trị chuỗi ≈ mô tả, giá trị số ≈ số đo. Đếm **số tên khác nhau**, không
đếm tổng giá trị (cùng `vessel_type` lặp ở 3 entity không giàu hơn 1). Cùng
nguyên tắc đã dùng ở `motion.py::entity_identity_facts()`.

### 18.20 Identity visibility — MỘT predicate, không phải hai tiêu chí (2026-07-30)

> Sửa §18.15/§18.17. Phát hiện khi kiểm một câu hỏi khác (`creative_identity` có
> tới `creation_experience_onboard` không) — câu hỏi đó hoá ra **không phải bug**,
> nhưng phép kiểm lộ ra hai lỗi nặng hơn.

**Nguyên nhân gốc: hai cơ chế identity dùng HAI tiêu chí visibility khác nhau.**

| Cơ chế | Quyết định theo | Ngữ nghĩa |
|---|---|---|
| `entity_identity_facts()` (Truth) | `camera.target` | "camera đang nhìn vật nào" |
| `creative_identity_for()` (Creative) | `framing` | "khung có đủ rộng không" |

Hai câu hỏi khác nhau ⇒ hai kết quả lệch nhau. Truth **tình cờ** đúng (tra id
không thấy thì trả rỗng); Creative không có lớp chặn nào.

**Bằng chứng đo trên config production** (script chạy thẳng hàm thật, không đoán):

| scene | framing | camera.target | Creative bị chèn? |
|---|---|---|---|
| `creation_design` | MEDIUM | `design_drawing` | 🔴 CÓ — khung chiếu **tờ bản vẽ** lại nhận *"dark navy metallic hull, white superstructure, raked plumb bow…"* |
| `creation_experience_onboard` | MEDIUM | `upper_deck` | 🔴 CÓ — camera **đứng trên boong** lại nhận mô tả con tàu nhìn từ ngoài |
| `creation_construction_engine` | MEDIUM | `marine_engine` | ⚠️ CÓ — shot kể về cỗ máy, không về dáng tàu |

Lỗi ở `creation_design` nghi là **nguyên nhân trực tiếp** của artifact scene
Design (§18.17): model được bảo vẽ một du thuyền sơn hoàn thiện trong khung đáng
lẽ là nét vẽ kỹ thuật. Lỗi ở `creation_experience_onboard` đúng bằng rủi ro mà
comment `camera_target` bên Laravel đã cảnh báo — override đó được thêm để chặn
"model kéo camera ra xa lấy trọn con tàu", nhưng mô tả ngoại thất quay lại bằng
**cửa khác** vì `creative_identity_for()` không nhìn `camera.target`.

**Sửa: gộp về `identity_visible(scene, world_entities)`** — một predicate, hai
điều kiện phải thoả CẢ HAI:

1. `identity_visible_at(framing)` — khung đủ rộng đọc được dáng tổng thể
2. `camera.target` là entity **có thật** trong `world.entities`

Cả Truth lẫn Creative gate bằng đúng predicate đó, tại **một** call site
(`session_runner.py`). Thêm điều kiện mới sau này (occlusion, silhouette,
reflection…) chỉ sửa một chỗ.

Kết quả đo lại: chỉ còn `construction_hull` và `experience_exterior` được chèn —
đúng hai scene mà camera thật sự nhìn vào con tàu. **9 scene THẬT không đổi**
(`camera.target` của chúng do `IntentPlanner` đặt từ `subjectIds[0]` nên luôn là
entity hợp lệ — đã kiểm trên RenderPlan production: chỉ 4 scene Creation Arc có
target không phải entity).

**Chưa làm, có lý do:** thêm trạng thái thứ ba `design` vào `creative_identity`
(hiện chỉ có `construction`/`final`, và `identity_variant('creation_design')` trả
`final` vì tiền tố không khớp). Sau khi gộp predicate thì Design **không còn nhận
nhầm** nữa, nên chưa có bằng chứng cần tăng độ phức tạp của state machine
(Rule 0). Nếu render kế tiếp vẫn hỏng ở Design thì đó mới là bằng chứng.

**Bất biến kèm theo — mọi nguồn identity đều phải qua CÙNG cổng.**

```
Truth identity  ─┐
                 ├─→ identity_visible(scene, world) ─→ composition_note
Creative identity┘
```

KHÔNG phải sơ đồ cũ (Truth có cổng riêng, Creative đi thẳng). Mai thêm brand /
material / historical identity thì tất cả đi qua đúng predicate đó — đây là thứ
ngăn bug vừa rồi tái diễn dưới tên khác.

#### GIỚI HẠN CÒN LẠI — predicate hỏi "entity thật?", chưa hỏi "ĐÚNG entity nào?"

> Phát hiện ngay sau bản sửa trên, khi soi lại cách phát biểu bất biến. Là bug
> **đang xảy ra trên dữ liệu production**, không phải rủi ro tương lai.

`identity_visible()` hỏi *"`camera.target` có phải entity thật không"*. Nó
**không** hỏi *"target có phải đúng entity mà `creative_identity` đang mô tả
không"* — vì `creative_identity_for()` chỉ key theo `scene.id`, hoàn toàn không
mang ràng buộc entity nào.

Với **Truth** thì không sao: `entity_identity_facts(target, …)` tra đúng fact của
chính target đó. Với **Creative** thì hụt.

Bằng chứng — 9 scene thật của bài Sixth Sense nhắm vào **4 con tàu khác nhau**:

| scene | framing | camera.target | Creative nhận được |
|---|---|---|---|
| `scene_1` | WIDE | `mylin_iv` | 🔴 identity của `sixth_sense` |
| `scene_5` | MEDIUM | `mylin_iv` | 🔴 identity của `sixth_sense` |
| `scene_4`/`8`/`9` | MEDIUM/WIDE/AERIAL | `sixth_sense` | ✅ đúng |
| `scene_2`/`3`/`6`/`7` | DETAIL | — | ✅ bị framing chặn |

`mylin_iv` là con tàu 1992 hoàn toàn khác, vậy mà nhận mô tả "dark navy metallic
hull, white superstructure" của Sixth Sense. **Đây chính là bug đã ghi ở §18.17
(*"scene_1/scene_2 gọi tên Mylin IV nhưng mang identity navy của Sixth Sense"*),
lúc đó quy sai nguyên nhân cho "RenderPlan thiếu `hero_entity`".** Nguyên nhân
thật: `creative_identity` không gắn với entity nào.

**Cách sửa bền vững (CHƯA làm):** gắn `creative_identity` vào một `entity_id`
(`CreationArcPlanner` vốn đã biết `$heroId`, emit ra là miễn phí), rồi
`creative_identity_for()` nhận thêm `target` và trả rỗng khi `target !=
entity_id`. Khi đó **cả hai** nguồn cùng tra theo `camera.target` — vẫn MỘT
predicate, và Creative trở nên entity-aware giống Truth.

**Vì sao chưa làm ngay:** bug chỉ chạm scene THẬT (1 và 5). **6 scene Creation
Arc không bị** — chúng có `subjects = [hero]`, target là hero hoặc vật thể bịa.
Nên render 6 scene arc để đo hai bản sửa trên vẫn hợp lệ, không bị nhiễu bởi lỗi
này. Sửa sau khi có kết quả render (Rule 0: đừng chồng thêm thay đổi lên một bản
sửa chưa được bằng chứng xác nhận).

### 18.21 Chốt chặn chi phí trong pipeline — dừng TRƯỚC cú gọi tốn tiền, không phải sau (2026-07-30)

> Bằng chứng khởi phát: một `dd()` cắm ngay SAU `extract()` để soi dữ liệu. Cú
> gọi Haiku vẫn bị tính **$0.0179** rồi toàn bộ kết quả bị vứt. Mỗi lần bấm lại
> để soi tiếp là mất thêm ngần ấy. Tiền đi trước, dữ liệu không về.

**Nguyên tắc:** `VideoPlanningPipeline::plan()` gọi Claude **1 + 1 + N** lần
(Extractor, Producer, Director mỗi scene). Điều kiện dừng phải đặt **ngay trước**
cú gọi kế tiếp, không phải ở cuối luồng — kiểm ở cuối thì tiền đã tiêu xong.

| Guard | Vị trí | Chặn được gì | Đã tốn phí chưa |
|---|---|---|---|
| **1** `articleHasNoText` | trước `extract()` | toàn bộ 1+1+N cú gọi | ❌ **chưa tốn đồng nào** |
| **2** `nothingSurvivedVerification` | sau Gatekeeper, trước Producer | Producer + N Director | ✅ đã tốn 1 (Extractor) |
| **3** `noSceneCouldBePlanned` | sau Timeline, trước Producer | Producer + N Director | ✅ đã tốn 1 |

Guard 1 là chốt **duy nhất** chặn được khi chưa mất xu nào — nên nó phải đứng
trước `extract()`, không phải sau.

**`PipelineAborted` ≠ lỗi hệ thống.** Nó mang `stage` (hỏng chặng nào) và
`spentMoney` (bấm lại có mất tiền không) — hai thứ người vận hành cần để quyết
định, mà stack trace không trả lời được.

**Hệ quả 1 — service KHÔNG nuốt lỗi thành `null` nữa.** Bản cũ bắt hết
`\Throwable` rồi `return null`; Controller chỉ hiện một câu chung chung và **lý
do mất sạch**. Chính vì thế mới phải cắm `dd()` để mò — mà mỗi lần mò là một cú
gọi bị tính tiền. Giờ `creatVideoById()` **ném ra ngoài** (vẫn log đủ stack
trace trước khi ném), Controller bắt và in `getMessage()` thẳng lên màn hình.

**Hệ quả 2 — benchmark phân biệt `ABORTED` với `ERROR`.** Với `video:benchmark`,
"bài này cho 0 entity" là một **kết quả đo hợp lệ**, không phải sự cố. Gộp vào
`ERROR` sẽ làm bài nghèo dữ liệu trông như hệ thống hỏng. `BenchmarkRunner` giữ
nguyên số liệu world ở hàng `ABORTED` — lấy được vì `onWorldVerified` chạy trước
guard.

**Test khoá đúng thứ đáng khoá:** không phải "có ném exception không" mà là
**Extractor/Producer có bị GỌI không** — đó mới là thứ mất tiền. Xem
`tests/Video/Pipeline/PipelineAbortedTest.php` (đếm số lần gọi bằng lớp ẩn danh).

#### Giới hạn đã đo của Guard 1 — CHƯA sửa, cần bằng chứng trước (2026-07-31)

Guard 1 tên là `articleHasNoText` nhưng điều kiện thật là `$index->isEmpty()`,
mà `ArticleNormalizer` đưa **cả tiêu đề** vào index:

```php
// ArticleNormalizer:33
if (trim($article->title) !== '') {
    $index->add(EvidenceSource::Headline, $article->title);
}
```

⇒ Guard 1 chỉ bắn khi **CẢ tiêu đề LẪN body đều rỗng**. Bài crawl hỏng kiểu
"có tiêu đề, mất body" **vẫn đi qua guard và vẫn tốn một cú Extractor**.

**Bằng chứng thực nghiệm (2026-07-31):** chạy `plan()` với
`RawArticle('article-proof', 'Tieu de', '')` — body rỗng, tiêu đề có chữ. Guard
1 KHÔNG bắn, pipeline chạm thẳng tới điểm gọi Extractor. Phát hiện tình cờ khi
viết script chứng minh `VideoPipelineFactory::claude()` không tốn tiền: dự đoán
"bài rỗng sẽ bị chặn" đã SAI.

**Chưa sửa, và đây là lý do.** Hai cách nhìn đều có lý:

- *Không phải lỗi*: tiêu đề `"100-Metre Feadship Moonrise Sold"` vẫn trích được
  entity `Moonrise` + claim `length_metres: 100`. Không phải giá trị bằng 0.
- *Là lỗ hổng*: một headline không đủ dựng World Graph cho video nhiều scene.
  Trả ~$0.002 để chắc chắn ra plan tồi là đúng loại lãng phí guard sinh ra để
  chặn.

Cách sửa hợp lý là đổi từ "kiểm rỗng" sang **ngưỡng độ dài** (index dưới N ký tự
thì dừng). Nhưng **N bằng bao nhiêu thì chưa có bằng chứng** — và đoán một con
số rồi viết vào code là đúng thứ dự án này đã nhiều lần phải vứt đi (§18.16 v1
mô tả sai ngành vì đoán). Cần thống kê độ dài index trên 30-50 bài thật, đối
chiếu với số entity thu được, rồi mới chọn ngưỡng.

Ghi thêm để không hiểu nhầm: tham số thứ hai của `PipelineAborted::articleHasNoText`
là `mb_strlen($article->html)` — **chỉ đếm body, không đếm tiêu đề**. Lúc guard
thật sự bắn thì cả hai đều rỗng nên con số đó luôn là 0; nó không nói dối, nhưng
cũng không cho biết gì.

### 18.22 Creation Arc THAY THẾ scene thật, không chèn thêm (2026-07-30, quyết định người dùng)

> Đảo quyết định 2026-07-24 ghi ở §18.16/§18.17 ("chèn TRƯỚC scene thật, video
> DÀI THÊM"). Bằng chứng đến từ lần compile thật đầu tiên chạy trọn vẹn.

**Quan sát.** Bài "The Sixth Sense" (category `yacht`) sinh ra **14 shot,
$2.52** — 6 scene arc + 8 scene thật. Đọc preflight của 8 scene thật:

```
scene_1..scene_8 : confidence 0.1 – 0.3  "nhiều lớp Spec còn trống"
scene_3          : prompt 256 ký tự, dưới ngưỡng bão hoà 300
```

Và nội dung của chúng nói về **Mylin IV**, du thuyền của Ratcliffe, du thuyền
của Cuban — những con tàu KHÁC, vì bài báo là tin di chuyển tàu chứ không phải
bài kể về một con tàu. Ghép chúng sau 6 scene kể chuyện đóng tàu không tạo ra
một câu chuyện; nó tạo ra hai video dán vào nhau.

**Quyết định.** Category có `phase_set` (`yacht`/`superyacht`, sau này
`cars`/`moto`) ⇒ video **CHỈ GỒM** scene arc. Category khác ⇒ đường thật như cũ,
Creation Arc không kích hoạt (hành vi đã có sẵn: `phases` rỗng → trả nguyên
`$renderPlan`).

Phân biệt bằng **hai thứ đã có**, không thêm cờ mới:
- **category** → quyết định có arc hay không (`creation_arc.categories`)
- **`scene.id` tiền tố `creation_`** → phân biệt scene arc với scene thật ở mọi
  tầng sau (compiler đã dùng tiền tố này ở `identity_variant()`)

**Hệ quả tốt bất ngờ: bài toán khó nhất của §18.15 BIẾN MẤT.** Mục đó viết:
*"Rủi ro lớn nhất là đúng ranh giới Craftsmanship → Experience: pha cuối bịa và
scene thật đầu tiên phải trông như CÙNG MỘT sản phẩm, nếu không cả 4-phase
feature coi như thất bại."* Không còn scene thật đứng sau ⇒ không còn ranh giới
đó ⇒ không còn rủi ro đó. Cơ chế identity (§18.20) vẫn giữ nguyên vì nó còn phải
lo nhất quán **giữa 6 scene arc với nhau**.

**Giữ lại gì trong RenderPlan sau khi thay:**

| Field | Xử lý | Vì sao |
|---|---|---|
| `scenes`/`acts`/`timeline` | **thay hoàn toàn** bằng arc | đây chính là thay đổi |
| `story.target_seconds` | = tổng thời lượng arc | không cộng dồn nữa |
| `world` | **giữ** | camera target + `entity_identity_facts()` đọc từ đây |
| `assets` | giữ, và **bảo đảm có `as_<hero>`** | scene arc tham chiếu nó; thiếu là dangling ref |
| `continuity`, `producer`, `facts` | giữ | cấp video, không thuộc scene nào |

**Chi phí:** 14 shot × $0.18 = $2.52 → **6 shot = $1.08**. Giảm 57%, và bỏ đúng
phần có confidence thấp nhất.

**Chưa làm, ghi để không quên:** `cars`/`moto` vẫn chưa có `phase_set` (thiếu tư
liệu ảnh — §18.16). Bài thuộc hai category đó hiện đi đường thật, KHÔNG bị thay
thế — đúng, vì chưa có arc nào để thay bằng.

### 18.23 Bỏ Producer/Director khi Creation Arc sắp thay sạch scene (2026-07-31)

**Vấn đề đo được.** §18.22 chốt arc THAY THẾ scene thật. Hệ quả không ai để ý:
`director_notes` và `objective` của scene thật bị vứt **cùng với scene**. Bài
"The Sixth Sense": **9/10 cú gọi Claude bị vứt** (1 Producer + 8 Director), tức
34% chi phí sinh plan trả cho dữ liệu không ai đọc.

**Chốt chặn.** `VideoPlanningPipeline::plan()` nhận thêm `?callable
$creativeNeededFor`. Trả `false` ⇒ bỏ hẳn Producer + N Director, đi thẳng tới
`assemble()`.

Hai điều kiện thiết kế, cả hai đều KHÔNG thoả hiệp được:

1. **Là predicate, không phải cờ bool.** Điều kiện chỉ đúng SAU Gatekeeper:
   category `yacht` khớp nhưng bài không trích được entity Vehicle nào ⇒ arc
   không kích hoạt ⇒ scene thật sống tiếp ⇒ VẪN cần creative. Quyết trước khi
   chạy pipeline là sai đúng ở ca đó.
2. **Predicate chỉ nhận `VerifiedWorldGraph`, KHÔNG nhận `Article`/category.**
   Pipeline phải mù chủ đề (§1) — nó không được biết LÝ DO người gọi từ chối.
   Có test khoá riêng điều này.

**Điều kiện thật là "có phase_set", không phải "có tên trong danh sách".**
`creationArcPhaseSetFor()` trả null khi `phases === []`. Nên thêm `cars`/`moto`
sau này chỉ cần thêm DỮ LIỆU vào config — việc bỏ Producer/Director tự động có
hiệu lực, không sửa dòng code nào. Ngược lại, category khai mà quên pha thì arc
không chạy, scene thật là nội dung cuối, và creative VẪN chạy — đúng, vì lúc đó
nó có người dùng.

`build()` và `applyCreationArc()` dùng CHUNG một hàm tra cứu. Hai nơi lệch nhau
sẽ tạo ra ca "bỏ Producer nhưng arc không thay scene" — plan không creative mà
cũng không arc.

### 18.24 Chi phí LLM: ghi lại, và đối chiếu với hoá đơn thật (2026-07-31)

**Luồng video nay ghi `claude_usage_logs`.** Trước đó chỉ có dòng `Claude OK`
trong `laravel.log`; muốn biết tốn bao nhiêu phải đọc log bằng tay.

- `action = 'video_renderplan'`, tách khỏi `send_to_claude`/`synthesize` của CMS.
- **Một hàng cho một lần bấm 🎬**, không phải một hàng mỗi cú gọi API — cùng độ
  hạt với CMS, nên `ClaudeUsageController` (`COUNT(*) as manual_calls`) không bị
  lệch nghĩa.
- Ghi trong `finally`: **lần chạy HỎNG cũng phải được ghi**. Chỉ ghi khi thành
  công thì thống kê sẽ giấu đúng phần lãng phí đang chống (bằng chứng: 5 lần cắt
  trần ≈ $0.09 không hệ thống nào ghi lại).
- Không có admin đăng nhập (CLI `video:benchmark`) ⇒ bỏ qua im lặng + `Log::info`.
  `claude_usage_logs.admin_id` có khoá ngoại, không ghi được hàng mồ côi.

**Số liệu là số THẬT, không ước lượng.** Dùng lại `CostAccumulatingLlmClient`
(vốn chỉ cho benchmark) thay vì đếm lần hai — nó cộng
`LlmResponse->tokensIn/tokensOut/costUsd`, mà ba trường đó lấy thẳng từ `usage.*`
của response Anthropic.

#### Bảng giá Haiku đã SAI 20% cho tới 2026-07-31

`PRICE_INPUT['haiku']` ghi `0.80`, `PRICE_OUTPUT['haiku']` ghi `4.00`. Giá thật:
**$1.00 / $5.00**. Lệch đúng 1.25× ⇒ **mọi con số chi phí ghi trước ngày đó thấp
hơn thực tế 20%; nhân 1.25 để quy đổi.** Luồng video chạy 100% Haiku nên sai toàn
bộ; luồng CMS chỉ sai ở các cú Haiku, phần Sonnet ($3/$15) vốn đúng.

Phát hiện bằng cách đối chiếu tay với `platform.claude.com/cost`. Đã khoá bằng
test so với **hằng số viết tay** — đọc lại chính bảng đó thì test luôn xanh kể cả
khi bảng sai.

#### `usage.input_tokens` KHÔNG phải tổng token đầu vào

Anthropic trả `input_tokens` là **phần chưa cache**. Tổng thật:

```
input_tokens + cache_creation_input_tokens + cache_read_input_tokens
```

Ba loại có ba đơn giá: cache ĐỌC 0.1× input, cache GHI 1.25× (TTL 5 phút).
Project hiện KHÔNG gửi `cache_control` nên hai trường cache luôn 0 — đã bắt sẵn
để ngày bật cache thì chi phí không tụt âm thầm.

`ClaudeResponse::totalInputTokens()` tên tránh chữ "prompt" CÓ CHỦ ĐÍCH: chỗ gọi
nằm trong `app/Video/`, nơi Architecture Test cấm chữ đó (§1). Đặt tên sai làm CI
đỏ ở file khác — đã xảy ra một lần khi viết mục này.

#### CHƯA LÀM — ghi để không quên

**(a) `cache_creation` phải tách 5m/1h.** API usage report tách
`ephemeral_5m_input_tokens` và `ephemeral_1h_input_tokens`, hai đơn giá khác nhau
(1.25× và 2×). Code hiện gộp làm một và nhân cứng 1.25× ⇒ nếu ai bật cache TTL 1
giờ thì thiếu 37%. Chưa ảnh hưởng vì cache đang tắt, nhưng GIẢ ĐỊNH ĐÃ GHI SAI.

**(b) Bảng giá phẳng bỏ qua 3 chiều** mà API thật có: `context_window`
(`0-200k` vs `200k-1M` — Sonnet 1M context vượt 200k tính giá khác),
`service_tier` (`standard`/`batch`/`flex`/`priority`), và `speed`. Đúng cho hôm
nay (Haiku, standard, <200k) nhưng không đúng tổng quát.

**(c) Đối chiếu với hoá đơn thật.** Hai endpoint (auth `Authorization: Bearer`
+ admin OAuth token, KHÁC `x-api-key`):

| Endpoint | Độ mịn nhỏ nhất | Lọc theo API key | Cho gì |
|---|---|---|---|
| `GET /v1/organizations/usage_report/messages` | **1 phút** | ✅ `api_key_ids` | số token, tách 5 loại |
| `GET /v1/organizations/cost_report` | 1 ngày | ❌ | số tiền |

**Không có API nào trả chi phí per-call** — per-call BẮT BUỘC tính tại chỗ. Cách
làm hai bên khớp là suy đơn giá TỪ hoá đơn thay vì viết tay:

```
đơn_giá(model, token_type) = cost_report.amount ÷ usage_report.tokens
chi_phí_mỗi_cú           = token_của_cú × đơn_giá
⇒ Σ chi_phí_mỗi_cú ≡ hoá đơn, theo định nghĩa
```

Bug 20% ở trên đã tự lộ nếu có cơ chế này: tỉ lệ `local ÷ thật` = 0.8 đều đặn
mỗi ngày là chữ ký của SAI GIÁ, không phải nhiễu.

Lưu ý phạm vi: `cost_report` là cấp TỔ CHỨC, không lọc được theo API key. Nếu
`CLAUDE_API_KEY` còn dùng ở chỗ khác thì tổng org luôn lớn hơn tổng local và
tiền sẽ không bao giờ khớp — chỉ token đối chiếu được chính xác.

Hoãn vì cần **admin OAuth token** chưa có. `amount` tính bằng CENT, không phải
đô (`"123.45"` = $1.23) — bẫy sai gấp 100 lần.

### 18.25 Laravel BẮN, Python chạy — bỏ chạy tay (2026-07-31, quyết định người dùng)

**Trước:** bấm 🎬 xong Laravel dừng hẳn. Muốn có prompt phải mở terminal gõ
`python tools/session_runner.py`. Không ai nhắc, không có báo lỗi — quên là
session nằm im ở `composing` mãi. Đúng câu hỏi người dùng đã hỏi: *"sao không
thấy prompt đâu cả"*.

**Phương án BỊ LOẠI — Python chạy vòng lặp poll.** Đề xuất đầu tiên của tôi là
`--watch`, poll 10 giây/lần. Người dùng bác: *"gọi vòng lặp cả 1000 lần thì sập
server"*.

Số thật: 10s/lần = 8.640 request/ngày × ~5ms ≈ **43 giây CPU/ngày** — KHÔNG
phải rủi ro tải, và tôi đã đính chính điều đó. Nhưng quyết định vẫn đúng, chỉ
khác lý do: poll **trễ 10 giây khi máy đang rảnh**, và **bắt giữ một tiến trình
sống mãi** — quên bật thì lại im lặng đúng như bệnh cũ.

**Phương án BỊ LOẠI — Python thành HTTP server (FastAPI/Flask).** Không giải
quyết được gì: vẫn cần một tiến trình sống mãi, lại thêm một tầng server phải
viết và phải nuôi.

**Đã chọn — bắn rồi quên (fire-and-forget).** Laravel sinh tiến trình nền rồi
TRẢ VỀ NGAY; request HTTP không bao giờ chờ Python.

| Nút | Laravel làm | rồi bắn | Đặc tính |
|---|---|---|---|
| 🎬 Tạo Video | chạy pipeline, lưu `renderplan_json`, `status=composing` | `session_runner.py --session=<code>` | vài giây, **$0**, người dùng ĐANG chờ |
| 🎬 Render shot đã duyệt | `approved → queued`, `status=rendering` | `render_queued_shots.py --session=<code>` | **6-18 phút**, **$1.08**, người dùng KHÔNG chờ |

Cả hai script **đã tồn tại** từ trước — đây là việc NỐI DÂY, không phải viết mới.

#### Vì sao một việc chạy 18 phút vẫn hợp với "bắn rồi quên"

Nhờ `PATCH /api/video-shots/{id}/result` báo **theo từng shot**, không phải một
cục cuối cùng:

```
clip 1 xong → PATCH → DB đã lưu     ┐
clip 2 xong → PATCH → DB đã lưu     ├─ an toàn
clip 3 xong → PATCH → DB đã lưu     ┘
clip 4 → 💥 máy sập
         ⇒ 3 clip đầu KHÔNG mất
         ⇒ 3 clip còn lại vẫn `queued`
         ⇒ BẤM LẠI nút Render = chỉ chạy 3 cái thiếu
```

Bấm lại an toàn vì `queueApprovedForSession()` chỉ lấy shot `approved`, và Python
chỉ lấy shot `queued`. Không render trùng, không trả tiền hai lần.

#### Ba cái giá, chấp nhận có ý thức

1. **Buộc Laravel và Python cùng MỘT máy.** Hôm nay đúng vậy (`D:\xampp\...` và
   `D:\1. Work\...`) nên miễn phí. **Đường lui:** ngày tách máy thì quay lại kiểu
   poll — API HTTP giữ nguyên không đổi một dòng, chính vì thế mà giữ.
2. **Tiến trình nền hỏng thì im lặng.** Bù bằng: Python ghi log ra file, và
   Laravel hiện cảnh báo kèm câu lệnh chạy tay khi sinh tiến trình thất bại.
   **Sinh tiến trình hỏng KHÔNG được làm hỏng session** — plan đã lưu rồi.
3. **Clip đang render dở lúc máy sập** có thể đã bị Veo tính tiền mà chưa PATCH
   về ⇒ mất $0.18 không có sổ. Không thiết kế nào tránh được, kể cả poll.

#### Ràng buộc thi công

- Sinh tiến trình gom vào **một class `PythonRunner`** — `grep` ra đúng một chỗ,
  cùng lý do `spendingLlmClient()` là nơi duy nhất cấp quyền tiêu tiền.
- Đường dẫn nằm ở `.env` (`VIDEO_PYTHON_BIN`, `VIDEO_RUNNER_DIR`), KHÔNG hardcode.
- Class đó đặt ở `app/Services/`, **không phải `app/Video/`** — Architecture Test
  cấm tên công cụ render trong `app/Video/` (§1). Đây cũng là lý do tầng Video
  vẫn hoàn toàn mù về việc Python tồn tại: chỉ tầng Service biết.
- Hai script Python cần thêm `--session=<code>` để xử lý ĐÚNG session vừa tạo,
  thay vì quét toàn bộ.

### 18.26 Khung cố định — cơ chế khoá identity mạnh hơn chữ (2026-07-31)

**Bằng chứng:** một video **AI sinh ra** dài 23 giây (nguồn: Facebook), dựng lại
một chiếc Mitsubishi Evo IX trong gara. Người dùng đưa cả 23 frame.

Vì là **AI sinh ra chứ không phải quay thật**, nó là **bằng chứng khả thi**:
provider LÀM ĐƯỢC việc này. Nếu là video quay thật thì nó chỉ là mục tiêu.

#### Quan sát quyết định

Nền phía sau — kệ lốp, hai màn hình TV, bàn nguội, bình khí — **giống hệt nhau ở
mọi frame**. Máy quay không nhích một milimet suốt 23 giây. Thứ thay đổi là
chiếc xe, ngay trong khung:

```
00s  xe đỏ nát, cản buộc dây rút, đứng MỘT MÌNH
04s  thợ bê cản rời khỏi xe
07s  trơ khung trên bốn đội kê, tháo sạch bánh
09s  phun primer
12s  MÀU TRỞ LẠI — đỏ sâu + ca-pô carbon + hông rộng
18s  đèn sáng, hoàn chỉnh, hạ xuống đất
22s  xe đi khỏi, gara TRỐNG, còn vệt lốp
```

#### Vì sao điều này quan trọng hơn "một template mới"

§18.15 ghi bằng chứng đau: *"3 clip nhận cùng câu nhận dạng vẫn ra 3 con tàu
khác nhau — chữ không đủ độ phân giải để khóa silhouette"*. Từ đó dự án chống
identity drift bằng **chữ** (`visual_identity`) và tính dùng **ảnh neo**.

Video này giải bằng **CẤU TRÚC**: máy đứng yên + nền bất biến ⇒ người xem TỰ THẤY
đó là cùng một vật. Nền chính là bằng chứng, không cần ai thuyết phục.

Đây là hướng thứ ba, chưa từng cân nhắc:

| Cách khoá identity | Cơ chế | Trạng thái |
|---|---|---|
| Câu mô tả (`visual_identity`) | chữ | đã có — §18.15 chứng minh KHÔNG đủ |
| Ảnh neo (Flux/Kontext → i2v) | ảnh | Reserved, chưa thi công |
| **Khung cố định + nền bất biến** | **bố cục** | **mới, §18.26** |

#### `restoration` phase_set — cars + moto dùng chung (quyết định user)

Bảy pha, **tất cả cùng `WIDE` + `STATIC`**: `arrival` → `teardown` →
`bare_shell` → `bodywork` → `paint` → `reveal` → `drive_out`.

Không phải một prompt lặp bảy lần: `composition_note` và `micro_physics` khác
nhau hoàn toàn. **Chỉ vị trí máy là bất biến** — và đó chính là thứ tạo ra sức
mạnh, nên nó là BẤT BIẾN có test canh
(`test_the_restoration_arc_never_moves_the_camera`).

Bản nháp đầu tiên của mục này để `bodywork` ở `MEDIUM` và **bị chính test đó
bắt** — đổi framing cũng là đổi vị trí máy.

Pha cuối kết bằng **khung trống**: xe đi khỏi, chỉ còn vệt lốp. Đóng vòng với pha
1 (xe hỏng đứng một mình) — cùng khung, khác nội dung, không cần một lời nào.

Tháo lắp xe máy khác ô tô, nhưng cấu trúc 7 pha thì chung — khác biệt để trong
`composition_note`, không tách phase_set.

#### ⚠️ CHƯA CHẠY ĐƯỢC ĐÚNG Ý ĐỒ — đọc trước khi render

23 giây **dài hơn mọi lần sinh đơn lẻ** (Veo tối đa 8s), nên video tham chiếu
chắc chắn là **nhiều clip nối nhau**, và cách duy nhất giữ nền giống hệt qua các
clip là **frame cuối clip trước làm ảnh gốc clip sau** — i2v chuỗi.

Python hiện render **100% t2v độc lập** (`render_queued_shots.py` docstring dòng
18: *"renderer=i2v khai trong render_plan hiện KHÔNG khả thi cho shot 'motion'
đơn lẻ"*). Nghĩa là **7 clip sẽ ra 7 cái gara khác nhau** — đúng thứ khung cố
định sinh ra để chống.

Dữ liệu trong config ĐÚNG và dùng được ngay ở mức nội dung. Phần khoá identity
phải chờ i2v chuỗi. **Đừng render `restoration` rồi kết luận cơ chế này sai** —
cơ chế chưa được bật.

`set_dressing` (câu tả bối cảnh lặp nguyên văn ở mọi pha) là biện pháp tạm: khi
chưa có i2v, lặp chữ là thứ duy nhất còn lại để ghì nền lại gần nhau.

#### Còn với `vessel` thì sao — KHÔNG bê nguyên

Đóng tàu THẬT SỰ diễn ra ở nhiều nơi: phòng vẽ, ụ tàu, xưởng hoàn thiện, mặt
nước. Ép một khung cố định là bịa quy trình — đúng loại sai lầm của v1 (§18.16).

**Áp dụng được một phần, CHƯA LÀM:** ba pha `construction_hull` →
`construction_engine` → `craftsmanship` có thể dùng chung MỘT vị trí máy ở ụ tàu
thay vì ba nơi ba kiểu. Ba shot đó vốn đã cùng `STATIC`; chỉ cần cùng bối cảnh
nữa là có hiệu ứng đối chiếu. Chưa sửa vì cần bằng chứng render, và vì `vessel`
là phase_set đã có sản phẩm chạy được — không đổi cái đang chạy để chạy theo một
cơ chế còn chưa bật.

#### Kiểm chứng thật — $0.265, cơ chế ĐỨNG VỮNG (2026-07-31)

`tools/probe_fixed_frame.py`. Chạy pha khó nhất (`teardown`: 3 người, nhiều tay,
nhiều công cụ) thay vì cả 7 pha — hỏng thì mất $0.24 chứ không phải $1.50.

| Bước | Model | Kết quả |
|---|---|---|
| nền | `fal-ai/flux/dev` | ✅ |
| sửa ảnh | `fal-ai/flux-pro/kontext` | ✅ giữ nền, ⚠️ 2 lần mới giữ được xe |
| hoạt hoá | `fal-ai/veo3.1/lite/image-to-video` | ✅ endpoint ĐÚNG (trước đó chỉ suy từ quy ước fal) |

**Nền giữ được, lặp lại được 2/2 lần.** Bằng chứng mạnh nhất: **vệt lốp cong trên
sàn** — chi tiết ngẫu nhiên, KHÔNG hề nhắc trong prompt Kontext, vẫn nằm đúng chỗ
ở mọi ảnh và mọi frame video. Kontext **chép** nền chứ không vẽ lại. t2v không
bao giờ làm được: 7 lần sinh là 7 vệt lốp khác nhau.

**Veo giữ khung + giữ xe + THỰC HIỆN THAY ĐỔI CẤU TRÚC.** Cản crôm biến mất khỏi
đầu xe giữa frame đầu và frame cuối. Negative `camera movement, zoom, pan` có tác
dụng — máy không nhích.

#### BÀI HỌC VIẾT PROMPT — phần dùng lại được nhiều nhất

**1. Nói cái PHẢI GIỮ, đừng nói cái được đổi.**

```
❌ "Change only the car and add people"
     → Kontext vẽ lại chiếc xe (Datsun → Mustang: khác lưới tản nhiệt, khác đèn)
✅ "Keep the SAME CAR: same body shape, same headlights, same grille,
     same proportions, parked in the same spot"
```

"Change the car" là giấy phép vẽ lại. Model làm ĐÚNG lời, không sai.

**2. Giao đúng việc cho đúng công cụ.**

```
Kontext  →  dựng TRẠNG THÁI ĐẦU của clip  (ai đứng đâu, tay đặt vào đâu)
Veo i2v  →  tạo THAY ĐỔI                   (cản rời ra, khe hở mở rộng)
```

Sai lầm đã mắc: bắt Kontext sinh ra trạng thái "cản đã tháo". Nó hỏng 2/2 lần, và
tôi rút ra kết luận SAI là *"Kontext kém khoản tháo rời"* → *"teardown/bare_shell
rủi ro cao"*. Cả hai đều sai: tháo rời một bộ phận là **CHUYỂN ĐỘNG**, việc của
i2v. Người dùng bác lại (*"người khác làm được sao bạn không làm được"*) và chính
điều đó dẫn tới chẩn đoán đúng. Nếu nhận kết luận đầu thì `restoration` đã bị
gạch bỏ oan.

**3. Frame–Text Coherence (Core Invariant #5, ADR v1.2) — tả ĐÚNG cái đang có
trong khung.**

```
❌ "The DETACHED front bumper moves away from the body"
     → nhưng trong ảnh cản VẪN GẮN trên xe. Veo hoà giải mâu thuẫn bằng cách
       xoá cản crôm khỏi xe VÀ bịa ra một cản nhựa đỏ khác đặt xuống sàn.
       Người dùng bắt được: "đang tháo cản thì phải lấy từ xe ra chứ, đây đưa
       ngoài vào".
✅ "The mechanics unbolt the car's CHROME front bumper and lift it down onto
     the floor. It is the SAME chrome bumper throughout."
```

Mô tả một trạng thái không tồn tại trong ảnh = mời model bịa thêm vật thể.

**4. Tả NGOẠI HÌNH của bộ phận, không chỉ tên nó.**

Không tả thì model tự chọn, và nó chọn khác giữa lúc tháo ra và lúc đặt xuống.
`"the chrome front bumper"` chứ không phải `"the front bumper"`. Đây là bản sao
của bài học identity ở cấp bộ phận: chữ nghèo → hình trôi.

**5. Kontext BỎ QUA `aspect_ratio`.** Nền 576×1024 (0.562) → ảnh sửa 752×1392
(0.540), bất kể truyền `aspect_ratio: '9:16'`. Khung dịch nhẹ, lộ thêm trần. Chưa
tìm được cách ép kích thước — **giả thuyết CHƯA KIỂM**: chính việc sinh ở độ phân
giải khác là nguyên nhân xe bị xoay góc trong ảnh v2.

#### Trạng thái từng mắt xích

```
① Flux t2i   → nền cố định                        ✅
② Kontext    → giữ nền                            ✅ 2/2
③ Kontext    → giữ chiếc xe                       ⚠️ chỉ khi nói "SAME CAR"; góc vẫn xoay
④ Veo i2v    → giữ khung + giữ xe trong clip      ✅
⑤ Veo i2v    → thực hiện thay đổi cấu trúc        ✅
⑥ Veo i2v    → hoạt hoá nhiều người + công cụ     ✅
⑦ liên tục MỘT vật thể qua thay đổi               ❌ tháo crôm, đặt xuống nhựa đỏ
```

⑦ là khiếm khuyết DUY NHẤT còn lại, và nó sửa bằng chữ (bài học 3+4) — chưa chạy
lại để xác nhận (người dùng dừng ở đây).

#### CHƯA TRẢ LỜI — cần bằng chứng, đừng đoán

| Câu hỏi | Cách kiểm | Giá |
|---|---|---|
| Prompt sửa theo bài học 3+4 có khắc phục ⑦ không | chạy lại bước video | $0.18 |
| Nối clip N → N+1 (frame cuối làm ảnh gốc) có mượt không | 1 clip nữa | $0.18 |
| Bước nhảy lớn (primer → sơn đỏ) có cần Kontext không | 1 Kontext + 1 clip | ~$0.21 |
| 7 clip có giữ được CÙNG MỘT xe không | chạy đủ 7 pha | ~$1.30 |

Chưa có câu nào được trả lời ⇒ **chưa được kết luận `restoration` chạy tốt end-to-
end**. Mới chứng minh được từng mắt xích riêng lẻ.

### 18.27 Tư liệu đóng du thuyền THẬT — kho ý tưởng cho prompt & ảnh (2026-07-31)

**Nguồn:** **249 frame** trích từ phim tư liệu kênh **Yachtory** (Lürssen và các
xưởng Ý/Đức). Người dùng cung cấp.

⚠️ **BẢN KIỂM KÊ DƯỚI ĐÂY CÓ THỂ CHƯA ĐẦY ĐỦ.** Bản đầu của mục này ghi "~90
frame" — tôi ƯỚC LƯỢNG rồi viết ra như đã đếm, đó là bịa. Người dùng đính chính:
249. Tôi không xác minh được mình đã nhận và đọc đủ 249 hay chỉ một phần, nên
danh sách beat và thủ pháp bên dưới phải coi là **SÀN, không phải trần** — có thể
còn beat chưa được ghi. Ai bổ sung sau thì thêm vào, đừng cho là đã đủ.

⚠️ **QUAY THẬT, KHÔNG PHẢI AI SINH RA.** Khác hẳn video Evo IX ở §18.26. Nghĩa
là nó là **MỤC TIÊU**, không phải bằng chứng khả thi — chưa chứng minh provider
làm được. Đừng đọc mục này như §18.26. Chín ảnh tư liệu tĩnh của §18.16 cũng
cùng loại nguồn này.

#### ⚠️ SỬA §18.26: khung cố định LÀM ĐƯỢC cho tàu — tôi đã kết luận sai

§18.26 ghi: *"Đóng tàu THẬT SỰ diễn ra ở nhiều nơi... Ép một khung cố định là
bịa quy trình"*. **Sai.**

Tư liệu có nhiều frame rõ ràng là **webcam timelapse cố định trong nhà xưởng**:
cùng một góc cao nhìn xuống sàn xưởng, chụp qua nhiều tháng —

```
① sàn xưởng gần trống, vài khối thép rời rạc
② các khối thân bắt đầu ghép, giàn giáo dựng lên
③ thượng tầng nhiều tầng đã chồng lên, tàu gần thành hình
```

Cùng cột, cùng cửa trời, cùng vạch sơn trên sàn. **Xưởng đóng tàu thật DÙNG
đúng cơ chế khung cố định** — vì nó là cách duy nhất cho người xem thấy "cùng
một chỗ, khác thời điểm".

Cái sai của tôi: lẫn giữa *"toàn bộ quy trình ở một chỗ"* (đúng là không) với
*"giai đoạn thi công thân ở một chỗ"* (đúng là có). Thi công thân **đứng yên
hàng tháng trong một nhà xưởng** — đó chính là chỗ khung cố định phát huy.

Hệ quả: `vessel` NÊN có một cụm 3 pha dùng chung một vị trí máy trong nhà xưởng,
đúng như đã ghi ở §18.26 mục "áp dụng được một phần" — nhưng lý do mạnh hơn tôi
tưởng, vì có tư liệu thật chứ không phải suy đoán.

#### Kho BEAT — các giai đoạn nhìn thấy trong tư liệu

Nhiều hơn hẳn 6 pha `vessel` hiện có. Đánh dấu ⭐ = chưa có trong config.

| # | Beat | Nhìn thấy gì |
|---|---|---|
| 1 | Thiết kế — bàn vẽ | hai người cúi trên bản vẽ kỹ thuật trải rộng |
| 2 | ⭐ Phác thảo tay | bàn tay cầm bút chì vẽ dáng tàu lên giấy trắng; nhiều tờ chồng nhau |
| 3 | ⭐ Cắt thép | mũi khoan/dao phay ăn vào phôi, phoi kim loại bắn |
| 4 | Hàn | hồ quang xanh trắng chói trong tối gần đen, khói cuộn |
| 5 | ⭐ Xưởng mộc — bào tay | bào gỗ, phoi xoăn vàng cuộn; cận cảnh dụng cụ nằm im |
| 6 | ⭐ Xưởng mộc — lắp tủ | thợ ráp thùng tủ veneer sẫm trên bàn máy |
| 7 | ⭐ Nội thất — vật liệu | tay vuốt tấm thảm/vải lên mặt gỗ; kính/đá bóng |
| 8 | Thân trong nhà xưởng | thân sẫm khổng lồ, giàn giáo hai bên, đối xứng tuyệt đối |
| 9 | ⭐ Timelapse cố định | cùng góc cao, sàn xưởng → khối → tàu thành hình |
| 10 | Hạ động cơ | máy trắng lớn treo, boong hở bên dưới |
| 11 | ⭐ Lắp chân vịt | chân vịt đồng 5 cánh, người đứng cạnh làm thước tỉ lệ |
| 12 | ⭐ Sơn/đánh bóng | thân đen soi gương phản chiếu nguyên giàn giáo |
| 13 | ⭐ Kéo ra khỏi xưởng | tàu trượt trên ray, cửa xưởng mở toang |
| 14 | ⭐ Ngập nước ụ khô | nước tràn vào ụ, tàu bắt đầu nổi |
| 15 | ⭐ Vận chuyển bằng sà lan | tàu nằm trên sà lan đỏ, 3-4 tàu kéo, đi dọc sông |
| 16 | ⭐ Lễ đặt tên | HÀNG TRĂM thợ đồng phục xanh + mũ trắng vỗ tay |
| 17 | Hạ thuỷ ban đêm | tàu rời xưởng, đèn vàng, mặt nước phản chiếu |
| 18 | Chạy thử | tàu chạy trên biển, sóng mũi, trời xám |
| 19 | Thành phẩm | hoàng hôn, tàu neo tĩnh, núi phía sau |

#### THỦ PHÁP LẶP LẠI — thứ đáng đưa vào prompt nhất

**① Tương phản tỉ lệ — dùng ở gần như mọi beat.** Một người **nhỏ xíu** đặt cạnh
vật khổng lồ: thợ đứng dưới chân vịt, người đi giữa hai giàn giáo dưới mũi tàu,
công nhân trên boong so với khối thượng tầng.

Đây là thứ làm người xem **cảm** được kích thước. Không có người trong khung thì
con tàu chỉ là một vật, không có thang đo. `composition_note` nên LUÔN cài một
người làm thước.

**② Đối xứng tuyệt đối — dành riêng cho mũi tàu trong xưởng.** Mũi nằm chính
giữa, hai hàng giàn giáo chạy song song hai bên, điểm tụ ở giữa khung. Bố cục
này chỉ xuất hiện ở nhà xưởng và nó rất mạnh. Dùng `composition: SYMMETRY`.

**③ Nhìn thẳng từ trên xuống (top-down).** Tàu thành một **hình đồ hoạ** trên nền
nước sẫm — thấy rõ sân đỗ trực thăng chữ H, hồ bơi, boong gỗ. Chỉ đọc được ở góc
này. Dùng cho beat vận chuyển và chạy thử.

**④ Mặt sơn soi gương.** Thân đen bóng phản chiếu **nguyên giàn giáo và trần
xưởng** — bằng chứng thị giác của "đã sơn xong", mạnh hơn mọi câu tả màu.

**⑤ Chi tiết là "món trang sức".** Cận cảnh chân vịt đồng, mũi khoan, bào gỗ nằm
im cạnh phoi xoăn. Những shot này KHÔNG có tàu trong khung mà vẫn kể chuyện đóng
tàu.

**⑥ Đám đông đồng phục = nghi lễ.** Hàng trăm người mặc **cùng một bộ xanh navy,
cùng mũ trắng**, đứng thành hàng vỗ tay. Đồng phục biến đám đông thành một khối
— rất khác với 3 thợ rời rạc lúc thi công.

#### VÒNG CUNG ÁNH SÁNG — đọc được từ tư liệu

```
thiết kế / mộc     ấm, dịu, ánh ngày trong nhà       NEUTRAL·SOFT
hàn                xanh trắng CHÓI trong gần đen      COOL·HARSH
nhà xưởng          đèn công nghiệp trên cao, lạnh     COOL·NEUTRAL
sơn xong           phản chiếu, tương phản cao         NEUTRAL·HARSH
hạ thuỷ đêm        vàng cam nhân tạo                  GOLDEN·SOFT
chạy thử           ánh ngày tự nhiên, trời xám        COOL·NEUTRAL
thành phẩm         hoàng hôn                          GOLDEN·SOFT
```

Không phải trang trí: vòng cung **lạnh → vàng** chính là cảm giác thời gian trôi
và "việc đã xong". Config hiện có vòng cung này nhưng thô hơn (chỉ COOL→GOLDEN).

#### NGỮ PHÁP MÁY QUAY — 7 kiểu phân biệt được

| Kiểu | Dùng cho | Enum tương ứng |
|---|---|---|
| Timelapse cố định | thi công trong xưởng | `STATIC` + WIDE |
| Đối xứng chính diện mũi | tàu trong xưởng | `STATIC` + `SYMMETRY` |
| Ngước từ dưới lên | tỉ lệ khổng lồ | `STATIC` + WIDE |
| Top-down | vận chuyển, chạy thử | `AERIAL` |
| Bay theo | sà lan trên sông | `TRACK` + `AERIAL` |
| Cận cực gần | dụng cụ, chân vịt, tay | `CLOSE`/`DETAIL` |
| Tĩnh rộng | đám đông lễ đặt tên | `STATIC` + WIDE |

Sáu pha `vessel` hiện chỉ dùng 3 trong 7 kiểu. Thiếu nhất: **top-down** và **ngước
từ dưới lên** — hai kiểu cho cảm giác quy mô mạnh nhất.

#### DÙNG KHO NÀY THẾ NÀO — chưa làm, đừng nhảy vào viết config

1. **Sửa §18.26 phần `vessel`** — cụm 3 pha thi công dùng chung một vị trí máy
   trong nhà xưởng, nay có tư liệu thật chống lưng.
2. **Cài người làm thước tỉ lệ** vào mọi `composition_note` có tàu trong khung.
3. **Thêm `SYMMETRY` và `AERIAL`** — hai thứ config chưa dùng bao giờ, mà tư liệu
   cho thấy chúng mang lại cảm giác quy mô.
4. **Cân nhắc beat mới**: lắp chân vịt (⭐11), sơn soi gương (⭐12), lễ đặt tên
   (⭐16). Cả ba đều là hình ảnh mạnh mà arc hiện KHÔNG có.
5. **KHÔNG mở rộng `vessel` quá 6-7 pha** nếu chưa có bằng chứng render: mỗi pha
   là $0.18, và §18.22 đã cho thấy cắt bớt scene yếu còn tốt hơn thêm scene.

Thứ tự đúng vẫn là: sửa cái đang chạy trước, thêm beat mới sau khi có render thật
chứng minh beat cũ đã ổn.

#### LƯỢT XEM THỨ HAI — bổ sung 9 beat + 5 thủ pháp còn thiếu (2026-07-31)

Bản kiểm kê đầu chỉ dựa trên một phần tư liệu. Xem lại đầy đủ thì thiếu khá
nhiều, và có một điều **quan trọng hơn mọi beat**:

#### ⚠️ ĐÍNH CHÍNH: tư liệu là NHIỀU CON TÀU KHÁC NHAU, không phải một

Trong tập frame có ít nhất **5 con tàu riêng biệt**: một explorer xám Lürssen,
một thân đen bóng kiểu Feadship, một megayacht trắng nhiều tầng, một **catamaran**
(SEAWOLF X), và một thân **trimaran xanh**. Bản kiểm kê đầu ngầm giả định "một
lượt đóng tàu từ đầu tới cuối" — **sai**.

Hệ quả không nhỏ: bản thân phim tư liệu **KHÔNG giữ identity xuyên suốt**. Nó
dựng arc bằng cách ghép nhiều con tàu, và người xem không nhận ra vì mỗi beat chỉ
xuất hiện vài giây, ở bối cảnh khác nhau.

Đây là một lời giải thứ TƯ cho bài toán identity, khác cả ba đã ghi ở §18.26:

```
① chữ (visual_identity)        — §18.15 chứng minh KHÔNG đủ
② ảnh neo (Flux/Kontext)       — Reserved
③ khung cố định + nền bất biến — §18.26, đã kiểm chứng
④ CẮT NHANH + ĐỔI BỐI CẢNH     — người xem không có thời gian đối chiếu
```

④ chính là thứ arc `vessel` hiện tại đang vô tình làm (6 scene, 5 bối cảnh). Nó
KHÔNG phải lỗi — nó là một chiến lược hợp lệ, chỉ là chưa ai gọi tên. Ngược lại
với `restoration` (khung cố định, mời người xem đối chiếu).

**Chọn chiến lược nào là quyết định biên tập, không phải kỹ thuật.** Ghi rõ ở đây
để đừng ai "sửa" `vessel` thành khung cố định chỉ vì `restoration` làm vậy.

#### 9 BEAT còn thiếu

| # | Beat | Nhìn thấy gì |
|---|---|---|
| 20 | **Ụ khô TRONG NHÀ ngập nước** | tàu nổi trong nhà xưởng có nước, **một tàu kéo đi VÀO TRONG hall** cùng nó |
| 21 | **Cửa xưởng / mái mở** | cửa trượt khổng lồ hé ra; một frame là **mái nhà xưởng mở lộ trời xanh** ngay trên mũi tàu |
| 22 | **Xe rơ-moóc tự hành (SPMT)** | dàn bánh trắng đỏ nhiều trục dưới thân tàu; cận cảnh hàng lốp |
| 23 | **Cẩu NHẤC tàu bằng dây cáp** | catamaran treo lơ lửng giữa hai cần cẩu, hạ xuống sà lan |
| 24 | **Mũi quả lê (bulbous bow)** | góc thấp sát nước, khối cầu dưới mũi |
| 25 | **Số IMO in trên thân** | `IMO 1012957` stencil trên vỏ trắng — chi tiết "hồ sơ", rất tư liệu |
| 26 | **Chữ tên tàu** | chữ crôm `BLUE — GEORGE TOWN` cận cảnh, ngược sáng |
| 27 | **Nội thất HOÀN THIỆN** | cầu thang gỗ + inox trong khoang đã xong |
| 28 | **Lau chùi lần cuối** | thuyền viên đánh bóng tay vịn inox bên mạn |

Cộng thêm hai beat phụ đáng chú ý: **khối thân dựng ĐỨNG ngoài trời lúc hoàng
hôn** (một mảnh vỏ cong khổng lồ đứng thẳng, ngược nắng), và **nhà xưởng TRỐNG
với móc cẩu treo** (tối, ánh vàng qua ô cửa kính, một cái móc duy nhất) — nốt lặng
mở đầu rất mạnh.

#### 5 THỦ PHÁP còn thiếu

**⑦ Người đứng trước MẶT PHẢN CHIẾU.** Thợ mặc áo đỏ/cam đứng trước thân tàu đen
bóng, **bóng họ hiện trên vỏ**. Vừa cho tỉ lệ, vừa chứng minh mặt sơn — hai việc
trong một khung. Mạnh hơn hẳn thủ pháp ② (chỉ có người làm thước).

**⑧ Khoảng mở dẫn ra ngoài.** Cửa xưởng hé, mái trượt ra, khe hở giữa hai vách ụ
— luôn có một **vệt sáng/trời** ở cuối khung tối. Đây là ngữ pháp "sắp ra ngoài",
dùng ngay trước beat hạ thuỷ.

**⑨ Top-down BAN ĐÊM.** Boong vàng rực nổi trên nền đen tuyệt đối. Khác hẳn
top-down ban ngày (③) — ban ngày cho thấy **hình dáng**, ban đêm cho thấy **con
tàu như một vật thể phát sáng**. Hai shot khác nhau, đừng gộp.

**⑩ Giờ xanh (blue hour).** Trời xanh thẫm + nhà xưởng vàng. Nằm giữa "ban ngày
lạnh" và "đêm vàng" trong vòng cung ánh sáng — mắt xích tôi bỏ sót.

**⑪ Chi tiết "hồ sơ".** Số IMO, chữ tên tàu, nhãn mác. Chúng nói *"đây là một con
tàu CÓ THẬT, có giấy tờ"* — thứ mà cảnh đẹp không nói được. Rất hợp cho beat gần
cuối.

#### VÒNG CUNG ÁNH SÁNG — bản sửa

```
thiết kế / mộc        ấm, dịu                        NEUTRAL·SOFT
hàn                   xanh trắng CHÓI trong đen      COOL·HARSH
nhà xưởng             đèn cao, lạnh                  COOL·NEUTRAL
sơn xong              phản chiếu, tương phản cao     NEUTRAL·HARSH
GIỜ XANH  ⭐ MỚI      trời xanh thẫm + đèn vàng      COOL·SOFT
hạ thuỷ đêm           vàng cam                       GOLDEN·SOFT
chạy thử              ngày tự nhiên, biển động       COOL·NEUTRAL
thành phẩm            hoàng hôn                      GOLDEN·SOFT
```

#### VIỆC CẦN LÀM — cập nhật

Ngoài 5 mục đã ghi ở lượt trước, thêm:

6. **Đặt tên cho chiến lược ④** trong config — `vessel` đang dùng "cắt nhanh, đổi
   bối cảnh" mà không ai biết. Nên ghi thành một khoá rõ ràng ở phase_set để
   người sau hiểu vì sao hai arc thiết kế ngược nhau.
7. **Beat 21 (cửa/mái mở)** đáng thêm nhất trong 9 beat mới: nó là *chuyển tiếp*
   giữa "trong xưởng" và "ra ngoài" mà arc hiện tại đang nhảy cóc.
8. **Beat 25-26 (số IMO, tên tàu)** — rẻ và mạnh, nhưng ⚠️ va thẳng vào §18.17
   (đã chốt: KHÔNG để tên riêng vào prompt dương, vì Veo sơn tên lên thân tàu
   sai chữ). Muốn dùng thì phải giải quyết mâu thuẫn đó trước, đừng thêm bừa.

### 18.28 Tư liệu GIỚI THIỆU du thuyền hoàn thiện — thể loại thứ hai (2026-07-31)

**Nguồn:** frame từ video giới thiệu một du thuyền đã bàn giao (Bilgin 263 ft, cờ
Thổ Nhĩ Kỳ, Istanbul). Người dùng cung cấp. **Quay thật, không phải AI.**

Khác HẲN §18.27 về thể loại — và khác biệt đó quan trọng hơn nội dung.

#### Hai thể loại, hai ngữ pháp ngược nhau

| | §18.27 Đóng tàu | §18.28 Giới thiệu |
|---|---|---|
| Chủ thể | quá trình | thành phẩm |
| **Con người** | **LUÔN có** — làm thước tỉ lệ | **KHÔNG MỘT AI** trong toàn bộ frame |
| Ánh sáng | lạnh → vàng (vòng cung thời gian) | **ấm suốt** — không có vòng cung |
| Bối cảnh | 5-6 nơi khác nhau | tàu + biển + bến, hết |
| Cảm giác | lao động, quy mô, thời gian | sở hữu, tĩnh lặng, sang trọng |
| Nhịp | có tiến triển | không tiến triển, chỉ **liệt kê** |

**Việc vắng bóng người là CÓ CHỦ ĐÍCH.** Thể loại giới thiệu cố tình xoá người
để người xem tự đặt mình vào đó. Ngược hẳn thủ pháp ① của §18.27 (luôn cài một
người làm thước). Dùng nhầm là hỏng cả hai: có người trong shot giới thiệu thì
thành ảnh môi giới; không người trong shot đóng tàu thì mất cảm giác quy mô.

#### ⚠️ KHOẢNG TRỐNG LỚN: phần lớn bài báo nói về THÀNH PHẨM, không phải quá trình

Arc `vessel` hiện có 6 pha, trong đó **4 pha là quá trình đóng** và chỉ **2 pha là
thành phẩm** (`experience_exterior`, `experience_onboard`).

Nhưng bài báo thật thì ngược lại. Bài Matilde 7 — bài đang chạy — là **thông cáo
bàn giao**: nó nói về kích thước, vật liệu, số mét vuông boong, tốc độ, độ ồn
cabin. **Không một câu nào về quá trình đóng.** Bài "The Sixth Sense" cũng vậy.

Nghĩa là Creation Arc đang **bịa ra 4 pha mà bài báo không hề nhắc**, rồi nén toàn
bộ nội dung CÓ THẬT vào 2 pha cuối. §18.16 cho phép ngoại lệ đó có chủ đích —
nhưng tư liệu này cho thấy có một lựa chọn khác chưa ai cân nhắc:

```
`vessel`     (đang có)  → kể chuyện ĐÓNG:      4 pha bịa + 2 pha thật
`showcase`   (ĐỀ XUẤT)  → kể chuyện THÀNH PHẨM: bám sát dữ liệu bài báo
```

**Chưa quyết định gì.** Ghi ra vì đây là câu hỏi thiết kế thật, không phải chi
tiết: bài giàu thông số như Matilde 7 (33,5m, thép-nhôm, mũi thẳng, 150m² boong,
4.300 hải lý, 39,2 dB) có thể hợp `showcase` hơn hẳn — mỗi thông số là một shot.

#### BEAT của thể loại giới thiệu

| # | Beat | Nhìn thấy gì |
|---|---|---|
| 1 | Chân dung ngang mạn | tàu chạy, chụp ngang, **kèm số đo overlay "263 FT"** |
| 2 | Top-down trên biển sâu | thân trắng thon trên nước xanh thẫm, vệt sóng hai bên |
| 3 | Chạy ba-phần-tư | góc chéo từ trên, thấy cả mạn lẫn boong |
| 4 | Salon chính | **đối xứng tuyệt đối**, hành lang giữa, đèn hắt trần, tranh hai bên |
| 5 | Cabin chủ | giường trung tâm, đầu giường xanh navy + chỉ vàng, ghế nằm bên cửa sổ |
| 6 | Boong sau nhìn tới | **đối xứng**, hồ bơi tiền cảnh, bạt che, cờ giữa khung |
| 7 | Hoàng hôn tại bến | hồ bơi sáng xanh, nội thất hắt vàng, đèn thành phố sau lưng |
| 8 | Chi tiết boong lúc chạng vạng | đèn ấm dưới mái che, nhìn từ trên chéo xuống |

#### THỦ PHÁP mới — 3 cái chưa có trong §18.27

**⑫ Số đo chồng lên hình.** Chữ `263 FT` với hai đầu mũi tên chạy dọc thân tàu.

Đây là **lớp DỰNG, không phải lớp render** — Veo không vẽ được chữ đúng (§18.17
đã chốt: tên tàu bị sơn sai lên thân). Nếu muốn có, phải làm ở khâu ghép video
bên Python, chồng text lên clip đã render. Ghi vào đây để đừng ai đưa số đo vào
prompt rồi thất vọng.

Nhưng ý tưởng thì rất hợp dự án: Truth Layer **đã trích được** `length_metres`,
`range_nautical_miles`, `top_speed_knots`… Những con số đó hiện **không đi đâu
cả**. Chồng chúng lên video là cách dùng dữ liệu đã verify mà không cần model vẽ.

**⑬ Đối xứng NỘI THẤT.** Khác đối xứng mũi tàu (§18.27 thủ pháp ②): đây là nhìn
dọc một không gian kín — salon, boong sau — với trục giữa rõ rệt và hai bên cân
nhau. Rất hợp `composition: SYMMETRY`, và nội thất là chỗ **duy nhất** identity
không kiểm chứng được nên thả camera tự do được (§18.16 đã ghi).

**⑭ Ánh sáng HẮT RA từ trong.** Lúc chạng vạng, nội thất sáng vàng **hắt ra
ngoài** qua cửa kính, hồ bơi sáng teal từ dưới nước. Con tàu trở thành **nguồn
sáng**, không phải vật được chiếu sáng. Đây là shot mạnh nhất của cả thể loại và
arc hiện tại **không có gì tương đương**.

#### VIỆC CẦN LÀM

9. **Quyết định có làm `showcase` phase_set không.** Đây là câu hỏi cho người
   dùng, không phải việc kỹ thuật: phần lớn bài báo yacht là thông cáo bàn giao,
   mà arc hiện tại lại kể chuyện đóng tàu. Cả hai đều hợp lệ — nhưng nên biết
   mình đang chọn cái nào và vì sao.
10. **Lớp chồng số đo (⑫)** — hạ tầng đã có (Truth trích được số), thiếu khâu
    dựng. Rẻ, không cần model vẽ chữ, và dùng đúng dữ liệu đã verify.
11. **Thêm shot "ánh sáng hắt ra" (⑭)** vào `experience_exterior` hoặc thành pha
    riêng — arc hiện tại kết ở boong ban ngày, thiếu nốt trầm cuối.

---

### 18.29 FilmOS là KNOWLEDGE OS, không phải Architecture (2026-08-03, quyết định người dùng)

Mục này chốt lại một ngày làm việc gồm **13 lượt render ảnh thật** và một cuộc
thiết kế dài về việc chuyển tri thức thị giác từ `config/video.php` (sửa tay)
sang cơ sở dữ liệu (truy vấn được). Ghi lại **trước** khi viết code, theo luật
đầu tài liệu.

Bối cảnh quan trọng: dự án này **đã từng xây FilmOS** — 16 tầng code trên nhánh
`feature/video-AI` — và **xoá nó ngày 2026-07-17**. `Rule 0` ra đời từ lần đó.
Không ai ghi lại lý do bỏ, nên hôm nay phải bàn lại từ đầu. Mục này tồn tại để
lần sau không phải bàn lại lần nữa.

#### A. Bằng chứng: 13 lượt render, cái gì sửa được ảnh

| Lỗi quan sát được | Sửa bằng | Đã đưa vào |
|---|---|---|
| Ảnh trông "như hoạt hình" | **BỎ** `cinematic, golden-hour, glossy` | ⚠️ xem §D |
| Bả + sơn lót + gỉ trong cùng khung (lệch giai đoạn cả năm) | vòng đời vật liệu một chiều | Materials `ages_into` |
| "engineers in dark jackets" | PPE thật: mũ trắng + áo phản quang | Human Library |
| Người to hơn tàu, mất bao quát | **đổi góc máy** (LOW → ELEVATED) | `angle_impossible` |
| Bumper "đã tháo" trong khi khung hình đang lắp | Frame–Text Coherence | Core Invariant #5, đã có |
| Ảnh 16:9 trong khi pipeline 9:16 | tham số aspect | đã có |
| 7 clip `restoration` ra **7 gara khác nhau** | *(chưa có)* | **Sequence Grammar — thiếu thật** |

**Bài học prompt rút ra (5):**

1. **Liệt kê cái VẮNG MẶT trong prompt dương** mạnh hơn `negative_prompt`.
   `"window openings cut but EMPTY, no glass"` thắng `"no windows"`.
2. **Số đo thắng tính từ** khi cần tỉ lệ. `"a 2 m worker at the foot of a 9 m
   hull"` thắng `"a huge hull"`.
3. **Đổi góc máy thắng đổi câu chữ.** Ba lượt sửa từ ngữ không giải quyết được
   "người quá to"; một lần đổi từ góc thấp sang góc cao thì xong.
4. **Từ vựng vật chất thắng từ vựng điện ảnh.** `bare steel, mill-scale, weld
   seams, flat overhead light` cho ảnh thật hơn `cinematic, moody, golden hour`.
5. **Thép trần thắng sơn lót trắng** — không mơ hồ về giai đoạn. Sơn lót trắng
   dễ bị model đọc thành "đã sơn xong".

Bài học 4 là dữ liệu đắt nhất trong ngày và nó **đi ngược trực giác**: thêm từ
vựng điện ảnh làm ảnh **xấu đi**; thêm từ vựng vật chất làm ảnh **tốt lên**.

#### B. Bốn tầng tri thức (sửa cách phân lớp cũ)

Cách nói cũ *"Truth lo phần chữ, Ontology lo phần hình"* **SAI**, vì
`human_for_scale` / `symmetry_reveal` không phải hình — chúng là **quy tắc sắp
xếp**. Phân theo bản chất tri thức, không theo đầu ra:

```
Identity         cái này LÀ gì            33.5 m · dark navy · thép-nhôm
Visual Language  NHÌN thế nào             human_for_scale · symmetry · mirror
Editorial        KỂ thế nào               chọn stage, chọn device, nhịp
Prompt           VIẾT thế nào             cú pháp, thứ tự lớp
```

Chỉ **một** tầng là mới:

| Tầng | Hiện trạng |
|---|---|
| Identity | ✅ Truth Layer — Extractor + Gatekeeper + VerifiedWorldGraph |
| **Visual Language** | ❌ **KHÔNG CÓ** — đây là thứ phải xây |
| Editorial | ✅ `EditorialInterpreter` + `ClaudeDirector` |
| Prompt | ✅ `MotionComposer` (Python) |

Đây là câu trả lời cho Rule 0: không phải kiến trúc mới 4 tầng, mà là **lấp một
lỗ hổng ở giữa** — đúng lỗ mà hôm nay đã phải lấp bằng tay 7 lần.

#### C. Truth chỉ lấy KÍCH THƯỚC + MÀU (quyết định người dùng)

Bài báo là **nguồn ý tưởng**, không phải kịch bản. Nội dung video viết mới hoàn
toàn. Truth Layer vẫn nằm trên đường video, nhưng **thu hẹp phạm vi**:

```
LẤY:     kích thước, màu, vật liệu, hình dáng — thứ ảnh hưởng đến HÌNH
BỎ:      owner, builder, brand, seller, shipyard, designer… (8 loại
         semantic_claims hiện tại) — không ảnh hưởng đến hình, và §18.17
         đã chốt tên riêng không được đưa vào prompt
```

Hệ quả đo được: prompt Extractor đang ~4.400 ký tự và chiếm **66% chi phí LLM**
(§18.24). Cắt xuống phần đo được + màu thì rẻ hơn rõ rệt **và chính xác hơn** —
ít thứ để bịa.

Và nó chặn đúng lỗi `74-metre` viết cứng trong config: `length_metres: 33.5` từ
Truth ghi đè.

#### D. `category_contexts.art_style` đang lưu công thức ĐÃ BỊ BÁC BỎ

Giá trị đang lưu cho Superyacht:

```
"cinematic, golden-hour, glossy"
```

Đây **chính là** công thức sinh ra những ảnh bị gọi là "như hoạt hình". Phải sửa
**trước** khi nối `video_framework_id` vào pipeline, nếu không sẽ tự động hoá
đúng cái lỗi vừa mất $0.42 để phát hiện.

#### E. Lưu trữ: B+ normalized (quyết định người dùng)

Không lưu blob JSON. Chuẩn hoá thành thực thể để truy vấn được.

```
CHUẨN HOÁ (nhiều stage dùng chung — trả được tiền thuê ngay):
    material_library · object_library · human_library · machine_library
    environment_library · motion_library · device_library
    composition_library · camera_behavior_library · visual_language_library
    + bảng pivot stage ↔ thư viện

GIỮ TRÊN stage (thuộc tính riêng, không dùng chung):
    purpose · visual_goal · geometry · lighting · atmosphere
    invariants · impossible_combinations · qa_reject_if · shot_variants
```

**Chưa tách** `StageConstraints` / `StageEvidence` / `StageQA` thành bảng riêng:
chúng là thuộc tính của **một** stage, không giải quyết trùng lặp nào đang tồn
tại. Tách sau, khi có truy vấn thật cần. (Rule 0.)

`ages_into` trong Materials là trường quan trọng nhất của cả lược đồ: nó mã hoá
**mũi tên thời gian một lần**, và máy tự suy ra `bare_steel` + `faired_paint`
không thể cùng khung — thay vì phải viết `impossible_combinations` cho từng cặp
ở từng stage.

#### F. FilmOS = 12 thư viện DỮ LIỆU, không phải 12 tầng CODE

Đây là chốt quan trọng nhất của mục này, và là chỗ khác lần 2026-07-17.

§1 đã có sẵn luật: *domain knowledge tồn tại chỉ như DATA, không bao giờ như
nhánh code*. FilmOS v1 chết vì nó là 16 nhóm class. Kiến thức trong đó không
sai — cách chứa nó sai.

| Hạng mục FilmOS | Vào đâu | Code mới |
|---|---|---|
| Lifecycle 30 stage | bảng DB | **0** |
| Materials · Objects · Humans · Machines · Environments | bảng DB | **0** |
| Motion taxonomy (`close_gap`, `descend`, `rotate`…) | bảng DB | **0** |
| Device Library | bảng DB | **0** |
| Composition Library | bảng DB | **0** |
| Cinematography **ngữ nghĩa** (`very wide`, `monumental`, `layered`) | bảng DB | **0** |
| Camera Behavior (`observe`, `wait`, `reveal`, `inspect`…) | bảng DB | **0** |
| Visual Language (`craftsmanship` → framing/scale/light) | bảng ánh xạ | **0** |
| **Sequence Grammar** | `TimelinePlanner` — **đã có** | ~1 rule engine |
| **Visual Rhythm** | `TimelinePlanner` — **đã có** | ~1 rule engine |
| QA repetition score | `RenderPlanQualityReport` — **đã có** | vài luật |

**Không có tầng kiến trúc nào mới.** Mọi thứ hoặc là hàng trong bảng, hoặc là
luật thêm vào lớp đã tồn tại. Đây là bằng chứng cụ thể phân biệt lần này với
lần trước, không phải lời hứa.

#### G. Cinematography phải lưu ở mức NGỮ NGHĨA, không phải vật lý

```
KHÔNG lưu:   Arri Alexa · 24mm · f/2.8 · sensor S35 · rolling shutter · DOF 1.2m
LƯU:         field_of_view   very wide | wide | medium | tight | macro
             perspective     top_down | high | eye_level | low | ground | inside
             subject_scale   tiny | medium | hero | monumental
             depth           flat | layered | deep | compressed
```

Lý do kỹ thuật, đo được: `tools/render_queued_shots.py` gửi cho Veo đúng **6
tham số** — `prompt`, `negative_prompt`, `duration`, `aspect_ratio`,
`resolution`, `generate_audio`. **Không tham số nào là camera.** Viết "24mm"
không cho tiêu cự 24mm; nó chỉ dịch phân phối về phía ảnh có caption chứa
"24mm". Một ontology cinematography vật lý 20 field cuối cùng cô lại thành vài
từ, và những từ đó **cạnh tranh chỗ** với `bare steel`, `weld seams` — thứ đã
chứng minh có tác dụng (§A bài học 4).

Camera Behavior thì ngược lại — nó mô tả **cái gì xảy ra trong khung**, thứ i2v
thực thi được:

```
observe   camera cố định, chủ thể chuyển động
wait      camera cố định, chủ thể ĐI VÀO khung
reveal    tiền cảnh dạt ra, chủ thể lộ dần
inspect   camera rà chậm qua chi tiết
```

`wait` chính là câu đã cho kết quả tốt nhất trong 7 lượt probe hôm nay.

#### H. Contract của Director — ĐỀ XUẤT, chưa chốt

Đề xuất ban đầu của người dùng cho Director xuất thẳng `Camera`, `Composition`,
`Lighting`. Việc đó **mâu thuẫn §18.7** (Camera là Objective, `IntentPlanner` sở
hữu) và §18.6 (đã bác "LLM cinematographer").

Nhưng không cần vi phạm, vì **Visual Language Library đã là bảng ánh xạ**. Đề
xuất thu nhỏ Contract:

```
Director xuất (Subjective — cần phán đoán):
    goal        show_craftsmanship | show_scale | show_precision | show_isolation
    device      reflection | absence_bookend | human_for_scale…
    emphasis    hands | bow | weld_seam

Rule Engine tra bảng (Objective — tất định):
    camera_behavior · field_of_view · composition · lighting · motion_speed
```

Ba lợi ích: giữ §18.7 nguyên vẹn; **nhất quán hơn** (cùng `goal` → cùng cách xử
lý ở mọi video, mọi domain); rẻ hơn (Director trả ít token).

Và nó đúng mục tiêu "đa dạng nhưng nhất quán": **đa dạng** đến từ Director chọn
`goal` khác nhau; **nhất quán** đến từ bảng ánh xạ cố định. Nếu Director tự chọn
camera thì đa dạng tăng nhưng nhất quán mất — đúng lỗi 7-clip-7-gara ở §A.

⚠️ Đây là **đề xuất**, chưa được người dùng chốt. Không viết code theo mục này
cho đến khi có xác nhận.

#### I. Cái gì HOÃN — và điều kiện mở lại

Hoãn không phải bỏ. Ghi điều kiện để lần sau không bàn lại từ đầu:

| Hoãn | Mở lại khi |
|---|---|
| Style Library (BBC / NatGeo / Apple…) | có ≥1 video hoàn chỉnh và người dùng muốn đổi phong cách |
| Film Language KB (nguyên lý trừu tượng) | Sequence Grammar + Visual Rhythm đã chạy mà video vẫn "rời" |
| Tách 6 bảng con của Stage | có truy vấn thật cần JOIN chúng riêng |
| Cinematography **vật lý** (lens/DOF/sensor) | phép đo §J cho thấy nó thắng |
| Shot Knowledge Graph | ≥50 video có `prompt_metrics` thật để xếp hạng |

#### J. PHÉP ĐO chốt lớp cinematography — $0.05

Trước khi viết 20 field cinematography cho 30 stage:

```
Ảnh A:  stage B5 + thư viện vật chất                       (materials, humans, machines)
Ảnh B:  stage B5 + thư viện vật chất + lớp cinematography   (+ FOV, depth, eye-path, scale)
        cùng seed, cùng model
```

- B rõ ràng hơn A → xây lớp cinematography, **có bằng chứng**
- B bằng hoặc tệ hơn A → tiết kiệm vài tuần và biết vì sao

Bảy lượt lặp hôm nay cho thấy phép đo **luôn thắng** phép suy luận, kể cả khi
suy luận nghe rất hợp lý.

#### K. Thứ tự thi công

```
1  §18.29 — mục này                                              $0   ✔
2  5 thư viện primitive (materials, objects, humans, machines,
   motions) + Device + Composition                               $0
3  Sequence Grammar + Visual Rhythm (rule engine trong
   TimelinePlanner) + 5 trục diversity                           $0
4  Migration + seed                                              $0
5  1 stage B5 tham chiếu thư viện                                $0
6  ► PHÉP ĐO §J: ảnh A vs B                                      $0.05
7  29 stage còn lại — có/không lớp cinematography tuỳ bước 6     $0
8  ► Render 1 video 6 scene HOÀN CHỈNH                          ~$1.10
9  Xem video. Cái gì hỏng ở đó mới là thư viện tiếp theo cần xây.
```

Bước 8 là chỗ duy nhất trả lời được câu "đã production-grade chưa". Mọi thứ
trước đó là giả thuyết.

#### L. Rủi ro phải giữ

`config/video.php` **đang chạy thật** — 2 phase_set, bấm 🎬 ra 6 scene. Hệ
ontology chạy **song song**, và chỉ chuyển `vessel` sang khi nó sinh prompt tốt
hơn hoặc bằng bản config hiện tại — **đo bằng render thật, không đo bằng cảm
giác**.

#### M. Ghi để lần sau không lặp

Bộ nhớ dự án có **12 bản "Freeze"** cho FilmOS v1. Mười hai lần đóng băng kiến
trúc. **Không lần nào có video hoàn chỉnh ở giữa.**

Mục 18.29 này là bản thứ 13. Khác biệt duy nhất được phép có: bước 8.
