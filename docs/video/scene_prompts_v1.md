# Scene Prompt Contracts v1

This document separates the shared scene contract from the image and video
prompt compilers. Values in angle brackets are resolved from the approved
canonical state; they must not be hard-coded from an example vessel.

## Shared Image Contract

```text
Use case: <photorealistic-natural|precise-object-edit|compositing>
Asset type: canonical state frame for a production AI-video pipeline
Input images: <label every image by role: edit target, identity reference, environment reference>
Scene/backdrop: <resolved environment>
Subject: one coherent physical object at the requested canonical state
Style/medium: photorealistic documentary photography, physically plausible scale and materials
Composition/framing: vertical 9:16, complete required subject visible, readable geometry
Lighting/mood: neutral documentary lighting unless the scene specifies otherwise
Constraints: change only the requested state or scene delta; preserve all listed invariants
Avoid: text, logos, watermark, collage, split screen, duplicated subject, impossible scale
```

## Shared Video Contract

```text
Use case: <precise-object-edit|photorealistic-natural|compositing>
Asset type: six-second image-to-video state transition
Input images: Image 1: exact first frame; Image 2: exact last frame when supplied
Primary request: one continuous physically credible action connecting the supplied states
Constraints: preserve identity, completed structures, camera, environment, scale and lighting
Avoid: morphing, teleportation, time-lapse, sudden stage jumps, camera orbit, flicker, identity drift
```

## Identity Invariants

Use this block in every scene that contains the vessel:

```text
The supplied approved identity image is authoritative for the same physical vessel.
Preserve its canonical silhouette, length-to-beam proportion, bow and stern geometry,
sheer line, deck count, superstructure footprint, permanent openings and completed work.
The current construction state may advance only by the scene delta below.
```

## Scene Contracts

Each scene below is intentionally short. The runtime compiler expands the shared
contract, resolves canonical values, labels image roles, and appends the scene delta.

### S01 - Concept sketch

Image inputs: `identity reference` as reference image.

```text
Use case: photorealistic-natural
Scene/backdrop: naval design studio with drafting table and abstract monitors.
Primary request: show designers creating the first profile and deck-volume sketches of the same vessel.
Scene delta: drawings show the canonical hull, bow, stern, sheer, deck count and superstructure tiers.
Change only the design activity; no physical vessel or construction exists.
```

Video delta: one architect draws a continuous sheer line while another points once to the bow; restrained slow push-in; drawings and room remain unchanged.

### S02 - Engineering model

Image inputs: `Scene S01` as edit target; `identity reference` as identity reference.

```text
Use case: precise-object-edit
Primary request: show the same design as a neutral three-dimensional naval-architecture model.
Scene delta: the monitor contains the canonical hull, four superstructure tiers, bridge, forward deck and aft terraces; diagram text is abstract and unreadable.
Change only the design-review content; no physical construction.
```

Video delta: digital model rotates approximately twenty degrees; designers make small inspection gestures; model geometry does not change; camera nearly locked.

### S03 - Production planning

Image inputs: `identity reference`; optional `environment reference`.

```text
Use case: photorealistic-natural
Primary request: show engineers completing the fabrication and assembly plan for the same vessel.
Scene/backdrop: project room adjacent to the shipyard.
Scene delta: scale model and abstract sequencing material show keel, frames, shell, deck and deckhouse blocks.
Change only planning content; steel construction has not started.
```

Video delta: one scale-model deckhouse block is placed on the model; only that block moves; slow lateral camera slide.

### S04 - Empty prepared berth

Image inputs: `environment master` as edit target.

```text
Use case: precise-object-edit
Primary request: prepare the empty shipbuilding berth for the vessel.
Scene/backdrop: preserve the supplied hall, high-walkway camera, blue support rows, crane, floor and vanishing point.
Scene delta: add only survey markers, aligned cables, tools and small safety barriers.
Keep the berth empty and preserve the original composition.
```

Video delta: surveyor walks along the centreline while workers check markers; crane remains stationary; camera locked.

### S05 - Keel placement

Image inputs: `Scene S04` as edit target; `camera-matched vessel reference` as identity/camera reference.

```text
Use case: precise-object-edit
Primary request: advance the berth by adding only the central keel and earliest bottom foundation.
Scene delta: keel and aligned longitudinal girders occupy the canonical centreline between the supports; stern remains nearest the camera and bow at the far end.
The vessel contains no ribs, side shell, deckhouse, glazing or paint.
```

Video delta: one keel section descends vertically on taut cables and settles on prepared supports; riggers use tag lines from safe positions; no other structure appears.

### S06 - Bottom plating

Image inputs: `Scene S05` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: add only the complete bottom plating and internal bottom framing.
Scene delta: continuous girders, transverse floor plates and bottom plating extend nearly the full canonical length; bow narrows and stern widens correctly.
Preserve the hall, berth, keel, camera and all existing supports.
```

Video delta: one rigid bottom plate is lowered horizontally, aligned and settled; cables stay taut; all other structure remains unchanged.

### S07 - Transverse frames

Image inputs: `Scene S06` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: add only the engineered transverse-frame skeleton, stringers and temporary braces.
Scene delta: frames reveal the deep midship section, narrowing bow and broad stern while the hull remains open.
Preserve all bottom structure and do not close the side shell.
```

Video delta: one transverse frame descends vertically, guided by two riggers, and seats on prepared connections; all other frames remain rigid.

### S08 - Partial side shell

Image inputs: `Scene S07` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: add only partial raw-steel side-shell plating.
Scene delta: approximately seventy percent of the port and starboard hull envelope is enclosed; upper working sections remain open so frames and stringers are visible.
Preserve the fine bow, broad transom, sheer and existing frame arrangement.
```

Video delta: one curved shell plate moves toward the forward-quarter frames on taut cables and reaches alignment; no additional plates appear.

### S09 - Closed hull

Image inputs: `Scene S08` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: complete only the remaining hull shell, pointed bow shell, transom shell and raw-steel main deck.
Scene delta: genuine future openings remain correctly positioned; the vessel is still bare steel.
No deckhouse, bridge, upper deck, glass or paint exists.
```

Video delta: workers align and tack-weld one final closure plate; a small crane supports it until contact; no time-lapse closure.

### S10 - Structural deckhouse

Image inputs: `Scene S09` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: add only the raw structural deckhouse and upper-deck blocks.
Scene delta: the canonical number of exterior superstructure levels is complete; bridge, footprint, openings and aft terraces match the identity reference.
All openings remain empty and structural steel remains unfinished.
```

Video delta: one preassembled deckhouse block descends vertically on four taut cables and seats on prepared connections; no other structure changes.

### S11 - Technical outfitting

Image inputs: `Scene S10` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: advance to structural completion and early technical outfitting without changing exterior geometry.
Scene delta: add roof plates, bulkheads, machinery modules, ventilation, pipework and cable trays only through genuine openings; use restrained scaffolding.
```

Video delta: one machinery module moves toward one service opening on a transporter and enters partially; exterior panels and camera remain unchanged.

### S12 - Fairing and primer

Image inputs: `Scene S11` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: change only the exterior surface-preparation state.
Scene delta: show remaining raw steel, fairing, sanded transitions, matte primer, masking and controlled extraction equipment.
Preserve every geometric edge, opening, deck level and hull proportion.
```

Video delta: workers apply fairing to one controlled hull section while extraction runs; only that local section changes.

### S13 - Coating and glazing

Image inputs: `Scene S12` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: complete only the marine coating system and install glazing into existing openings.
Scene delta: apply the approved canonical colors and glazing without creating, moving or resizing openings; retain limited scaffolding.
```

Video delta: spray team coats one masked midship section inside scaffolding; overspray is extracted; no full-vessel color morph.

### S14 - Commissioning

Image inputs: `Scene S13` as edit target; matching identity reference.

```text
Use case: precise-object-edit
Primary request: advance to final outfitting and shipyard commissioning.
Scene delta: add approved rails, teak, anchors, hatches, stairs, restrained lighting, tender equipment and the canonical mast through existing interfaces.
Preserve the completed hull, glazing, deck count and superstructure geometry.
```

Video delta: navigation lights activate in sequence; one hydraulic door opens and stops; two engineers inspect a control cabinet; yacht remains stationary.

### S15 - Rollout

Image inputs: `completed vessel identity reference`; `exterior shipyard environment reference`.

```text
Use case: compositing
Primary request: show the exact completed vessel on engineered support cradles above synchronized transporters on the shipyard apron.
Scene/backdrop: exterior apron and hall doors; preserve realistic scale, support contact and safety zones.
Change only placement and rollout context; completed vessel identity remains authoritative.
```

Video delta: transporters move forward at very low speed; cradles and vessel remain rigid; camera tracks parallel; no launch occurs.

### S16 - Sea trial

Image inputs: `completed vessel identity reference`; matching-angle identity reference.

```text
Use case: photorealistic-natural
Primary request: show the exact completed vessel underway during a credible sea trial.
Scene/backdrop: calm open water and restrained horizon.
Scene delta: preserve completed hull, glazing, mast, deck equipment and superstructure; produce a physically correct bow wave and wake.
```

Video delta: vessel advances steadily at credible test speed; bow wave and wake develop continuously; aerial camera tracks smoothly from the declared quarter.

### S17 - Operational voyage

Image inputs: `completed vessel identity reference`; matching-angle identity reference.

```text
Use case: photorealistic-natural
Primary request: show the exact completed vessel operating during a calm coastal voyage.
Scene/backdrop: open coastal water and restrained distant coastline.
Scene delta: a few naturally scaled guests use the aft terraces while crew remain discreetly operational; preserve all completed identity features.
```

Video delta: vessel cruises steadily; wake flows from the transom; guests walk slowly on the aft terrace; camera tracks smoothly; no identity or geometry change.

## Runtime Rules

1. `Image 1` is always labeled explicitly as `edit target` when it is the state being changed.
2. Identity references control permanent geometry only; edit targets control camera, composition, environment and current state.
3. A scene prompt never contains the prompts for other scenes.
4. A video prompt describes one action between approved states; it never asks the model to recreate an entire construction sequence.
5. Canonical dimensions and counts are resolved from the approved state, never copied from example text.
6. Scene output must pass geometry/state QA before becoming the next scene's edit target.
