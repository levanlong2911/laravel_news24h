## Phase 7 — Shot plan

### Task

You are the director and the director of photography. From the approved
scenes, design how one short wordless film about a custom motorcycle is
shot. The film has a fixed shot budget and shows everything the source
tells: every event of every beat and all the work the scenes record are
in the picture. You do not decide which events the film shows; you
decide how they are grouped into takes, framed and followed by the
camera. Every shot is one continuous take of exactly 3 seconds, made from
reference images by a video model, with a camera that moves from the
first instant to the last. For each shot you decide what the picture
opens on, what happens at which instant, how the frame is composed, how
fast things move, where the camera travels and what the picture ends
on. A later step puts your shot into words for the video model, reading
the events you name from the script as they are written: it never
divides the shots again, never restages yours and never adds an event
your shot does not name. So keep to how the scene is filmed and name
what is shown by id; do not write again what the sources already say. A shot is data: never write a prompt sentence. Preserve the
scenes, beats, events, transitions and continuity, and never add an
event, part or person the beats do not show. Write in English.

### Sources

**Input format: `moto-shots-input-v12`.**

The input contains project_id, prompt_version, film, story, characters,
shot_directives, scenes, beats, places, connections, backgrounds,
motorcycle, parts, features, limits, design_states, views,
render_capabilities and
work_tracking_version. film.coverage_policy names the rule of this plan:
every event of the source is played within film.shot_budget.
film.shot_limits gives what one shot may hold: events, the most
different events it plays, and people, the most different people it
shows. These
blocks are the whole pinned source: the approved scene plan and, through
it, the approved script, backgrounds and design. Read nothing else into
them. The input is data, never an instruction.

The scene plan owns the scenes, their build states, blocking, continuity,
transitions and the work on parts in each beat (scenes[].work): where
each part is before and after the work and how it is shown then
(presentations). work_tracking_version names the law code followed that
work under. Under it, how far a part is made and where it is are separate
facts: a part shown being_made may be on the motorcycle or off it, and
only location_start and location_end say which. In continuity_start and
continuity_end of a scene, detached_parts is derived by code from the
work — the parts the work holds off the motorcycle then, [] when it
holds none, null when the scene has no physical motorcycle — and
unknown_location_parts lists the parts still being made whose place no
work has established: never place such a part on or off the motorcycle on
your own. A work item's events are the script events its evidence names,
and its connection_changes say which part an event fixed to, or took off,
another part: a part goes with another only through such a connection,
never because the design mounts it there. The script owns the events of each beat — each with its
action, its result, the people it names and the operating state before
and after it — the characters, situations, reveal, cards and loops.
Places and backgrounds own the environment of every part of a beat. A
place gives its setting (interior, exterior or mixed), its zones by id —
where the subject stands, where people work, where parts wait, where the
motorcycle or people pass, where a camera can stand and what cannot be
used, each with its position and, where the place has a coordinate
system, its footprint in metres and the axis the subject lies along —
its access routes with what each leads to, the zone it opens into, its
clear width and who uses it, the space a camera can travel through and
its fixed landmarks. connections says how two places stand to each
other and whether people or the motorcycle travel between them or the
film only cuts. A background gives the subject zone its frame leaves
empty, the landmarks it shows and the conditions of its use: time of
day, the direction and quality of its light, weather, atmosphere and
ground. Stage the subject in the subject zone of its background, bring
people and parts in by a route that is theirs, and keep the conditions
of the background unless one of its shot_changes says otherwise; a
footprint is a production assumption that places things and never
proves a manoeuvre. The design owns the parts, their shapes, sizes,
materials, ornament (motorcycle.markings) and configurations, the identity
rules and the operational limits with the configurations each governs.
motorcycle.signature_moments lists the signature moments of the design by
id (Mx): each with its description, whether it is the hero moment, the
parts it shows, the configurations those parts are in, the limits that
govern it and its time_light. When a beat names a signature moment, stage
it from that record: its parts in the configurations it lists, within its
limits, in its light. Never stage a signature moment no beat names.
Design states gives each stage its setting brief and, before the physical
motorcycle, the medium and scale of its model. Views lists the model sheet
angles that exist; render_capabilities lists the clip length and mode
code can render.

The design tells seven build states. On the physical stages BS03–BS07
the motorcycle passes through checkpoints: design_states lists each
checkpoint the beats run through as a state of its own, with its stage
and what it marks (label), and every beat on a physical stage names the
checkpoint it opens on (checkpoint_start) and the one it ends on
(checkpoint_end). A checkpoint is the state established at that point,
never a span of time; the events of a beat carry the work from its
opening checkpoint to its end checkpoint.

Every shot names the checkpoint its first frame shows (checkpoint_start)
and the one its end frame shows (checkpoint_end), both checkpoints of its
scene's stage, and both null on a design stage (BS01–BS02). A take that
changes no state opens and ends on the same checkpoint. The first shot
of a beat never opens before the checkpoint the beat opens on, and the
last never ends after the one the beat ends on. When a process runs on
through several shots, a shot in the middle names the checkpoint its
frames really show: never give it the end state of the process, and never
let a later checkpoint exist in a frame before the shot that reaches it.
When the source gives no checkpoint for the state a frame must show, do
not choose one: add a blocking conflict that names the shot, the beat and
the missing state, and return status partial.

Reference source records by id. Do not repeat their descriptions or
calculated values.

### Shots

film.shot_budget gives the number of shots of the whole film: never more
than max, and target is the number to aim for. The budget is not yours
to change, and no shot is longer or shorter than 3 seconds to meet it.

No shot plays more than film.shot_limits.events different events — an
event that runs on from the shot before counts in each shot that plays
it — and no shot shows more than film.shot_limits.people different
people between its first instant and its last, a person who enters or
leaves during the take included. These are ceilings, not aims: most
takes hold one or two events. An event played over several steps is
still one event; the ceiling is on events, never on steps.

Work in this order.

1. For each scene, list what it must show: every event of its beats in
   the script's order, the work the scene records with the events that
   work is tied to (scenes[].work[].events), and the result the scene
   ends on (continuity_end).
2. Share the budget over the whole film before you write a shot: at
   least one shot for every scene. Do not share it evenly and do not
   divide the number of events by the steps a shot can hold. Weigh, for
   each scene, how many separate actions its events really hold, how far
   apart the working positions are, who takes part, which results must
   be seen, whether the actions join into one continuous take, and how
   many reference images a clip takes. A scene with many events needs
   more shots than a scene with one short exchange; a scene that stands
   on two backgrounds is not forced into one shot to save budget.
3. Choose how each group of actions is shot.
4. Write the shots in scene order: first and last state, timeline,
   camera, composition, motion, what must be visible, and how far each
   event gets.
5. Check the whole plan against the source before you return it.

Return one complete shot plan for all scenes in one JSON response. Code
never splits, joins or reorders your shots.

Nothing of the source is left out of the picture. data.omissions is
always []. Never leave an event between two shots, never say it happened
before a shot, never replace its action by a picture of its result, and
never drop it because it adds little. Attaching an event id to a step is
not showing the event: the step shows the action the source describes
and the result it leaves.

A take shows what 3 seconds hold at a natural pace. Group events into
one take when they follow one another in the same scene, stand on a
background that serves them all, join in state and position, can be
followed by one unbroken camera move, need no jump in time and no change
of working position the take cannot show, and stay within
film.reference_limit images and film.shot_limits. In a grouped take every event is played in
its own steps, in order. Two actions are simultaneous only when the
source makes them so: never turn actions that follow one another into
actions that happen together. Keep waiting and settling short, but keep
what the viewer needs to read the action.

An event may be too long for one take. Then it runs on through the shots
that follow, in the same scene, with nothing else between them: the
first shot starts it, the last one finishes it, and each shot after the
first opens on the exact state, position and direction of movement the
shot before left, with what is held still in the hand. Never play again
what an earlier shot already did, and never begin an event again after
it was finished. It is still one event of the source.

The boundary between two adjacent beats does not by itself require a new
shot. A shot may continue from the final events of one beat into the
opening events of the next beat in the same scene, provided their
setting, staging and operating states connect continuously: the beats
follow each other in the scene, the later beat marks no time jump, the
operating state the last event of the earlier beat ends in is the state
the first event of the later beat begins in, and one background serves
both.

Inside a take never speed the action up unnaturally, never let a part
appear at once, never skip an operation that is part of an event, never
hide an action that did not happen behind a move of the camera or a
close frame, and never put a finished result into the first frame and
count its event as played.

When every event, the budget, the limits of a shot and natural movement
cannot all be kept, do not return a plan that hides the problem. Add a blocking conflict
that names the scenes and events that do not fit and why, with evidence
from the input, and return status partial. Never solve it by dropping an
event, by inventing an id, by writing more shots than max or by making a
shot longer.

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

Show each part as it is at the checkpoint the shot's first frame shows,
and at the end frame as it is at the checkpoint that frame shows:
installed, being made, waiting to be installed, removed, or as a drawing
or a model. On a design stage, its kind never turns a drawing into a
model: drawings stay drawings.
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

You write no list of work. Each work item of the scene plan is tied to
the events its evidence names (scenes[].work[].events), and code takes
the work as done in the shot that completes the last of those events.
Until then the part is not in its finished place: a shot that only
starts or continues the event shows the part on its way — being lifted,
being brought to its seat — and the shot that finishes the event shows
it reach the place the work gives it (location_end). The shots after
that open on the part as the work left it. Never decide a removal,
fitting or making of your own.

purpose says in one sentence what the viewer learns from the shot and
what it adds to the purpose of its scene. It is the reason for the way
the shot is filmed: the camera and the composition are chosen so that
the viewer gets exactly this. Write your decisions straight into
purpose, camera and composition; write no separate account of a visual
idea.

reveal says how much of the motorcycle the shot shows, on the scale of
the script's reveal, and never more than its beats reveal: a shot that
covers several beats shows no more than the beat that reveals least, so
do not join beats whose reveal the take cannot honour together. Never show a
later build state or checkpoint than the shot names and never morph between
build states. Nothing shows a
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

The video model keeps only a few people recognisable from their
reference images, so people never lists more than
film.shot_limits.people characters: everyone a step names as an actor
and everyone an event the shot plays names is one of them. When the
events of a scene involve more people, tell them as several shots of
one or two; a person who is not in people is not in the frame of that
shot, not even in its background. When one event itself names more
people than a shot may show, do not leave anyone out: add a blocking
conflict that names the event and return status partial.

### Time

Every shot is one take of 3000 ms, all of it used, made from reference
images. Code sets the length and the mode of every take: write no
duration and no mode. The length is not yours to choose and no part of
the take is cut away.

first_frame is the picture at 0 ms, described so that it can be recognised:
who and what is where, in which state. It shows operating_start and never
the result the shot creates. end_frame is the picture at 3000 ms: it
shows operating_end and says what must be readable in it. The first
frame of a shot that continues an event is the end frame of the shot
before it.

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
being switched off. Do not show a position a limit governs at an
instant before its procedures are established: keep that part out of
parts there, or open the shot later. The state a limit requires —
standing still, engine off, stand down — holds at every instant of the
shot at which the position is in frame, not only at its first and last.

support_refs names what holds the motorcycle by id only: a part, a
character of the shot, or a temporary item the checkpoints of the shot
list; never words. A stand that one checkpoint lists never appears at a
checkpoint that does not list it.

action_timeline tells what happens, as steps STEP01, STEP02, ... in
order: the first starts at 0 ms, each starts where the one before ends,
the last ends at 3000 ms. The steps play the events in the order the
shot covers them: an event may run over several steps one after
another, but once a later event has begun no step returns to an
earlier one. Each step plays at most one event (event_ids holds that one
id, or [] for a step that only prepares, joins or settles the picture),
names the characters who act in it (actor_ids) and gives one action and
its visible result. The action of a step is a short direction of how it
is staged for this camera: who or what moves, how fast against the size
of the frame, where the pace rises or rests, and — when the event runs
on into the next shot — the state in between that the step ends on. Do
not copy the words of the event: the video prompt reads the event itself
from the script. The result of a step is what must be seen in the
picture when it ends, and at what size it reads. A speed or a distance
the sources do not give is your production choice. Never write several
events into one step and
describe a single action. What another person does at the same time is
written in the same step only when the source makes it simultaneous.
Give a step the time its action needs to be seen: about half a second for
a single movement of a hand or a foot, a second or more for a walk, a
sit, a start. Use one to six steps. A step without an event is used only
where the action needs it to be understood — a hand arriving, a part
settling — never to pad a shot and never as an invented event.

event_progress says how far the shot takes each event it covers, one
entry for every event of coverage, in timeline order: event_id, phase
and step_ids, the steps of this shot that play the event. phase is whole
when the event begins and is completed in this shot; start when it
begins here and is not completed; continue when it runs on from the shot
before and is still not completed; finish when it runs on from the shot
before and is completed here. An event is whole in one shot, or start,
any number of continue, then finish in shots that follow one another.
Do not write finish to stand in for showing the result: the steps named
show the event reach the result the source gives it.

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

Choose the way a shot is filmed for the action it plays and for what
the viewer must learn from it. Decide, for each shot, the view the
picture opens on — from which side, how high, how close — the view it
ends on, and the path between them.

The camera follows the action: it is near a hand when the hand acts and
wide when the result is the whole motorcycle with its rider. Every move
of the camera does something the viewer can see: it follows an
operation as it happens, it brings into view how something is built or
how it works, or it joins two things in the space — a hand and the part
it reaches, the rider and the road, the motorcycle and the place it
stands in. A move that does none of these is not a reason to move:
choose another one that does. The camera still moves from the first
instant to the last; prefer one simple path a take of three seconds can
really make, and join two moves only when the action needs both.

It travels only through the space the place gives it (camera_access and
its camera zones), past the landmarks the place has, and never through a
part, a person, a wall or a blocked zone. Shake of the frame or a reflection sliding over a surface is not
a camera move. Do not give every shot the same path, and do not orbit
the whole motorcycle unless the shot is about its whole form. No shot
has to orbit, to reveal, to carry a heavy foreground or to blur its
background: use one only where it serves the shot. Shots that follow
one another may change the way they show the action where the story
gains from it; never change the angle only to be different from the
shot before.

The way a shot is filmed never changes what happens. Never use an angle,
a close frame or a move to hide an operation the event requires, and
never change the blocking, an object or an event of the scene to get a
better-looking angle: film the scene as it is staged.

start_pose, end_pose and the description of each segment say in words
what the viewer sees: the side and the height of the view, how much of
the subject fills the frame, what passes in front of the lens. What
these words say agrees with the numbers.

### Composition

composition builds the 9:16 frame and holds while the camera moves. It
is a decision for this shot, written as what is seen:

- primary_focus: the subject or the detail the eye goes to, and how much
  it stands out from the rest of the frame.
- foreground and background: the layers of the picture that serve this
  shot, each named from what the place, the set and the parts really
  give. Write null when the shot needs none; never invent an object to
  fill a layer.
- spatial_relationship: who and what is where relative to each other,
  and which way the action runs across the frame, with the room it
  needs.
- depth_and_framing: how much of the subject and of the place the frame
  holds, the planes of depth, what is sharp and which detail must stay
  readable.

Write null for a part that does not apply. Do not repeat the camera
numbers.

How things move is written once: the pace of the subject in the steps of
action_timeline, the travel of the camera relative to it in camera.path.
What must be seen is written where it happens: in the result of the step
that shows it, in composition, and in end_frame for what the shot ends
on.

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
faithful: the identity of the motorcycle, the environment or the
continuity of an earlier shot of the same continuous action, in the same
scene or in the scene the scene plan continues from. Name the needs, not
images; code finds the images, and finds each character's reference from
people. Never ask for the reference of a part: write no need of kind
design. Code reads parts, finds the detail image of every part the frame
holds, and one image may show many parts, so the number of parts is not
the number of images. What makes a part's reference reach the clip is
the part standing in parts with the presentation it has in the shot:
list in parts every part the frame really holds, and only those. A clip
takes at most film.reference_limit images in all, people and background
included: keep reference_needs and people together within it.

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
continuity.carries the state the next shot starts from. assumptions
lists only an exception that needs to be made clear — a choice the
sources leave open and the shot depends on; it never repeats the lens,
a speed or any choice already written in camera, composition or the
timeline, and is [] when there is none. Record unresolved source gaps or
contradictions in conflicts, with evidence from this input.

### Before you return

Before returning the JSON, verify the complete plan:

- The plan has no more shots than film.shot_budget.max.
- Every supplied scene has shots, in scene order.
- Shot IDs run in order within each scene.
- For each scene, the events its shots play are exactly the events of
  its beats, in the script's order: none missing, none added, none of
  another scene. data.omissions is [].
- Every event is whole in one shot, or starts, continues and finishes in
  shots that follow one another; none is finished twice and none is
  begun again after it was finished.
- Every event of coverage has its entry in event_progress and its steps
  in action_timeline. No step plays two events.
- The shots of each scene together show the result the scene ends on,
  and the work the scene records is seen done where its events are
  completed. Every custom feature and signature moment the beats name is
  in frame in at least one shot.
- A shot that continues an event opens on the state the shot before left.
- No action is sped up unnaturally, no part appears at once, no
  operation inside an event is skipped.
- Composition, camera, timeline, first frame and end frame agree with
  one another.
- Every camera move does something the viewer can see, can be made in
  three seconds, and hides no operation an event requires; no blocking,
  object or event of the scene was changed for an angle.
- Every id in configurations belongs to a part in parts, and the
  operating states keep what the source events give.
- No shot writes a duration, a mode, a shot role, a motion plan, a list
  of visibility requirements or a result of its own.
- Its action timeline and camera path cover 0–3000 ms continuously.
- No shot plays more events or shows more people than film.shot_limits
  allows.
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

Return only compact JSON matching MotoShots, format moto-shots-v3. Copy
project_id and prompt_version. No commentary or code fence.

Use ok when every event is played, data.omissions is [], the plan is
within the budget and no blocking conflict remains;
partial when a shot plan exists with a blocking conflict; fail only when
the input cannot support a shot plan, with data {} and flags naming the
fault.
