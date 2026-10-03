# CLI reference (`scripts/image_gen.py`)

> **UPDATED 2026-10-02.** Model, parameter and transparency guidance now follows the
> [OpenAI API reference — Images](https://developers.openai.com/api/reference/resources/images) (copied in
> `references/image-api.md`) and the [image generation guide](https://developers.openai.com/api/docs/guides/image-generation).
>
> Note for this system: `scripts/image_gen.py` belongs to the upstream `imagegen` skill and is **not part of this
> repository**. Its flags, defaults, validation and output behavior below are kept from the upstream skill text and are
> **unverified here** — in particular whether the script accepts the GPT Image 2.5 model ids and the `xhigh`/`max`
> quality values. Where the script and the API disagree, the API reference is authoritative. This repository calls the
> Image API from Laravel instead (`App\Services\Video\OpenAiImageClient`, mapped in `references/image-api.md`).
>
> The official OpenAI CLI (`openai images generate|edit`) and raw HTTP equivalents are listed in
> [Official OpenAI CLI and HTTP equivalents](#official-openai-cli-and-http-equivalents).

This file is for the fallback CLI mode only. Read it when the user explicitly asks to use `scripts/image_gen.py` / CLI / API / model controls.

`generate-batch` is a CLI subcommand in this fallback path. It is not a top-level mode of the skill.
The word `batch` in a user request is not CLI opt-in by itself.

## Contents
- [What this CLI does](#what-this-cli-does)
- [Quick start (works from any repo)](#quick-start-works-from-any-repo)
- [Quick start](#quick-start)
- [Guardrails](#guardrails)
- [Defaults](#defaults)
- [Model, size and quality guidance](#model-size-and-quality-guidance)
- [Transparent output](#transparent-output)
- [Quality, input fidelity, and masks (CLI fallback only)](#quality-input-fidelity-and-masks-cli-fallback-only)
- [Output handling](#output-handling)
- [Common recipes](#common-recipes)
- [Official OpenAI CLI and HTTP equivalents](#official-openai-cli-and-http-equivalents)
- [CLI notes](#cli-notes)
- [See also](#see-also)

## What this CLI does
- `generate`: generate a new image from a prompt
- `edit`: edit one or more existing images
- `generate-batch`: run many generation jobs from a JSONL file after the user explicitly chooses CLI/API/model controls

Real API calls require **network access** + `OPENAI_API_KEY`. `--dry-run` does not.

## Quick start (works from any repo)
Set a stable path to the skill CLI (default `CODEX_HOME` is `~/.codex`):

```
export CODEX_HOME="${CODEX_HOME:-$HOME/.codex}"
export IMAGE_GEN="$CODEX_HOME/skills/.system/imagegen/scripts/image_gen.py"
```

Install dependencies into that environment with its package manager. In uv-managed environments, `uv pip install ...` remains the preferred path.

## Quick start

Dry-run (no API call; no network required; does not require the `openai` package):

```bash
python "$IMAGE_GEN" generate \
  --prompt "Test" \
  --out output/imagegen/test.png \
  --dry-run
```

Notes:
- One-off dry-runs print the API payload and the computed output path(s). Use a dry-run to confirm that the script passes a GPT Image 2.5 model id and the requested quality before a paid call.
- Repo-local finals should live under `output/imagegen/`.

Generate (requires `OPENAI_API_KEY` + network):

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-flare \
  --prompt "A cozy alpine cabin at dawn" \
  --size 1024x1024 \
  --out output/imagegen/alpine-cabin.png
```

Edit:

```bash
python "$IMAGE_GEN" edit \
  --model gpt-image-2.5-sunburst \
  --image input.png \
  --prompt "Replace only the background with a warm sunset" \
  --out output/imagegen/sunset-edit.png
```

## Guardrails
- Use the bundled CLI directly (`python "$IMAGE_GEN" ...`) after activating the correct environment.
- Do **not** create one-off runners (for example `gen_images.py`) unless the user explicitly asks for a custom wrapper.
- **Never modify** `scripts/image_gen.py`. If something is missing, ask the user before doing anything else.
- Pass `--model` explicitly. Use `gpt-image-2.5-flare` (speed) or `gpt-image-2.5-sunburst` (quality, editing precision) as described in `SKILL.md` → *Choose a model*; ask before using any other model unless the user requested it.
- Do not move a workflow to `gpt-image-1.5` or `gpt-image-1`: both are deprecated (`gpt-image-1.5` shuts down on December 1, 2026; `gpt-image-1` on October 23, 2026). A transparent background no longer requires `gpt-image-1.5`.

## Defaults
Upstream script defaults (unverified in this repository):
- Model: `gpt-image-2` — pass `--model gpt-image-2.5-flare` or `--model gpt-image-2.5-sunburst` instead
- Supported model family for this CLI: GPT Image models (`gpt-image-*`)
- Size: `auto`
- Quality: `medium`
- Output format: `png`
- Default one-off output path: `output/imagegen/output.png`
- Background: unspecified unless `--background` is set

API defaults when a parameter is omitted (from the API reference):
- `POST /images/generations`: `model` must be specified explicitly; `quality` `auto`; `background` `auto`; `moderation` `auto`; `n` 1; `output_compression` 100.
- `POST /images/edits`: `model` defaults to `gpt-image-2.5-sunburst`; `quality` `auto`; `input_fidelity` defaults to `low` on the models that support it.

## Model, size and quality guidance

| Model | `--quality` | Custom `--size` | `--background transparent` | `--input-fidelity` |
| --- | --- | --- | --- | --- |
| `gpt-image-2.5-flare`, `gpt-image-2.5-sunburst` | `low`, `medium`, `high`, `xhigh`, `max`, `auto` | yes | yes | not listed in the API reference; do not pass |
| `gpt-image-2` | `low`, `medium`, `high`, `auto` | yes | preview | do not pass (always high fidelity) |
| `gpt-image-1.5`, `gpt-image-1` (deprecated) | `low`, `medium`, `high`, `auto` | no | yes | `low`, `high` |
| `gpt-image-1-mini` | `low`, `medium`, `high`, `auto` | no | yes | `low` only |

- Use `--quality low` for fast drafts, thumbnails, and quick iterations.
- For final assets, compare higher quality settings to find the right balance of detail, latency, and cost. Choose the model before tuning quality; the same quality label does not imply the same image quality or response time across models.
- Use `xhigh` or `max` (2.5 models only) only when they improve an unmet quality requirement within the latency budget.
- Square images are typically fastest. Use `--size 1024x1024` for quick square drafts.
- If the user asks for 4K-style output, use `--size 3840x2160` for landscape or `--size 2160x3840` for portrait.

Popular sizes:
- `1024x1024`
- `1536x1024`
- `1024x1536`
- `2048x2048`
- `2048x1152`
- `3840x2160`
- `2160x3840`
- `auto`

Size constraints for `gpt-image-2` and both 2.5 models:
- width and height divisible by `16px`
- aspect ratio between `1:3` and `3:1`
- maximum supported resolution `3840x2160`; max edge `<= 3840px`
- total pixels between `655,360` and `8,294,400`
- resolutions above `2560x1440` are experimental
- the requested size must also satisfy the model's current pixel and edge limits

Older GPT Image models (`gpt-image-1.5`, `gpt-image-1`, `gpt-image-1-mini`) accept only `1024x1024`, `1536x1024`, `1024x1536`, or `auto`.

Fast draft:

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-flare \
  --prompt "A product thumbnail of a matte ceramic mug on a stone surface" \
  --quality low \
  --size 1024x1024 \
  --out output/imagegen/mug-draft.png
```

Final 2K landscape:

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-sunburst \
  --prompt "A polished landing-page hero image of a matte ceramic mug on a stone surface" \
  --quality high \
  --size 2048x1152 \
  --out output/imagegen/mug-hero.png
```

4K landscape (experimental above `2560x1440`):

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-sunburst \
  --prompt "A detailed architectural visualization at golden hour" \
  --size 3840x2160 \
  --quality high \
  --out output/imagegen/architecture-4k.png
```

## Transparent output

Both GPT Image 2.5 models support `background=transparent`; for `gpt-image-2` it is in preview. Use `png` or `webp` output, request an isolated subject in the prompt, and check the decoded alpha channel (hair, glass, shadows, object edges). A drawn checkerboard is not transparency.

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-flare \
  --prompt "A clean product cutout on a fully transparent background, centered, crisp silhouette, clean alpha edges" \
  --background transparent \
  --output-format png \
  --out output/imagegen/product-cutout.png
```

The old guidance — that CLI `gpt-image-2` cannot produce transparent output, so a confirmed `gpt-image-1.5` fallback or a chroma-key extraction script is required — is obsolete.

## Quality, input fidelity, and masks (CLI fallback only)
These are explicit CLI controls. They are not built-in `image_gen` tool arguments.

- `--quality` works for `generate`, `edit`, and `generate-batch`. The API accepts `low|medium|high|auto` for every GPT image model and also `xhigh|max` for the 2.5 models; the upstream script validated `low|medium|high|auto` and may reject `xhigh`/`max` (unverified).
- `--input-fidelity` is **edit-only** and validated as `low|high`. API reference: `high` and `low` on `gpt-image-1` and `gpt-image-1.5`; `gpt-image-1-mini` supports only `low`; omit it for `gpt-image-2`; the 2.5 models are not listed, so do not pass it. High input fidelity uses more image input tokens.
- `--mask` is **edit-only**

Example (deprecated model, kept to show `--input-fidelity`; it applies only to `gpt-image-1`/`gpt-image-1.5`):

```bash
python "$IMAGE_GEN" edit \
  --model gpt-image-1.5 \
  --image input.png \
  --prompt "Change only the background" \
  --quality high \
  --input-fidelity high \
  --out output/imagegen/background-edit.png
```

Current-model equivalent (no `--input-fidelity`):

```bash
python "$IMAGE_GEN" edit \
  --model gpt-image-2.5-sunburst \
  --image input.png \
  --prompt "Change only the background; keep the subject, its edges and the camera angle unchanged" \
  --quality high \
  --out output/imagegen/background-edit.png
```

Mask notes:
- For multi-image edits, pass repeated `--image` flags. Their order is meaningful. Note for this system: on `gpt-image-2` the image order decides and index labels in the prompt were measured to have no effect (see the CORRECTION in `references/prompting.md`); describe each image by role, and keep the order deliberate.
- The CLI accepts a single `--mask`.
- Image and mask must be the same size and format and each under 50MB.
- Masks must include an alpha channel.
- If multiple input images are provided, the mask applies to the first image.
- Masking is prompt-guided; do not promise exact pixel-perfect mask boundaries.
- Use a PNG mask when possible; the script treats mask handling as best-effort and does not perform full preflight validation beyond file checks/warnings.
- In the edit prompt, repeat invariants (`change only the background; keep the subject unchanged`) to reduce drift.

## Output handling
- Use `tmp/imagegen/` for temporary JSONL inputs or scratch files.
- Use `output/imagegen/` for final outputs.
- Reruns fail if a target file already exists unless you pass `--force`.
- `--out-dir` changes one-off naming to `image_1.<ext>`, `image_2.<ext>`, and so on.
- Downscaled copies use the default suffix `-web` unless you override it.

## Common recipes

Generate with augmentation fields:

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-flare \
  --prompt "A minimal hero image of a ceramic coffee mug" \
  --use-case "product-mockup" \
  --style "clean product photography" \
  --composition "wide product shot with usable negative space for page copy" \
  --constraints "no logos, no text" \
  --out output/imagegen/mug-hero.png
```

Generate + also write a downscaled copy for fast web loading:

```bash
python "$IMAGE_GEN" generate \
  --model gpt-image-2.5-flare \
  --prompt "A cozy alpine cabin at dawn" \
  --size 1024x1024 \
  --downscale-max-dim 1024 \
  --out output/imagegen/alpine-cabin.png
```

Generate multiple prompts concurrently (async batch):

```bash
mkdir -p tmp/imagegen output/imagegen/batch
cat > tmp/imagegen/prompts.jsonl << 'EOF'
{"prompt":"Cavernous hangar interior with a compact shuttle parked near the center","use_case":"stylized-concept","composition":"wide-angle, low-angle","lighting":"volumetric light rays through drifting fog","constraints":"no logos or trademarks; no watermark","size":"1536x1024","model":"gpt-image-2.5-flare"}
{"prompt":"Gray wolf in profile in a snowy forest","use_case":"photorealistic-natural","composition":"eye-level","constraints":"no logos or trademarks; no watermark","size":"1024x1024","model":"gpt-image-2.5-flare"}
EOF

python "$IMAGE_GEN" generate-batch \
  --input tmp/imagegen/prompts.jsonl \
  --out-dir output/imagegen/batch \
  --concurrency 5

rm -f tmp/imagegen/prompts.jsonl
```

Notes:
- `generate-batch` requires `--out-dir`.
- Use `--concurrency` to control parallelism (default `5`).
- Per-job overrides are supported in JSONL (for example `size`, `quality`, `background`, `output_format`, `output_compression`, `moderation`, `n`, `model`, `out`, and prompt-augmentation fields).
- `--n` generates multiple variants for a single prompt (API range 1–10); `generate-batch` is for many different prompts.
- In batch mode, per-job `out` is treated as a filename under `--out-dir`.
- For many requested deliverable assets, provide one prompt/job per distinct asset and use semantic filenames when possible.

## Official OpenAI CLI and HTTP equivalents

The [image generation guide](https://developers.openai.com/api/docs/guides/image-generation) shows the official OpenAI CLI (`openai images …`) next to raw `curl`. These are copied verbatim from the guide; the API reference examples are in `references/image-api.md`.

Generate an image:

```bash
curl -X POST "https://api.openai.com/v1/images/generations" \
    -H "Authorization: Bearer $OPENAI_API_KEY" \
    -H "Content-type: application/json" \
    -d '{
        "model": "gpt-image-2.5-sunburst",
        "prompt": "A children'\''s book drawing of a veterinarian using a stethoscope to listen to the heartbeat of a baby otter."
    }' | jq -r '.data[0].b64_json' | base64 --decode > otter.png
```

```bash
openai images generate \
  --model gpt-image-2.5-sunburst \
  --prompt "A children's book drawing of a veterinarian using a stethoscope to listen to the heartbeat of a baby otter." \
  --raw-output \
  --transform 'data.0.b64_json' | base64 --decode > otter.png
```

Create a new image using image references:

```bash
curl -s -D >(grep -i x-request-id >&2) \
  -o >(jq -r '.data[0].b64_json' | base64 --decode > gift-basket.png) \
  -X POST "https://api.openai.com/v1/images/edits" \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -F "model=gpt-image-2.5-sunburst" \
  -F "image[]=@body-lotion.png" \
  -F "image[]=@bath-bomb.png" \
  -F "image[]=@incense-kit.png" \
  -F "image[]=@soap.png" \
  -F 'prompt=Generate a photorealistic image of a gift basket on a white background labeled "Relax & Unwind" with a ribbon and handwriting-like font, containing all the items in the reference pictures'
```

```bash
openai images edit \
  --model gpt-image-2.5-sunburst \
  --image body-lotion.png \
  --image bath-bomb.png \
  --image incense-kit.png \
  --image soap.png \
  --prompt 'Generate a photorealistic image of a gift basket on a white background labeled "Relax & Unwind" with a ribbon and handwriting-like font, containing all the items in the reference pictures' \
  --raw-output \
  --transform 'data.0.b64_json' | base64 --decode > gift-basket.png
```

Edit an image using a mask:

```bash
curl -s -D >(grep -i x-request-id >&2) \
  -o >(jq -r '.data[0].b64_json' | base64 --decode > lounge.png) \
  -X POST "https://api.openai.com/v1/images/edits" \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -F "model=gpt-image-2.5-sunburst" \
  -F "mask=@mask.png" \
  -F "image[]=@sunlit_lounge.png" \
  -F 'prompt=A sunlit indoor lounge area with a pool containing a flamingo'
```

```bash
openai images edit \
  --model gpt-image-2.5-sunburst \
  --image sunlit_lounge.png \
  --mask mask.png \
  --prompt "A sunlit indoor lounge area with a pool containing a flamingo" \
  --raw-output \
  --transform 'data.0.b64_json' | base64 --decode > out.png
```

## CLI notes
- Supported sizes depend on the model. `gpt-image-2` and both 2.5 models support flexible constrained sizes; older GPT Image models support `1024x1024`, `1536x1024`, `1024x1536`, or `auto`.
- Transparent outputs require `output_format` to be `png` or `webp`. They are supported by both 2.5 models and in preview for `gpt-image-2`.
- `--prompt-file`, `--output-compression`, `--moderation`, `--max-attempts`, `--fail-fast`, `--force`, and `--no-augment` are supported by the upstream script (unverified here).
- This CLI is intended for GPT Image models. Do not assume older non-GPT image-model behavior applies here; `response_format` and `style` are legacy parameters that GPT image models do not support.

## See also
- API reference for the Images endpoints and the Laravel mapping: `references/image-api.md`
- Prompt examples shared across both top-level modes: `references/sample-prompts.md`
- Official prompting guide and examples: `references/prompting.md`
- Network/sandbox notes for fallback CLI mode: `references/codex-network.md`
- Built-in-first transparent image workflow: `SKILL.md`
