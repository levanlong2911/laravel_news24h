<?php

return [
    'pipeline_version' => '2026.07.22',

    'llm_cost_ceiling_usd' => (float) env('VIDEO_LLM_COST_CEILING_USD', 0.05),

    'api_token' => env('VIDEO_API_TOKEN'),

    'runner' => [
        'python_bin' => env('VIDEO_PYTHON_BIN', 'python'),
        'runner_dir' => env('VIDEO_RUNNER_DIR', ''),

        'log_dir' => env('VIDEO_RUNNER_LOG_DIR', storage_path('logs/video-runner')),

        'log_retention_days' => env('VIDEO_RUNNER_LOG_RETENTION_DAYS', 21),
    ],

    'sync_render' => (bool) env('VIDEO_SYNC_RENDER', true),

    'render_mode' => env('VIDEO_RENDER_MODE', 'direct'),

    'python_runner_enabled' => (bool) env('VIDEO_PYTHON_RUNNER', true),

    'planning_queue' => [
        'connection' => env('VIDEO_PLANNING_QUEUE_CONNECTION', 'video'),
        'name' => env('VIDEO_PLANNING_QUEUE', 'video-planning'),

        'sync' => (bool) env('VIDEO_PLANNING_SYNC', false),
    ],

    'editorial_policies' => [
        [
            'match' => ['builder' => 'Feadship'],
            'prohibit_attribute' => 'domes',
            'prohibit_value' => true,
            'reason' => 'integrated satellite receivers instead of exposed radomes (2025 refit)',
        ],
    ],

    'creative_concept' => [
        'mode' => env('VIDEO_CREATIVE_CONCEPT_MODE', 'disabled'),
    ],

    'tests' => [
        'known_good_geometry_prompt_sha256' => '2092f18636fa1cc798d6c6708c1fc09968333a35f1d2abb86315b28c8f716a4b',
        'known_good_geometry_render_sha256' => 'b2d52e0e6384b01d1457aff320a6d6bea0cb3846786042c7ca140d518eb3f116',
    ],

    'creative_profiles' => [
        'categories' => [
            'yacht' => 'luxury_vessel',
        ],

        'profiles' => [
            'luxury_vessel' => [
                'mission' => 'Prepare a concise source-inspiration brief containing information from this article that could help a separate creative designer invent a completely new superyacht.',

                'concept_mission' => 'Design a superyacht that has never existed. Its proportions and silhouette must be readable from outside the vessel.',

                'identity_cross_checks' => [
                    [
                        'kind' => 'ratio',
                        'numerator' => 'design_length_m',
                        'denominator' => 'design_beam_m',
                        'equals' => 'length_to_beam_ratio',
                        'tolerance' => 0.08,
                    ],
                ],

                'design_spec_export' => [
                    'schema_version' => '1.0',

                    'invariants' => [
                        'length_to_beam_ratio',
                        'bow_geometry',
                        'stern_geometry',
                        'continuous_sheer',
                        'superstructure_envelope',
                        'enclosed_deck_level_count',
                        'opening_layout',
                    ],

                    'export_aliases' => [
                        'bow.forefoot' => ['continuous_convex' => 'continuous_convex_transition'],
                        'bow.chine' => ['hard_to_midships' => 'hard_chine_to_midships'],
                        'stern.transom' => ['plumb_full_beam' => 'plumb_full_beam_transom'],
                        'stern.platform' => ['recessed_waterline' => 'integrated_recessed_waterline_platform'],
                        'superstructure.envelope' => ['one_continuous_shell' => 'one_continuous_primary_shell'],
                        'openings.distribution' => ['horizontal_ribbon' => 'horizontal_flush_ribbon_apertures'],
                        'openings.hull_openings' => ['sparse_service' => 'sparse_service_openings'],
                    ],

                    'export_key_names' => [
                        'stern.transom' => 'type',
                        'openings.aperture_bands' => 'superstructure_bands',
                        'openings.distribution' => 'language',
                        'openings.surface_relationship' => 'configuration',
                    ],
                ],

                'concept_forbidden_terms' => [
                    'cantilever',
                    'cantilevered',
                    'cantilevering',
                    'wedding cake',
                    'stacked slabs',
                    'apartment block',
                    'cruise ship',
                    'bulbous',
                    'overhanging',
                    'floating slab',
                    'fin',
                    'fins',
                ],

                'concept_antipatterns' => [
                    'independent horizontal slabs stacked like a wedding cake',
                    'apartment-block or cruise-ship massing',
                    'decorative mast, radar domes, antennas unless required by source evidence',
                    'bulbous bow, anchor pockets, or unexplained hull-side apertures unless required by source evidence',
                ],

                'arc_stages' => ['design', 'construction', 'finishing', 'completion', 'operation'],

                'arc_required_stages' => ['design', 'construction', 'completion', 'operation'],
                'article_patterns' => [
                    'design_profile',
                    'new_build_launch_or_delivery',
                    'construction_progress',
                    'refit_or_conversion',
                    'sale_charter_or_market',
                    'owner_or_celebrity_news',
                    'performance_or_sea_trial',
                    'incident_or_legal',
                    'mixed',
                ],
                'inspection_aspects' => [
                    'size_and_dimensions',
                    'form_and_proportions',
                    'spatial_layout',
                    'deck_organization',
                    'materials_and_finishes',
                    'amenities',
                    'windows_and_glazing',
                    'wellness_and_relaxation',
                    'transformable_spaces',
                    'capacity',
                    'construction_new_build_and_refit',
                ],

                'identity_slots' => [
                    'design_length_m' => [
                        'type' => 'number',
                        'min' => 100.0,
                        'max' => 180.0,
                        'guidance' => 'Editorial floor: if the source vessel is smaller, '
                            .'scale the new design up to at least 100 m.',
                    ],
                    'design_beam_m' => [
                        'type' => 'number',
                        'min' => 10.0,
                        'max' => 35.0,
                        'guidance' => 'Beam must be consistent with design_length_m and length_to_beam_ratio.',
                    ],
                    'length_to_beam_ratio' => ['type' => 'number', 'min' => 5.5, 'max' => 7.5],
                    'design_draft_m' => ['type' => 'number', 'min' => 2.0, 'max' => 6.0],
                    'visible_freeboard_at_midships_m' => ['type' => 'number', 'min' => 1.5, 'max' => 6.0],
                    'typical_deck_to_deck_height_m' => ['type' => 'number', 'min' => 2.6, 'max' => 3.5],
                    'visible_deck_tiers' => ['type' => 'integer', 'min' => 3, 'max' => 6,
                        'guidance' => 'A verification count, not a design motif. State it here and nowhere else.'],
                    'bow' => ['type' => 'object', 'fields' => [
                        'stem' => ['type' => 'enum', 'values' => ['plumb', 'near_plumb']],
                        'rake_degrees' => ['type' => 'number', 'min' => 0.0, 'max' => 25.0],
                        'waterline_entry' => ['type' => 'enum', 'values' => ['fine', 'moderate', 'full']],
                        'forefoot' => ['type' => 'enum', 'values' => ['continuous_convex', 'hard_knuckle', 'rounded']],
                        'chine' => ['type' => 'enum', 'values' => ['hard_to_midships', 'soft', 'none']],
                    ]],
                    'hull' => ['type' => 'object', 'fields' => [
                        'sheer' => ['type' => 'enum', 'values' => ['continuous_gentle_rise_toward_bow', 'continuous_level']],
                        'midbody' => ['type' => 'enum', 'values' => ['full_displacement', 'slender_displacement']],
                        'keel' => ['type' => 'enum', 'values' => ['continuous_central_baseline']],
                    ]],
                    'stern' => ['type' => 'object', 'fields' => [
                        'transom' => ['type' => 'enum', 'values' => ['plumb_full_beam']],
                        'platform' => ['type' => 'enum', 'values' => ['recessed_waterline']],
                        'overhang' => [
                            'type' => 'boolean',
                            'guidance' => 'true only when the stern projects aft beyond the transom base.',
                        ],
                        'transom_face' => ['type' => 'text', 'max_length' => 90],
                    ]],
                    'superstructure' => ['type' => 'object', 'fields' => [
                        'envelope' => ['type' => 'enum', 'values' => ['one_continuous_shell', 'faceted_continuous_shell', 'swept_integrated_shell']],
                        'massing_position' => ['type' => 'enum', 'values' => ['central', 'central_aft', 'aft']],
                        'external_read' => ['type' => 'enum', 'values' => ['single_integrated_mass', 'continuous_low_volume', 'compressed_wedge_mass']],
                        'long_foredeck' => [
                            'type' => 'boolean',
                            'guidance' => 'true when the foredeck runs clear for roughly a fifth of the length or more.',
                        ],
                        'tier_rule' => ['type' => 'enum', 'values' => [
                            'verifiable_within_one_continuous_mass',
                            'verifiable_within_two_connected_masses',
                        ]],
                        'profile_note' => ['type' => 'text', 'max_length' => 90],
                    ]],
                    'openings' => ['type' => 'object', 'fields' => [
                        'aperture_bands' => [
                            'type' => 'integer',
                            'min' => 1,
                            'max' => 8,
                            'guidance' => 'Count aperture bands, not deck levels; align with visible_deck_tiers when they correspond.',
                        ],
                        'distribution' => ['type' => 'enum', 'values' => ['horizontal_ribbon']],
                        'vertical_extent' => ['type' => 'enum', 'values' => ['partial_height', 'mixed_by_zone']],
                        'surface_relationship' => ['type' => 'enum', 'values' => ['flush_recessed', 'flush']],
                        'hull_openings' => ['type' => 'enum', 'values' => ['sparse_service', 'minimal_service', 'none']],
                    ]],
                    'glazing_type' => [
                        'type' => 'text',
                        'max_length' => 60,
                        'guidance' => 'The finished glazing only; at fabrication stage every aperture is still empty.',
                    ],
                    'hull_material' => ['type' => 'text', 'max_length' => 60],
                    'superstructure_material' => ['type' => 'text', 'max_length' => 60],
                    'hull_colour' => ['type' => 'text', 'max_length' => 60],
                    'boot_stripe_colour' => [
                        'type' => 'text',
                        'max_length' => 90,
                        'guidance' => 'State the boot stripe colour and its placement along the lower hull.',
                    ],
                    'superstructure_colour' => ['type' => 'text', 'max_length' => 60],
                ],

                'viewpoint_guidance' => [
                    'front_three_quarter' => 'Low camera near the design waterline, off the bow. Bow face and hull-side features read well; flat deck surfaces are strongly foreshortened and may be unreadable.',
                    'side' => 'Low camera near the design waterline, square to the centreline. Sheer line, freeboard, tier count and hull-side features read well; deck surfaces are edge-on.',
                    'rear_three_quarter' => 'Low camera near the design waterline, off the quarter. Transom and aft hull-side features read well; flat deck surfaces are strongly foreshortened.',
                ],

                'excluded_context_types' => [
                    'owner',
                    'celebrity',
                    'sale_price',
                    'builder',
                    'designer',
                    'brand',
                    'product_name',
                    'legal_controversy',
                ],
            ],
        ],
    ],

    'creation_arc' => [
        'categories' => [
            'yacht' => 'vessel',

            'cars' => 'restoration',
            'moto' => 'restoration',
        ],

        'phase_sets' => [
            'vessel' => [
                'identity' => [
                    'permanent' => [
                        'visual_identity' => 'This is the construction of a 90-metre steel superyacht — a slender superyacht about six times longer than it is wide, with a knife-like vertical plumb bow and a wide flat stern.',
                    ],

                    'construction' => [
                        'visual_identity' => 'the same large steel superyacht hull under construction, a future luxury motor yacht, raw mill-scale steel plate in patchy grey and red-brown primer, rough matte surface with rust streaks, visible horizontal weld seams and white chalk survey marks, a raked plumb bow, no superstructure on it yet — the top is an open flat deck edge with nothing above it, no windows fitted, only rectangular cut-outs in the plating, no railings, no mast, no radar, no name',
                    ],
                    'final' => [
                        'visual_identity' => 'the same luxury motor yacht with a dark navy metallic hull, white superstructure, three decks, a raked plumb bow, long horizontal tinted glass bands along each deck, and a slender radar mast behind the wheelhouse',
                    ],
                ],

                'phases' => [
                    'design' => [
                        'purpose' => 'ESTABLISH',
                        'render_strategy' => 'IMAGE',

                        'requires_state' => 'technical_drawing',

                        'objective' => 'Establish that this vessel began as a drawing — the concept exists on paper before any steel is cut.',

                        'camera_target' => 'design_drawing',
                        'hero' => 'design_drawing',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => "A naval architect's hand holds a fine pen over a large General Arrangement drawing sheet spread across a wooden desk — several deck plan views laid out side by side, a side profile view of {hero_name} running along the top edge of the sheet; leather and fabric material swatches and a bound sample book rest beside the drawings.",
                        'micro_physics' => [
                            'The pen tip advances along a deck plan outline and a freshly drawn line remains behind it, the outlined section extending further across the sheet with every pass.',
                            "The architect's other hand slides a second drawing sheet a few centimetres to one side, uncovering more of the profile view beneath it.",
                        ],
                    ],

                    'construction_hull' => [
                        'requires_state' => 'hull_shell',
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',

                        'objective' => 'Show the vessel taking physical form — the bare steel hull being welded up from the inside.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'MAJESTIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],

                        'setting' => ['environment' => 'covered_shipbuilding_hall'],

                        'crowd' => ['worker' => 3],
                        'composition_note' => 'Two welders in hard hats stand inside the open steel hull of {hero_name}, between its upright transverse frames, tiny against its length; the overhead gantry crane runs on its rails above them with its hook hanging empty.',
                        'micro_physics' => [
                            'The nearer welder straightens up, steps across two frame bays toward the bow, and lowers himself again at the next joint.',
                            'His welding arc flares and dies in short bursts, and the raw steel around him brightens and dims with it.',
                            'Thin welding smoke rises out of the open hull and drifts slowly up toward the roof trusses.',
                        ],
                    ],

                    'construction_engine' => [
                        'requires_state' => 'machinery_installation',
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the scale of what goes inside — the propulsion machinery lowered into a hull that is still an open shell.',

                        'camera_target' => 'marine_engine',
                        'hero' => 'marine_engine',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'CENTERED', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],

                        'setting' => ['environment' => 'covered_shipbuilding_hall'],

                        'crowd' => ['supervisor' => 1],
                        'composition_note' => "A massive white marine diesel engine hangs from a heavy crane's rectangular lifting frame, chains taut at all four corners, suspended above the open deck plating of {hero_name}; a supervisor in a hard hat walks across the deck below, dwarfed by the engine's bulk.",
                        'micro_physics' => [
                            'The engine descends steadily toward the open engine-room hatch and the clearance beneath it shrinks continuously until the hatch opening is nearly filled.',
                            'The four lifting chains remain taut and evenly loaded, the engine holding level as it comes down.',
                            'The supervisor below walks clear of the landing zone, his path opening a widening distance between himself and the descending load.',
                        ],
                    ],

                    'craftsmanship' => [
                        'requires_state' => 'complete_hull',
                        'purpose' => 'DETAIL',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the hand work that separates a hull from a finished surface — precision at a scale the eye can check.',

                        'camera_target' => 'hull_seam',
                        'hero' => 'hull_seam',

                        'camera' => ['framing' => 'CLOSE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'HARSH', 'light_grade' => 'COOL'],
                        'setting' => ['environment' => 'finishing_hall'],
                        'crowd' => ['worker' => 1],
                        'composition_note' => 'A worker in protective gear and a face shield stands on the platform of an orange scissor lift raised against the towering white-painted hull of {hero_name}, working an angle grinder along a seam on the hull side.',
                        'micro_physics' => [
                            'The grinder advances along the seam and a smooth faired strip remains behind it, the finished stretch extending continuously while the rough section ahead grows shorter.',
                            'A tight fan of orange sparks streams from the contact point and travels with the grinder, always originating exactly where the disc meets the hull.',
                            'Fine dust drifts down the hull face below the contact point, settling on the platform rail.',
                        ],
                    ],

                    'experience_exterior' => [
                        'requires_state' => 'finished_vessel',
                        'purpose' => 'REVEAL',
                        'render_strategy' => 'IMAGE',

                        'objective' => 'Close the loop with the opening drawing — the finished vessel leaving the shed is the object that was on paper.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'TRACK', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'TRIUMPHANT', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],

                        'composition_note' => '{hero_name}, now complete, moves out of the shipyard shed onto open water at dusk, crew members standing in a line along the foredeck, beneath a pink and violet sky.',
                        'micro_physics' => [
                            'The vessel moves steadily forward and the lit shed opening behind it grows narrower, more of the open water ahead entering the frame.',
                            'Water parts at the bow into a widening wake that lengthens continuously astern.',
                            'The reflection of the dusk sky slides along the dark hull side as the vessel advances.',
                        ],
                    ],

                    'experience_onboard' => [
                        'requires_state' => 'finished_vessel',
                        'purpose' => 'RESOLUTION',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Land the payoff — put the viewer aboard the space that all the earlier construction was building toward.',

                        'camera_target' => 'upper_deck',
                        'hero' => 'upper_deck',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'LEADING_LINES', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],
                        'composition_note' => 'On the upper deck of {hero_name} at golden hour, a wide teak-planked social space opens out: low cream lounge seating arranged along both sides, an alfresco dining table set under an overhang, and a rectangular swimming pool set into the deck at the far end, its water catching the low sun.',
                        'micro_physics' => [
                            'The camera glides forward along the teak planking and more of the pool deck enters the frame with every metre, the seating that filled the foreground passing out of shot behind it.',
                            'The pool surface ripples continuously, its band of reflected sunlight stretching further across the water as the angle changes.',
                            'A light breeze moves the edge of a folded towel and the hem of a seat cushion cover.',
                        ],
                    ],
                ],
            ],

            'restoration' => [
                'identity' => [
                    'construction' => [
                        'visual_identity' => 'the same car, paint faded and scratched, front bumper cracked and held on with zip ties, mismatched wheels, no badges',
                    ],
                    'final' => [
                        'visual_identity' => 'the same car, now with deep glossy paint, a bare carbon-fibre bonnet, wide bolt-on fenders, and black split-spoke wheels',
                    ],
                ],

                'phases' => [
                    'arrival' => [
                        'purpose' => 'ESTABLISH',
                        'render_strategy' => 'IMAGE',
                        'objective' => 'Establish the before — the car as it arrived, alone in the workshop, before a single part comes off.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} stands alone on the polished concrete floor of a workshop, nose angled toward the camera, no one else in the room; steel racks of stacked tyres line the left wall, two dark wall-mounted screens and a tool bench sit along the back wall, and long fluorescent tubes run across the open timber ceiling.',
                        'micro_physics' => [
                            'Dust drifts slowly through the shafts of light from the skylight, settling on the bonnet.',
                            'A loose cable-tie on the front bumper sways slightly and comes to rest.',
                        ],
                    ],

                    'teardown' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the car coming apart — the first panels leaving the body and landing on the floor.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'CENTERED', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],
                        'composition_note' => 'Three mechanics in grey overalls work around the front of {hero_name} in the same workshop, one crouched at the wheel arch with a cordless impact driver, one lifting the bonnet, one drawing the front bumper away from the body; the same tyre racks, wall screens and tool bench stand behind them.',
                        'micro_physics' => [
                            'The front bumper separates from the body and the gap between them widens continuously until the bumper is clear.',
                            'The freed bumper is set down on the concrete and comes to rest, its lower lip flexing once.',
                            'The bonnet rises on its hinges and the dark engine bay opens into view beneath it.',
                        ],
                    ],

                    'bare_shell' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Reach the low point — the car stripped to a shell on stands, further from finished than when it arrived.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'HARSH', 'light_grade' => 'COOL'],
                        'composition_note' => '{hero_name} sits high on four axle stands in the same workshop with every wheel removed, bare brake discs exposed, the whole front clip stripped back to the intercooler and radiator support, removed panels laid out on the floor around it; the same tyre racks and wall screens fill the background.',
                        'micro_physics' => [
                            'A dark patch of fluid spreads slowly outward across the concrete beneath the engine bay.',
                            'A brake disc turns a quarter rotation on its hub and stops.',
                        ],
                    ],

                    'bodywork' => [
                        'purpose' => 'DETAIL',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the shape changing, not just the surface — wider panels going on that were never part of the original car.',

                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'LEADING_LINES', 'light_intensity' => 'HARSH', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => 'Two workers in respirators hold a pale unpainted composite fender panel against the flank of {hero_name}, whose body is now uniform grey primer; a matching panel is already fixed on the far side and a low front splitter rests under the nose, in the same workshop with the same racks behind.',
                        'micro_physics' => [
                            'The panel closes the last centimetres onto the flank and the gap along its edge disappears.',
                            'Fine sanding dust lifts off the primed surface and drifts down the body side.',
                        ],
                    ],

                    'paint' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Bring the colour back — the moment the shell stops being a project and starts being a car again.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'MAJESTIC', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} stands on its stands in the same workshop with fresh deep colour on every panel and a bare carbon-fibre bonnet left unpainted, the wide fenders now blended into the body, still without wheels or headlights.',
                        'micro_physics' => [
                            'The overhead fluorescent tubes draw a long highlight down the wet flank, the reflection lengthening as the surface flashes off.',
                            'A last drift of overspray thins out and clears from in front of the body.',
                        ],
                    ],

                    'reveal' => [
                        'purpose' => 'REVEAL',
                        'render_strategy' => 'IMAGE',
                        'objective' => 'Close the loop with the opening shot — the same spot on the same floor, the same car, finished.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'TRIUMPHANT', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],
                        'composition_note' => '{hero_name} sits back down on its own wheels on the workshop floor, complete, in the same position and the same angle it held in the opening shot, with the same tyre racks and wall screens behind it.',
                        'micro_physics' => [
                            'The body settles the last few centimetres as the stands come away and the suspension takes the weight.',
                            'The headlights come on and their glow spreads across the concrete ahead of the car.',
                        ],
                    ],

                    'drive_out' => [
                        'purpose' => 'RESOLUTION',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'End on the empty room — the work is over and the only thing left of it is on the floor.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'NEGATIVE_SPACE', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} pulls forward out of the workshop from the same camera position, the same tyre racks, wall screens and tool bench standing empty behind it.',
                        'micro_physics' => [
                            'The car moves out of frame and the floor it stood on opens up empty behind it.',
                            'Dark curved tyre marks stay printed on the concrete where it pulled away.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
