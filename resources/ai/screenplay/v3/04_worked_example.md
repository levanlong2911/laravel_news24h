HOW THIS EXAMPLE WAS DECIDED

Read it against the input and the output beside it. It shows how scene
boundaries were drawn, how participants were declared and how coverage and
build state were filled in. It does not stand in for the design work this
assignment asks of you.

Its subject is a footbridge. Yours is not. Copy the reasoning, never the
crossing, the crews or the sentences.

WHAT THE THESIS DECIDED

central_idea states one rule: leave the floor open and protect the sides.
The other four fields are that rule seen from four sides, which is why they
can be checked against each other.

It does not claim the open deck performs better in wind than a solid one.
The input says the deck is open so wind passes through; the thesis repeats
that and stops. A thesis explaining why the edge members are also lighter
because of wind would be inventing engineering it cannot establish.

Nothing here says the crossing has been verified, certified or proved safe.
sc_05 is an examination of finished work. It is not a demonstration of
safety, not an acceptance, and not a transfer of responsibility, and nothing
in the coverage or the build state claims otherwise.

WHY THE DEVICE HOLDS

The device is a motif, not a prohibition: the walking surface is in frame in
all six scenes. Check a device against every scene before keeping it. One
that is true of three scenes out of six is a claim the film does not keep.

WHERE THE BOUNDARIES FALL

sc_04 holds several actions: an edge is sighted, a junction is closed,
protection is set upright and run out, and the lead stops between fixings to
look down. One place, one unbroken stretch of time, one situation. It is one
scene. Splitting it at the second fixing would divide a continuous action
for no reason.

sc_02 and sc_03 are separate scenes because the location changes and time
passes between them, not because the work changes character.

None of the six scenes uses CONTINUOUS. None of them needed to. Use it only
when a scene follows the one before it with no gap, typically across a
change of location, and never as a way to split a situation that is still
running.

Six scenes is the size this story needed. It is not a target. A film with
more ground to cover needs more scenes, and one with less needs fewer.

WHY THE PEOPLE ARE GROUPS

Design is settled by a team, the parts are made by a fabrication crew, the
structure is placed by an erection crew, and the crossing is used by a
stream of people. Declaring one designer, one fabricator and one traveller
would have turned a production process into three isolated figures.

A group is one declared subject, not its members. ch_fabricators is one
entry, not six.

Three people are declared separately, and each for a reason. ch_designer
carries the decision out of the room and returns at the end, so the film can
show whether it survived. ch_finisher does work no group performs
collectively: sighting an edge and deciding where a fixing goes. ch_operator
reads the finished detail on behalf of the people who will keep the crossing
open, which is one person's judgement rather than a crew's.

ch_designer appears in sc_02 alongside ch_fabricators. They are described
distinctly, the representative reading the drawing and the crew working the
trestles, so the same people are not counted twice.

There is no dialogue anywhere in this film. Everything the people decide is
visible in what they do. If a line had been needed, it would have gone to a
declared person present in that scene; a group never speaks as one voice.

WHAT BUILD STATE RECORDS

Every scene carries the field. sc_01 carries null, because at the layout
table the crossing does not physically exist yet: there is no build state to
record, not merely an unchanged one.

sc_03 and sc_04 both describe a deck that is not finished, and they describe
it differently, because build_state is the state at that scene and not the
change since the last one. A reader can open sc_04 alone and know what is
standing.

sc_02 records shop work and says plainly that nothing has been placed at the
gorge. Keeping that in the field is what stops a later scene from quietly
inheriting progress it never showed.

Null would have been wrong in sc_03 and sc_04 even if nothing had moved
between them. An unchanged state is still recorded.

A scene that showed only the people, with the crossing out of frame, would
carry null. That case does not arise here, so the shape is worth stating
rather than manufacturing:

  "build_state": null

WHAT COVERAGE CLAIMS

Ten items, each appearing exactly once. Coverage is an accounting of the
film that already exists; it is not a list of scenes to write.

sc_01 carries two items and sc_03 carries two, because a scene can support
more than one. No scene was added to reach an item.

cov_build_supports is a transition. The towers, anchorages and cables are
never shown being built. Naming sc_02 and sc_03, in that order, says where
the omitted work sits and what it was. Two distinct scenes, the one before
and the one after.

Every evidence line describes something a reader can find in the named
scene's action. "A pin is driven through the aligned holes at the joint" is
in sc_03 and can be checked. "The joint was completed and verified" would
not be: the film shows one fastening being made, not a joint finished.

Two labels in the profile were written to match what the film shows, rather
than the film stretched to match them. cov_build_erection asks for
fastening work at a connection, not for a joint completed and not for a span
crossed. cov_comp_inspection
asks for a finished detail examined, not for a handover. When a coverage item
and a scene disagree, one of them is wrong; fixing the evidence line alone
only hides it.

No item here is not_applicable, because every item in this profile applies
to this film. Do not invent a reason to use the mode. When an item genuinely
does not apply, it looks like this, with no scenes and the reason in
evidence:

  {
    "coverage_id": "cov_fin_surfacing",
    "mode": "not_applicable",
    "scene_ids": [],
    "evidence": "The open deck is the walking surface; there is no separate surfacing operation to show."
  }

That claim goes to an editor. It never satisfies a required item, and it
never authorises production on its own.

WHAT APPEARANCE HOLDS, AND WHAT IT DOES NOT

appearance describes what a participant looks like, and nothing else. It
has to read the same in the first scene and the last, because a later stage
reuses it without knowing which scene it came from.

So ch_finisher wears a padded jacket gone shiny at the elbows and knee pads
over the trousers. That the lead kneels more often than stands, and sights
along an edge before fixing anything, is behaviour: it lives in sc_04's
action and in personality, not in appearance.

The same separation applies to locations. lo_deck_section is a stretch of
deck reached along the crossing from the near rim, and that is all. An
earlier draft added "open along both edges", which was true in sc_04 and
false in sc_05, because the side protection goes up between them. A
temporary condition belongs in build_state, never in a description two
scenes share.

For a group, describe what its members share without making them identical.
ch_travellers carry bags and bundles and one or two have a child on the
hip; they are not a row of matching figures.

WHAT THE DURATIONS ARE FOR

Each estimate comes from the action written above it, not from a house
rhythm. sc_03 is the shortest because it is one pin and one wrench. sc_05 is
nearly as short because it is one person at one junction. sc_04 is the
longest because a junction is closed and a run of protection goes up. sc_01
needs room for a route traced the long way and then the short way.

The estimate is also a check on the action. An earlier sc_03 ran the whole
joint hole by hole, rechecked part of it and declared it finished, all in
eleven seconds: the action and the number disagreed.

Estimate the scene's duration from its observable action. If the estimate
does not fit, revise the duration or the scope of action. A scene may
contain several shots; shot planning comes later.

They are estimates, and they are the writer's best reading of the action.
Neither a flat number across every scene nor a spread of different numbers
is evidence of anything on its own.

WHAT WAS LEFT OUT

The film omits everything that moves a section out over the gorge and sets
it down. sc_03 opens with two sections already carried and held where they meet, and
the action says so rather than leaving the reader to assume it; the crew
reaches that joint along the deck behind it and makes one fastening. Nothing in the input establishes how a section travels, what
holds it on the way or how it is landed, so no scene shows any of it and no
sentence implies it.

Two earlier drafts failed this. The first had the crew "setting sections out
along the cables". The second had them "bring the next one up to the joint".
Both gestured at handling the film had not established, and neither was
fixed by moving the scene or renaming its location. The scene had to start
later, after the placing, with the pieces already held.

Omitting an operation is honest. Describing one vaguely is not, and a
transition is where the omission gets declared: cov_build_supports names the
placing as work that happens between sc_02 and sc_03.

finishing appears in arc_stages but not in arc_required_stages. It is in the
film because the story needed it, not because the profile demanded it.
