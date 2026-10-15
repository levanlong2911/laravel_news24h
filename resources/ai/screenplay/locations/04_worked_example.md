HOW THIS EXAMPLE WAS DECIDED

Read it against the input beside it. Its subject is a working harbour ferry.
Yours is not. Copy the reasoning, never the vessel, the crews or the
sentences.

WHAT THE SCREENPLAY CONTENT ALREADY DECIDED

The foundation fixed the ferry and its story: the passenger hall, the crew
stair, the helm cabin and the rows of gates along both sides. This step
reads only that content. The approved design is not in the input; the
application finds each place aboard in it afterwards.

Locations are the exception. The studio, the building dock, the harbour
and the two piers are places where the foundation's work and use happen,
so they may be added and are described in full.

THE PLACE ABOARD

The passenger hall is the one space of the brief, so it is subject_part,
carries brief_space "passenger_hall" and has story_name null. Its name,
description, enclosure and layout are null and its fixed_features and
light_sources are []: the application builds them from the design, so the
hall can never be described in two different ways. The gates are not
mentioned anywhere in the place, because the finishing treatment hangs
them; whether they are there belongs to each scene. Fitting-out inside the
hall is off screen, so the hall is a place only for the crossing.

The crew stair and the helm cabin are not declared, because no stage
treatment works or rests there. They stay part of the ferry. Had a
treatment set work in the helm cabin, that place would carry the
story_name "helm cabin", the words the foundation uses for it.

WHAT A PLACE AWAY FROM THE SUBJECT HOLDS

Each description is a short summary; the layout carries the arrangement.
The building dock has blocks, staging towers and a crane, but no hull: the
hull and how far it has been built belong to the scenes set there. The
template the crews carry is a prop, not a fixed feature, so it is left to
the scenes; so are the paper strip and the card benches in the studio. The
piers have a boarding edge and a waiting area, but no crowd and no ferry:
who waits and what arrives belong to the scenes.

THE LAUNCH

The completion treatment says "Yard crews flood the building dock.", so
launch quotes those words, takes "building dock" from them as the means
and points to lo_building_dock. The dock carries that means in its
fixed_features, in the watertight gate and the flooding culverts that hold
the words "building dock". It carries no slipway and no ship lift, because
the treatment names neither. The harbour is a place of its own because the
treatment warps the ferry out into it. Had the content not said how the
ferry is launched, launch would be null and no means would be chosen.

CONNECTIONS

The two piers are each their own place, connected to the hall and each
connecting back, because the operation treatment has the crowd step
aboard across the width there. The hall is aboard and the piers are not,
so each via says in a sentence what lies beyond the ferry's side; the
application finds the gates of the design that lead outside. The dock and
the harbour, both away from the ferry, are joined through via alone: the
dock gate. The studio connects to nothing a scene walks through: the film
moving from one place to another is a cut in the story, never a
connection.

THE PROJECT BRIEF

The film_brief lists one space, the passenger hall, and the treatment
brings passengers into it, so lo_ferry_hall carries its key. Every other
location carries null. The brief leaves engine room work, fitting-out
inside the hall and the handover off screen, so there is no engine room
location and no fitting-out quay, though the engine room stays part of the
ferry.

WHAT THIS STEP DOES NOT DO

There are no characters, scenes, coverage, measurements or ids in the
answer, and nothing about equipment access: the application records it.
