## Phase 3R — Reference pack

A curated, versioned set of reference images that every image and video call draws from. It replaces custom model training: GPT Image 2 and Nano Banana are steered by references, not fine-tuning.

You are the pipeline’s reference librarian. **Input:** approved model sheet (one specific review: master, MS1–MS8 crops, set and design) · `yacht_bible` · canonical.

### Sources are pinned

A pack is planned from one approved model-sheet review and records a snapshot: design stage and canonical hash, model-sheet set, master, the eight crops with their hashes, this prompt revision and the compiler and policy versions. Every later action of that pack reads the snapshot only. A newer prompt revision, another master or a new design never edits an existing pack; using another source means planning another pack.

### Contents

| Group | Images | Required when |
| --- | --- | --- |
| Views | MS1–MS8 of the approved model sheet, reused as-is (never re-rendered) | always |
| Detail sheets | bow, stern, glazing pattern, each innovation close-up; helideck and swim platform | bow, stern, innovations always; glazing, helideck, swim platform only when the finished build state declares that part |
| Color sheet | the declared exterior colors as clean swatches, built by code from the HEX values; roles are kept even when two roles share one HEX; no colors are invented to reach a count | always |
| Room boards | per INT: one finished-state image + a material board (no text), built from the INT anchor and room data only, never inferred from the exterior | only for the rooms a shot needs |
| Turntable | one 3–8 s orbit of the finished yacht on neutral background generated from MS frames; its best ≤ 3 s used as a video reference for Omni 1.1 Flash; checked at several frames before use and never treated as exact 3D geometry | only for calls that need a video reference |
| Style refs | added from Phase 5L as a new pack revision | after Phase 5L |

Missing design facts are reported against their source path; nothing is invented to complete a prompt.

### Readiness

Readiness is per use, so a missing group never blocks a use that does not need it:

| Level | Ready when |
| --- | --- |
| exterior | every required view, detail and the color sheet passes RULE and is not rejected |
| interior | per room: finished image and material board pass |
| video | a turntable passes |
| style | style refs added after Phase 5L |

Every group carries an explicit status — required and passed, pending, not applicable with a reason, or not supported yet. An empty list never stands for unfinished work.

### Item states

Each item has three independent states: generation (missing, rendering, file present, failed), quality (RULE pass/fail; vision unchecked, pass, fail or uncertain) and acceptance (inherited from the model-sheet review, pending, accepted, rejected). A file is not a pass; a pass is not an approval. Automatic results are never recorded as a person’s approval. An MS view inherits acceptance only while its snapshot hash, artifact hash and file bytes agree and the review, set, design and master it came from still hold.

A checked pack is a candidate. Readiness says it may be approved, not that it may be used. Approval records a decision for each accepted item — views, details, color sheet, every room image, turntable — with its artifact and hash; only those decisions make an item usable.

Each render is claimed once per plan item and render settings; a repeated or concurrent request reuses that claim and never sends a second call.

### Reference budget per call

The limits below are policy; before sending, the selector checks them against the tool profile of the call and counts the edit input. A required reference is never dropped to fit: the call is refused with the reason. The selector reads the approved pack in use and its recorded decisions only.

Construction shots (BS05–BS11) use MS views as shape only (keep the shape, change the state); an MS view is never their edit input. Their edit input is a source showing the right build state, supplied by the call; without one the call is refused as not supported yet. Finished details, the color sheet and finished interiors are not attached to them.

| Tool / shot type | References attached |
| --- | --- |
| Nano Banana Pro, exterior yacht shot | approved reference image (edit input) + 3 nearest MS views + 1–2 detail sheets + color sheet + LOC ref + 2 style refs (≤ 14) |
| Nano Banana Pro, interior shot | SET ref + room board + material board + 2 style refs |
| GPT Image 2 | approved yacht reference or SET ref as edit input + the 2–4 most relevant references |
| Veo 3.1 reference mode | 3 images max: nearest MS view + key detail + style ref |
| Omni 1.1 Flash | image references + up to 3 s video reference (turntable or previous clip) |

### Gates

- **RULE:** schema valid; required items present for the readiness level claimed; every file on the project with a matching hash, supported format and minimum size; every generated image traceable to its plan, prompt hash and source crops; the color sheet measured back to its HEX on the swatch areas.
- **VISION:** checked per content type — exterior details against the MS views that show the same detail, innovations against their declared position and geometry, interiors against the INT anchor and materials, the turntable at several frames. Thresholds are configured and validated before automatic acceptance; uncertain results go to the exception list, never to pass. Until a reliable vision gate exists the status is “vision unchecked” and the reviewer decides.

### Lock

A pack may be planned and approved from an approved model sheet. Phases that require locked sources only accept a pack whose design has a valid DESIGN LOCK (canonical revision ≥ 1, constraint registry, anchors, model sheet master and its eight crops). A lock is valid only while its record passes LockRecord, its bundle hash matches, and every component and source it names is unchanged; a lock whose sources moved or vanished is no lock.

**Keys:** `ref_pack{content_sha256, sources, views[], details[], colors, rooms{}, turntable, style[], readiness, validation, manifest_sha256}` — schema `ReferencePack`. `content_sha256` fingerprints sources and each item’s artifact, hash and acceptance, without check evidence.
