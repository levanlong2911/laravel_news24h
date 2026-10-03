# Prompting best practices

> **UPDATED 2026-10-02** from [OpenAI — Image prompting](https://developers.openai.com/api/docs/guides/image-prompting?site_locale=en)
> (the "GPT Image 2.5 prompting guide", read from the page's `image-prompting.md` on the same day).
> This file is NO LONGER a verbatim copy of the `imagegen` skill. Copied verbatim from the guide:
> *Overview*, *Choose a model*, *Model parameters*, *Migrate an existing workflow*, *Prompting fundamentals*,
> all 23 *Official examples* (technique note, settings, input images, prompt, Flare and Sunburst outputs),
> *Run a complete example*, *Check the result* and *Older model reference*. The skill's own principle sections
> (*Structure* to *Use-case tips*) are extended with the fundamentals and link to the examples that demonstrate them.
> Additions by this system are marked "Note for this system" or "CORRECTION"; wherever the new guide disagrees
> with the old skill text, it is stated.

> **CORRECTION for this system.** The [Input images and references](#input-images-and-references)
> section below teaches labelling images by index (`Image 1: edit target`, `Image 2: style reference`).
> With `gpt-image-2` this has NO effect: swapping the `REFERENCE IMAGE 1/2` labels while keeping
> the image order unchanged produced an unchanged result. Measured 2026-08-10; evidence
> `work/experiments/multi_reference/20260810-074411Z_result.json` (Python repo), recorded in
> `contracts/providers/gpt-image.json` → `capabilities.numeric_reference_labels_honoured`.
>
> **IMAGE ORDER is what takes effect** — `media_runtime/providers/gpt_image.py` sends
> `image[]` in exactly that order.
>
> The 2026-10 OpenAI guide still recommends numbering images by role. The measurement above was made
> ONLY on `gpt-image-2`, NOT yet on `gpt-image-2.5-flare` / `gpt-image-2.5-sunburst` — until it is measured,
> treat image order as decisive and labels as accompanying description only.

These prompting principles are shared by both top-level modes of the skill:
- built-in `image_gen` tool (default)
- explicit `scripts/image_gen.py` CLI fallback

This file is about model choice, prompt structure, specificity, and iteration. Fallback-only execution controls such as masks and output paths live in the fallback docs.

## Contents
- [Overview](#overview)
- [Choose a model](#choose-a-model)
- [Model parameters](#model-parameters)
- [Migrate an existing workflow](#migrate-an-existing-workflow)
- [Prompting fundamentals](#prompting-fundamentals)
- [Structure](#structure)
- [Specificity policy](#specificity-policy)
- [Allowed and disallowed augmentation](#allowed-and-disallowed-augmentation)
- [Composition and layout](#composition-and-layout)
- [Constraints and invariants](#constraints-and-invariants)
- [Text in images](#text-in-images)
- [Input images and references](#input-images-and-references)
- [Iterate deliberately](#iterate-deliberately)
- [Transparent images](#transparent-images)
- [Fallback-only execution controls](#fallback-only-execution-controls)
- [Use-case tips](#use-case-tips)
- [Official examples (OpenAI guide, 2026-10-02)](#official-examples-openai-guide-2026-10-02)
  - [Generate images](#generate-images)
  - [Edit images](#edit-images)
  - [Refine an image across turns](#refine-an-image-across-turns)
  - [More workflows](#more-workflows)
- [Run a complete example](#run-a-complete-example)
- [Check the result](#check-the-result)
- [Older model reference](#older-model-reference)
- [Where to find copy/paste recipes](#where-to-find-copypaste-recipes)

## Overview
Start with the image you need, then describe the subject, composition, style, and constraints. For edits, identify what should change and what must stay the same. Refine one thing at a time and inspect the result.

GPT Image 2.5 includes two model choices. GPT Image 2.5 Flare is the small model, optimized for speed, with image quality comparable to GPT Image 2. GPT Image 2.5 Sunburst is the base model, optimized for quality, with higher image quality than GPT Image 2. Both models offer improvements in precise editing and subject preservation.

For API setup and request examples, see the [image generation guide](https://developers.openai.com/api/docs/guides/image-generation).

## Choose a model
For a new workflow, start with GPT Image 2.5 Flare when speed is the priority, or GPT Image 2.5 Sunburst when demanding quality requirements are the priority. Once the output meets your requirements, look for opportunities to reduce latency.

For migrating from a current image model, use your current image quality as the starting point. Both models support image generation, editing, and transparent backgrounds.

| Your current workflow | Start by testing |
| --- | --- |
| An existing, validated GPT Image 2 workflow already meets your quality requirements | GPT Image 2.5 Flare. Check whether you can retain acceptable quality while reducing latency. |
| A complex use case where GPT Image 2 does not meet your quality requirements | GPT Image 2.5 Sunburst. First establish that it delivers the quality you need. |

If GPT Image 2.5 Sunburst meets your quality requirements, then test GPT Image 2.5 Flare with the same prompts and inputs. Switch to GPT Image 2.5 Flare if it also meets those requirements and improves latency. Keep GPT Image 2.5 Sunburst when its quality advantage is necessary for your workflow.

Measure response time and quality on your own workload. Results depend on your prompts, reference images, output dimensions, and quality settings; a speed improvement on one workload doesn't establish a fixed improvement on another.

## Model parameters
Set API parameters separately from the prompt.

| Parameter | GPT Image 2.5 settings |
| --- | --- |
| `model` | `gpt-image-2.5-flare` (small model) or `gpt-image-2.5-sunburst` (base model) |
| `quality` | `auto` (default), `low`, `medium`, `high`, `xhigh`, or `max` |
| `size` | `auto` or a custom resolution. Common sizes: `1024x1024` (square), `1536x1024` (landscape), `1024x1536` (portrait), `2048x2048` (2K square), `2048x1152` (2K landscape), `3840x2160` (4K landscape), and `2160x3840` (4K portrait). |
| `background` | `auto`, `opaque`, or `transparent` |

For a custom resolution, use `WIDTHxHEIGHT` and follow these constraints:

- Each edge must be no more than 3,840 pixels.
- Both edges must be multiples of 16 pixels.
- The ratio of the longer edge to the shorter edge must not exceed 3:1.
- The total pixel count must be between 655,360 and 8,294,400.

Outputs with more than 3,686,400 total pixels (`2560x1440`) are experimental.

Choose the model using the workflow above before tuning `quality`. For the first comparison, keep an explicitly selected quality setting unchanged when both models support it, along with the prompt, reference images, and output dimensions. The same quality label does not imply the same image quality or response time across models.

If the output falls short, test a higher quality setting. Once it meets your requirements, test lower settings to see whether they preserve acceptable quality while reducing latency. Use `xhigh` or `max` only when they improve an unmet quality requirement within your latency budget. A higher setting doesn't guarantee a better result for every prompt.

For transparent assets, explicitly request `background="transparent"` and use PNG or WebP. Check the decoded image's alpha channel, including hair, glass, shadows, and object edges. Use `output_compression` only for JPEG or WebP output, not PNG.

Note for this system: the GPT Image 2.5 table does not list `input_fidelity`, `output_format` or `output_compression`. The [image generation guide](https://developers.openai.com/api/docs/guides/image-generation) lists `png` (default), `jpeg` and `webp` as output formats, without a separate restriction for 2.5. `input_fidelity` is not documented for 2.5 — do not set it until verified. The settings of older models are in [Older model reference](#older-model-reference).

## Migrate an existing workflow
1. **Save a baseline.** Collect representative production prompts and reference images, including difficult edits, exact text, faces, product geometry, and transparent assets. Record the current model, request settings, and results.
2. **Choose the first candidate.** If GPT Image 2 already meets your quality requirements, start with GPT Image 2.5 Flare and test for a latency improvement. If GPT Image 2 falls short on a complex use case, start with GPT Image 2.5 Sunburst and first establish that it meets your quality requirements. Keep the prompt, references, dimensions, and output format unchanged for the first comparison.
3. **Check the complete result.** Compare instruction following, identity and product preservation, text accuracy, unwanted changes, and transparency. Repeat requests to measure consistency. For editing workflows, test the complete sequence of edits as well as individual steps.
4. **Test for a latency gain after quality passes.** If you started with GPT Image 2.5 Sunburst and it meets your quality requirements, evaluate GPT Image 2.5 Flare against the same requirements. Switch only if the quality remains acceptable and latency improves; otherwise, keep GPT Image 2.5 Sunburst.
5. **Tune one setting at a time.** Compare quality levels before rewriting the prompt. Measure typical and slow responses, failures, retries, and cost per accepted image. Confirm current pricing rather than assuming the faster model costs less.
6. **Roll out by workflow.** Once the released model passes your acceptance criteria, move a small share of traffic, monitor the same measures, and expand gradually. Keep the previous model available for rollback while it remains supported.

When migrating from GPT Image 1 or 1.5, use the reference tabs to check parameter differences and shutdown dates. Test the candidate model's supported request settings rather than copying older settings unchanged. For GPT Image 2, keep your existing resolution and transparency requirements in the comparison.

Repeated edits can still change details you intended to preserve. Restate those constraints and inspect each result. If a region must remain pixel-identical, composite the approved edit into the original image instead of relying on prompting alone.

## Prompting fundamentals
1. **Define the result.** Name the subject and intended use, such as a product photograph, advertisement, or diagram. Specify the composition, aspect ratio, and important placement constraints. For complex requests, organize the prompt as scene, subject, details, and constraints, using labeled sections.
2. **Choose a maintainable format.** Short prompts, descriptive paragraphs, JSON-like structures, instructions, and tags can all express the same intent. Choose the format that makes the requirements easiest to read and update rather than relying on special syntax.
3. **Describe visible details.** Name materials, lighting, colors, and the visual medium. Request “photorealistic” or “real photograph” explicitly when that is the goal, and describe framing and texture. Treat camera specifications as cues for appearance, not a guarantee of exact physical simulation. For wide, cinematic, low-light, rainy, or neon scenes, specify scale, atmosphere, and color instead of relying on mood words alone.
4. **Specify people and actions.** Describe body framing, relative scale, gaze, and interaction with objects. Instructions such as “full body visible, feet included,” “looking down at the open book,” or “hands naturally gripping the handlebars” make the intended pose and action clearer.
5. **Specify exact text.** Put required wording in quotes and describe its position and typography. Spell unusual words or brand names letter by letter when needed. Ask for no extra text, then check spelling and legibility in the output. Compare medium or high quality for small text, dense information, or multiple fonts.
6. **Separate changes from constraints.** For edits, say “change only X” and list the details to preserve, such as identity, geometry, layout, lighting, or labels. State exclusions such as unwanted text, logos, or watermarks. For precise local edits, also identify saturation, contrast, arrows, camera angle, and surrounding objects that must remain unchanged.
7. **Assign roles to references.** Identify each input by number and purpose: subject, style, clothing, or background. Explain how the inputs should combine and which elements should move where.
8. **Iterate deliberately.** Pass the previous output as the next edit input, request one change, and repeat the details to preserve. References such as “same style as before” can carry context, but restate critical constraints if the result drifts. Compare results before adding more instructions.

The examples below each demonstrate a different technique. Keep their prompts as starting points and adapt them to your own images and requirements.

Note for this system: fundamental 7 recommends numbering references; see the CORRECTION at the top of this file. The sections from [Structure](#structure) to [Use-case tips](#use-case-tips) are the skill's own principles extended with these fundamentals; each one links to the [Official examples](#official-examples-openai-guide-2026-10-02) that demonstrate it.

## Structure
- Define the result: name the subject and intended use (product photograph, advertisement, diagram), the composition, aspect ratio, and important placement constraints.
- Use a consistent order: scene/backdrop -> subject -> key details -> constraints -> output intent. For complex requests, use short labeled sections instead of one long paragraph.
- Choose a maintainable format. Short prompts, paragraphs, JSON-like structures, instructions, and tags can all express the same intent; pick the one that is easiest to read and update rather than relying on special syntax.

Examples: labeled sections — [Establish the character](#establish-the-character), [Design a holiday card](#design-a-holiday-card), [Design collectible merchandise](#design-collectible-merchandise); a short plain prompt — [Use historical and real-world context](#use-historical-and-real-world-context); a bulleted artifact spec — [Build slides, diagrams, and charts](#build-slides-diagrams-and-charts).

## Specificity policy
- If the user prompt is already specific and detailed, normalize it into a clean spec without adding creative requirements.
- If the prompt is generic, you may add tasteful detail when it materially improves the output.
- Treat examples in `sample-prompts.md` as fully-authored recipes, not as the default amount of augmentation to add to every request.
- Describe visible details: materials, lighting, colors, and the visual medium.
- For photorealism, request `photorealistic` or `real photograph` directly when that is the goal, plus framing and concrete real-world texture such as pores, wrinkles, fabric wear, material grain, or imperfect everyday detail.
- Camera specifications (lens, film stock) are cues for appearance, not a guarantee of exact physical simulation.
- For wide, cinematic, low-light, rainy, or neon scenes, specify scale, atmosphere, and color instead of relying on mood words alone.

Examples: [Control style and lighting](#control-style-and-lighting) (`photorealistic`, pores and sun texture, `35mm film`, `50mm lens`, `No glamorization, no heavy retouching`); [Insert a person into a scene](#insert-a-person-into-a-scene) (`not an overly enhanced or cinematic movie-poster image`, `Avoid cinematic lighting, dramatic color grading`); a deliberately generic prompt the model expands on its own — [Explain a process visually](#explain-a-process-visually).

## Allowed and disallowed augmentation

Allowed augmentation for generic prompts:
- composition and framing cues
- intended-use or polish-level hints
- practical layout guidance
- reasonable scene concreteness that supports the request

Do not add:
- extra characters, props, or objects that are not implied
- brand palettes, slogans, or story beats that are not implied
- arbitrary side-specific placement unless the surrounding layout supports it

## Composition and layout
- Specify framing and viewpoint (close-up, wide, top-down) and placement only when it materially helps.
- Call out negative space if the asset clearly needs room for UI or copy.
- Avoid making left/right layout decisions unless the user or surrounding layout supports them.
- For people, describe body framing, relative scale, gaze, and interaction with objects (`full body visible, feet included`, `looking down at the open book`, `hands naturally gripping the handlebars`).

Examples: [Control style and lighting](#control-style-and-lighting) (`medium close-up at eye level`, `calmly adjusting a net while his dog sits nearby`); [Insert a person into a scene](#insert-a-person-into-a-scene) (`centered in the image but looking away from the camera`); [Design a reusable logo](#design-a-reusable-logo) (`a single centered logo with generous padding`); [Turn a story into a comic strip](#turn-a-story-into-a-comic-strip) (`framed in the window behind them, small against the glass`).

## Constraints and invariants
- Separate changes from constraints. For edits, say `change only X; keep Y unchanged` and list what to preserve: identity, geometry, layout, lighting, labels.
- State exclusions such as unwanted text, logos, or watermarks.
- For precise local edits, also name what must stay unchanged around the edit: saturation, contrast, arrows, camera angle, surrounding objects.
- Repeat invariants on every iteration to reduce drift.

Examples: [Preserve identity and change clothing](#preserve-identity-and-change-clothing) (lists face, skin tone, body shape, pose, background, camera angle, framing as fixed); [Change furniture in a room](#change-furniture-in-a-room) (`replace ONLY the white chairs`, `Preserve camera angle, room lighting, floor shadows`); [Remove an object](#remove-an-object) and [Translate while preserving layout](#translate-while-preserving-layout) (`Do not change anything else` / `Do not change any other aspect of the image`); [Continue the story](#continue-the-story) (constraints repeated on the next turn).

## Text in images
- Put required wording in quotes (or ALL CAPS) and describe its position and typography (font style, size, color, placement).
- Say how many times the text should appear (`render the tagline exactly once`).
- Spell unusual words or brand names letter by letter when accuracy matters.
- Ask for no extra text, then check spelling and legibility in the output.
- Compare `medium` or `high` quality for small text, dense information, multiple fonts, legends, axes, and footnotes.

Examples: [Render exact text](#render-exact-text) (`Render the tagline exactly once ... No extra text`); [Create the starting image](#create-the-starting-image) (`Billboard text (EXACT, verbatim, no extra characters)`, `Typography: bold sans-serif, high contrast, centered`); [Design a holiday card](#design-a-holiday-card) and [Design collectible merchandise](#design-collectible-merchandise) (`Include ONLY this ... text (verbatim)`); [Build slides, diagrams, and charts](#build-slides-diagrams-and-charts) and [Create scientific and educational visuals](#create-scientific-and-educational-visuals) (dense labels at `quality="high"`).

## Input images and references
> Read the CORRECTION at the top of this file before relying on numeric labels.
- Do not assume that every provided image is an edit target.
- Assign each input a number and a purpose: subject, style, clothing, or background (`Image 1: edit target`, `Image 2: style reference`). Explain how the inputs combine and which elements move where.
- If the user provides images for style, composition, or mood guidance and does not ask to modify them, treat the request as generation with references.
- If the user asks to preserve an existing image while changing specific parts, treat the request as an edit.
- For compositing, say which element moves, its destination, and what must remain unchanged (`place the dog from the second image into the setting of image 1, right next to the woman ... Do not change anything else`).
- For style transfer, give the reference a specific role (its palette, texture, or medium) and describe the new subject separately.

Examples: [Combine references](#combine-references) (scene as image 1, dog as image 2, what moves where); [Preserve identity and change clothing](#preserve-identity-and-change-clothing) (one identity image plus three clothing references); [Transfer a visual style](#transfer-a-visual-style) (style-only reference, new subject described separately).

## Iterate deliberately
- Start with a clean base prompt, inspect the result, then pass that output as the next edit input.
- Request one change per turn and repeat the details to preserve.
- References such as `same style as before` can carry context, but restate critical constraints if the result drifts.
- Compare results before adding more instructions; prefer one targeted follow-up over rewriting the whole prompt.

Examples: [Create the starting image](#create-the-starting-image) → [Change one condition](#change-one-condition) (a one-line follow-up on the previous output); [Establish the character](#establish-the-character) → [Continue the story](#continue-the-story) (the defining details are repeated).

## Transparent images
- Request an isolated subject in the prompt and set `background="transparent"` in the API, with `output_format` `png` or `webp`.
- Preserve the returned alpha channel, and check the decoded image's alpha, including hair, glass, shadows, and object edges.
- A drawn checkerboard is not transparency.
- Do not set `output_compression` for PNG.
- For subsequent edits, repeat the requirement to preserve the transparent background.
- Correction to the old skill text: it said `gpt-image-2` does not support `background=transparent`; the 2026-10 guide says transparency for `gpt-image-2` is available **in preview**, while both 2.5 models support it fully.

Examples: [Create a transparent product cutout](#create-a-transparent-product-cutout) (`background="transparent"`, `output_format="png"`, `no halos/fringing`, `Do not add a solid backdrop, checkerboard`); [Design a reusable logo](#design-a-reusable-logo) (`clean alpha edges`); [Run a complete example](#run-a-complete-example) (code that keeps the returned PNG bytes with their alpha channel).

## Fallback-only execution controls
- `quality`, `input_fidelity`, explicit masks, output format, and output paths are fallback-only execution controls.
- Do not assume they are built-in `image_gen` tool arguments.
- If the user explicitly chooses CLI fallback, see `references/cli.md` and `references/image-api.md` for those controls; model-specific values are in [Model parameters](#model-parameters).
- Use `low` for fast drafts and thumbnails, and move up only as the `quality` tuning paragraphs in [Model parameters](#model-parameters) describe.
- For 4K-style output, use `3840x2160` for landscape or `2160x3840` for portrait; anything above `2560x1440` is experimental.
- Local edits that need a mask: see [editing with a mask](https://developers.openai.com/api/docs/guides/image-generation#edit-an-image-using-a-mask).

## Use-case tips
Generate:
- photorealistic-natural: Prompt as if a real photo is captured in the moment; use photography language (lens, lighting, framing); call for real texture; exclude glamorization or heavy retouching unless requested. Example: [Control style and lighting](#control-style-and-lighting).
- product-mockup: Describe the product/packaging and materials; ensure clean silhouette and label clarity; if in-image text is needed, require verbatim rendering and specify typography. Examples: [Create the starting image](#create-the-starting-image), [Design collectible merchandise](#design-collectible-merchandise).
- ui-mockup: Describe the product as if it already exists. Describe the target fidelity first (shippable mockup or low-fi wireframe), then focus on layout, hierarchy, spacing, and real UI elements; avoid concept-art language. Example: [Create an interface preview](#create-an-interface-preview).
- infographic-diagram: Name the process, audience, and the information the image must communicate; label parts explicitly; require verbatim text; verify labels and factual relationships, not only appearance. Example: [Explain a process visually](#explain-a-process-visually).
- logo-brand: Describe the brand and the shapes that define the mark; keep it simple and scalable with a strong silhouette and balanced negative space; avoid decorative flourishes unless requested. Use `n` for variations of one prompt. Example: [Design a reusable logo](#design-a-reusable-logo).
- ads-marketing: Write like a creative brief; include brand positioning, audience, desired vibe, scene, and exact tagline if text must appear, stating how many times it appears. Examples: [Render exact text](#render-exact-text), [Design a holiday card](#design-a-holiday-card).
- productivity-visual: Write an artifact spec, not an illustration request. Name the exact deliverable (slide, chart, workflow diagram), define canvas and hierarchy, put the real numbers and labels in the prompt, use a landscape size for decks and `quality="high"` for small text. Sample figures in a prompt are design inputs; replace them with verified data before use. Example: [Build slides, diagrams, and charts](#build-slides-diagrams-and-charts).
- scientific-educational: Prompt like an instructional design brief: audience, lesson objective, visual format, required labels, scientific constraints. List required components and what must not be included; ask for a flat visual system, clear arrows, readable labels, scan-friendly whitespace; use `quality="high"` for dense labels. Example: [Create scientific and educational visuals](#create-scientific-and-educational-visuals).
- illustration-story: Define one clear visual beat per panel; keep each action concrete. Example: [Turn a story into a comic strip](#turn-a-story-into-a-comic-strip).
- character-consistency: Establish the character first (appearance, proportions, outfit, tone, plain background), then reuse that image in every scene and repeat the defining details plus `do not redesign the character`. Example: [Keep a character consistent](#keep-a-character-consistent).
- stylized-concept: Specify style cues, material finish, and rendering approach (3D, painterly, clay) without inventing new story elements. Example: [Establish the character](#establish-the-character) (watercolor storybook style).
- historical-scene: Name the place and date; constrain clothing, props, and environment to the era, then inspect them for accuracy. Example: [Use historical and real-world context](#use-historical-and-real-world-context).

Edit:
- text-localization: Change only the text; preserve layout, typography, spacing, and hierarchy; check the translation and any words left in the original language. Example: [Translate while preserving layout](#translate-while-preserving-layout).
- identity-preserve: Lock identity (face, body, pose, hair, expression, proportions); change only the specified elements; match lighting, shadows, and color temperature. The same pattern applies when a product or object must stay recognizable. Examples: [Preserve identity and change clothing](#preserve-identity-and-change-clothing), [Insert a person into a scene](#insert-a-person-into-a-scene).
- precise-object-edit: Name exactly what to remove or replace (`replace ONLY the white chairs`); preserve camera angle, lighting, contact shadows, surrounding texture, and objects; end with `Do not change anything else`. Examples: [Remove an object](#remove-an-object), [Change furniture in a room](#change-furniture-in-a-room).
- lighting-weather: Change only environmental conditions (light, shadows, atmosphere, precipitation); keep geometry, framing, and subject identity. A short one-line follow-up on the previous output is enough. Example: [Change one condition](#change-one-condition).
- background-extraction: Request a clean cutout on a genuinely transparent background; preserve product geometry, fine edges, and label text; no halos, fringing, backdrop, checkerboard, shadow, or restyling. Example: [Create a transparent product cutout](#create-a-transparent-product-cutout).
- style-transfer: Give the reference a role (palette, texture, brushwork, medium) and describe the new subject separately; add `no extra elements` to prevent drift. Example: [Transfer a visual style](#transfer-a-visual-style).
- compositing: Reference inputs by index (see the CORRECTION at the top); specify what moves where; match lighting, perspective, and scale; keep the base framing unchanged. Example: [Combine references](#combine-references).
- sketch-to-render: Preserve layout, proportions, and perspective; add realism through plausible materials, lighting, and environment; `do not add new elements or text`. Example: [Turn a drawing into a realistic image](#turn-a-drawing-into-a-realistic-image).

## Official examples (OpenAI guide, 2026-10-02)

Every example below is copied verbatim from the [OpenAI image prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?site_locale=en): the technique note, the request settings, the input images, the prompt, and the published outputs of both GPT Image 2.5 models. Each example demonstrates a different technique; the guide says to keep the prompts as starting points and adapt them to your own images and requirements.

### Generate images

#### Control style and lighting
Describe a photograph through its subject, framing, light, and texture. This example specifies a candid composition and explicitly excludes heavy retouching.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a photorealistic candid photograph of an elderly sailor standing on a small fishing boat.
He has weathered skin with visible wrinkles, pores, and sun texture, and a few faded traditional sailor tattoos on his arms.
He is calmly adjusting a net while his dog sits nearby on the deck. Shot like a 35mm film photograph, medium close-up at eye level, using a 50mm lens.
Soft coastal daylight, shallow depth of field, subtle film grain, natural color balance.
The image should feel honest and unposed, with real skin texture, worn materials, and everyday detail. No glamorization, no heavy retouching.
```

Outputs:
- GPT Image 2.5 Flare: ![Photorealistic portrait of a sailor repairing a net — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/photorealism-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Photorealistic portrait of a sailor repairing a net — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/photorealism-gpt-image-2-5-sunburst.webp)

#### Explain a process visually
Name the process, audience, and information the image should communicate. For diagrams and information graphics, verify labels and factual relationships as well as appearance.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a detailed Infographic of the functioning and flow of an automatic coffee machine like a Jura.
From bean basket, to grinding, to scale, water tank, boiler, etc.
I'd like to understand technically and visually the flow.
```

Outputs:
- GPT Image 2.5 Flare: ![Diagram explaining an automatic coffee machine — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/infographic-coffee-machine-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Diagram explaining an automatic coffee machine — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/infographic-coffee-machine-gpt-image-2-5-sunburst.webp)

#### Render exact text
Quote the required copy and tell the model how many times it should appear. Specify the audience and visual treatment without adding unrelated instructions.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Give me a cool in culture ad / fashion shot for a brand called Thread.
It's a hip young street brand. The ad shows a group of friends hanging out together with the tagline "Yours to Create."
Make it feel like a polished campaign image for a youth streetwear audience: stylish, contemporary, energetic, and tasteful.
Use clean composition, strong color direction, natural poses, and premium fashion photography cues.
Render the tagline exactly once, clearly and legibly, integrated into the ad layout.
No extra text, no watermarks, no unrelated logos.
```

Outputs:
- GPT Image 2.5 Flare: ![Thread streetwear campaign with the requested tagline — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/thread-ad-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Thread streetwear campaign with the requested tagline — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/thread-ad-gpt-image-2-5-sunburst.webp)

#### Design a reusable logo
Describe the brand and the shapes that should define the mark. Specify a clear composition that remains legible at different sizes. Use `n` to request multiple variations.

Generation settings: `size="1024x1536"`, `quality="medium"`, `background="transparent"`, `output_format="png"`, `n=1`.

```text
Create an original, non-infringing logo for a company called Field & Flour, a local bakery.
The logo should feel warm, simple, and timeless. Use clean, vector-like shapes, a strong silhouette, and balanced negative space.
Favor simplicity over detail so it reads clearly at small and large sizes. Flat design, minimal strokes, no gradients unless essential.
Fully transparent background. Deliver a single centered logo with generous padding, clean alpha edges, and no solid backdrop, scenery, checkerboard, or watermark.
```

Each row compares one variation from each model.

| Variation | GPT Image 2.5 Flare | GPT Image 2.5 Sunburst |
| --- | --- | --- |
| 1 | ![Field and Flour bakery logo, first variation — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-1-gpt-image-2-5-flare.webp) | ![Field and Flour bakery logo, first variation — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-1-gpt-image-2-5-sunburst.webp) |
| 2 | ![Field and Flour bakery logo, second variation — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-2-gpt-image-2-5-flare.webp) | ![Field and Flour bakery logo, second variation — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-2-gpt-image-2-5-sunburst.webp) |
| 3 | ![Field and Flour bakery logo, third variation — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-3-gpt-image-2-5-flare.webp) | ![Field and Flour bakery logo, third variation — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-3-gpt-image-2-5-sunburst.webp) |
| 4 | ![Field and Flour bakery logo, fourth variation — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-4-gpt-image-2-5-flare.webp) | ![Field and Flour bakery logo, fourth variation — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/logo-generation-4-gpt-image-2-5-sunburst.webp) |

#### Use historical and real-world context
Name the place and date to establish a historical setting. The model can infer contextual details, but inspect clothing, staging, and surroundings for historical accuracy.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a realistic outdoor crowd scene in Bethel, New York on August 16, 1969.
Photorealistic, period-accurate clothing, staging, and environment.
```

Outputs:
- GPT Image 2.5 Flare: ![Crowd scene in Bethel, New York, in August 1969 — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/world-knowledge-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Crowd scene in Bethel, New York, in August 1969 — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/world-knowledge-gpt-image-2-5-sunburst.webp)

#### Turn a story into a comic strip
For story-to-comic generation, define the narrative as a sequence of clear visual beats, one per panel. Keep descriptions concrete and action-focused so the model can translate the story into readable, well-paced panels.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a short vertical comic-style reel with 4 panels.
Panel 1: The owner leaves through the front door. The pet is framed in the window behind them, small against the glass, eyes wide, paws pressed high, the house suddenly quiet.
Panel 2: The door clicks shut. Silence breaks. The pet slowly turns toward the empty house, posture shifting, eyes sharp with possibility.
Panel 3: The house transformed. The pet sprawls across the couch like it owns the place, crumbs nearby, sunlight cutting across the room like a spotlight.
Panel 4: The door opens. The pet is seated perfectly by the entrance, alert and composed, as if nothing happened.
```

Outputs:
- GPT Image 2.5 Flare: ![Four-panel comic about a pet at home — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/comic-reel-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Four-panel comic about a pet at home — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/comic-reel-gpt-image-2-5-sunburst.webp)

#### Create an interface preview
Interface previews work best when you describe the product as if it already exists. Focus on layout, hierarchy, spacing, and real interface elements, and avoid concept art language so the result looks like a usable, shipped interface rather than a design sketch.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a realistic mobile app UI mockup for a local farmers market.
Show today’s market with a simple header, a short list of vendors with small photos and categories, a small “Today’s specials” section, and basic information for location and hours.
Design it to be practical, and easy to use. White background, subtle natural accent colors, clear typography, and minimal decoration.
It should look like a real, well-designed, beautiful app for a small local market.
Place the UI mockup in an iPhone frame.
```

Outputs:
- GPT Image 2.5 Flare: ![Farmers market mobile app mockup — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/ui-farmers-market-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Farmers market mobile app mockup — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/ui-farmers-market-gpt-image-2-5-sunburst.webp)

#### Create scientific and educational visuals
Scientific and educational visuals are strong fits for biology, chemistry, classroom explanations, flat scientific icon systems, diagrams, and learning assets. Prompt them like an instructional design brief: define the audience, lesson objective, visual format, required labels, and scientific constraints. For best results, ask for a clean, flat visual system with consistent icon style, clear arrows, readable labels, and enough white space for students to scan the concept quickly.

When accuracy matters, list the required components explicitly and say what should not be included. Use `quality="high"` for dense labels, diagrams, or assets that will be used in slides or course materials.

Generation settings: `size="1536x1024"`, `quality="high"`.

```text
Create a simple biology diagram titled "Cellular Respiration at a Glance" for high school students.

Show how glucose turns into energy inside a cell. Include glycolysis, the Krebs cycle, and the electron transport chain.
Use arrows to connect the steps, and label the main molecules: glucose, pyruvate, ATP, NADH, FADH2, CO2, O2, and H2O.
Make it look like a clean classroom handout or slide, with a white background, simple icons, clear labels, and easy-to-read text.

Avoid tiny text, extra decoration, or anything that makes the diagram hard to understand.
```

Outputs:
- GPT Image 2.5 Flare: ![Classroom diagram of cellular respiration — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/scientific-educational-cellular-respiration-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Classroom diagram of cellular respiration — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/scientific-educational-cellular-respiration-gpt-image-2-5-sunburst.webp)

#### Build slides, diagrams, and charts
Productivity visuals work best when the prompt is written like an artifact spec rather than an illustration request. Name the exact deliverable (slide, workflow diagram, chart, page image), define the canvas and hierarchy, provide the real text or data, and describe the visual language. These prompts should include practical constraints: readable typography, polished spacing, no decorative clutter, and no generic stock-photo treatment.

For slides, charts, and diagram-heavy assets, include the numbers and labels directly in the prompt. Use a landscape size for deck-style outputs and `quality="high"` when the image contains small text, legends, axes, or footnotes.

The sample market figures and citations below are fictional design inputs. Replace them with verified data before using the slide.

Generation settings: `size="1536x864"`, `quality="high"`.

```text
Create one pitch-deck slide titled **"Market Opportunity"** that feels like a real Series A fundraising slide from a YC-backed startup.

Use a clean white background, modern sans-serif typography like Inter, and a crisp, minimal layout. The slide should include:

* A TAM/SAM/SOM concentric-circle diagram in muted blues and grays
* Specific, believable market sizing numbers:

  * **TAM:** $42B
  * **SAM:** $8.7B
  * **SOM:** $340M
* A clean bar chart below showing market growth from **2021 to 2026**, with a subtle upward trend
* Small footnotes: **"AGI Research, 2024"** and **"Internal analysis"**
* A company logo placeholder in the bottom-right corner

The design should look like it belongs in a deck that actually raised money: highly readable text, clear data hierarchy, polished spacing, and professional startup-style visual language.

Avoid clip art, stock photography, gradients, shadows, decorative elements, or anything that feels generic or overdesigned.
```

Outputs:
- GPT Image 2.5 Flare: ![Market opportunity slide with sample market sizing figures — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/market-opportunity-slide-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Market opportunity slide with sample market sizing figures — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/market-opportunity-slide-gpt-image-2-5-sunburst.webp)

### Edit images
Use `client.images.edit` with the referenced input images. For local edits that require a mask, see [editing with a mask](https://developers.openai.com/api/docs/guides/image-generation#edit-an-image-using-a-mask).

#### Translate while preserving layout
Use each model's coffee-machine diagram from [Explain a process visually](#explain-a-process-visually) as the input. Ask to replace its text while keeping the design unchanged, then check the translation and any words left in the original language.

Edit settings: `size="1024x1536"`, `quality="high"`.

```text
Translate the text in the infographic to Spanish. Do not change any other aspect of the image.
```

Outputs:
- GPT Image 2.5 Flare: ![Coffee machine diagram translated into Spanish — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/infographic-coffee-machine-sp-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Coffee machine diagram translated into Spanish — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/infographic-coffee-machine-sp-gpt-image-2-5-sunburst.webp)

#### Transfer a visual style
Assign the reference image a specific role: its palette, texture, or visual medium. Describe the new subject separately. Use the pixel-art image below as the input.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Use the same style from the input image and generate a man riding a motorcycle on a white background.
```

Input image: ![Pixel-art game screen used as a style reference](https://developers.openai.com/images/platform/guides/image-prompting/pixels.webp)

Outputs:
- GPT Image 2.5 Flare: ![Pixel-art motorcycle rider using the reference style — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/motorcycle-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Pixel-art motorcycle rider using the reference style — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/motorcycle-gpt-image-2-5-sunburst.webp)

#### Preserve identity and change clothing
Use the person photograph and three clothing references below as inputs. State which aspects of the person must remain fixed, and allow only the clothing to change. This pattern also applies to edits where a product or object must remain recognizable.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Edit the image to dress the woman using the provided clothing images. Do not change her face, facial features, skin tone, body shape, pose, or identity in any way. Preserve her exact likeness, expression, hairstyle, and proportions. Replace only the clothing, fitting the garments naturally to her existing pose and body geometry with realistic fabric behavior. Match lighting, shadows, and color temperature to the original photo so the outfit integrates photorealistically, without looking pasted on. Do not change the background, camera angle, framing, or image quality, and do not add accessories, text, logos, or watermarks.
```

Input images:
1. ![Woman in a museum used as the identity reference](https://developers.openai.com/images/platform/guides/image-prompting/woman-in-museum.webp)
2. ![Beige jacket used as a clothing reference](https://developers.openai.com/images/platform/guides/image-prompting/jacket.webp)
3. ![White tank top used as a clothing reference](https://developers.openai.com/images/platform/guides/image-prompting/tank-top.webp)
4. ![Gray boots used as a clothing reference](https://developers.openai.com/images/platform/guides/image-prompting/boots.webp)

Outputs:
- GPT Image 2.5 Flare: ![Woman wearing the supplied clothing items — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/outfit-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Woman wearing the supplied clothing items — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/outfit-gpt-image-2-5-sunburst.webp)

#### Combine references
Pass the scene photograph as image 1 and the dog photograph as image 2. Specify which element to move, its destination, and what must remain unchanged.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Place the dog from the second image into the setting of image 1, right next to the woman, use the same style of lighting, composition and background. Do not change anything else.
```

Input images:
1. ![Woman in a street scene, the first compositing input](https://developers.openai.com/images/platform/guides/image-prompting/test-woman.webp)
2. ![Woman with a dog, the second compositing input](https://developers.openai.com/images/platform/guides/image-prompting/test-woman-2.webp)

Outputs:
- GPT Image 2.5 Flare: ![Dog placed beside the woman in the street scene — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/test-woman-with-dog-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Dog placed beside the woman in the street scene — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/test-woman-with-dog-gpt-image-2-5-sunburst.webp)

Note for this system: this example relies on "image 1" / "the second image" labels. See the CORRECTION at the top — on `gpt-image-2` the order of `image[]` decides, not the labels.

#### Create a transparent product cutout
Request both an isolated subject in the prompt and `background="transparent"` in the API. Use PNG or WebP, preserve the returned alpha channel, and omit `output_compression` for PNG. A drawn checkerboard is not transparency. For subsequent edits, repeat the requirement to preserve the transparent background. Use the product photograph below as the input.

Edit settings: `size="1024x1536"`, `quality="medium"`, `background="transparent"`, `output_format="png"`.

```text
Extract the product from the input image and isolate it on a fully transparent background.
Output: centered product, crisp silhouette, no halos/fringing.
Preserve product geometry and label legibility exactly.
Add only light polishing. Do not add a solid backdrop, checkerboard, scenery, or shadow.
Do not restyle the product; remove the background and preserve clean alpha transparency.
```

Input image: ![Original shampoo product photograph](https://developers.openai.com/images/platform/guides/image-prompting/shampoo.webp)

Outputs:
- GPT Image 2.5 Flare: ![Isolated shampoo bottle from the original example — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/extract-product-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Isolated shampoo bottle from the original example — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/extract-product-gpt-image-2-5-sunburst.webp)

#### Turn a drawing into a realistic image
Sketch-to-render workflows are great for turning rough drawings into photorealistic concepts while keeping the original intent. Treat the prompt like a spec: preserve layout and perspective, then _add realism_ by specifying plausible materials, lighting, and environment. Include "do not add new elements/text" to avoid creative reinterpretations.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Turn this drawing into a photorealistic image.
Preserve the exact layout, proportions, and perspective.
Choose realistic materials and lighting consistent with the sketch intent.
Do not add new elements or text.
```

Input image: ![Line drawing of a river valley](https://developers.openai.com/images/platform/guides/image-prompting/drawings.webp)

Outputs:
- GPT Image 2.5 Flare: ![Photorealistic river valley rendered from the drawing — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/realistic-valley-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Photorealistic river valley rendered from the drawing — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/realistic-valley-gpt-image-2-5-sunburst.webp)

#### Remove an object
Remove one object by naming it explicitly and preserving everything around it. Keep the person, pose, lighting, and composition unchanged so the edit stays local.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Remove the flower from man's hand. Do not change anything else.
```

Input image: ![Man holding a flower and wearing a blue cap](https://developers.openai.com/images/platform/guides/image-prompting/man-with-blue-hat.webp)

Outputs:
- GPT Image 2.5 Flare: ![Same man after the flower has been removed — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/man-with-no-flower-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Same man after the flower has been removed — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/man-with-no-flower-gpt-image-2-5-sunburst.webp)

#### Insert a person into a scene
Insert a person into a new scene while preserving their identity. Specify natural lighting, believable detail, body framing, gaze, and interaction with the scene. State which facial features and proportions must remain unchanged. For `gpt-image-2`, omit `input_fidelity`; image inputs are always processed at high fidelity.

Use the [woman in the museum](https://developers.openai.com/images/platform/guides/image-prompting/woman-in-museum.webp) as the input image.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Generate a highly realistic action scene where this person is running away from a large, realistic brown bear attacking a campsite. The image should look like a real photograph someone could have taken, not an overly enhanced or cinematic movie-poster image.
She is centered in the image but looking away from the camera, wearing outdoorsy camping attire, with dirt on her face and tears in her clothing. She is clearly afraid but focused on escaping, running away from the bear as it destroys the campsite behind her.
The campsite is in Yosemite National Park, with believable natural details. The time of day is dusk, with natural lighting and realistic colors. Everything should feel grounded, authentic, and unstyled, as if captured in a real moment. Avoid cinematic lighting, dramatic color grading, or stylized composition.
```

Outputs:
- GPT Image 2.5 Flare: ![Woman running from a bear in a campsite scene — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/scene-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Woman running from a bear in a campsite scene — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/scene-gpt-image-2-5-sunburst.webp)

### Refine an image across turns
Start with one output, inspect it, and use it as the next input. Keep each follow-up narrow so you can see which change helped.

#### Create the starting image
Use the shampoo photograph from [Create a transparent product cutout](#create-a-transparent-product-cutout) as the input for this billboard scene. Quote the label text exactly.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a realistic billboard mockup of the shampoo on a highway scene during sunset.
Billboard text (EXACT, verbatim, no extra characters):
"Fresh and clean"
Typography: bold sans-serif, high contrast, centered, clean kerning.
Ensure text appears once and is perfectly legible.
No watermarks, no logos.
```

Input image: ![Original shampoo product photograph](https://developers.openai.com/images/platform/guides/image-prompting/shampoo.webp)

Outputs:
- GPT Image 2.5 Flare: ![Shampoo billboard at sunset — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/billboard-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Shampoo billboard at sunset — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/billboard-gpt-image-2-5-sunburst.webp)

#### Change one condition
Pass each model's billboard output from the previous step into its next edit request. This short follow-up changes the weather while retaining the existing scene.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Make it look like a winter evening with snowfall.
```

Outputs:
- GPT Image 2.5 Flare: ![Shampoo billboard in a snowy evening scene — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/billboard-winter-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Shampoo billboard in a snowy evening scene — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/billboard-winter-gpt-image-2-5-sunburst.webp)

#### Keep a character consistent
For a book with multiple illustrations, create a reusable character reference to help preserve appearance across scenes, poses, and pages. Change the environment and story while repeating the character’s defining details.

##### Establish the character
Define the character’s appearance, proportions, outfit, and tone.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a children’s book illustration introducing a main character.

Character:
A young, storybook-style hero inspired by a little forest outlaw,
wearing a simple green hooded tunic, soft brown boots, and a small belt pouch.
The character has a kind expression, gentle eyes, and a brave but warm demeanor.
Carries a small wooden bow used only for helping, never harming.

Theme:
The character protects and rescues small forest animals like squirrels, birds, and rabbits.

Style:
Children’s book illustration, hand-painted watercolor look,
soft outlines, warm earthy colors, whimsical and friendly.
Proportions suitable for picture books (slightly oversized head, expressive face).

Constraints:
- Original character (no copyrighted characters)
- No text
- No watermarks
- Plain forest background to clearly showcase the character
```

Outputs:
- GPT Image 2.5 Flare: ![Forest hero introducing a children's book character — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/childrens-book-illustration-1-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Forest hero introducing a children's book character — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/childrens-book-illustration-1-gpt-image-2-5-sunburst.webp)

##### Continue the story
Reuse each model's generated character image and describe a new scene. Repeat the appearance constraints so the character stays consistent.

Edit settings: `size="1024x1536"`, `quality="medium"`.

```text
Continue the children’s book story using the same character.

Scene:
The same young forest hero is gently helping a frightened squirrel
out of a fallen tree after a winter storm.
The character kneels beside the squirrel, offering reassurance.

Character Consistency:
- Same green hooded tunic
- Same facial features, proportions, and color palette
- Same gentle, heroic personality

Style:
Children’s book watercolor illustration,
soft lighting, snowy forest environment,
warm and comforting mood.

Constraints:
- Do not redesign the character
- No text
- No watermarks
```

Outputs:
- GPT Image 2.5 Flare: ![Same forest hero helping a squirrel in a winter scene — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/childrens-book-illustration-2-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Same forest hero helping a squirrel in a winter scene — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/childrens-book-illustration-2-gpt-image-2-5-sunburst.webp)

### More workflows

#### Change furniture in a room
Visualize furniture or décor changes in real spaces without recreating the entire scene. The goal is surgical realism: swap a single object while preserving camera angle, lighting, shadows, and surrounding context so the edit looks like a real photograph, not a redesign.

Edit settings: `size="1536x1024"`, `quality="medium"`.

```text
In this room photo, replace ONLY the white chairs with chairs made of wood.
Preserve camera angle, room lighting, floor shadows, and surrounding objects.
Keep all other aspects of the image unchanged.
Photorealistic contact shadows and fabric texture.
```

Input image: ![Original kitchen with white chairs](https://developers.openai.com/images/platform/guides/image-prompting/kitchen.webp)

Outputs:
- GPT Image 2.5 Flare: ![Kitchen with replacement wooden chairs — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/kitchen-chairs-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Kitchen with replacement wooden chairs — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/kitchen-chairs-gpt-image-2-5-sunburst.webp)

#### Design a holiday card
For seasonal card concepts, describe the scene, emotional tone, materials, lighting, and exact copy. For a 3D pop-up or photographed-card treatment, specify paper layers, fibers, folds, and soft studio lighting. The example below uses a nostalgic teddy-bear scene.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a Christmas holiday card illustration.

Scene:
a cozy Christmas scene with an old teddy bear sitting inside a keepsake box, slightly worn fur, soft stitching repairs, placed near a window with falling snow outside. The scene suggests the child has grown up, but the memories remain.

Mood:
Warm, nostalgic, gentle, emotional.

Style:
Premium holiday card photography, soft cinematic lighting,
realistic textures, shallow depth of field,
tasteful bokeh lights, high print-quality composition.

Constraints:
- Original artwork only
- No trademarks
- No watermarks
- No logos

Include ONLY this card text (verbatim):
"Merry Christmas — some memories never fade."
```

Outputs:
- GPT Image 2.5 Flare: ![Holiday card showing a teddy bear by a window — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/christmas-holiday-card-teddy-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Holiday card showing a teddy bear by a window — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/christmas-holiday-card-teddy-gpt-image-2-5-sunburst.webp)

#### Design collectible merchandise
Explore merchandise and packaging concepts using product photography cues: materials, packaging, and print clarity. Keep designs original and non-infringing, and compare multiple character or packaging variants.

Generation settings: `size="1024x1536"`, `quality="medium"`.

```text
Create a collectible action figure of a vintage-style toy propeller airplane with rounded wings, a front-mounted spinning propeller, slightly worn paint edges, classic childhood proportions, designed as a nostalgic holiday collectible, in blister packaging.

Concept:
A nostalgic holiday collectible inspired by the simple toy airplanes
children used to play with during winter holidays.
Evokes warmth, imagination, and childhood wonder.

Style:
Premium toy photography, realistic plastic and painted metal textures,
studio lighting, shallow depth of field,
sharp label printing, high-end retail presentation.

Constraints:
- Original design only
- No trademarks
- No watermarks
- No logos

Include ONLY this packaging text (verbatim):
"Christmas Memories Edition"
```

Outputs:
- GPT Image 2.5 Flare: ![Collectible toy airplane in holiday packaging — GPT Image 2.5 Flare](https://developers.openai.com/images/platform/guides/image-prompting/christmas-collectible-toy-airplane-gpt-image-2-5-flare.webp)
- GPT Image 2.5 Sunburst: ![Collectible toy airplane in holiday packaging — GPT Image 2.5 Sunburst](https://developers.openai.com/images/platform/guides/image-prompting/christmas-collectible-toy-airplane-gpt-image-2-5-sunburst.webp)

## Run a complete example
This runnable example remains pinned to `gpt-image-2`. Use it as a baseline, then choose an available model and its supported request settings for your evaluation.

The examples below generate four logo variations and extract a product onto a transparent background. Install the [OpenAI SDK](https://developers.openai.com/api/docs/libraries#install-an-official-sdk) with `pip install openai` for Python or `gem install openai` for Ruby. Set `OPENAI_API_KEY` and save the [product photograph](https://developers.openai.com/images/platform/guides/image-prompting/shampoo.webp) as `input_images/shampoo.webp`. Live requests incur API usage charges.

### View the complete example
Generate and edit transparent assets:

```python
import base64
from pathlib import Path

from openai import OpenAI

client = OpenAI()


prompt = """
Create an original, non-infringing logo for a company called Field & Flour, a local bakery.
The logo should feel warm, simple, and timeless. Use clean, vector-like shapes, a strong silhouette, and balanced negative space.
Favor simplicity over detail so it reads clearly at small and large sizes. Flat design, minimal strokes, no gradients unless essential.
Fully transparent background. Deliver a single centered logo with generous padding, clean alpha edges, and no solid backdrop, scenery, checkerboard, or watermark.
"""

result = client.images.generate(
    model="gpt-image-2",
    prompt=prompt,
    size="1024x1536",
    quality="medium",
    background="transparent",
    output_format="png",
    n=4,  # Generate 4 versions of the logo
)

# Preserve the returned PNG bytes, including the alpha channel.
for index, item in enumerate(result.data, start=1):
    Path(f"logo-generation-{index}-gpt-image-2.png").write_bytes(
        base64.b64decode(item.b64_json)
    )

# Extract a product from a reference image.
prompt = """
Extract the product from the input image and isolate it on a fully transparent background.
Output: centered product, crisp silhouette, no halos/fringing.
Preserve product geometry and label legibility exactly.
Add only light polishing. Do not add a solid backdrop, checkerboard, scenery, or shadow.
Do not restyle the product; remove the background and preserve clean alpha transparency.
"""

result = client.images.edit(
    model="gpt-image-2",
    image=[
        Path("input_images/shampoo.webp"),
    ],
    prompt=prompt,
    size="1024x1536",
    quality="medium",
    background="transparent",
    output_format="png",
)

Path("extract-product-gpt-image-2.png").write_bytes(
    base64.b64decode(result.data[0].b64_json)
)
```

```ruby
require "base64"
require "openai"
require "pathname"

client = OpenAI::Client.new
result = client.images.generate(
  model: "gpt-image-2",
  prompt: "Create an original logo for Field & Flour, a local bakery. Use warm, simple shapes on a fully transparent background, with clean alpha edges and no shadow or checkerboard.",
  size: "1024x1536", quality: :medium, background: :transparent, output_format: :png, n: 4
)
Array(result.data).each_with_index do |item, index|
  File.binwrite("logo-generation-#{index + 1}-gpt-image-2.png", Base64.strict_decode64(item.b64_json || raise("No PNG returned")))
end
result = client.images.edit(
  model: "gpt-image-2", image: OpenAI::FilePart.new(Pathname("input_images/shampoo.webp"), content_type: "image/webp"),
  prompt: "Extract the product onto a fully transparent background. Preserve its geometry and label, with clean edges and no shadow or restyling.",
  size: "1024x1536", quality: :medium, background: :transparent, output_format: :png
)
File.binwrite("extract-product-gpt-image-2.png", Base64.strict_decode64(Array(result.data).fetch(0).b64_json || raise("No PNG returned")))
```

For additional prompts and complete workflows, see the [original notebook](https://github.com/openai/openai-cookbook/blob/d310dfa05d20fb653caa9c1c4b89ac1a4aeeeae4/examples/multimodal/image-gen-models-prompting-guide.ipynb).

## Check the result
Check the output against the requirements before using it:
- Is required text accurate and legible? Are diagram labels and relationships correct?
- Do identities, product shapes, labels, and reference details remain intact?
- Did the edit change only what you requested?
- If transparency is required, does the file contain an alpha channel rather than a painted background?

Compare quality, latency, and cost on representative inputs when changing prompts or models. See [image generation pricing](https://developers.openai.com/api/docs/pricing#image-generation) for current costs.

## Older model reference
The guide keeps one reference tab per older model; each is copied below verbatim.

### GPT Image 2
Overview and request settings for existing GPT Image 2 workflows.

GPT Image 2 supports image generation and editing, including text rendering, reference-based edits, and flexible output sizes. Use this reference to maintain existing integrations. The [prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?model=gpt-image-2.5) covers shared techniques for composition, text, reference images, and preserving details during edits. Its illustrated examples use GPT Image 2.5 Flare and GPT Image 2.5 Sunburst; outputs can differ across models. For migration, use the guide's [model selection](https://developers.openai.com/api/docs/guides/image-prompting?model=gpt-image-2.5#choose-a-model) and [evaluation workflow](https://developers.openai.com/api/docs/guides/image-prompting?model=gpt-image-2.5#migrate-an-existing-workflow).

Use `client.images.generate` for generation and `client.images.edit` for edits. See the [image generation guide](https://developers.openai.com/api/docs/guides/image-generation) for API setup and request examples.

| Parameter | GPT Image 2 |
| --- | --- |
| `model` | `gpt-image-2` |
| `quality` | `low`, `medium`, `high`, or `auto` |
| `size` | `auto` or a supported resolution; see [size constraints](https://developers.openai.com/api/docs/guides/image-generation#size-and-quality-options) |
| `input_fidelity` | Omit it. Image inputs are always processed at high fidelity. |
| `output_format` | `png`, `jpeg`, or `webp` |
| `background` | For transparent output, explicitly set `transparent` and use PNG or WebP. |
| `output_compression` | Use only for JPEG or WebP output, not PNG. |

Transparent backgrounds are available in preview for `gpt-image-2`. For the original prompts, inputs, and runnable workflows, see the pinned [GPT Image 2 notebook](https://github.com/openai/openai-cookbook/blob/d310dfa05d20fb653caa9c1c4b89ac1a4aeeeae4/examples/multimodal/image-gen-models-prompting-guide.ipynb).

### GPT Image 1.5
Overview and request settings for existing GPT Image 1.5 workflows.

**Deprecated model.** `gpt-image-1.5` is scheduled to shut down on December 1, 2026. See the [deprecation notice](https://developers.openai.com/api/docs/deprecations#2026-06-02-gpt-image-model-deprecations) and validate existing workflows with `gpt-image-2` before migrating.

GPT Image 1.5 supports image generation and editing, including text rendering, photorealistic images, and reference-based edits. Use this reference to maintain existing integrations. The [prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?model=gpt-image-2.5) covers shared techniques for composition, text, reference images, and preserving details during edits. Test those techniques with your model and inputs; outputs can differ across models.

Use `client.images.generate` for generation and `client.images.edit` for edits. See the [image generation guide](https://developers.openai.com/api/docs/guides/image-generation) for API setup and request examples.

| Parameter | GPT Image 1.5 |
| --- | --- |
| `model` | `gpt-image-1.5` |
| `quality` | `low`, `medium`, `high`, or `auto` |
| `size` | `1024x1024`, `1024x1536`, `1536x1024`, or `auto` |
| `output_format` | `png`, `jpeg`, or `webp` |
| `output_compression` | 0 to 100, for JPEG or WebP output only |
| `background` | Set `transparent` explicitly for transparent output; use PNG or WebP |
| `input_fidelity` | `low` or `high`; `high` preserves input details, while `quality` controls output generation. Omit this parameter when migrating to GPT Image 2, which always uses high input fidelity. |

### GPT Image 1
Overview and request settings for existing GPT Image 1 workflows.

**Deprecated model.** `gpt-image-1` is scheduled to shut down on October 23, 2026. See the [deprecation notice](https://developers.openai.com/api/docs/deprecations#2026-04-22-legacy-gpt-model-snapshots) and validate existing workflows with `gpt-image-2` before migrating.

GPT Image 1 supports image generation and editing with reference images and masks. Use this reference to maintain existing integrations. For shared techniques such as describing a scene, preserving details, and refining an edit, see the [prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?model=gpt-image-2.5).

Use `client.images.generate` for generation and `client.images.edit` for edits. See the [image generation guide](https://developers.openai.com/api/docs/guides/image-generation) for API setup and request examples.

| Parameter | GPT Image 1 |
| --- | --- |
| `model` | `gpt-image-1` |
| `quality` | `low`, `medium`, `high`, or `auto` |
| `size` | `1024x1024`, `1024x1536`, `1536x1024`, or `auto` |
| `output_format` | `png`, `jpeg`, or `webp` |
| `output_compression` | 0 to 100, for JPEG or WebP output only |
| `background` | Set `transparent` explicitly for transparent output; use PNG or WebP |
| `input_fidelity` | `low` or `high`; `high` preserves input details, while `quality` controls output generation. High input fidelity uses more image input tokens. Omit this parameter when migrating to GPT Image 2, which always uses high input fidelity. |

## Where to find copy/paste recipes
For the skill's own copy/paste prompt specs, see `references/sample-prompts.md`. The OpenAI examples are in [Official examples](#official-examples-openai-guide-2026-10-02) above; the source page is the [OpenAI image prompting guide](https://developers.openai.com/api/docs/guides/image-prompting?site_locale=en).
