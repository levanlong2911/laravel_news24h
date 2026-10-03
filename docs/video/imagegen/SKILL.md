# Image Generation Skill — GPT Image 2.5

> **UPDATED 2026-10-02.** Based on the `SKILL.md` of the `imagegen` skill, brought up to date with the official
> OpenAI documentation read on 2026-10-02 from the pages' `.md` sources:
> [Image generation guide](https://developers.openai.com/api/docs/guides/image-generation) and
> [Image prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?site_locale=en).
> This file is NO LONGER a verbatim copy of the skill. Skill text that is still correct is kept; model, parameter,
> API, limitation and cost information is replaced by the 2026-10 documentation. Additions by this system are
> marked "Note for this system".

> Generate or edit raster images when the task benefits from AI-created bitmap
> visuals such as photos, illustrations, textures, sprites, mockups, or
> transparent-background cutouts. Use when Codex should create a brand-new image,
> transform an existing image, or derive visual variants from references, and the
> output should be a bitmap asset rather than repo-native code or vector. Do not
> use when the task is better handled by editing existing SVG/vector/code-native
> assets, extending an established icon or logo system, or building the visual
> directly in HTML/CSS/canvas.

Generates or edits images for the current project (for example website assets, game assets, UI mockups, product mockups, wireframes, logo design, photorealistic images, or infographics).

## Contents
- [Top-level modes and rules](#top-level-modes-and-rules)
- [When to use](#when-to-use)
- [When not to use](#when-not-to-use)
- [Choose a model](#choose-a-model)
- [Decision tree](#decision-tree)
- [Workflow](#workflow)
- [Transparent image requests](#transparent-image-requests)
- [Prompt augmentation](#prompt-augmentation)
- [Use-case taxonomy (exact slugs)](#use-case-taxonomy-exact-slugs)
- [Shared prompt schema](#shared-prompt-schema)
- [Examples](#examples)
- [Prompting best practices](#prompting-best-practices)
- [Guidance by asset type](#guidance-by-asset-type)
- [GPT Image model guidance](#gpt-image-model-guidance)
- [API reference](#api-reference)
- [Limitations](#limitations)
- [Cost and latency](#cost-and-latency)
- [Fallback CLI mode only](#fallback-cli-mode-only)
- [Reference map](#reference-map)

## Top-level modes and rules

This skill has exactly two top-level modes:

- **Default built-in tool mode (preferred):** built-in `image_gen` tool for image generation, editing, and transparent-image requests. Does not require `OPENAI_API_KEY`.
- **Fallback CLI mode:** `scripts/image_gen.py` CLI. Use when the user explicitly asks for or confirms the CLI/API/model path. Requires `OPENAI_API_KEY`.

Within CLI fallback, the CLI exposes three subcommands:

- `generate`
- `edit`
- `generate-batch`

Rules:

- Use the built-in `image_gen` tool by default for normal image generation and editing requests.
- Do not switch to CLI fallback for ordinary quality, size, or file-path control.
- For transparent images, ask for a transparent background (`background="transparent"` with PNG or WebP output) and preserve the generated alpha.
- Never silently switch models. On the API path, use `gpt-image-2.5-flare` or `gpt-image-2.5-sunburst` as described in [Choose a model](#choose-a-model); ask the user before using any other model unless they explicitly requested it.
- Do not move a workflow to `gpt-image-1.5` or `gpt-image-1`: both are deprecated (`gpt-image-1.5` shuts down on December 1, 2026; `gpt-image-1` on October 23, 2026). A transparent background no longer requires `gpt-image-1.5` — both GPT Image 2.5 models support it, and it is available in preview for `gpt-image-2`.
- The word `batch` by itself does not mean CLI fallback. If the user asks for many assets or says to batch-generate assets without explicitly asking for CLI/API/model controls, stay on the built-in path and issue one built-in call per requested asset or variant.
- If the built-in tool fails or is unavailable, tell the user the CLI fallback exists and that it requires `OPENAI_API_KEY`. Proceed only if the user explicitly asks for that fallback.
- If the user explicitly asks for CLI mode, use the bundled `scripts/image_gen.py` workflow. Do not create one-off SDK runners.
- Never modify `scripts/image_gen.py`. If something is missing, ask the user before doing anything else.

Built-in save-path policy:

- In built-in tool mode, Codex saves generated images under `$CODEX_HOME/*` by default.
- Do not describe or rely on OS temp as the default built-in destination.
- Do not describe or rely on a destination-path argument (if any) on the built-in `image_gen` tool. If a specific location is needed, generate first and then move or copy the selected output from `$CODEX_HOME/generated_images/...`.
- Save-path precedence in built-in mode:
  1. If the user names a destination, move or copy the selected output there.
  2. If the image is meant for the current project, move or copy the final selected image into the workspace before finishing.
  3. If the image is only for preview or brainstorming, render it inline; the underlying file can remain at the default `$CODEX_HOME/*` path.
- Never leave a project-referenced asset only at the default `$CODEX_HOME/*` path.
- Do not overwrite an existing asset unless the user explicitly asked for replacement; otherwise create a sibling versioned filename such as `hero-v2.png` or `item-icon-edited.png`.

Shared prompt guidance for both modes lives in `references/prompting.md` (including all official OpenAI examples) and `references/sample-prompts.md`.

Fallback-only docs/resources for CLI mode:

- `references/cli.md`
- `references/image-api.md`
- `references/codex-network.md`
- `scripts/image_gen.py`

Note for this system: `scripts/image_gen.py` is not part of this repository, so the script's own flags and defaults described in `references/cli.md` are unverified here. All reference files were updated for GPT Image 2.5 on 2026-10-02: `references/image-api.md` holds the full OpenAI API reference for the Images endpoints and how this repo's Laravel client maps to it; `references/cli.md` and `references/sample-prompts.md` follow it.

## When to use

- Generate a new image (concept art, product shot, cover, website hero)
- Generate a new image using one or more reference images for style, composition, or mood
- Edit an existing image (inpainting, lighting or weather transformations, background replacement, object removal, compositing, transparent background)
- Produce many assets or variants for one task

## When not to use

- Extending or matching an existing SVG/vector icon set, logo system, or illustration library inside the repo
- Creating simple shapes, diagrams, wireframes, or icons that are better produced directly in SVG, HTML/CSS, or canvas
- Making a small project-local asset edit when the source file already exists in an editable native format
- Any task where the user clearly wants deterministic code-native output instead of a generated bitmap

## Choose a model

The API generates and edits images with `gpt-image-2.5-sunburst` and `gpt-image-2.5-flare`. Choose Sunburst for workflows where editing precision matters most, and Flare for fast, high-quality everyday image generation.

- **GPT Image 2.5 Flare** (`gpt-image-2.5-flare`) is the small model, optimized for speed, with image quality comparable to GPT Image 2.
- **GPT Image 2.5 Sunburst** (`gpt-image-2.5-sunburst`) is the base model, optimized for quality, with higher image quality than GPT Image 2.
- Both models offer improvements in precise editing and subject preservation, and both support image generation, editing, and transparent backgrounds.
- Dated snapshots visible to this system's API key on 2026-09-25: `gpt-image-2.5-flare-2026-09-08`, `gpt-image-2.5-sunburst-2026-09-08` (`resources/ai/providers/openai_models_2026_09_25.json`).

For a new workflow, start with Flare when speed is the priority, or Sunburst when demanding quality requirements are the priority. Once the output meets the requirements, look for opportunities to reduce latency.

| Current workflow | Start by testing |
| --- | --- |
| An existing, validated GPT Image 2 workflow already meets the quality requirements | GPT Image 2.5 Flare. Check whether acceptable quality holds while latency drops. |
| A complex use case where GPT Image 2 does not meet the quality requirements | GPT Image 2.5 Sunburst. First establish that it delivers the quality needed. |

If Sunburst meets the requirements, test Flare with the same prompts and inputs; switch to Flare only if it also meets them and improves latency. Measure response time and quality on your own workload — results depend on prompts, reference images, output dimensions, and quality settings. The full migration procedure is in `references/prompting.md` → *Migrate an existing workflow*.

Note for this system: the identity anchor uses `gpt-image-2.5-flare` by default (`config/image_prompt.php` → `anchor_model`, env `IMAGE_ANCHOR_MODEL`). `App\Enums\ImageModel` offers `gpt-image-2`, `gpt-image-2.5-flare` and `gpt-image-2.5-sunburst`, and `ImageModel::qualities()` allows `xhigh` and `max` only for the two 2.5 models.

## Decision tree

Think about two separate questions:

1. **Intent:** is this a new image or an edit of an existing image?
2. **Execution strategy:** is this one asset or many assets/variants?

Intent:

- If the user wants to modify an existing image while preserving parts of it, treat the request as **edit**.
- If the user provides images only as references for style, composition, mood, or subject guidance, treat the request as **generate** (on the API, reference-guided generation also goes through the edits endpoint — see [API reference](#api-reference)).
- If the user provides no images, treat the request as **generate**.

Built-in edit semantics:

- Built-in edit mode is for images already visible in the conversation context, such as attached images or images generated earlier in the thread.
- If the user wants to edit a local image file with the built-in tool, first load it with built-in `view_image` tool so the image is visible in the conversation context, then proceed with the built-in edit flow.
- Do not promise arbitrary filesystem-path editing through the built-in tool.
- If a local file still needs direct file-path control, masks, or other explicit CLI-only parameters, use the explicit CLI fallback only when the user asks for it.
- For edits, preserve invariants aggressively and save non-destructively by default.

Execution strategy:

- In the built-in default path, produce many assets or variants by issuing one `image_gen` call per requested asset or variant.
- In the CLI fallback path, use the CLI `generate-batch` subcommand only when the user explicitly chose CLI mode and needs many prompts/assets.
- For many distinct assets, do not use `n` as a substitute for separate prompts. `n` is for variants of one prompt (by default the API returns a single image); distinct assets need distinct built-in calls or distinct CLI `generate-batch` jobs.

Assume the user wants a new image unless they clearly ask to change an existing one.

## Workflow

1. Decide the top-level mode: built-in by default, including transparent-output requests; fallback CLI only if explicitly requested or confirmed.
2. Decide the intent: `generate` or `edit`.
3. Decide whether the output is preview-only or meant to be consumed by the current project.
4. Decide the execution strategy: single asset vs repeated built-in calls vs CLI `generate-batch`.
5. Collect inputs up front: prompt(s), exact text (verbatim), constraints/avoid list, and any input images.
6. For every input image, label its role explicitly:
   - reference image
   - edit target
   - supporting insert/style/compositing input
7. If the edit target is only on the local filesystem and you are staying on the built-in path, inspect it with `view_image` first so the image is available in conversation context.
8. If the user asked for a photo, illustration, sprite, product image, banner, or other explicitly raster-style asset, use `image_gen` rather than substituting SVG/HTML/CSS placeholders. If the request is for an icon, logo, or UI graphic that should match existing repo-native SVG/vector/code assets, prefer editing those directly instead.
9. Augment the prompt based on specificity:
   - If the user's prompt is already specific and detailed, normalize it into a clear spec without adding creative requirements.
   - If the user's prompt is generic, add tasteful augmentation only when it materially improves output quality.
10. Use the built-in `image_gen` tool by default.
11. For transparent-output requests, ask for a transparent background and preserve the generated alpha channel.
12. Inspect outputs and validate: subject, style, composition, text accuracy, and invariants/avoid items. Use the checklist in `references/prompting.md` → *Check the result*: Is required text accurate and legible, are diagram labels and relationships correct? Do identities, product shapes, labels, and reference details remain intact? Did the edit change only what was requested? If transparency is required, does the file contain an alpha channel rather than a painted background?
13. Iterate with a single targeted change, then re-check.
14. For preview-only work, render the image inline; the underlying file may remain at the default `$CODEX_HOME/generated_images/...` path.
15. For project-bound work, move or copy the selected artifact into the workspace and update any consuming code or references. Never leave a project-referenced asset only at the default `$CODEX_HOME/generated_images/...` path.
16. For batches or multi-asset requests, persist every requested deliverable final in the workspace unless the user explicitly asked to keep outputs preview-only. Discarded variants do not need to be kept unless requested.
17. If the user explicitly chooses or confirms the CLI fallback, then use [GPT Image model guidance](#gpt-image-model-guidance) and the fallback-only docs for model, quality, size, masks, output format, output paths, and network setup.
18. Always report the final saved path(s) for any workspace-bound asset(s), plus the final prompt or prompt set and whether the built-in tool or fallback CLI mode was used.

## Transparent image requests

- Request an isolated subject in the prompt and a genuinely transparent background in the request: `background="transparent"` with `output_format` `png` or `webp`.
- Preserve the returned alpha channel and check the decoded image's alpha, including hair, glass, shadows, and object edges. A drawn checkerboard is not transparency.
- Do not set `output_compression` for PNG.
- For subsequent edits, repeat the requirement to preserve the transparent background.
- Both GPT Image 2.5 models support transparent backgrounds; for `gpt-image-2` it is available in preview.

Worked example: `references/prompting.md` → *Create a transparent product cutout*.

## Prompt augmentation

Reformat user prompts into a structured, production-oriented spec. Make the user's goal clearer and more actionable, but do not blindly add detail.

Treat this as prompt-shaping guidance, not a closed schema. Use only the lines that help, and add a short extra labeled line when it materially improves clarity.

### Specificity policy

Use the user's prompt specificity to decide how much augmentation is appropriate:

- If the prompt is already specific and detailed, preserve that specificity and only normalize/structure it.
- If the prompt is generic, you may add tasteful augmentation when it will materially improve the result.

Allowed augmentations:

- composition or framing hints
- polish level or intended-use hints
- practical layout guidance
- reasonable scene concreteness that supports the stated request

Not allowed augmentations:

- extra characters or objects that are not implied by the request
- brand names, slogans, palettes, or narrative beats that are not implied
- arbitrary side-specific placement unless the surrounding layout supports it

Note: when the image tool runs inside the Responses API, the mainline model automatically revises the prompt; the result is returned in `revised_prompt` (see [API reference](#api-reference)). Inspect it when the output drifts from the request.

## Use-case taxonomy (exact slugs)

Classify each request into one of these buckets and keep the slug consistent across prompts and references. Each slug has an official worked example in `references/prompting.md` → *Official examples*.

Generate:

- `photorealistic-natural` — candid/editorial lifestyle scenes with real texture and natural lighting.
- `product-mockup` — product/packaging shots, catalog imagery, merch concepts.
- `ui-mockup` — app/web interface mockups and wireframes; specify the desired fidelity.
- `infographic-diagram` — diagrams/infographics with structured layout and text.
- `scientific-educational` — classroom explainers, scientific diagrams, and learning visuals with required labels and accuracy constraints.
- `ads-marketing` — campaign concepts and ad creatives with audience, brand position, scene, and exact tagline/copy.
- `productivity-visual` — slide, chart, workflow, and data-heavy business visuals.
- `logo-brand` — logo/mark exploration, vector-friendly.
- `illustration-story` — comics, children's book art, narrative scenes.
- `stylized-concept` — style-driven concept art, 3D/stylized renders.
- `historical-scene` — period-accurate/world-knowledge scenes.

Edit:

- `text-localization` — translate/replace in-image text, preserve layout.
- `identity-preserve` — try-on, person-in-scene; lock face/body/pose.
- `precise-object-edit` — remove/replace a specific element (including interior swaps).
- `lighting-weather` — time-of-day/season/atmosphere changes only.
- `background-extraction` — transparent background / clean cutout. Ask for actual transparency.
- `style-transfer` — apply reference style while changing subject/scene.
- `compositing` — multi-image insert/merge with matched lighting/perspective.
- `sketch-to-render` — drawing/line art to photoreal render.

## Shared prompt schema

Use the following labeled spec as shared prompt scaffolding for both top-level modes:

```text
Use case: <taxonomy slug>
Asset type: <where the asset will be used>
Primary request: <user's main prompt>
Input images: <Image 1: role; Image 2: role> (optional)
Scene/backdrop: <environment>
Subject: <main subject>
Style/medium: <photo/illustration/3D/etc>
Composition/framing: <wide/close/top-down; placement>
Lighting/mood: <lighting + mood>
Color palette: <palette notes>
Materials/textures: <surface details>
Text (verbatim): "<exact text>"
Constraints: <must keep/must avoid>
Avoid: <negative constraints>
```

Notes:

- `Asset type` and `Input images` are prompt scaffolding, not dedicated CLI flags.
- `Scene/backdrop` refers to the visual setting. It is not the same as the API `background` parameter, which controls output transparency behavior.
- Execution settings such as `quality`, `size`, `background`, `output_format`, masks, and output paths are request parameters, not prompt text. The official guide: "Set API parameters separately from the prompt."
- The official guide states that short prompts, descriptive paragraphs, JSON-like structures, instructions, and tags can all express the same intent; choose the format that makes the requirements easiest to read and update rather than relying on special syntax. This schema is one such format.

Augmentation rules:

- Keep it short.
- Add only the details needed to improve the prompt materially.
- For edits, explicitly list invariants (`change only X; keep Y unchanged`).
- If any critical detail is missing and blocks success, ask a question; otherwise proceed.

## Examples

### Generation example (hero image)

```text
Use case: product-mockup
Asset type: landing page hero
Primary request: a minimal hero image of a ceramic coffee mug
Style/medium: clean product photography
Composition/framing: wide composition with usable negative space for page copy if needed
Lighting/mood: soft studio lighting
Constraints: no logos, no text, no watermark
```

### Edit example (invariants)

```text
Use case: precise-object-edit
Asset type: product photo background replacement
Primary request: replace only the background with a warm sunset gradient
Constraints: change only the background; keep the product and its edges unchanged; no text; no watermark
```

All 23 official OpenAI examples (prompt, settings, input images, and the published Flare and Sunburst outputs) are in `references/prompting.md` → *Official examples*.

## Prompting best practices

- Define the result: name the subject and intended use (product photograph, advertisement, diagram), the composition, aspect ratio, and important placement constraints.
- Structure prompt as scene/backdrop -> subject -> details -> constraints; for complex requests, use labeled sections.
- Include intended use (ad, UI mock, infographic) to set the mode and polish level.
- Describe visible details: materials, lighting, colors, and the visual medium. Request `photorealistic` or `real photograph` explicitly when that is the goal.
- Use camera/composition language for photorealism; treat camera specifications as cues for appearance, not a guarantee of exact physical simulation.
- For wide, cinematic, low-light, rainy, or neon scenes, specify scale, atmosphere, and color instead of relying on mood words alone.
- For people, describe body framing, relative scale, gaze, and interaction with objects.
- Only use SVG/vector stand-ins when the user explicitly asked for vector output or a non-image placeholder.
- Quote exact text, specify typography + placement, and say how many times it should appear; ask for no extra text, then check spelling and legibility.
- For tricky words, spell them letter-by-letter and require verbatim rendering.
- Compare `medium` or `high` quality for small text, dense information, or multiple fonts.
- For multi-image inputs, reference images by index and describe how they should be used. Note for this system: on `gpt-image-2` the index labels were measured to have no effect and the order of `image[]` decides; see the CORRECTION in `references/prompting.md`.
- For edits, separate changes from constraints: say `change only X`, list what to preserve (identity, geometry, layout, lighting, labels), and state exclusions (unwanted text, logos, watermarks). For precise local edits, also name saturation, contrast, arrows, camera angle, and surrounding objects that must remain unchanged.
- For edits, repeat invariants every iteration to reduce drift.
- Iterate with single-change follow-ups: pass the previous output as the next edit input, request one change, and repeat the details to preserve.
- If a region must remain pixel-identical, composite the approved edit into the original image instead of relying on prompting alone.
- If the prompt is generic, add only the extra detail that will materially help.
- If the prompt is already detailed, normalize it instead of expanding it.
- For model, `quality`, size, masks, output format, and output paths, see [GPT Image model guidance](#gpt-image-model-guidance) and [API reference](#api-reference).
- For transparent images, ask for actual transparency and preserve its alpha.

More principles shared by both modes: `references/prompting.md`.
Copy/paste specs shared by both modes: `references/sample-prompts.md`.

## Guidance by asset type

Asset-type templates (website assets, game assets, wireframes, logo) are consolidated in `references/sample-prompts.md`. Official worked examples for logos, interfaces, diagrams, slides, comics, historical scenes, holiday cards, and merchandise are in `references/prompting.md` → *Official examples*.

## GPT Image model guidance

### GPT Image 2.5 (`gpt-image-2.5-flare`, `gpt-image-2.5-sunburst`)

- Use one of the two GPT Image 2.5 models for new workflows. The official documentation: "For new integrations, use one of the GPT Image 2.5 models."
- `quality`: `low`, `medium`, `high`, `xhigh`, `max`, or `auto`. Both models default to `auto`. Earlier GPT Image models support quality settings up to `high`.
- Use `quality="low"` for quick drafts. For final assets, compare higher quality settings to find the right balance of detail, latency, and cost.
- Choose the model before tuning `quality`. The same quality label does not imply the same image quality or response time across models. Use `xhigh` or `max` only when they improve an unmet quality requirement within the latency budget; a higher setting doesn't guarantee a better result for every prompt.
- `size`: `auto` or `WIDTHxHEIGHT`. Recommended sizes: `1024x1024` (square), `1536x1024` (landscape), `1024x1536` (portrait). Common sizes also include `2048x2048` (2K square), `2048x1152` (2K landscape), `3840x2160` (4K landscape), and `2160x3840` (4K portrait).
- Custom size constraints: width and height must be multiples of 16; the aspect ratio must be between 1:3 and 3:1; neither edge may exceed 3840 pixels; the total pixel count must be between 655,360 and 8,294,400 (4K). Resolutions above `2560x1440` are experimental.
- `background`: `auto`, `opaque`, or `transparent`. For transparent output use `output_format` `png` or `webp`.
- `output_format`: `png` (default), `jpeg`, or `webp`. `output_compression` (0–100%) applies to JPEG and WebP only. JPEG is faster than PNG; prioritize it if latency is a concern.
- `size`, `quality`, and `background` support `auto`, where the model selects the best option based on the prompt.
- `input_fidelity` is not documented for the 2.5 models; do not set it until it is verified.

### GPT Image 2 (`gpt-image-2`, earlier model)

- The documentation keeps `gpt-image-2` under *Earlier GPT Image models*; use it to maintain existing integrations.
- `quality`: `low`, `medium`, `high`, or `auto` (default).
- `size`: any resolution satisfying the same constraints as above (max edge `3840px`, both edges multiples of `16px`, long-to-short ratio at most `3:1`, total pixels from `655,360` to `8,294,400`). Square images are typically fastest to generate. Popular sizes: `1024x1024`, `1536x1024`, `1024x1536`, `2048x2048`, `2048x1152`, `3840x2160`, `2160x3840`, `auto` (default).
- `input_fidelity`: omit it; the API doesn't allow changing it because the model processes every image input at high fidelity automatically. Image input tokens can therefore be higher for edit requests that include reference images.
- `background`: for transparent output, explicitly set `transparent` and use PNG or WebP. Transparent backgrounds are available in preview for `gpt-image-2`.
- `output_compression`: JPEG or WebP only, not PNG.
- Note for this system: the upstream skill's CLI `scripts/image_gen.py` defaulted to `gpt-image-2`; the script is not in this repository, so its current default is unverified.

### Deprecated models

- `gpt-image-1.5`: shuts down on December 1, 2026 ([deprecation notice](https://developers.openai.com/api/docs/deprecations#2026-06-02-gpt-image-model-deprecations)). Sizes `1024x1024`, `1024x1536`, `1536x1024`, `auto`; `input_fidelity` `low` or `high`.
- `gpt-image-1`: shuts down on October 23, 2026 ([deprecation notice](https://developers.openai.com/api/docs/deprecations#2026-04-22-legacy-gpt-model-snapshots)). Sizes `1024x1024`, `1024x1536`, `1536x1024`, `auto`; `input_fidelity` `low` or `high` (high input fidelity uses more image input tokens).
- Validate existing workflows with a current model before migrating; full parameter tables are in `references/prompting.md` → *Older model reference*.

## API reference

### Image API and Responses API

- **Image API** — two endpoints: **Generations** create images from scratch based on a text prompt; **Edits** modify existing images using a new prompt, either partially or entirely. Set `model` to `gpt-image-2.5-sunburst` or `gpt-image-2.5-flare` directly.
- **Responses API** — generates images as part of conversations or multi-step flows through the built-in image generation tool, and accepts image inputs and outputs within context. Select a supported mainline model at the top level and specify `gpt-image-2.5-sunburst` or `gpt-image-2.5-flare` in the tool's `model` field. `gpt-5` and newer models should support the image generation tool; check the model detail page to confirm.
- Compared to the Image API, the Responses API adds multi-turn editing (iterative high-fidelity edits with prompting) and flexible inputs (image File IDs as input images, not just bytes).
- Choosing: if you only need to generate or edit a single image from one prompt, the Image API is the best choice; for conversational, editable image experiences, use the Responses API.
- Both APIs customize output by quality, size, format, and compression.
- Organization verification: you may need to complete [API Organization Verification](https://help.openai.com/en/articles/10910291-api-organization-verification) from the developer console before using GPT Image models.

### Generation options

- `n` generates multiple images in a single request; by default the API returns a single image.
- **Multi-turn (Responses API):** provide earlier image generation call outputs in context (or just the image ID), or use `previous_response_id`. The optional `action` parameter controls whether the tool generates or edits: `action: "auto"` lets the model decide, `"generate"` always creates a new image, `"edit"` forces editing when an image is in context.
- **Streaming:** the Responses API and Image API support streaming partial images. `partial_images` takes 0–3; at 0 only the final image is returned, and for larger values fewer partials may arrive if the full image is generated quickly. Each partial image incurs an additional 100 image output tokens.
- **Revised prompt:** with the Responses API image tool, the mainline model automatically revises the prompt; read it from `revised_prompt` on the image generation call.

### Edits, references, and masks

- The edits endpoint edits existing images, generates new images using other images as references (one or more), and edits parts of an image with a mask.
- With the Responses API, input images can be a fully qualified URL, a Base64-encoded data URL, or a File ID created with the Files API.
- Masks: masking with GPT Image is entirely prompt-based; the model uses the mask as guidance but may not follow its exact shape with complete precision. With multiple input images, the mask applies to the first image. The image and mask must be the same format and size, each under 50MB, and the mask must contain an alpha channel. See [editing with a mask](https://developers.openai.com/api/docs/guides/image-generation#edit-an-image-using-a-mask).

### Output

- The Image API returns base64-encoded image data.
- Output format `png` (default), `jpeg`, or `webp`; `output_compression` 0–100% for JPEG/WebP (for example, `output_compression=50` compresses the image by 50%).

### Content moderation and errors

- All prompts and generated images are filtered in accordance with the [content policy](https://openai.com/policies/usage-policies/). The `moderation` parameter takes `auto` (default, standard filtering) or `low` (less restrictive filtering).
- Handle failures like other API errors: check the HTTP status or SDK exception type, log the request ID, retry transient rate-limit and server failures with backoff, and don't automatically retry quota errors or user errors that require changing the request.
- User-correctable failures may return `error.type = "image_generation_user_error"`; don't retry them without modifying the prompt or input images. Use `error.code` as the stable discriminator.
- `error.code = "moderation_blocked"` may include `error.moderation_details` with `moderation_stage` (`input`, `output`, or `unknown`) and coarse `categories` (for example `harassment`, `self-harm`, `sexual`, `violence`). Keep the end-user message generic; use the details for developer logs, support workflows, analytics, and light remediation hints.

## Limitations

- **Latency:** complex prompts may take up to 2 minutes to process.
- **Text rendering:** although significantly improved, the model can still struggle with precise text placement and clarity.
- **Consistency:** the model may occasionally struggle to maintain visual consistency for recurring characters or brand elements across multiple generations.
- **Composition control:** despite improved instruction following, the model may have difficulty placing elements precisely in structured or layout-sensitive compositions.
- Repeated edits can still change details intended to be preserved; restate constraints and inspect each result.

## Cost and latency

- Both GPT Image 2.5 models use the same token rates: $8 per million image input tokens, $2 per million cached image input tokens, $30 per million image output tokens, $5 per million text input tokens, and $1.25 per million cached text input tokens. See [pricing](https://developers.openai.com/api/docs/pricing#image-generation).
- Equal token rates don't mean equal cost per image: token consumption can differ by model and quality setting. Use the response's `usage` to measure token consumption for your prompts, sizes, and quality settings, and confirm current pricing rather than assuming the faster model costs less.
- Responses API requests include the mainline model's token usage in addition to image generation costs.
- Cached input pricing for GPT Image 2 and GPT Image 2.5 applies only to the image generation tool in the Responses API, not to direct Images API requests (including `/v1/images/edits`). Cached token counts aren't included in the Responses API output, so `usage` can't verify cache hits.
- Each streamed partial image costs an additional 100 image output tokens.
- A larger non-square resolution can sometimes produce fewer output tokens than a smaller or square resolution at the same quality setting.
- Published per-image prices for earlier models (output only, excluding input tokens):

| Model | Quality | 1024 x 1024 | 1024 x 1536 | 1536 x 1024 |
| --- | --- | --- | --- | --- |
| GPT Image 2 | Low | $0.006 | $0.005 | $0.005 |
| GPT Image 2 | Medium | $0.053 | $0.041 | $0.041 |
| GPT Image 2 | High | $0.211 | $0.165 | $0.165 |
| GPT Image 1.5 | Low | $0.009 | $0.013 | $0.013 |
| GPT Image 1.5 | Medium | $0.034 | $0.05 | $0.05 |
| GPT Image 1.5 | High | $0.133 | $0.2 | $0.2 |
| GPT Image 1 | Low | $0.011 | $0.016 | $0.016 |
| GPT Image 1 | Medium | $0.042 | $0.063 | $0.063 |
| GPT Image 1 | High | $0.167 | $0.25 | $0.25 |
| GPT Image 1 Mini | Low | $0.005 | $0.006 | $0.006 |
| GPT Image 1 Mini | Medium | $0.011 | $0.015 | $0.015 |
| GPT Image 1 Mini | High | $0.036 | $0.052 | $0.052 |

- No per-image table is published for the GPT Image 2.5 models; the guide provides a token calculator instead.

Note for this system: `resources/ai/providers/openai_image_pricing_2026_09_14.json` (`verified: false`) estimates every model, including both 2.5 models, at low $0.015 / medium $0.041 / high $0.11 per image. These estimates do not match the gpt-image-2 table above (low $0.005–0.006, high $0.165–0.211) and are not official 2.5 figures.

## Fallback CLI mode only

### Temp and output conventions

These conventions apply only to the CLI fallback. They do not describe built-in `image_gen` output behavior.

- Use `tmp/imagegen/` for intermediate files (for example JSONL batches); delete them when done.
- Write final artifacts under `output/imagegen/`.
- Use `--out` or `--out-dir` to control output paths; keep filenames stable and descriptive.

### Dependencies

Prefer `uv` for dependency management in this repo.

Required Python package:

```bash
uv pip install openai
```

Optional for image inspection and downscaling:

```bash
uv pip install pillow
```

Portability note:

- If you are using the installed skill outside this repo, install dependencies into that environment with its package manager.
- In uv-managed environments, `uv pip install ...` remains the preferred path.

### Environment

- `OPENAI_API_KEY` must be set for live API calls.
- Do not ask the user for `OPENAI_API_KEY` when using the built-in `image_gen` tool.
- Never ask the user to paste the full key in chat. Ask them to set it locally and confirm when ready.

If the key is missing, give the user these steps:

1. Create an API key in the OpenAI platform UI: https://platform.openai.com/api-keys
2. Set `OPENAI_API_KEY` as an environment variable in their system.
3. Offer to guide them through setting the environment variable for their OS/shell if needed.

If installation is not possible in this environment, tell the user which dependency is missing and how to install it into their active environment.

### Script-mode notes

- CLI commands + examples: `references/cli.md`
- API parameter reference: `references/image-api.md` (full OpenAI API reference for the Images endpoints, updated 2026-10-02)
- Network approvals / sandbox settings for CLI mode: `references/codex-network.md`

## Reference map

- `references/prompting.md`: shared prompting principles for both modes, the official GPT Image 2.5 prompting guide (model choice, parameters, migration, fundamentals), all official examples with Flare and Sunburst outputs, a runnable example, the result checklist, and the older-model reference. Updated 2026-10-02.
- `references/sample-prompts.md`: shared copy/paste prompt recipes for both modes, each linked to its official OpenAI example with the request settings used, plus recipes adapted from official examples (background extraction, object removal, person insertion, product scene, holiday card, merchandise, character reference). Updated 2026-10-02.
- `references/cli.md`: fallback-only CLI usage via `scripts/image_gen.py` (script flags unverified in this repo), with GPT Image 2.5 model, size, quality and transparency guidance and the official OpenAI CLI and `curl` equivalents. Updated 2026-10-02.
- `references/image-api.md`: the OpenAI API reference for `POST /images/generations`, `POST /images/edits` and the retired variations endpoint, verbatim with every schema, streaming event and example, plus a summary and the mapping to this repo's Laravel client (`OpenAiImageClient`). Updated 2026-10-02.
- `references/codex-network.md`: fallback-only network/sandbox troubleshooting for CLI mode.
- `scripts/image_gen.py`: fallback-only CLI implementation. Use only when the user explicitly chooses or confirms CLI mode. Not part of this repository.
- Sources: [Image generation guide](https://developers.openai.com/api/docs/guides/image-generation), [Image prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?site_locale=en), [Pricing](https://developers.openai.com/api/docs/pricing#image-generation).
