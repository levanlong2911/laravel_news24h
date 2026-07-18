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

```jsonc
{
  "plan_version": "1.0",
  "plan_id": "uuid",
  "article_id": "uuid",
  "generated_at": "2026-07-17T10:00:00Z",

  "story": { "title": "Moonrise sold for €325M", "language": "en", "target_seconds": 60 },

  "world": {
    "entities": [
      {
        "id": "moonrise2025",
        "type": "vehicle",                 // ontology chung — KHÔNG phải "superyacht"
        "attributes": {                    // vật lý, render được → xuống ProviderIR
          "length_m": 101, "hull_color": "grey", "bow": "vertical",
          "satellite": "integrated", "domes": false, "swim_platform": "long"
        },
        "identity": {
          "name": "Moonrise",
          "visual_referent": true,         // semantic: tên ghim một hình dạng cụ thể
          "semantic": {                    // KHÔNG bao giờ xuống ProviderIR
            "builder": "Feadship", "owner": "Jan Koum", "price_eur": 325000000
          }
        }
      }
    ],
    "relations": [ { "id": "r1", "from": "moonrise2025", "to": "moonrise2020", "type": "successor_of" } ],
    "events": [
      { "id": "e1", "type": "construction", "entity_id": "moonrise2025" },
      { "id": "e2", "type": "sale",         "entity_id": "moonrise2025" }
    ]
  },

  "facts": [
    { "id": "f1", "claim": "measures 101 metres", "entity_id": "moonrise2025",
      "visual_hint": "vertical bow, grey hull, long swim platform" }
  ],

  // Act = node|edge của World Graph. Đúng MỘT trong 3 ref.
  "acts": [
    { "id": "a1", "ordinal": 1, "source": "ENTITY",   "entity_ref": "moonrise2025" },
    { "id": "a2", "ordinal": 2, "source": "EVENT",    "event_ref": "e1" },
    { "id": "a3", "ordinal": 5, "source": "RELATION", "relation_ref": "r1" }
  ],

  "scenes": [
    {
      "id": "s1", "ordinal": 1, "act_id": "a1",
      "purpose": "REVEAL",
      "subjects": ["moonrise2025"],
      "emotion": "MAJESTIC",
      "composition": "CENTERED",
      "motion_intent": "LOW",              // NONE|LOW|HIGH — thay content_type

      "camera":    { "framing": "WIDE", "movement": "ORBIT", "speed": "SLOW", "target": "moonrise2025" },

      // Editorial taste — LUÔN có mặt, không phụ thuộc chủ đề (§13)
      "aesthetic": { "emotion": "MAJESTIC", "composition": "CENTERED", "light_intensity": "SOFT", "light_grade": "GOLDEN" },

      // World facts từ Truth — TẤT CẢ optional, vắng khi Truth im lặng (§13).
      // Provider tự điền bằng world-knowledge của nó khi vắng.
      "world":     { "medium": "WATER", "location": "open ocean", "time_of_day": "GOLDEN_HOUR", "weather": "CLEAR", "light_source": "NATURAL" },

      "fact_refs": ["f1"],
      "asset_refs": ["as_hull"]
    }
  ],

  "timeline": [ { "scene_id": "s1", "start_sec": 0, "end_sec": 5 } ],
  "assets":   [ { "id": "as_hull", "kind": "structure", "entity_id": "moonrise2025", "required": true } ],

  "continuity": {
    "invariants": [
      { "entity_id": "moonrise2025", "attribute": "hull_color", "value": "grey",     "scope": "always" },
      { "entity_id": "moonrise2025", "attribute": "bow",        "value": "vertical", "scope": "always" }
    ],
    "prohibitions": [
      { "entity_id": "moonrise2025", "attribute": "domes", "value": true, "reason": "2025 refit uses integrated receivers" }
    ]
  }
}
```

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

```
app/Video/
├── Contracts/       Planner interfaces
│
│   ── TRUTH LAYER — xem §11 ──
├── Article/         ArticleNormalizer
├── Evidence/        EvidenceIndex, Evidence, EvidenceLocator, ProvenanceLevel
├── Extraction/      LlmExtractor (Hypothesis Generator), CandidateWorldGraph
├── Gatekeeper/      EvidenceGatekeeper, GatekeeperReport   ← TRÁI TIM của hệ thống
├── World/           VerifiedWorldGraph, Entity, Relation, Event, Identity
│
│   ── PLANNING LAYER — trạm đầu, xem §12 ──
├── Editorial/       EditorialPolicy (data), EditorialInterpreter (generic code)
├── Story/           StoryPlanner, StoryGraph, Act
├── Scene/           ScenePlanner, SceneGraph, Scene
├── Intent/          IntentPlanner, IntentScene, CameraIntent, MotionIntent  ← camera+motion (P3)
├── Timeline/        TimelinePlanner, TimedScene, TimeRange                  ← scheduler cơ học (P4)
├── Editorial/       EditorialPolicy (data), EditorialInterpreter (generic)  ← taste + fill-missing (P5)
├── Knowledge/       CharacterLibrary, VehicleLibrary, StyleLibrary   ← data, không phải code (khi cần)
├── RenderPlan/      RenderPlanAssembler, Serializer                          ← projection + validate (P5)
└── Pipeline/        VideoPlanningPipeline
# BỎ: Asset/ (projection, không phải planner) · Continuity/, Rules/ hoà vào Editorial
```

### Python — `media_runtime/`

```
media_runtime/
├── compiler/          ★ MỚI — phần đang thiếu
│   ├── video_ir.py       VideoIR (trung lập)
│   ├── provider_ir.py    ProviderIR (đã tối ưu)
│   ├── builder.py        RenderPlan.json → VideoIR
│   ├── pipeline.py       CompilerPipeline
│   ├── prompt_compiler.py   ProviderIR → prompt (mỏng)
│   └── passes/
│       ├── base.py subject.py camera.py lighting.py physics.py
│       ├── material.py weather.py environment.py motion.py
│       ├── fx.py audio.py continuity.py provider.py
├── providers/         ✔ GIỮ — 5 luật FAL/Kling nằm ở đây
├── render/            ✔ GIỮ — compositor, ffmpeg_builder
├── assets/            ✔ GIỮ — cache, downloader, uploader
├── core/              ✔ GIỮ — job_manager, scheduler, metrics
└── api/               ↻ SỬA — fetch RenderPlan thay job payload cũ
```

`providers/prompt_rewriter.py` → **xoá**. Trách nhiệm chuyển vào `compiler/passes/provider.py`. Đây là chỗ prompt logic đang rò rỉ sai tầng.

---

## 8. Architecture Tests — CI fail, không phải code review

Không phải convention. Không phải review. **Test thật, CI đỏ.**

| Test | Kiểm |
|---|---|
| `LaravelIsPromptBlindTest` | `app/Video/` không chứa `prompt`, `negative_prompt`, `cinematic`, `ultra realistic`, `8k`, `masterpiece`, `photorealistic`, `mm lens` |
| `NoDomainBranchingTest` | `app/Video/` không chứa `yacht`, `superyacht`, `switch ($topic)`, `$topic ===` |
| `NoRenderTechniqueTest` | `app/Video/` không chứa `ken burns`, `kling`, `flux`, `veo`, `runway`, `content_type` |
| `ProviderIsSemanticBlindTest` | ProviderIR không chứa giá trị nào từ `identity.semantic` |
| `EvidenceNeverCrossesBoundaryTest` | RenderPlan schema reject mọi trường `evidence`/`quote`/`span`/`offset`/`provenance`/`confidence` |
| `GatekeeperIsDeterministicTest` | `app/Video/Gatekeeper/` không import/gọi bất kỳ AI client nào |
| `NoDerivedWordTest` | Từ `derived` bị cấm trong code — dùng `NORMALIZED_VALUE`. Xem §11 |
| `EditorialHasNoDomainLiteralsTest` | `app/Video/Editorial/` không chứa `Feadship`, `Ferrari`, `Tesla`, `Lion`, `Moonrise`… — domain chỉ tồn tại trong DỮ LIỆU. Xem §12 |
| `test_topic_swap_tesla` | Tesla Factory (`building`) → chỉ sửa dữ liệu Laravel, Python không đổi |
| `test_topic_swap_lion` | Lion (`living_object`) → như trên |
| `test_provider_swap_veo` | Thêm Veo → 1 ProviderPass + 1 adapter; VideoIR và Laravel không đổi |

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
| **5** | Laravel: **Editorial Interpreter** (§12, taste + fill-missing) → **RenderPlanAssembler** → emit | Editorial fill chỗ Truth im lặng (KHÔNG overwrite); Assembler ráp Truth+Story+Scene+Intent+Timeline → RenderPlan pass schema |
| **6** | Python: VideoIR Builder | fixture → VideoIR, không mất dữ liệu |
| **7** | Python: Pass pipeline | mỗi pass test riêng; ContinuityPass gỡ được `domes` |
| **8** | Python: ProviderIR + Prompt Compiler + allowlist | prompt Moonrise sinh 100% từ IR; không rò `Jan Koum`/`Feadship` |
| **9** | Render end-to-end (⚠ tốn phí — cần approval) | video Moonrise |

**Gate:** mỗi phase test xanh trước khi sang phase sau. Phase 0 cứng nhất — sai contract thì hai bên sai theo.

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
