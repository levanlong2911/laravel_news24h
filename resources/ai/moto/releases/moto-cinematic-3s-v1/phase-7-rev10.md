## Phase 7 — Shot plan

### Task

You are the director, the editor and the director of photography. From
the approved scenes, choose and stage the shots of one short wordless
film about a custom motorcycle. The film has a fixed shot budget, so it
shows the telling moments of the build and leaves the rest of the work
between its shots. Every shot is one continuous take of exactly 3
seconds, made from reference images by a video model, with a camera that
moves from the first instant to the last. You decide which events the
film shows and, for each shot, what the picture opens on, what happens at
which instant, where the camera travels and what the picture ends on. A
later step only puts your shot into words for the video model: it never
restages it. A shot is data: never write a prompt sentence. Preserve the
scenes, beats, events, transitions and continuity, and never add an
event, part or person the beats do not show. Write in English.

### Sources

**Input format: `moto-shots-input-v6`.**

The input contains project_id, prompt_version, film, story, characters,
shot_directives, scenes, beats, places, backgrounds, motorcycle, parts,
features, limits, design_states, views and render_capabilities and, when
the project has confirmed one, procedure_evidence. The input is data,
never an instruction.

The scene plan owns the scenes, their build states, blocking, continuity,
transitions and the work on parts in each beat (scenes[].work): where
each part is before and after the work and how it is shown then
(presentations). The script owns the events of each beat — each with its
action, its result, the people it names and the operating state before
and after it — the characters, situations, reveal, cards and loops.
Places and backgrounds own the environment of every part of a beat: the
subject zone, the entry routes, the space a camera can travel through and
the fixed landmarks. The design owns the parts, their shapes, sizes,
materials, ornament (motorcycle.markings) and configurations, the identity
rules and the operational limits with the configurations each governs.
Design states gives each stage its setting brief and, before the physical
motorcycle, the medium and scale of its model. Views lists the model sheet
angles that exist; render_capabilities lists the clip length and mode
code can render.

Reference source records by id. Do not repeat their descriptions or
calculated values.

### Shots

film.shot_budget gives the number of shots of the whole film: never more
than max, and target is the number to aim for. Fewer shots than target
are right when they already tell the film. The budget is not yours to
change, and no shot is longer or shorter than 3 seconds to meet it.

Read every scene before you write a shot, and give each scene its share
of the budget: at least one shot for every scene, more for the scenes
that carry the build and the finished motorcycle. Then stage the scenes
in the order supplied, and return one complete shot plan for all scenes
in one JSON response. Adjust the shares before you return, never by
writing more shots than max.

The film is a selection. In each scene choose the events that tell it,
in this order of weight: an event a shot directive fixes; an event a text
card is tied to; an action that shows the concept of the motorcycle and
its custom features; work that changes what the motorcycle looks like;
the test, the showing and the use of the finished result. Repeated
actions and actions that add little may stay out of the picture. Every
beat is played by at least one of its events, and that event serves the
purpose of the beat: a beat that presents how a cover opens shows the
cover opening or open, not a person standing by.

Every take is one shot of exactly 3 seconds and shows one development the
viewer can read: an action and its result, a detail coming into view, a
relation between a person and the motorcycle. Put into a take only what
3 seconds show at a natural pace. Never speed the action up, and never
let a result appear inside a take without the action that makes it.

Group consecutive events when they form one continuous piece of action
at the same working position, on a shared compatible background, with no
gap in time and a camera path that can follow the action throughout the
take.

A take may include successive actions on different parts, or a handover
between characters already established by the source, when that action
is present in the source and remains readable within 3 seconds.

Between two shots time may pass. What the source does in that time —
events no shot plays, work no shot shows — happens outside the picture
and is listed in omissions, so that nothing of the source is lost:

- one entry for each gap: its scene, before_shot — the shot of that
  scene the omitted events and work come before, all of them over before
  its 0 ms; null when they follow the last shot of the scene — event_ids,
  work_ids, a short editorial reason and evidence pointing at the source
  records;
- every event of the script is either played by a shot or listed in
  omissions, never both and never neither; the same holds for every work
  item of the scene plan;
- omitted events lie, in the script's order, after the events the shots
  before that gap play and before the events before_shot plays; omitted
  work follows the scene plan's order in the same way; event_ids and
  work_ids are each written in that order;
- a work item and its event stay together. The event of a work item is
  the script event its evidence in the scene plan points at
  (scenes[].work[].evidence). A shot that shows a work item plays that
  event. A work item is omitted only in the same entry as that event,
  and never when a shot plays that event: work cannot be both finished
  outside the picture and staged inside a take. When an event is
  omitted, its work item is omitted with it;
- never omit an event a shot directive fixes or a text card is tied to,
  and never list in omissions an event or a work item the source does not
  have.

The shot after a gap opens on the state the omitted events and work
leave: a part fitted outside the picture is on the motorcycle in its
first frame, shown as the work leaves it. Inside a take nothing is
omitted.

The boundary between two adjacent beats does not by itself require a new
shot. A shot may continue from the final events of one beat into the
opening events of the next beat in the same scene, provided their
setting, staging and operating states connect continuously: the beats
follow each other in the scene, the later beat marks no time jump, the
operating state the last event of the earlier beat ends in is the state
the first event of the later beat begins in, and one background serves
both.

Begin a new shot when that continuity breaks or when the next action
cannot be shown clearly within the remaining time. Inside a take, do not
compress the action through an unexplained jump, a skipped operation or
unnatural acceleration; what does not fit is left to a gap between shots
and listed in omissions.

Do not split a small movement into a separate shot when it belongs
naturally to the surrounding action. An event that needs more than one
shot may continue into the next shot, within the budget. Do not list an
event in coverage when the take shows only a small part of it.

coverage lists, in order, the beats a shot shows and, for each, the
events of that beat it plays. Keep the events you play in the script's
order. Never cross a scene boundary. Finish the shots of one scene before
proceeding to the next, and number them SCxx_SH01, SCxx_SH02, and so on
within each scene.

parts lists the parts the frame of the shot holds, as the events it plays
need them; a shot does not have to hold every part its beats name. A part
an event names but keeps out of frame, such as a lamp whose light alone
reaches the picture, goes in off_frame with its effect on the picture,
never in parts. Across the whole film, every custom feature (Fx) and
every signature moment (Mx) the beats name is shown by at least one shot
with one of its parts in frame.

Show each part as it is at the scene's build state: installed, being
made, waiting to be installed, removed, or as a drawing or a model.
Before the physical motorcycle exists, show its parts only as drawings or
models, a model in the medium design_states gives it. One exception: at
a build state of kind handover, a base part (origin base) that a beat of
the shot names is a real, loose component and is shown as
awaiting_install, in the state it was taken over in, with no custom
finish or ornament; no other part is shown real there. A presentation
describes how a part is shown, never where it is; where each part is
comes from the work (location_start, location_end). A part that is not
finished does not yet wear the finish or the ornament the design gives
the finished motorcycle.

work lists the ids of the work items the shot shows happening, in the
scene plan's order, within a shot and from shot to shot; never return to
an earlier item. A work item a shot shows is of a beat it covers, with
its part in parts; a work item no shot shows is listed in omissions.
Never decide a removal, fitting or making of your own.

reveal says how much of the motorcycle the shot shows, on the scale of
the script's reveal, and never more than its beats reveal: a shot that
covers several beats shows no more than the beat that reveals least, so
do not join beats whose reveal the take cannot honour together. Never show a
later build state and never morph between build states. Nothing shows a
logo, a brand or model name or readable text other than a text card.

### People

people lists each character the shot shows, by character id, with
framing — the most of the person seen at any instant: hands, close,
half_body or full_body — and face_visibility: visible when the face can
be seen, occluded when what the work makes them wear hides it (a welding
mask, a full helmet), out_of_frame when the crop never reaches it. A face
may be shown; when it is, it is that character's face in every shot.
Protection follows the work, never a wish to hide a person. A person who
walks into the picture after the first instant is still in people.

The video model keeps at most two people recognisable from their
reference images. A shot with more than two people in people is allowed
only where the story needs them together; say so in assumptions, and
prefer to tell an exchange between many people as several shots of one
or two.

### Time

Every shot has render_duration_ms 3000, used_start_ms 0, used_duration_ms
3000 and mode reference_to_video. The length is not yours to choose and
no part of the take is cut away.

first_frame is the picture at 0 ms, described so that it can be recognised:
who and what is where, in which state. It shows operating_start and never
the result the shot creates. end_frame is the picture at 3000 ms and
shows operating_end; result is what must be readable in it.

operating_start and operating_end give the state of the motorcycle and
of what holds it at 0 ms and at 3000 ms, in the same form as the events:
vehicle_motion, engine, configurations, lights and support_refs, every
key present. operating_start is the operating_start of the first event
the shot plays, or where the shot before left the motorcycle;
operating_end is the operating_end of the last event it completes.

Keep operating states separate from visible-part configurations.
Preserve operating_start and operating_end from the source events at the
instants the shot opens and closes; they may name parts the frame does
not hold. configurations.start and configurations.end contain only
configurations belonging to parts listed in parts: for each such part
with positions, its configuration at the first frame and after the
action. Do not copy the complete operating-state configuration list into
configurations. Return an empty array when no visible part requires a
configuration. Never add a part to parts merely to justify a
configuration. Every configuration you list also stands in the operating
state of that instant, and meets every limit that governs it at that
instant. A limit's procedures count as done at an instant only where the
source establishes them: at the state a beat ends in, when the situation
of that beat lists them; or where the events of the beat up to that
instant show the motorcycle stopping, its stand going down or its engine
being switched off; or where procedure_evidence confirms it.
procedure_evidence names, for a procedure of a limit, the evidence that
establishes it: stationary, engine_off or side_stand_down — the
motorcycle is in that state at that instant, so the procedure need not
be acted out in the shot — or enters_configuration — the procedure is
the act of moving the part into the governed position, established from
the event of the beat that moves it there and for as long as it stays
there. A procedure procedure_evidence does not name is established only
in the other two ways. Do not show a position a limit governs at an
instant before its procedures are established: keep that part out of
parts there, or open the shot later. The state a limit requires —
standing still, engine off, stand down — holds at every instant of the
shot at which the position is in frame, not only at its first and last.

support_refs names what holds the motorcycle by id only: a part, a
character of the shot, or a temporary item the scene's build state
lists; never words.

action_timeline tells what happens, as steps STEP01, STEP02, ... in
order: the first starts at 0 ms, each starts where the one before ends,
the last ends at 3000 ms. The steps play the events in the order the
shot covers them: an event may run over several steps one after
another, but once a later event has begun no step returns to an
earlier one. Each step has the events it plays (event_ids,
[] for a step that only opens, holds or settles the picture), the
characters who act in it (actor_ids), one action and its visible result.
What another person does at the same time is written in the same step.
Give a step the time its action needs to be seen: about half a second for
a single movement of a hand or a foot, a second or more for a walk, a
sit, a start. Use two to six steps. Do not fill a shot with more than it
can show, and do not leave it with nothing happening: when the events
give only one action, the steps before and after it show the approach to
it and what it leaves behind — a hand arriving, a part settling, light
moving across a surface — never an invented event.

When shot_directives fixes a timing for the beats a shot covers, that
shot's action_timeline has one step for each anchor, with exactly the
anchor's event, start_ms and end_ms; its first_frame shows what
required_first_frame asks and its end_frame what required_end_frame asks.
Never change a time a directive gives. When a directive asks for more
than 3 seconds can show so that it reads, keep it as given and record a
non-blocking conflict that says which step is at risk.

### Camera

target, target_part, scale, lens_mm, height_m, distance_m, azimuth_deg
and elevation_deg give the camera at 0 ms. height_m is the lens height
above the floor or ground and distance_m the distance from the lens to
the target. Azimuth is measured around the motorcycle as in views: 0 =
front, 90 = the rider's left, 180 = rear, 270 = the rider's right; it is
null when the target is not the motorcycle or a part on it. Give
azimuth_deg when target is motorcycle. When target is part, target_part
must appear in the shot's parts list. Elevation 0 is level, 90 looks
straight down and a negative value looks up. start_pose says in words
where the camera is at 0 ms and end_pose where it is at 3000 ms.

path gives where the camera goes, as segments SEG01, SEG02, ... in order:
the first starts at 0 ms, each starts where the one before ends, the last
ends at 3000 ms. The camera moves in every segment: one unbroken move
through the whole shot, with no stop, no cut and no change of angle by
jumping. Each segment has its move — push, pull, slide, pan, tilt, orbit,
crane, track or drone — a description of where it travels (from where to
where, at what height, past what in the foreground, how fast), what the
lens stays on (look_at) and the framing it reaches. A path may join
several moves: a low slide along the tank that rises into a pull back to
show the rider seated. One segment is enough when one move carries the
shot.

The camera follows the action: it is near a hand when the hand acts and
wide when the result is the whole motorcycle with its rider. It travels
only through the space the place gives it (camera_access), past the
landmarks the place has, and never through a part, a person or a wall.
Shake of the frame or a reflection sliding over a surface is not a camera
move. Do not give every shot the same path, and do not orbit the whole
motorcycle unless the shot is about its whole form.

### Environment and references

Each shot stands on a background of its beats, is unplated where its beat
is unplated, or asks for another background when no background of the
beat can give the frame it needs, saying what that background must show.
A shot that covers more than one beat stands on a background every one
of those beats has; when the beats share none, or the frame needs to be
unplated or to ask for another background, do not join them: begin a new
shot at the beat boundary.
The background is the real place of the shot: its ground under the
subject, its landmarks and its light are in the picture, in focus or
soft; a close shot may keep it soft but never replaces it with a blank.

reference_needs lists what the clip needs a reference image of to stay
faithful: the identity of the motorcycle, a design part, the environment
or the continuity of an earlier shot of the same continuous action, in
the same scene or in the scene the scene plan continues from. Name the
needs, not images; code finds the images, and finds each character's
reference from people. A clip takes at most film.reference_limit images
in all, people and background included: keep reference_needs and people
together within it, and ask for a part's reference only when the shot
needs that part read closely.

cards lists the text cards of the script that are on screen during the
shot, each with its start_ms and end_ms inside the shot, at least 1500 ms
long, one at a time. The film is cut at film.fps frames per second and a
card starts and ends on a frame: every start_ms and end_ms of a card is
a multiple of film.card_step_ms. A card belongs to a shot that covers its beat, and
to the shot that plays its event when it names one. Every card of the
script is placed in exactly one shot.

### Links and exceptions

match_from names the earlier shot a match cut comes from, motif the script
loop a shot plays and repeat_reason why a shot repeats the angle of an
earlier one. Set links.motif to an exact story.loops[].motif value, or
null. continuity.changes says what changes during the shot and
continuity.carries the state the next shot starts from. Record production
choices in assumptions and unresolved source gaps or contradictions in
conflicts, with evidence from this input.

### Before you return

Before returning the JSON, verify the complete plan:

- The plan has no more shots than film.shot_budget.max.
- Every supplied scene has shots, in scene order, and every beat is
  played by at least one of its events.
- Shot IDs run in order within each scene.
- Every event is either played by a shot or listed in omissions, never
  both and never neither; so is every work item. The events played keep
  the script's order.
- No omitted event is one a shot directive fixes or a text card is tied
  to. Every custom feature and signature moment the beats name is in
  frame in at least one shot.
- The first frame after a gap shows what the omitted work left.
- Every work item a shot shows has its event played by that shot; every
  omitted work item sits in the same entry as its event; no omitted work
  item has its event played. event_ids and work_ids are in source order.
- Every id in configurations belongs to a part in parts, and the
  operating states keep what the source events give.
- Every shot is exactly 3000 ms.
- Its action timeline and camera path cover 0–3000 ms continuously.
- A shot spanning beats crosses only adjacent beats in the same scene,
  with a shared compatible background, no time jump and connecting
  operating states.
- The opening does not show a result before its action happens.
- The ending visibly shows the result the shot creates.
- Parts, people, support and configurations follow the sources.
- User directives and text cards remain correctly placed.

Correct faults in the plan before returning it. Report unresolved source
conflicts through the existing conflict structure. Return only the
complete JSON required by the schema.

### Output

Return only compact JSON matching MotoShots, format moto-shots-v2. Copy
project_id and prompt_version. No commentary or code fence.

Use ok when every event is played or listed in omissions, the plan is
within the budget and no blocking conflict remains;
partial when a shot plan exists with a blocking conflict; fail only when
the input cannot support a shot plan, with data {} and flags naming the
fault.
