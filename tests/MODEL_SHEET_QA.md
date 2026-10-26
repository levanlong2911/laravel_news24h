# Model sheet QA regression tests

Run from the repository root:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Unit/ModelSheetQaRegressionTest.php --do-not-cache-result --colors=never
```

These tests use synthetic design fixtures and in-memory planning-stage objects. They do
not boot Laravel, access a database, call an image/LLM provider, or modify business code.
The service tests exercise only `ModelSheetQaService::output()` with an isolated config
container. Each test restores the previous container after use.

Review result on 2026-10-25: **33 tests, 61 assertions, 23 passing, 10 failing**.
The failing tests assert the required safe behaviour; they intentionally remain ordinary
failures until the production implementation is corrected. Do not turn them into tests
that expect the invalid QA to pass.

## Remaining defects reproduced

| Case | Failing tests | Observed behaviour |
| --- | --- | --- |
| Opposite-facing references | `test_opposite_facing_entity_is_also_rejected_via_fact_reference`, `test_service_does_not_mark_a_wrong_side_fact_reference_ready`, `test_opposite_facing_entity_is_also_rejected_via_child_path` | Fact IDs and descendant source paths bypass the side check; the service returns `ready=true`. The direct entity-ID control is rejected correctly. |
| Symmetric counterpart | `test_a_symmetric_partner_does_not_make_the_wrong_facing_part_visible` | Having a symmetric partner skips the explicit facing check for the referenced component itself. |
| MS8 fallback disagreement | `test_qa_cannot_change_the_ms8_fallback_without_changing_compiler_target` | With no explicit MS8 label, QA may select N2 while the compiler still uses fallback N1. |
| Invalid explicit MS8 target | `test_unknown_explicit_ms8_target_does_not_silently_fall_back_to_n1` | An explicit missing N9 target silently becomes N1. |
| Incomplete metadata propagation | `test_innovation_metadata_is_preserved_for_camera_filtering`, `test_ranged_feature_preserves_explicit_facing` | Innovation side/level and ranged-feature facing are dropped. |
| Coordinate origin | `test_coordinate_y_requires_the_centreline_origin` | A y axis explicitly measured from the starboard rail is accepted and treated as a centreline offset. |
| Self-occlusion alias | `test_self_occlusion_cannot_be_disguised_as_its_source_path` | A component's own source path is accepted as the component hiding it. |

Passing controls cover valid QA, coverage links, candidate decisions, whole-yacht framing,
blocking findings, typed numeric ownership/property, operational-state source and coverage,
explicit MS8 identity, profile/plan fact indexing, coordinate units and feet conversion,
declared-side conflicts, camera substitution, compiler length/token handling, malformed
JSON, and service prompt hashes.

These are structural regression tests. They do not certify natural-language semantics,
physical visibility, provider schema acceptance, image quality, SQL concurrency, or the
full render/approval/proxy workflow.
