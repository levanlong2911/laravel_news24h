HOW THIS EXAMPLE WAS DECIDED

Read it against the input beside it. Its subject is a working harbour ferry.
Yours is a superyacht. Copy the reasoning, never the vessel or the
sentences.

WHAT WAS SET ASIDE FROM THE INSPIRATION

Everything that made the source ferry's design its own was set aside: its
length, beam and passenger count, the aluminium catamaran hull, the
enclosed saloon and its bar, and the open upper deck at the stern. None of
them appears in the answer, renamed or otherwise. Only one abstract
question was kept: how can each stop take less of the crossing?

ONE IDEA, THE WHOLE VESSEL

central_idea states one rule: people move on and off across the ferry's
width. The identical ends, the gates along both sides, the benches across
the width and the helm cabin at mid-length all follow from it, and none of
them is a source feature turned the other way round.

THE REQUIREMENT AND THE MOVING PART

The profile asked for sides that transform. The answer designs the gates
in each state: what they slide on, how they sit closed, what they are
open, where they stack and the band they sweep, and for each state only
whether the side can be passed. When the gates open and who walks through
them is left to the screenplay. A design without such a requirement need
not move at all.

spatial_consequence names what the idea does to the hall: one undivided
space whose width joins the seats to the pier. It says nothing about what
passengers feel or do there, and the thesis names no building or fitting
out for the film to show; both belong to the screenplay.

Each opening people pass through names both its sides in connects: the
gates join the hall to the quay, so their second side is null; the hatch
joins the helm floor to the hall floor, the two surfaces at the ends of the
upper crew flight that passes through it, and names no space, because the
hall lies on another deck; the walkway door joins the helm
floor to the roof walkway. The helm glazing is not passed through, so its
connects is null.

EACH FACT IN ONE PLACE

The answer describes the ferry once. Each sentence sits on the part it
describes and nowhere else:

- the hull's profile, plan and ends are written in hull_geometry,
  bow_geometry and stern_geometry, never retold in a summary;
- what each deck holds is the organization of that deck row;
- what each gate band and each cabin window looks like is the description
  of that opening row;
- the passenger hall, the one brief space, is the spatial_topology row
  whose brief_space is passenger_hall: its boundaries, connections,
  circulation, light and interior are written there, and its deck is named
  by id in deck_ids;
- the helm cabin is a space of its own, because its deck lies in a
  primary mass; its consoles are the fixed furniture of its interior;
- what each signature feature is and how it differs is written on its
  signature region, together with what still needs verifying;
- the finish is appearance_identity; how the ferry is built is
  construction.

A text may name another part when it needs to, as the hall's connections
name the crew stair, but it never adds a part or a connection the rows do
not declare, and it never gives a part a different deck, side or state.
The hall's interior adds no door, stair or deck: its ways in are the gate
openings and the crew stair rows.

ROW DESCRIPTIONS

Every row of every list carries a description that reads on its own. The
Port gate band's description says it is on the port side of the Hall on
the Main deck, what kind of opening it is, how its leaves move and what
carries the roof around it, all with names, never ids. It no longer says
the leaves are framed in the hull colour: that is finish, and finish lives
in appearance_identity.materials. The helm cabin windows are described as
fixed glazing in a named wall of the Helm cabin, not as clear glazing,
for the same reason. Each route names the place and deck at both ends,
its direction, the opening it passes and what carries it, and says its
landings are not described rather than inventing them. The figures'
descriptions say what each quantity measures and where its value comes
from without repeating a number. The structured fields stay exactly as
before; each description restates its row's decisions and adds none.

Every passage is declared once. The gates and the hatch are openings with
both sides in connects; the crew stair and the walkway steps are routes
between the decks they join. The walkway door joins the helm cabin floor
to the walkway one level lower, so the two steps outside it are a route of
their own. No space here opens straight onto another without an opening
or a route, so every open_to is empty; a hall flowing onto an open terrace
with no door would name the terrace there. A part that only lies beside
or within another is never a passage.

Each space lists its parts: the hall lists its void, and the hall floor
belongs to it because the floor lies within that void; the helm cabin
lists its floor, and the walkway lists its own surface, which stays placed
around the cabin. Belonging to a space is not a way through it: people
move between decks only along routes, so the hatch does not take anyone
from the hall to the cabin; the crew stair does. Each route names in
through the openings it passes: the upper flight of the crew stair the
floor hatch, the walkway steps the door at their head; the lower flight
passes none. A route is open only while those openings can be passed.

access_points says where people come aboard, as a decision of the design:
two points, one at each gate band, used from the pier. Each names the
state the gates are in while it is used, open on its own side; the gates
say in closes which state lets each band be passed. In the standard
state the gates are closed and nobody comes aboard, and that is not
asked of it. From each access point, in its own state, every locked space
is reached: the hall through the open gates, the helm cabin by the crew
stair, the walkway through the door and down its steps. The folding rail
also closes the gate bands, but it is a proposal, so the locked access
points do not rely on it; a design that locked the rail would add its
lowered state to both access points.

Each space says where its light comes from. The hall's light is the
glazing of the gate leaves and a proposed line of fixed lights; the
walkway is open to the sky. Later steps take the light of a place from
these sentences only.

figures inherits length and beam exactly from principal_dimensions; the
other numbers are proposed or left undetermined, and a figure that is not
decided says so in its note.

THE STRUCTURED GEOMETRY

canonical_design defines the ferry as ids, relations and states. The single
end door is transformed into the gate band and the end wheelhouse is
rejected; both patterns come from anti_regression and are forbidden only
because they conflict with this design. superyacht_reading, written here
for a ferry, says how the whole still reads as its own kind of vessel.
Every mass and void takes part in a relationship, every signature region
has a transition, and both transforming features have configuration states
while the fixed helm cabin has none. The core sections, both masses, the
hall void and both P0 regions are locked, and every locked relationship,
transition, invariant and proof names only locked parts. What is a
proposal stays proposed: the folding rail and the finishes stay out of
every invariant. A locked row also never relies on a proposed one in its
text: the helm cabin's connections and the roof deck's organization use
the walkway, its door and its steps, so those are locked too, and the
mooring stations the hall's circulation reaches are locked. The folding
rail stays proposed, so the hall's interior does not list it; the rail is
written once, on its own region. A detail with no row of its own, such as
the bicycle racks or the line of lights, may be marked as a proposal in
the text that owns it. The hall runs from bulwark to bulwark, so the
mooring stations stand in its corners inside the bulwarks; no strip of
deck is left outside it. Each P0 region and each
P0 must_preserve entry names the views that prove it.

THE TYPED GEOMETRY

The decks run from the crew spaces in the hull to the helm cabin, one per
level. Each gate band is one movable_closure opening on its side of the
main deck, and the helm cabin has a glazing opening on each of its four
faces and a hatch in its floor. The floors, the proposed walkway and the
crew stair are rows of their own; the stair joins the decks it really
joins, and the door and steps to the walkway are rows of their own. A
ferry has no pool, so basins is empty.

Every governing line, mass, void, transition, opening, surface, basin,
route and moving component has a name, and every part is called by that
name wherever a text mentions it. An invariant that protects the sheer
line is shown as "Sheer line", never as its id. Later steps show these
names; they never make a name up from an id.

The helm cabin glazing sits in a shallow recess. The recess is a secondary
row whose position names the faces it holds; each glazing opening still
serves the helm cabin floor behind it, never the recess.

Every region, opening, floor and route says how it stands to the outside.
The gates and the cabin glazing are exterior. The hall, its floor and the
upper flight of the crew stair lie inside but show through the open gate
bands, so they are interior_affects_exterior. The crew floor in the hull,
the lower flight below it and the hatch in the cabin floor show nowhere
outside, so they are interior_only. Each is decided from where the part
lies and what the outside shows, never from its name.

Every floor spans the full width of its deck and the walkway runs around
the helm cabin, so each surface's side is both. A ferry with a separate
walkway along each side would give them sides port and starboard; one
whose side the design leaves open would say unspecified.

proportion_system.vertical gives the heights against one another without a
unit, and the hull states its design waterline and every part below it,
naming what the hull does not have.

The ferry has no mast. Its one radar stands on the centerline at the
forward part of the highest deck, the helm cabin roof, on a mounting base
that is its own structural row with equipment_kind null. The radar, the
side lights and the horn are rows of form equipment; their mounting bases
are structural rows. None of them is named by an invariant,
a transition or a proof, because the equipment is fitted at finishing and
a bare-metal reference leaves it out while keeping the base.

construction states the structure, its materials, the block division, the
assembly and the weight arrangement as principles. It names no
calculation and no verified result, and what is not decided, such as the
block division along the length, says so.

WHAT THIS STEP DOES NOT DO

There is no premise, no stage, no event and no scene. The film is written
later, around this design, and may not change it.
