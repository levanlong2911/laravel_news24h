# Sample prompts (copy/paste)

> **UPDATED 2026-10-02.** The prompt recipes of the `imagegen` skill are kept unchanged — prompt text does not depend on
> the model. What changed: the model notes follow the OpenAI API reference (`references/image-api.md`) and the
> GPT Image 2.5 guides; every recipe links to the matching official OpenAI example in `references/prompting.md` →
> *Official examples* (whose published Flare and Sunburst outputs show what the technique produces) together with the
> request settings that example used; and recipes for official workflows the skill had no recipe for are added, marked
> "Adapted from the official example". Official prompts are verbatim in `references/prompting.md`; the recipes here
> restate them in the labeled scaffolding.

These prompt recipes are shared across both top-level modes of the skill:
- built-in `image_gen` tool (default)
- `scripts/image_gen.py` CLI fallback for explicit or user-confirmed CLI/API/model requests

Use these as starting points. They are intentionally complete prompt recipes, not the default amount of augmentation to add to every user request.

When adapting a user's prompt:
- keep user-provided requirements
- only add detail according to the specificity policy in `SKILL.md`
- do not treat every example below as permission to invent extra story elements

The labeled lines are prompt scaffolding, not a closed schema. `Asset type` and `Input images` are prompt-only scaffolding; the CLI does not expose them as dedicated flags.

Execution details such as explicit CLI flags, `quality`, `input_fidelity`, masks, output formats, and local output paths depend on mode. Use built-in `image_gen` by default, request transparent backgrounds directly, and preserve the generated alpha; apply CLI-specific controls only when the user chooses or confirms that fallback.

"Request settings" lines below are API parameters (`size`, `quality`, `background`, `output_format`, `n`), set separately from the prompt — never paste them into the prompt text.

Model notes:
- Use `gpt-image-2.5-flare` (small model, optimized for speed, image quality comparable to GPT Image 2) or `gpt-image-2.5-sunburst` (base model, optimized for quality, best for editing precision). See `SKILL.md` → *Choose a model*.
- The 2.5 models accept `quality` `low`, `medium`, `high`, `xhigh`, `max`, `auto` (default `auto`); `gpt-image-2` accepts `low`, `medium`, `high`, `auto`.
- Custom sizes (`WIDTHxHEIGHT`, edges divisible by 16, aspect ratio 1:3–3:1, up to `3840x2160`, experimental above `2560x1440`) work on `gpt-image-2` and both 2.5 models. For 4K-style output use `3840x2160` or `2160x3840`.
- `background=transparent` (with `png` or `webp`) is supported by both 2.5 models and in preview for `gpt-image-2`. Switching to `gpt-image-1.5` for transparency is no longer needed; `gpt-image-1.5` shuts down on December 1, 2026.
- Do not set `input_fidelity` with `gpt-image-2` (always high fidelity) or with the 2.5 models (not listed in the API reference).
- Note for this system: recipes that label several input images by index (`Image 1`, `Image 2`) rely on labels that were measured to have no effect on `gpt-image-2`; the order of the images sent decides. See the CORRECTION in `references/prompting.md`.

For prompting principles (structure, specificity, invariants, iteration), see `references/prompting.md`.

## Generate

### photorealistic-natural
```
Use case: photorealistic-natural
Primary request: candid photo of an elderly sailor on a small fishing boat adjusting a net
Scene/backdrop: coastal water with soft haze
Subject: weathered skin with wrinkles and sun texture
Style/medium: photorealistic candid photo
Composition/framing: medium close-up, eye-level
Lighting/mood: soft coastal daylight, shallow depth of field, subtle film grain
Materials/textures: real skin texture, worn fabric, salt-worn wood
Constraints: natural color balance; no heavy retouching; no glamorization; no watermark
Avoid: studio polish; staged look
```
Official example: [Control style and lighting](prompting.md#control-style-and-lighting). Request settings: `size="1024x1536"`, `quality="medium"`.

### product-mockup
```
Use case: product-mockup
Primary request: premium product photo of a matte black shampoo bottle with a minimal label
Scene/backdrop: clean studio gradient from light gray to white
Subject: single bottle centered with subtle reflection
Style/medium: premium product photography
Composition/framing: centered, slight three-quarter angle, generous padding
Lighting/mood: softbox lighting, clean highlights, controlled shadows
Materials/textures: matte plastic, crisp label printing
Constraints: no logos or trademarks; no watermark
```
Related official examples: [Create a transparent product cutout](prompting.md#create-a-transparent-product-cutout), [Create the starting image](prompting.md#create-the-starting-image), [Design collectible merchandise](prompting.md#design-collectible-merchandise).

### product-mockup: collectible merchandise
Adapted from the official example [Design collectible merchandise](prompting.md#design-collectible-merchandise).
```
Use case: product-mockup
Primary request: collectible action figure of a vintage-style toy propeller airplane in blister packaging
Subject: rounded wings, a front-mounted spinning propeller, slightly worn paint edges, classic childhood proportions
Style/medium: premium toy photography; realistic plastic and painted metal textures; high-end retail presentation
Lighting/mood: studio lighting, shallow depth of field; nostalgic holiday warmth
Materials/textures: sharp label printing
Text (verbatim): "Christmas Memories Edition" (the only packaging text)
Constraints: original design only; no trademarks; no watermarks; no logos
```
Request settings: `size="1024x1536"`, `quality="medium"`.

### ui-mockup
```
Use case: ui-mockup
Primary request: mobile app home screen for a local farmers market with vendors and daily specials
Asset type: mobile app screen
Style/medium: realistic product UI, not concept art
Composition/framing: clean vertical mobile layout with clear hierarchy
Constraints: practical layout, clear typography, no logos or trademarks, no watermark
```
Official example: [Create an interface preview](prompting.md#create-an-interface-preview). Request settings: `size="1024x1536"`, `quality="medium"`.

### infographic-diagram
```
Use case: infographic-diagram
Primary request: detailed infographic of an automatic coffee machine flow
Scene/backdrop: clean, light neutral background
Subject: bean hopper -> grinder -> brew group -> boiler -> water tank -> drip tray
Style/medium: clean vector-like infographic with clear callouts and arrows
Composition/framing: vertical poster layout, top-to-bottom flow
Text (verbatim): "Bean Hopper", "Grinder", "Brew Group", "Boiler", "Water Tank", "Drip Tray"
Constraints: clear labels, strong contrast, no logos or trademarks, no watermark
```
Official example: [Explain a process visually](prompting.md#explain-a-process-visually). Request settings: `size="1024x1536"`, `quality="medium"`. Verify labels and factual relationships as well as appearance.

### scientific-educational
```
Use case: scientific-educational
Primary request: biology diagram titled "Cellular Respiration at a Glance" for high school students
Scene/backdrop: clean white classroom handout background
Subject: glucose turns into energy inside a cell; include glycolysis, Krebs cycle, and electron transport chain
Style/medium: flat scientific diagram with consistent icons, arrows, and readable labels
Composition/framing: landscape slide-style layout with clear hierarchy and generous whitespace
Text (verbatim): "Cellular Respiration at a Glance", "Glucose", "Pyruvate", "ATP", "NADH", "FADH2", "CO2", "O2", "H2O"
Constraints: scientifically plausible; avoid tiny text; no extra decoration; no watermark
```
Official example: [Create scientific and educational visuals](prompting.md#create-scientific-and-educational-visuals). Request settings: `size="1536x1024"`, `quality="high"`.

### logo-brand
```
Use case: logo-brand
Primary request: original logo for "Field & Flour", a local bakery
Style/medium: vector logo mark; flat colors; minimal
Composition/framing: single centered logo on a plain background with generous padding
Constraints: strong silhouette, balanced negative space; original design only; no gradients unless essential; no trademarks; no watermark
```
Official example: [Design a reusable logo](prompting.md#design-a-reusable-logo). Request settings: `size="1024x1536"`, `quality="medium"`, `background="transparent"`, `output_format="png"`, `n=1` (use `n` for variations). The official prompt asks for a fully transparent background with clean alpha edges instead of a plain background.

### illustration-story
```
Use case: illustration-story
Primary request: 4-panel comic about a pet left alone at home
Scene/backdrop: cozy living room across panels
Subject: pet reacting to the owner leaving, then relaxing, then returning to a composed pose
Style/medium: comic illustration with clear panels
Composition/framing: 4 equal-sized vertical panels, readable actions per panel
Constraints: no text; no logos or trademarks; no watermark
```
Official example: [Turn a story into a comic strip](prompting.md#turn-a-story-into-a-comic-strip) (one concrete visual beat per panel). Request settings: `size="1024x1536"`, `quality="medium"`.

### illustration-story: establish a reusable character
Adapted from the official example [Establish the character](prompting.md#establish-the-character).
```
Use case: illustration-story
Asset type: reusable character reference for a picture book
Primary request: children's book illustration introducing a main character
Subject: young storybook-style hero inspired by a little forest outlaw; simple green hooded tunic, soft brown boots, small belt pouch; kind expression, gentle eyes, brave but warm demeanor; carries a small wooden bow used only for helping
Scene/backdrop: plain forest background that clearly showcases the character
Style/medium: hand-painted watercolor look, soft outlines, warm earthy colors, whimsical and friendly
Composition/framing: picture-book proportions (slightly oversized head, expressive face)
Constraints: original character (no copyrighted characters); no text; no watermarks
```
Request settings: `size="1024x1536"`, `quality="medium"`. Continue with the edit recipe *character consistency workflow* below.

### stylized-concept
```
Use case: stylized-concept
Primary request: cavernous hangar interior with tall support beams and drifting fog
Scene/backdrop: industrial hangar interior, deep scale, light haze
Subject: compact shuttle parked near the center
Style/medium: cinematic concept art, industrial realism
Composition/framing: wide-angle, low-angle
Lighting/mood: volumetric light rays cutting through fog
Constraints: no logos or trademarks; no watermark
```
No direct official counterpart; the closest style-driven official example is [Establish the character](prompting.md#establish-the-character).

### ads-marketing
```
Use case: ads-marketing
Primary request: campaign image for a streetwear brand called Thread
Subject: group of friends hanging out together in a stylish urban setting
Style/medium: polished youth streetwear campaign photography
Composition/framing: vertical ad layout with natural poses and integrated headline space
Lighting/mood: contemporary, energetic, tasteful
Text (verbatim): "Yours to Create."
Constraints: render the tagline exactly once; clean legible typography; no extra text; no watermarks; no unrelated logos
```
Official example: [Render exact text](prompting.md#render-exact-text). Request settings: `size="1024x1536"`, `quality="medium"`.

### ads-marketing: holiday card
Adapted from the official example [Design a holiday card](prompting.md#design-a-holiday-card).
```
Use case: ads-marketing
Asset type: Christmas holiday card
Primary request: cozy Christmas scene with an old teddy bear sitting inside a keepsake box near a window with falling snow outside
Subject: slightly worn fur, soft stitching repairs; the scene suggests the child has grown up but the memories remain
Style/medium: premium holiday card photography; realistic textures; high print-quality composition
Lighting/mood: soft cinematic lighting, shallow depth of field, tasteful bokeh lights; warm, nostalgic, gentle, emotional
Text (verbatim): "Merry Christmas — some memories never fade." (the only card text)
Constraints: original artwork only; no trademarks; no watermarks; no logos
```
Request settings: `size="1024x1536"`, `quality="medium"`. For a 3D pop-up or photographed-card treatment, specify paper layers, fibers, folds, and soft studio lighting.

### productivity-visual
```
Use case: productivity-visual
Primary request: one pitch-deck slide titled "Market Opportunity"
Asset type: fundraising slide image
Style/medium: clean modern deck slide, white background, crisp sans-serif typography
Subject: TAM/SAM/SOM concentric-circle diagram plus a small growth bar chart from 2021 to 2026
Composition/framing: 16:9 landscape slide, clear data hierarchy, polished spacing
Text (verbatim): "Market Opportunity", "TAM: $42B", "SAM: $8.7B", "SOM: $340M", "AGI Research, 2024", "Internal analysis"
Constraints: readable labels, no clip art, no stock photography, no decorative clutter, no watermark
```
Official example: [Build slides, diagrams, and charts](prompting.md#build-slides-diagrams-and-charts). Request settings: `size="1536x864"`, `quality="high"`. The sample market figures and citations are fictional design inputs; replace them with verified data before use.

### historical-scene
```
Use case: historical-scene
Primary request: outdoor crowd scene in Bethel, New York on August 16, 1969
Scene/backdrop: open field with period-appropriate staging
Subject: crowd in period-accurate clothing, authentic environment
Style/medium: photorealistic photo
Composition/framing: wide shot, eye-level
Constraints: period-accurate details; no modern objects; no logos or trademarks; no watermark
```
Official example: [Use historical and real-world context](prompting.md#use-historical-and-real-world-context) (a two-line prompt; the model infers context — inspect clothing, staging, and surroundings for accuracy). Request settings: `size="1024x1536"`, `quality="medium"`.

## Asset type templates (taxonomy-aligned)

### Website assets template
```
Use case: <photorealistic-natural|stylized-concept|product-mockup|infographic-diagram|ui-mockup>
Asset type: <hero image / section illustration / blog header>
Primary request: <short description>
Scene/backdrop: <environment or abstract backdrop>
Subject: <main subject>
Style/medium: <photo/illustration/3D>
Composition/framing: <wide/centered; note usable negative space only if needed>
Lighting/mood: <soft/bright/neutral>
Color palette: <brand colors or neutral>
Constraints: <no text; no logos; no watermark; leave room for UI if needed>
```

### Website assets example: minimal hero background
```
Use case: stylized-concept
Asset type: landing page hero background
Primary request: minimal abstract background with a soft gradient and subtle texture
Style/medium: matte illustration / soft-rendered abstract background
Composition/framing: wide composition with usable negative space for page copy
Lighting/mood: gentle studio glow
Color palette: restrained neutral palette
Constraints: no text; no logos; no watermark
```

### Website assets example: feature section illustration
```
Use case: stylized-concept
Asset type: feature section illustration
Primary request: simple abstract shapes suggesting connection and flow
Scene/backdrop: subtle light-gray backdrop with faint texture
Style/medium: flat illustration; soft shadows; restrained contrast
Composition/framing: centered cluster; open margins for UI
Color palette: muted neutral palette
Constraints: no text; no logos; no watermark
```

### Website assets example: blog header image
```
Use case: photorealistic-natural
Asset type: blog header image
Primary request: overhead desk scene with notebook, pen, and coffee cup
Scene/backdrop: warm wooden tabletop
Style/medium: photorealistic photo
Composition/framing: wide crop with clean room for page copy
Lighting/mood: soft morning light
Constraints: no text; no logos; no watermark
```

### Game assets template
```
Use case: stylized-concept
Asset type: <game environment concept art / game character concept / game UI icon / tileable game texture>
Primary request: <biome/scene/character/icon/material>
Scene/backdrop: <location + set dressing> (if applicable)
Subject: <main focal element(s)>
Style/medium: <realistic/stylized>; <concept art / character render / UI icon / texture>
Composition/framing: <wide/establishing/top-down>; <camera angle>; <focal point placement>
Lighting/mood: <time of day>; <mood>; <volumetric/fog/etc>
Constraints: no logos or trademarks; no watermark
```

### Game assets example: environment concept art
```
Use case: stylized-concept
Asset type: game environment concept art
Primary request: cavernous hangar interior with tall support beams and drifting fog
Scene/backdrop: industrial hangar interior, deep scale, light haze
Subject: compact shuttle parked near the center
Style/medium: cinematic concept art, industrial realism
Composition/framing: wide-angle, low-angle
Lighting/mood: volumetric light rays cutting through fog
Constraints: no logos or trademarks; no watermark
```

### Game assets example: character concept
```
Use case: stylized-concept
Asset type: game character concept
Primary request: desert scout character with layered travel gear
Subject: long coat, satchel, practical travel clothing
Style/medium: character render; stylized realism
Composition/framing: neutral hero pose on a simple backdrop
Constraints: no logos or trademarks; no watermark
```

### Game assets example: UI icon
```
Use case: stylized-concept
Asset type: game UI icon
Primary request: round shield icon with a subtle rune pattern
Style/medium: painted game UI icon
Composition/framing: centered icon; generous padding; clear silhouette
Constraints: no text; no background scene elements; no logos or trademarks; no watermark
```
Request settings: consider `background="transparent"` with `output_format="png"` for an icon cutout (both 2.5 models support it).

### Game assets example: tileable texture
```
Use case: stylized-concept
Asset type: tileable game texture
Primary request: worn sandstone blocks
Style/medium: seamless tileable texture; PBR-ish look
Scene/backdrop: neutral lighting reference only
Constraints: seamless edges; no obvious focal elements; no text; no logos or trademarks; no watermark
```

### Wireframe template
```
Use case: ui-mockup
Asset type: website wireframe
Primary request: <page or flow to sketch>
Style/medium: low-fi grayscale wireframe
Composition/framing: <landscape or portrait to match expected device>
Subject: <sections in order; grid/columns; key labels>
Constraints: no color; no logos; no real photos; no watermark
```

### Wireframe example: homepage (desktop)
```
Use case: ui-mockup
Asset type: website wireframe
Primary request: SaaS homepage layout with clear hierarchy
Style/medium: low-fi grayscale wireframe
Subject: top nav; hero with headline and CTA; three feature cards; testimonial strip; pricing preview; footer
Composition/framing: landscape desktop layout
Constraints: label major blocks; no color; no logos; no real photos; no watermark
```

### Wireframe example: pricing page
```
Use case: ui-mockup
Asset type: website wireframe
Primary request: pricing page layout with comparison table
Style/medium: low-fi grayscale wireframe
Subject: header; plan toggle; 3 pricing cards; comparison table; FAQ accordion; footer
Composition/framing: desktop or tablet layout
Constraints: label key areas; no color; no logos; no real photos; no watermark
```

### Wireframe example: mobile onboarding flow
```
Use case: ui-mockup
Asset type: mobile onboarding wireframe
Primary request: three-screen mobile onboarding flow
Style/medium: low-fi grayscale wireframe
Subject: screen 1 headline and CTA; screen 2 feature bullets; screen 3 form fields and CTA
Composition/framing: portrait mobile layout
Constraints: label screens and blocks; no color; no logos; no real photos; no watermark
```

### Logo template
```
Use case: logo-brand
Asset type: logo concept
Primary request: <brand idea or symbol concept>
Style/medium: vector logo mark; flat colors; minimal
Composition/framing: centered mark; clear silhouette; generous margin
Color palette: <1-2 colors; high contrast>
Text (verbatim): "<exact name>" (only if needed)
Constraints: no gradients; no mockups; no 3D; no watermark
```

### Logo example: abstract symbol mark
```
Use case: logo-brand
Asset type: logo concept
Primary request: geometric leaf symbol suggesting sustainability and growth
Style/medium: vector logo mark; flat colors; minimal
Composition/framing: centered mark; clear silhouette
Color palette: deep green and off-white
Constraints: no text unless requested; no gradients; no mockups; no 3D; no watermark
```

### Logo example: monogram mark
```
Use case: logo-brand
Asset type: logo concept
Primary request: interlocking monogram of the letters "AV"
Style/medium: vector logo mark; flat colors; minimal
Composition/framing: centered mark; balanced spacing
Color palette: black on white
Constraints: no gradients; no mockups; no 3D; no watermark
```

### Logo example: wordmark
```
Use case: logo-brand
Asset type: logo concept
Primary request: clean wordmark for a modern studio
Style/medium: vector wordmark; flat colors; minimal
Text (verbatim): "Studio North"
Composition/framing: centered text; even letter spacing
Constraints: no gradients; no mockups; no 3D; no watermark
```

## Edit

### text-localization
```
Use case: text-localization
Input images: Image 1: original infographic
Primary request: replace "Bean Hopper", "Grinder", "Brew Group", "Boiler", "Water Tank", and "Drip Tray" with "Tolva", "Molino", "Grupo de infusión", "Caldera", "Depósito de agua", and "Bandeja de goteo"
Constraints: change only the text; preserve layout, typography, spacing, and hierarchy; no extra words; do not alter logos or imagery
```
Official example: [Translate while preserving layout](prompting.md#translate-while-preserving-layout) (a one-line prompt: "Translate the text in the infographic to Spanish. Do not change any other aspect of the image."). Request settings: `size="1024x1536"`, `quality="high"`. Check the translation and any words left in the original language.

### identity-preserve
```
Use case: identity-preserve
Input images: Image 1: person photo; Image 2..N: clothing references
Primary request: replace only the clothing with the provided garments
Constraints: preserve face, body shape, pose, hair, expression, and identity; match lighting and shadows; keep the background unchanged; no accessories or text
```
Official example: [Preserve identity and change clothing](prompting.md#preserve-identity-and-change-clothing) (one person photo plus three clothing references). Request settings: `size="1024x1536"`, `quality="medium"`. Send the person photo first (see the model notes on image order).

### identity-preserve: insert a person into a scene
Adapted from the official example [Insert a person into a scene](prompting.md#insert-a-person-into-a-scene).
```
Use case: identity-preserve
Input images: Image 1: photo of the person
Primary request: highly realistic action scene where this person is running away from a large brown bear attacking a campsite
Scene/backdrop: campsite in Yosemite National Park with believable natural details; dusk
Subject: the person centered but looking away from the camera; outdoorsy camping attire, dirt on the face, tears in the clothing; afraid but focused on escaping
Style/medium: looks like a real photograph someone could have taken, not an overly enhanced or cinematic movie-poster image
Lighting/mood: natural lighting and realistic colors; grounded, authentic, unstyled
Constraints: preserve the person's facial features and proportions; avoid cinematic lighting, dramatic color grading, or stylized composition
```
Request settings: `size="1024x1536"`, `quality="medium"`. For `gpt-image-2`, omit `input_fidelity`.

### precise-object-edit
```
Use case: precise-object-edit
Input images: Image 1: room photo
Primary request: replace only the white chairs with wooden chairs
Constraints: preserve camera angle, room lighting, floor shadows, and surrounding objects; keep all other aspects unchanged
```
Official example: [Change furniture in a room](prompting.md#change-furniture-in-a-room). Request settings: `size="1536x1024"`, `quality="medium"`.

### precise-object-edit: remove an object
Adapted from the official example [Remove an object](prompting.md#remove-an-object).
```
Use case: precise-object-edit
Input images: Image 1: photo of a man holding a flower
Primary request: remove the flower from the man's hand
Constraints: keep the person, pose, lighting, and composition unchanged; do not change anything else
```
Request settings: `size="1024x1536"`, `quality="medium"`.

### lighting-weather
```
Use case: lighting-weather
Input images: Image 1: original photo
Primary request: make it look like a winter evening with gentle snowfall
Constraints: preserve subject identity, geometry, camera angle, and composition; change only lighting, atmosphere, and weather
```
Official example: [Change one condition](prompting.md#change-one-condition) (the previous output is the input; the whole prompt is "Make it look like a winter evening with snowfall."). Request settings: `size="1024x1536"`, `quality="medium"`.

### background-extraction
Adapted from the official example [Create a transparent product cutout](prompting.md#create-a-transparent-product-cutout). The skill taxonomy lists this slug but had no recipe.
```
Use case: background-extraction
Input images: Image 1: product photograph
Primary request: extract the product and isolate it on a fully transparent background
Composition/framing: centered product, crisp silhouette
Constraints: preserve product geometry and label legibility exactly; add only light polishing; no halos or fringing; no solid backdrop, checkerboard, scenery, or shadow; do not restyle the product; preserve clean alpha transparency
```
Request settings: `size="1024x1536"`, `quality="medium"`, `background="transparent"`, `output_format="png"` (no `output_compression` for PNG). For later edits, repeat the requirement to keep the transparent background.

### style-transfer
```
Use case: style-transfer
Input images: Image 1: style reference
Primary request: apply Image 1's visual style to a man riding a motorcycle on a plain white backdrop
Constraints: preserve palette, texture, and brushwork; no extra elements
```
Official example: [Transfer a visual style](prompting.md#transfer-a-visual-style) (pixel-art reference; prompt: "Use the same style from the input image and generate a man riding a motorcycle on a white background."). Request settings: `size="1024x1536"`, `quality="medium"`.

### compositing
```
Use case: compositing
Input images: Image 1: base scene; Image 2: subject to insert
Primary request: place the subject from Image 2 next to the person in Image 1
Constraints: match lighting, perspective, and scale; keep the base framing unchanged; no extra elements
```
Official example: [Combine references](prompting.md#combine-references). Request settings: `size="1024x1536"`, `quality="medium"`. Send the base scene first and the subject second (see the model notes on image order).

### product-mockup: scene from a product photo
Adapted from the official example [Create the starting image](prompting.md#create-the-starting-image).
```
Use case: product-mockup
Input images: Image 1: product photograph
Primary request: realistic billboard mockup of the product on a highway scene during sunset
Text (verbatim): "Fresh and clean" (billboard text, exact, no extra characters, appears once)
Style/medium: realistic photo mockup
Composition/framing: billboard text centered
Constraints: bold sans-serif, high contrast, clean kerning; perfectly legible; no watermarks; no logos
```
Request settings: `size="1024x1536"`, `quality="medium"`. Follow with the *lighting-weather* recipe on the output to change one condition at a time.

### character consistency workflow
```
Use case: identity-preserve
Input images: Image 1: previous character anchor illustration
Primary request: continue the story with the same character in a new scene and action
Scene/backdrop: snowy forest after a winter storm
Subject: same young forest hero gently helping a frightened squirrel out of a fallen tree
Style/medium: same children's book watercolor illustration style as Image 1
Constraints: do not redesign the character; preserve facial features, proportions, outfit, color palette, and personality; no text; no watermark
```
Official example: [Continue the story](prompting.md#continue-the-story), after [Establish the character](prompting.md#establish-the-character). Request settings: `size="1024x1536"`, `quality="medium"`.

### sketch-to-render
```
Use case: sketch-to-render
Input images: Image 1: drawing
Primary request: turn the drawing into a photorealistic image
Constraints: preserve layout, proportions, and perspective; choose realistic materials and lighting; do not add new elements or text
```
Official example: [Turn a drawing into a realistic image](prompting.md#turn-a-drawing-into-a-realistic-image). Request settings: `size="1024x1536"`, `quality="medium"`.
