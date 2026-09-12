Được. **Phần 16** sẽ khóa toàn bộ bài toán `Anchor → Reference Pack → Multi-view QA → Identity Lock`. Đây là mốc rất quan trọng: sau khi `Identity Lock` được tạo, Scene Planner, image scene, video keyframe và video provider **không được tự chọn lại hình học của subject**.

Architecture cuối của Part 16:

```text
Canonical Revision
      ↓
Master Anchor Render
      ↓
Part 15 Vision QA
      ↓
QA PASS
      ↓
Human/Admin Anchor Approval
      ↓
AnchorLockCandidate
      ↓
ReferencePackPlan
      ↓
Generate N reference views
      ↓
Part 14 Render Orchestrator
      ↓
Part 15 Single-view QA
      ↓
Cross-view Identity QA
      ↓
ReferencePack QA Gate
      │
      ├── FAIL
      │     ↓
      │   targeted reference repair / regenerate
      │
      ├── REVIEW
      │     ↓
      │   human review
      │
      └── PASS
            ↓
        Human/Admin Pack Approval
            ↓
        IdentityLockManifest
            ↓
        SHA-256 identity_lock_hash
            ↓
        FREEZE
══════════════════════════════════════
        IDENTITY BOUNDARY
══════════════════════════════════════
            ↓
Scene / Shot / Video pipeline
```

# PHẦN 16 — cấu trúc code

```text
media_runtime/
├── identity/
│   ├── __init__.py
│   ├── enums.py
│   ├── exceptions.py
│   │
│   ├── anchor/
│   │   ├── __init__.py
│   │   ├── approved_anchor.py
│   │   └── validator.py
│   │
│   ├── reference_pack/
│   │   ├── __init__.py
│   │   ├── view_spec.py
│   │   ├── plan.py
│   │   ├── planner.py
│   │   ├── render_request_builder.py
│   │   ├── artifact.py
│   │   └── pack.py
│   │
│   ├── multiview/
│   │   ├── __init__.py
│   │   ├── target.py
│   │   ├── request.py
│   │   ├── result.py
│   │   ├── prompt_builder.py
│   │   ├── response_parser.py
│   │   ├── validator.py
│   │   ├── gate.py
│   │   └── service.py
│   │
│   ├── lock/
│   │   ├── __init__.py
│   │   ├── manifest.py
│   │   ├── serializer.py
│   │   ├── hasher.py
│   │   ├── validator.py
│   │   └── service.py
│   │
│   └── service.py
│
└── tests/identity/
    ├── test_anchor_approval.py
    ├── test_reference_pack_plan.py
    ├── test_reference_render_requests.py
    ├── test_multiview_parser.py
    ├── test_multiview_gate.py
    ├── test_identity_lock_manifest.py
    ├── test_identity_lock_hash.py
    └── test_identity_lock_immutability.py
```

Laravel:

```text
app/Video/Identity/
├── Enums/
│   ├── AnchorApprovalStatus.php
│   ├── ReferencePackStatus.php
│   └── IdentityLockStatus.php
│
├── Models/
│   ├── ApprovedAnchor.php
│   ├── ReferencePack.php
│   ├── ReferencePackAsset.php
│   └── IdentityLock.php
│
├── Services/
│   ├── AnchorApprovalService.php
│   ├── ReferencePackService.php
│   ├── ReferencePackCheckpointService.php
│   ├── IdentityLockService.php
│   └── IdentityLockResolver.php
│
└── Controllers/
    └── IdentityController.php
```

## 16.1 Boundary phải khóa trước

`CanonicalDesignSpec` vẫn là design truth.

`ApprovedAnchor` là:

```text
visual realization của canonical revision
đã qua QA + human approval
```

`ReferencePack` là:

```text
nhiều view của CÙNG subject identity
```

`IdentityLockManifest` là:

```text
canonical revision
+
approved anchor
+
approved reference pack
+
identity-critical constraints
+
hash lineage
```

Nó **không phải CanonicalDesignSpec mới**.

---

# 16.2 Enums

`identity/enums.py`

```python
from __future__ import annotations

from enum import StrEnum


class AnchorApprovalStatus(StrEnum):
    PENDING = "pending"
    APPROVED = "approved"
    REJECTED = "rejected"


class ReferencePackStatus(StrEnum):
    PLANNED = "planned"
    RENDERING = "rendering"
    QA_PENDING = "qa_pending"
    REVIEW = "review"
    APPROVED = "approved"
    REJECTED = "rejected"


class ReferenceAssetStatus(StrEnum):
    PLANNED = "planned"
    RENDERING = "rendering"
    QA_PENDING = "qa_pending"
    PASSED = "passed"
    FAILED = "failed"
    REVIEW = "review"


class IdentityLockStatus(StrEnum):
    CANDIDATE = "candidate"
    FROZEN = "frozen"
    SUPERSEDED = "superseded"


class ReferenceViewRole(StrEnum):
    PORT_PROFILE = "port_profile"
    STARBOARD_PROFILE = "starboard_profile"

    BOW_HEAD_ON = "bow_head_on"
    STERN_HEAD_ON = "stern_head_on"

    HIGH_FRONT_THREE_QUARTER = (
        "high_front_three_quarter"
    )

    HIGH_REAR_THREE_QUARTER = (
        "high_rear_three_quarter"
    )

    HIGH_OVERVIEW = "high_overview"

    CAMERA_MATCHED = "camera_matched"


class MultiViewDecision(StrEnum):
    PASS = "pass"
    FAIL = "fail"
    REVIEW = "review"


class MultiViewFindingStatus(StrEnum):
    CONSISTENT = "consistent"
    INCONSISTENT = "inconsistent"
    UNCERTAIN = "uncertain"
    NOT_COMPARABLE = "not_comparable"


class IdentityMismatchType(StrEnum):
    COUNT_DRIFT = "count_drift"
    PROPORTION_DRIFT = "proportion_drift"
    SILHOUETTE_DRIFT = "silhouette_drift"
    TOPOLOGY_DRIFT = "topology_drift"
    GEOMETRY_DRIFT = "geometry_drift"
    OPENING_DRIFT = "opening_drift"
    MATERIAL_DRIFT = "material_drift"
    STATE_DRIFT = "state_drift"
    FEATURE_DRIFT = "feature_drift"
    UNKNOWN = "unknown"
```

---

# 16.3 Exceptions

`identity/exceptions.py`

```python
from __future__ import annotations


class IdentityRuntimeError(RuntimeError):
    pass


class AnchorApprovalError(
    IdentityRuntimeError
):
    pass


class ReferencePackError(
    IdentityRuntimeError
):
    pass


class MultiViewQaError(
    IdentityRuntimeError
):
    pass


class IdentityLockError(
    IdentityRuntimeError
):
    pass


class IdentityLineageError(
    IdentityLockError
):
    pass
```

---

# 16.4 ApprovedAnchor

Anchor chỉ được dùng cho Reference Pack khi:

```text
Render SUCCEEDED
+
Part15 QA PASS
+
human/admin approved
```

`identity/anchor/approved_anchor.py`

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class ApprovedAnchor:
    version: str

    anchor_id: str

    asset_id: str

    render_id: str

    render_request_hash: str

    artifact_id: str

    artifact_hash: str

    artifact_storage_key: str

    mime_type: str

    width: int | None
    height: int | None

    qa_run_id: str
    qa_report_hash: str

    canonical_revision_id: str
    canonical_hash: str

    projection_hash: str
    constraint_set_hash: str
    prompt_spec_hash: str

    approval_id: str
    approved_by: str
    approved_at: str
```

`approved_by` là opaque admin/user ID từ Laravel. Python không cần biết identity người đó.

---

# 16.5 Anchor validator

`identity/anchor/validator.py`

```python
from __future__ import annotations

import re

from media_runtime.identity.anchor.approved_anchor import (
    ApprovedAnchor,
)
from media_runtime.identity.exceptions import (
    AnchorApprovalError,
)


_SHA256 = re.compile(
    r"^[a-f0-9]{64}$"
)


class ApprovedAnchorValidator:
    def validate(
        self,
        anchor: ApprovedAnchor,
    ) -> None:
        required = {
            "anchor_id":
                anchor.anchor_id,

            "render_id":
                anchor.render_id,

            "artifact_id":
                anchor.artifact_id,

            "qa_run_id":
                anchor.qa_run_id,

            "canonical_revision_id":
                anchor
                .canonical_revision_id,

            "approval_id":
                anchor.approval_id,
        }

        for name, value in (
            required.items()
        ):
            if not value:
                raise AnchorApprovalError(
                    f"{name} cannot be empty"
                )

        hashes = {
            "render_request_hash":
                anchor
                .render_request_hash,

            "artifact_hash":
                anchor.artifact_hash,

            "qa_report_hash":
                anchor.qa_report_hash,

            "canonical_hash":
                anchor.canonical_hash,

            "projection_hash":
                anchor.projection_hash,

            "constraint_set_hash":
                anchor
                .constraint_set_hash,

            "prompt_spec_hash":
                anchor.prompt_spec_hash,
        }

        for name, value in (
            hashes.items()
        ):
            if not _SHA256.fullmatch(
                value
            ):
                raise AnchorApprovalError(
                    f"Invalid {name}"
                )

        if not anchor.mime_type.startswith(
            "image/"
        ):
            raise AnchorApprovalError(
                "Approved anchor must be image."
            )
```

---

# 16.6 Reference View Spec

Reference pack không chứa prompt text.

Nó chứa **view intent**.

`identity/reference_pack/view_spec.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.identity.enums import (
    ReferenceViewRole,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceCameraSpec:
    view: str

    side: str | None

    elevation: str | None

    pitch: str | None

    lens_character: str | None

    framing: str

    subject_orientation: str | None = None


@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceViewSpec:
    view_id: str

    role: ReferenceViewRole

    order: int

    required: bool

    camera: ReferenceCameraSpec

    additional_required_paths: tuple[
        str,
        ...,
    ] = ()

    metadata: dict[
        str,
        Any,
    ] | None = None
```

---

# 16.7 Reference Pack Plan

`identity/reference_pack/plan.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.identity.reference_pack.view_spec import (
    ReferenceViewSpec,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ReferencePackPlan:
    version: str

    pack_id: str

    asset_id: str

    canonical_revision_id: str
    canonical_hash: str

    anchor_id: str
    anchor_artifact_id: str
    anchor_artifact_hash: str

    views: tuple[
        ReferenceViewSpec,
        ...,
    ]
```

---

# 16.8 Bao nhiêu reference view?

Đối với universal architecture, **Core không bắt buộc 8 ảnh cho mọi object**.

Nhưng broad profile có thể quyết định default pack.

Với `marine_vessel`, reference pack bạn đã chốt là 8 view:

```text
1 port profile
2 starboard profile
3 bow head-on
4 stern head-on
5 high 3/4 bow
6 high 3/4 stern
7 high overview
8 camera-matched
```

Đây nên nằm trong `CategoryCreativeProfile`/identity profile, không hard-code superyacht trong universal planner.

---

# 16.9 ReferencePackPlanner

`identity/reference_pack/planner.py`

```python
from __future__ import annotations

import hashlib

from media_runtime.identity.anchor.approved_anchor import (
    ApprovedAnchor,
)
from media_runtime.identity.exceptions import (
    ReferencePackError,
)
from media_runtime.identity.reference_pack.plan import (
    ReferencePackPlan,
)
from media_runtime.identity.reference_pack.view_spec import (
    ReferenceCameraSpec,
    ReferenceViewSpec,
)
from media_runtime.identity.enums import (
    ReferenceViewRole,
)


class ReferencePackPlanner:
    VERSION = "reference-pack-plan-v1"

    def build(
        self,
        *,
        anchor: ApprovedAnchor,
        profile_config: dict,
    ) -> ReferencePackPlan:
        raw_views = profile_config.get(
            "identity_reference_views"
        )

        if not isinstance(
            raw_views,
            list,
        ) or not raw_views:
            raise ReferencePackError(
                "Profile has no "
                "identity_reference_views."
            )

        views = []

        seen_roles: set[
            ReferenceViewRole
        ] = set()

        for index, raw in enumerate(
            raw_views
        ):
            if not isinstance(
                raw,
                dict,
            ):
                raise ReferencePackError(
                    "Reference view definition "
                    "must be object."
                )

            try:
                role = ReferenceViewRole(
                    raw["role"]
                )
            except Exception as exc:
                raise ReferencePackError(
                    "Invalid reference view role."
                ) from exc

            if role in seen_roles:
                raise ReferencePackError(
                    "Duplicate reference role: "
                    + role.value
                )

            seen_roles.add(
                role
            )

            camera_raw = raw.get(
                "camera"
            )

            if not isinstance(
                camera_raw,
                dict,
            ):
                raise ReferencePackError(
                    "Reference view camera required."
                )

            view_id = (
                self._view_id(
                    anchor=anchor,
                    role=role,
                )
            )

            views.append(
                ReferenceViewSpec(
                    view_id=view_id,

                    role=role,

                    order=int(
                        raw.get(
                            "order",
                            index,
                        )
                    ),

                    required=bool(
                        raw.get(
                            "required",
                            True,
                        )
                    ),

                    camera=(
                        ReferenceCameraSpec(
                            view=str(
                                camera_raw[
                                    "view"
                                ]
                            ),

                            side=(
                                camera_raw.get(
                                    "side"
                                )
                            ),

                            elevation=(
                                camera_raw.get(
                                    "elevation"
                                )
                            ),

                            pitch=(
                                camera_raw.get(
                                    "pitch"
                                )
                            ),

                            lens_character=(
                                camera_raw.get(
                                    "lens_character"
                                )
                            ),

                            framing=str(
                                camera_raw[
                                    "framing"
                                ]
                            ),

                            subject_orientation=(
                                camera_raw.get(
                                    "subject_orientation"
                                )
                            ),
                        )
                    ),

                    additional_required_paths=tuple(
                        raw.get(
                            "additional_required_paths",
                            [],
                        )
                    ),

                    metadata=raw.get(
                        "metadata"
                    ),
                )
            )

        views.sort(
            key=lambda item: (
                item.order,
                item.role.value,
                item.view_id,
            )
        )

        return ReferencePackPlan(
            version=self.VERSION,

            pack_id=(
                self._pack_id(
                    anchor
                )
            ),

            asset_id=(
                anchor.asset_id
            ),

            canonical_revision_id=(
                anchor
                .canonical_revision_id
            ),

            canonical_hash=(
                anchor.canonical_hash
            ),

            anchor_id=(
                anchor.anchor_id
            ),

            anchor_artifact_id=(
                anchor.artifact_id
            ),

            anchor_artifact_hash=(
                anchor.artifact_hash
            ),

            views=tuple(
                views
            ),
        )

    @staticmethod
    def _pack_id(
        anchor: ApprovedAnchor,
    ) -> str:
        raw = (
            anchor.anchor_id
            + "|"
            + anchor.canonical_hash
        )

        return (
            "RPK-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )

    @staticmethod
    def _view_id(
        *,
        anchor: ApprovedAnchor,
        role: ReferenceViewRole,
    ) -> str:
        raw = (
            anchor.anchor_id
            + "|"
            + role.value
        )

        return (
            "RV-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )
```

---

# 16.10 Marine profile example

Không nhét vào Python Core. Ví dụ profile config:

```json
{
  "profile_key": "marine_vessel",
  "profile_version": "1.0",

  "identity_reference_views": [
    {
      "role": "port_profile",
      "order": 1,
      "required": true,

      "camera": {
        "view": "side_profile",
        "side": "port",
        "elevation": "slightly_above_waterline",
        "pitch": "level",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "starboard_profile",
      "order": 2,
      "required": true,

      "camera": {
        "view": "side_profile",
        "side": "starboard",
        "elevation": "slightly_above_waterline",
        "pitch": "level",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "bow_head_on",
      "order": 3,
      "required": true,

      "camera": {
        "view": "head_on",
        "side": "bow",
        "elevation": "slightly_above_waterline",
        "pitch": "level",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "stern_head_on",
      "order": 4,
      "required": true,

      "camera": {
        "view": "head_on",
        "side": "stern",
        "elevation": "slightly_above_waterline",
        "pitch": "level",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "high_front_three_quarter",
      "order": 5,
      "required": true,

      "camera": {
        "view": "front_three_quarter",
        "side": "port",
        "elevation": "high",
        "pitch": "moderate_downward",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "high_rear_three_quarter",
      "order": 6,
      "required": true,

      "camera": {
        "view": "rear_three_quarter",
        "side": "starboard",
        "elevation": "high",
        "pitch": "moderate_downward",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "high_overview",
      "order": 7,
      "required": true,

      "camera": {
        "view": "high_overview",
        "side": null,
        "elevation": "high",
        "pitch": "downward",
        "lens_character": "moderate_telephoto_low_distortion",
        "framing": "full_subject_with_clear_margin"
      }
    },

    {
      "role": "camera_matched",
      "order": 8,
      "required": true,

      "camera": {
        "view": "asset_specific",
        "side": null,
        "elevation": null,
        "pitch": null,
        "lens_character": "low_distortion",
        "framing": "match_target_asset_camera"
      }
    }
  ]
}
```

---

# 16.11 Reference render không được redesign

Mỗi reference render phải dùng:

```text
same canonical_hash
same projection identity
same identity constraints
approved anchor as primary identity reference
```

Chỉ camera/view role thay đổi.

---

# 16.12 Reference render request builder

`identity/reference_pack/render_request_builder.py`

```python
from __future__ import annotations

import hashlib
import uuid

from media_runtime.identity.anchor.approved_anchor import (
    ApprovedAnchor,
)
from media_runtime.identity.reference_pack.plan import (
    ReferencePackPlan,
)
from media_runtime.identity.reference_pack.view_spec import (
    ReferenceViewSpec,
)
from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.render.enums import (
    ReferenceRole,
    RenderOperation,
)
from media_runtime.render.reference import (
    ReferenceAsset,
)
from media_runtime.render.reference_set import (
    ReferenceSet,
)
from media_runtime.render.request import (
    RenderRequest,
)


class ReferenceRenderRequestBuilder:
    VERSION = "reference-render-builder-v1"

    def build(
        self,
        *,
        pack: ReferencePackPlan,
        view: ReferenceViewSpec,
        anchor: ApprovedAnchor,
        anchor_local_path,
        base_request: RenderRequest,
    ) -> RenderRequest:
        self._assert_lineage(
            pack=pack,
            anchor=anchor,
            base_request=base_request,
        )

        reference = ReferenceAsset(
            artifact_id=(
                anchor.artifact_id
            ),

            path=(
                anchor_local_path
            ),

            sha256=(
                anchor.artifact_hash
            ),

            mime_type=(
                anchor.mime_type
            ),

            role=(
                ReferenceRole.IDENTITY
            ),

            priority=0,

            source_revision_id=(
                anchor
                .canonical_revision_id
            ),

            source_canonical_hash=(
                anchor.canonical_hash
            ),

            width=anchor.width,

            height=anchor.height,
        )

        references = ReferenceSet(
            version="reference-set-v1",

            asset_id=(
                pack.asset_id
            ),

            source_revision_id=(
                pack
                .canonical_revision_id
            ),

            source_canonical_hash=(
                pack.canonical_hash
            ),

            references=(
                reference,
            ),
        )

        prompt_text = (
            self._build_prompt(
                original=(
                    base_request
                    .compiled_prompt
                    .prompt
                ),

                view=view,
            )
        )

        compiled_prompt = (
            CompiledPrompt(
                version=(
                    "identity-reference-prompt-v1"
                ),

                provider_key=(
                    base_request
                    .compiled_prompt
                    .provider_key
                ),

                model_key=(
                    base_request
                    .compiled_prompt
                    .model_key
                ),

                source_prompt_spec_hash=(
                    base_request
                    .compiled_prompt
                    .source_prompt_spec_hash
                ),

                source_provider_plan_hash=(
                    base_request
                    .compiled_prompt
                    .source_provider_plan_hash
                ),

                prompt=prompt_text,

                negative_prompt=(
                    base_request
                    .compiled_prompt
                    .negative_prompt
                ),

                native_controls=dict(
                    base_request
                    .compiled_prompt
                    .native_controls
                ),

                prompt_hash=(
                    CompiledPrompt
                    .sha256_text(
                        prompt_text
                    )
                ),

                negative_prompt_hash=(
                    base_request
                    .compiled_prompt
                    .negative_prompt_hash
                ),
            )
        )

        return RenderRequest(
            version="render-request-v1",

            render_id=str(
                uuid.uuid4()
            ),

            run_id=(
                base_request.run_id
            ),

            session_code=(
                base_request
                .session_code
            ),

            asset_id=(
                pack.asset_id
            ),

            media_type=(
                base_request.media_type
            ),

            operation=(
                RenderOperation.EDIT
            ),

            provider_key=(
                base_request.provider_key
            ),

            model_key=(
                base_request.model_key
            ),

            source_revision_id=(
                pack
                .canonical_revision_id
            ),

            source_canonical_hash=(
                pack.canonical_hash
            ),

            projection_hash=(
                base_request
                .projection_hash
            ),

            constraint_set_hash=(
                base_request
                .constraint_set_hash
            ),

            prompt_spec_hash=(
                base_request
                .prompt_spec_hash
            ),

            provider_prompt_plan_hash=(
                base_request
                .provider_prompt_plan_hash
            ),

            compiled_prompt=(
                compiled_prompt
            ),

            references=references,

            width=(
                base_request.width
            ),

            height=(
                base_request.height
            ),

            aspect_ratio=(
                base_request
                .aspect_ratio
            ),

            quality=(
                base_request.quality
            ),

            output_format=(
                base_request
                .output_format
            ),

            provider_options=dict(
                base_request
                .provider_options
            ),

            idempotency_key=(
                base_request
                .idempotency_key
                + ":reference:"
                + view.role.value
            ),

            parent_render_id=(
                base_request.render_id
            ),

            parent_request_hash=(
                base_request
                .compiled_prompt
                .prompt_hash
            ),

            repair_id=None,

            qa_report_hash=None,

            repair_generation=0,
        )

    @staticmethod
    def _build_prompt(
        *,
        original: str,
        view: ReferenceViewSpec,
    ) -> str:
        camera = view.camera

        lines = [
            original.strip(),

            "",

            "IDENTITY-PRESERVING REFERENCE VIEW",

            (
                "Use the supplied approved anchor "
                "as the exact identity reference."
            ),

            (
                "Render the same physical subject. "
                "Do not redesign, restyle, simplify, "
                "add or remove permanent geometry."
            ),

            (
                "Change only the viewpoint required "
                "for this reference image."
            ),

            "",
            "REFERENCE VIEW",

            (
                "View role: "
                + view.role.value
                + "."
            ),

            (
                "Camera view: "
                + camera.view
                + "."
            ),
        ]

        if camera.side:
            lines.append(
                "Camera side: "
                + camera.side
                + "."
            )

        if camera.elevation:
            lines.append(
                "Camera elevation: "
                + camera.elevation
                + "."
            )

        if camera.pitch:
            lines.append(
                "Camera pitch: "
                + camera.pitch
                + "."
            )

        if camera.lens_character:
            lines.append(
                "Perspective character: "
                + camera.lens_character
                + "."
            )

        lines.append(
            "Framing: "
            + camera.framing
            + "."
        )

        if camera.subject_orientation:
            lines.append(
                "Subject orientation: "
                + camera
                .subject_orientation
                + "."
            )

        lines.extend([
            "",
            (
                "All permanent proportions, counts, "
                "topology, openings and distinctive "
                "geometry must remain the same as "
                "the approved anchor."
            ),
        ])

        return "\n".join(
            lines
        ).strip()

    @staticmethod
    def _assert_lineage(
        *,
        pack: ReferencePackPlan,
        anchor: ApprovedAnchor,
        base_request: RenderRequest,
    ) -> None:
        if (
            pack.canonical_hash
            != anchor.canonical_hash
        ):
            raise ValueError(
                "Pack/anchor canonical mismatch."
            )

        if (
            base_request
            .source_canonical_hash
            != pack.canonical_hash
        ):
            raise ValueError(
                "Base request canonical mismatch."
            )

        if (
            pack.anchor_artifact_hash
            != anchor.artifact_hash
        ):
            raise ValueError(
                "Anchor hash mismatch."
            )
```

Có một lỗi lineage nhỏ cần sửa ngay: `parent_request_hash` ở trên không được dùng `prompt_hash`.

Phải truyền exact anchor/base `RenderRequest` hash vào builder:

```python
base_request_hash: str
```

và:

```python
parent_request_hash=(
    base_request_hash
),
```

Đây là bản production.

---

# 16.13 Không dùng text-to-image cho reference pack nếu đã có anchor

Reference views nên:

```text
operation = EDIT / reference-guided generation
```

với approved anchor.

Không:

```text
canonical prompt
→ generate fresh view from scratch
```

vì sẽ tăng identity drift.

Nếu provider không support reference edit tốt:

```text
ProviderCapabilityProjection
→ incompatible
→ route provider khác
```

---

# 16.14 Reference artifact DTO

`identity/reference_pack/artifact.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.identity.enums import (
    ReferenceViewRole,
)


@dataclass(
    frozen=True,
    slots=True,
)
class IdentityReferenceArtifact:
    reference_id: str

    view_id: str

    role: ReferenceViewRole

    render_id: str
    request_hash: str

    artifact_id: str
    artifact_hash: str
    artifact_storage_key: str

    qa_run_id: str
    qa_report_hash: str

    canonical_revision_id: str
    canonical_hash: str

    width: int | None
    height: int | None

    mime_type: str
```

---

# 16.15 Reference Pack

`identity/reference_pack/pack.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.identity.reference_pack.artifact import (
    IdentityReferenceArtifact,
)


@dataclass(
    frozen=True,
    slots=True,
)
class IdentityReferencePack:
    version: str

    pack_id: str

    asset_id: str

    canonical_revision_id: str
    canonical_hash: str

    anchor_id: str
    anchor_artifact_hash: str

    references: tuple[
        IdentityReferenceArtifact,
        ...,
    ]
```

---

# 16.16 Reference pack validator

Mỗi required role phải có đúng một artifact.

```python
from __future__ import annotations

from media_runtime.identity.exceptions import (
    ReferencePackError,
)
from media_runtime.identity.reference_pack.pack import (
    IdentityReferencePack,
)
from media_runtime.identity.reference_pack.plan import (
    ReferencePackPlan,
)


class ReferencePackValidator:
    def validate(
        self,
        *,
        plan: ReferencePackPlan,
        pack: IdentityReferencePack,
    ) -> None:
        if (
            plan.pack_id
            != pack.pack_id
        ):
            raise ReferencePackError(
                "Reference pack ID mismatch."
            )

        if (
            plan.canonical_hash
            != pack.canonical_hash
        ):
            raise ReferencePackError(
                "Reference pack canonical mismatch."
            )

        required_roles = {
            view.role
            for view in plan.views
            if view.required
        }

        actual_roles = [
            ref.role
            for ref in pack.references
        ]

        if (
            len(actual_roles)
            != len(
                set(actual_roles)
            )
        ):
            raise ReferencePackError(
                "Duplicate reference roles."
            )

        missing = (
            required_roles
            - set(actual_roles)
        )

        if missing:
            raise ReferencePackError(
                "Missing required reference roles: "
                + ", ".join(
                    sorted(
                        item.value
                        for item in missing
                    )
                )
            )

        for reference in (
            pack.references
        ):
            if (
                reference
                .canonical_revision_id
                != pack
                .canonical_revision_id
            ):
                raise ReferencePackError(
                    "Reference revision mismatch."
                )

            if (
                reference.canonical_hash
                != pack.canonical_hash
            ):
                raise ReferencePackError(
                    "Reference canonical hash mismatch."
                )
```

---

# 16.17 Single-view QA vẫn chạy Part15

Mỗi reference view sau render phải:

```text
Part14 SUCCEEDED
↓
Part15 QA
```

Không dùng cross-view QA thay thế single-view QA.

Vì:

```text
single-view QA
= view này có đúng canonical không?

cross-view QA
= các view này có phải cùng một subject không?
```

Hai câu hỏi khác nhau.

---

# 16.18 Multi-view QA Target

Cross-view QA không check mọi soft material/camera field.

Nó ưu tiên identity-bearing constraints:

```text
P0 identity
P1 count
P2 topology
P3 proportion
P4 permanent geometry
P5 permanent openings
```

`identity/multiview/target.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.constraints.enums import (
    ConstraintPriority,
    ConstraintPrimitive,
    ConstraintSeverity,
)


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewIdentityTarget:
    target_id: str

    semantic_key: str

    primitive: ConstraintPrimitive

    priority: ConstraintPriority

    severity: ConstraintSeverity

    expected_value: Any

    source_constraint_ids: tuple[
        str,
        ...,
    ]

    applicable_view_ids: tuple[
        str,
        ...,
    ]
```

---

# 16.19 Multi-view target builder

Không gửi P7 camera hoặc P8 presentation làm identity consistency target.

```python
from __future__ import annotations

import hashlib

from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.constraints.enums import (
    ConstraintPriority,
)
from media_runtime.identity.multiview.target import (
    MultiViewIdentityTarget,
)
from media_runtime.identity.reference_pack.pack import (
    IdentityReferencePack,
)


class MultiViewIdentityTargetBuilder:
    VERSION = "multiview-target-v1"

    _ALLOWED_PRIORITIES = {
        ConstraintPriority.P0_IDENTITY,
        ConstraintPriority.P1_COUNT,
        ConstraintPriority.P2_TOPOLOGY,
        ConstraintPriority.P3_PROPORTION,
        ConstraintPriority.P4_GEOMETRY,
        ConstraintPriority.P5_PERMANENT_OPENINGS,
    }

    def build(
        self,
        *,
        constraint_set: ConstraintSet,
        pack: IdentityReferencePack,
    ) -> tuple[
        MultiViewIdentityTarget,
        ...,
    ]:
        all_view_ids = tuple(
            reference.view_id
            for reference in pack.references
        )

        result = []

        for constraint in (
            constraint_set.constraints
        ):
            if (
                constraint.priority
                not in self._ALLOWED_PRIORITIES
            ):
                continue

            if not (
                constraint
                .visual_verification
            ):
                continue

            result.append(
                MultiViewIdentityTarget(
                    target_id=(
                        self._id(
                            constraint.id,
                            constraint.semantic_key,
                        )
                    ),

                    semantic_key=(
                        constraint.semantic_key
                    ),

                    primitive=(
                        constraint.primitive
                    ),

                    priority=(
                        constraint.priority
                    ),

                    severity=(
                        constraint.severity
                    ),

                    expected_value=(
                        constraint.value
                    ),

                    source_constraint_ids=(
                        constraint.id,
                    ),

                    applicable_view_ids=(
                        all_view_ids
                    ),
                )
            )

        return tuple(
            sorted(
                result,
                key=lambda item: (
                    int(item.priority),
                    item.semantic_key,
                ),
            )
        )

    @staticmethod
    def _id(
        constraint_id: str,
        semantic_key: str,
    ) -> str:
        digest = hashlib.sha256(
            (
                constraint_id
                + "|"
                + semantic_key
                + "|multiview"
            ).encode("utf-8")
        ).hexdigest()[:16]

        return (
            "MVT-"
            + digest
        )
```

---

# 16.20 Một nuance: applicable views

Không phải constraint nào cũng quan sát được trên mọi view.

Ví dụ stern opening:

```text
stern_head_on
high_rear_three_quarter
```

mới meaningful.

Production-grade tốt hơn là profile có:

```json
{
  "visibility_rules": {
    "permanent_geometry.stern": [
      "stern_head_on",
      "high_rear_three_quarter"
    ]
  }
}
```

Core không đoán bằng string path.

Do đó builder production nên nhận:

```python
visibility_policy
```

Interface:

```python
class IdentityVisibilityPolicy:
    def applicable_views(
        self,
        *,
        semantic_key: str,
        pack,
    ) -> tuple[str, ...]:
        ...
```

Fallback generic:

```python
all views
```

nhưng Vision vẫn có `not_comparable`.

---

# 16.21 Multi-view request

`identity/multiview/request.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.identity.multiview.target import (
    MultiViewIdentityTarget,
)


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewImageInput:
    view_id: str

    role: str

    artifact_id: str
    artifact_hash: str
    path: str
    mime_type: str


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewQaRequest:
    version: str

    qa_run_id: str

    pack_id: str

    canonical_revision_id: str
    canonical_hash: str

    constraint_set_hash: str

    anchor_artifact_id: str
    anchor_artifact_hash: str

    images: tuple[
        MultiViewImageInput,
        ...,
    ]

    targets: tuple[
        MultiViewIdentityTarget,
        ...,
    ]
```

---

# 16.22 Vision output contract multi-view

Mỗi target phải trả:

```text
per-view observations
+
cross-view consistency
```

`identity/multiview/result.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.identity.enums import (
    IdentityMismatchType,
    MultiViewDecision,
    MultiViewFindingStatus,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ViewObservation:
    view_id: str

    observable: bool

    observed_value: Any

    confidence: float

    evidence: str


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewFinding:
    target_id: str

    semantic_key: str

    status: MultiViewFindingStatus

    confidence: float

    mismatch_type: (
        IdentityMismatchType | None
    )

    observations: tuple[
        ViewObservation,
        ...,
    ]

    evidence: str

    source_constraint_ids: tuple[
        str,
        ...,
    ]


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewQaReport:
    version: str

    qa_run_id: str

    pack_id: str

    canonical_revision_id: str
    canonical_hash: str

    constraint_set_hash: str

    anchor_artifact_hash: str

    reference_artifact_hashes: tuple[
        str,
        ...,
    ]

    decision: MultiViewDecision

    findings: tuple[
        MultiViewFinding,
        ...,
    ]

    inconsistent_hard_count: int

    inconsistent_soft_count: int

    uncertain_count: int

    report_hash: str

    provider_key: str
    model_key: str
```

---

# 16.23 Multi-view Vision prompt

`identity/multiview/prompt_builder.py`

```python
from __future__ import annotations

from typing import Any

from media_runtime.identity.multiview.request import (
    MultiViewQaRequest,
)


class MultiViewQaPromptBuilder:
    VERSION = "multiview-qa-prompt-v1"

    SYSTEM_PROMPT = """
You are a strict multi-view physical identity
verification engine.

You receive several rendered views that are intended
to depict one and the same physical subject.

Your task is NOT to judge aesthetics and NOT to
redesign the subject.

For every supplied identity target:

1. Inspect only the views listed as applicable.

2. Determine whether the visible evidence across
   views is consistent with one stable physical
   subject and with the supplied expected value.

3. Different camera angle, foreshortening, occlusion,
   lighting and perspective are NOT identity drift.

4. A change in permanent count, topology, proportion,
   silhouette, opening layout or permanent geometry
   IS identity drift when clearly visible.

5. Never infer hidden geometry simply because it is
   absent from a view.

6. If views cannot reliably be compared for a target,
   return uncertain or not_comparable rather than
   inconsistent.

7. Exact counts must remain exact.

8. Do not invent new requirements.

9. Return one result for every target_id exactly once.

Return only the structured response required by the
output schema.
""".strip()

    def build(
        self,
        request: MultiViewQaRequest,
    ) -> tuple[
        str,
        dict[str, Any],
    ]:
        payload = {
            "qa_run_id":
                request.qa_run_id,

            "pack_id":
                request.pack_id,

            "anchor_artifact_id":
                request.anchor_artifact_id,

            "images": [
                {
                    "view_id":
                        image.view_id,

                    "role":
                        image.role,

                    "artifact_id":
                        image.artifact_id,
                }
                for image
                in request.images
            ],

            "identity_targets": [
                {
                    "target_id":
                        target.target_id,

                    "semantic_key":
                        target.semantic_key,

                    "primitive":
                        target
                        .primitive
                        .value,

                    "severity":
                        target
                        .severity
                        .value,

                    "expected_value":
                        target.expected_value,

                    "applicable_view_ids":
                        list(
                            target
                            .applicable_view_ids
                        ),
                }
                for target
                in request.targets
            ],
        }

        return (
            self.SYSTEM_PROMPT,
            payload,
        )
```

---

# 16.24 Multi-view schema

```python
MULTIVIEW_QA_SCHEMA = {
    "type": "object",

    "additionalProperties": False,

    "required": [
        "results",
        "overall_notes",
    ],

    "properties": {
        "overall_notes": {
            "type": "string",
        },

        "results": {
            "type": "array",

            "items": {
                "type": "object",

                "additionalProperties": False,

                "required": [
                    "target_id",
                    "status",
                    "confidence",
                    "mismatch_type",
                    "evidence",
                    "observations",
                ],

                "properties": {
                    "target_id": {
                        "type": "string",
                    },

                    "status": {
                        "type": "string",

                        "enum": [
                            "consistent",
                            "inconsistent",
                            "uncertain",
                            "not_comparable",
                        ],
                    },

                    "confidence": {
                        "type": "number",
                        "minimum": 0,
                        "maximum": 1,
                    },

                    "mismatch_type": {
                        "type": [
                            "string",
                            "null",
                        ],

                        "enum": [
                            None,
                            "count_drift",
                            "proportion_drift",
                            "silhouette_drift",
                            "topology_drift",
                            "geometry_drift",
                            "opening_drift",
                            "material_drift",
                            "state_drift",
                            "feature_drift",
                            "unknown",
                        ],
                    },

                    "evidence": {
                        "type": "string",
                    },

                    "observations": {
                        "type": "array",

                        "items": {
                            "type": "object",

                            "additionalProperties": False,

                            "required": [
                                "view_id",
                                "observable",
                                "observed_value",
                                "confidence",
                                "evidence",
                            ],

                            "properties": {
                                "view_id": {
                                    "type":
                                        "string",
                                },

                                "observable": {
                                    "type":
                                        "boolean",
                                },

                                "observed_value":
                                    {},

                                "confidence": {
                                    "type":
                                        "number",

                                    "minimum":
                                        0,

                                    "maximum":
                                        1,
                                },

                                "evidence": {
                                    "type":
                                        "string",
                                },
                            },
                        },
                    },
                },
            },
        },
    },
}
```

---

# 16.25 Strict response parser

`identity/multiview/response_parser.py`

```python
from __future__ import annotations

from media_runtime.identity.enums import (
    IdentityMismatchType,
    MultiViewFindingStatus,
)
from media_runtime.identity.exceptions import (
    MultiViewQaError,
)
from media_runtime.identity.multiview.result import (
    MultiViewFinding,
    ViewObservation,
)


class MultiViewQaResponseParser:
    def parse(
        self,
        *,
        raw: dict,
        targets,
        view_ids: tuple[str, ...],
    ) -> tuple[
        MultiViewFinding,
        ...,
    ]:
        if not isinstance(
            raw,
            dict,
        ):
            raise MultiViewQaError(
                "Multi-view response "
                "must be object."
            )

        raw_results = raw.get(
            "results"
        )

        if not isinstance(
            raw_results,
            list,
        ):
            raise MultiViewQaError(
                "results must be list."
            )

        target_map = {
            target.target_id:
                target
            for target in targets
        }

        expected_ids = set(
            target_map.keys()
        )

        valid_view_ids = set(
            view_ids
        )

        seen = set()

        findings = []

        for raw_item in raw_results:
            if not isinstance(
                raw_item,
                dict,
            ):
                raise MultiViewQaError(
                    "Result item must be object."
                )

            target_id = raw_item.get(
                "target_id"
            )

            if target_id not in (
                expected_ids
            ):
                raise MultiViewQaError(
                    "Unknown multi-view "
                    f"target {target_id}"
                )

            if target_id in seen:
                raise MultiViewQaError(
                    "Duplicate multi-view "
                    f"target {target_id}"
                )

            seen.add(
                target_id
            )

            try:
                status = (
                    MultiViewFindingStatus(
                        raw_item["status"]
                    )
                )
            except Exception as exc:
                raise MultiViewQaError(
                    "Invalid multi-view status."
                ) from exc

            confidence = raw_item.get(
                "confidence"
            )

            if (
                not isinstance(
                    confidence,
                    (int, float),
                )
                or isinstance(
                    confidence,
                    bool,
                )
                or not (
                    0
                    <= confidence
                    <= 1
                )
            ):
                raise MultiViewQaError(
                    "Invalid confidence."
                )

            raw_mismatch = (
                raw_item.get(
                    "mismatch_type"
                )
            )

            mismatch = None

            if raw_mismatch is not None:
                try:
                    mismatch = (
                        IdentityMismatchType(
                            raw_mismatch
                        )
                    )
                except ValueError as exc:
                    raise MultiViewQaError(
                        "Invalid mismatch type."
                    ) from exc

            if (
                status
                == MultiViewFindingStatus
                .INCONSISTENT
                and mismatch is None
            ):
                raise MultiViewQaError(
                    "Inconsistent result "
                    "requires mismatch_type."
                )

            raw_observations = (
                raw_item.get(
                    "observations"
                )
            )

            if not isinstance(
                raw_observations,
                list,
            ):
                raise MultiViewQaError(
                    "observations must "
                    "be list."
                )

            observations = []

            observed_view_ids = set()

            for raw_observation in (
                raw_observations
            ):
                view_id = (
                    raw_observation.get(
                        "view_id"
                    )
                )

                if (
                    view_id
                    not in valid_view_ids
                ):
                    raise MultiViewQaError(
                        "Unknown view_id "
                        f"{view_id}"
                    )

                if (
                    view_id
                    in observed_view_ids
                ):
                    raise MultiViewQaError(
                        "Duplicate observation "
                        f"for {view_id}"
                    )

                observed_view_ids.add(
                    view_id
                )

                observable = (
                    raw_observation.get(
                        "observable"
                    )
                )

                if not isinstance(
                    observable,
                    bool,
                ):
                    raise MultiViewQaError(
                        "observable must "
                        "be boolean."
                    )

                observation_confidence = (
                    raw_observation.get(
                        "confidence"
                    )
                )

                if (
                    not isinstance(
                        observation_confidence,
                        (int, float),
                    )
                    or isinstance(
                        observation_confidence,
                        bool,
                    )
                    or not (
                        0
                        <= observation_confidence
                        <= 1
                    )
                ):
                    raise MultiViewQaError(
                        "Invalid observation "
                        "confidence."
                    )

                evidence = (
                    raw_observation.get(
                        "evidence"
                    )
                )

                if not isinstance(
                    evidence,
                    str,
                ):
                    raise MultiViewQaError(
                        "Observation evidence "
                        "must be string."
                    )

                observations.append(
                    ViewObservation(
                        view_id=(
                            view_id
                        ),

                        observable=(
                            observable
                        ),

                        observed_value=(
                            raw_observation.get(
                                "observed_value"
                            )
                        ),

                        confidence=float(
                            observation_confidence
                        ),

                        evidence=(
                            evidence.strip()
                        ),
                    )
                )

            target = (
                target_map[target_id]
            )

            findings.append(
                MultiViewFinding(
                    target_id=(
                        target_id
                    ),

                    semantic_key=(
                        target.semantic_key
                    ),

                    status=status,

                    confidence=float(
                        confidence
                    ),

                    mismatch_type=(
                        mismatch
                    ),

                    observations=tuple(
                        sorted(
                            observations,
                            key=lambda item:
                                item.view_id,
                        )
                    ),

                    evidence=str(
                        raw_item.get(
                            "evidence",
                            "",
                        )
                    ).strip(),

                    source_constraint_ids=(
                        target
                        .source_constraint_ids
                    ),
                )
            )

        missing = (
            expected_ids
            - seen
        )

        if missing:
            raise MultiViewQaError(
                "Missing multi-view targets: "
                + ", ".join(
                    sorted(missing)
                )
            )

        return tuple(
            sorted(
                findings,
                key=lambda item: (
                    item.semantic_key,
                    item.target_id,
                ),
            )
        )
```

---

# 16.26 Deterministic multi-view validator

Vision không được nói:

```text
consistent confidence=.2
```

rồi pass.

`identity/multiview/validator.py`

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintSeverity,
)
from media_runtime.identity.enums import (
    MultiViewFindingStatus,
)
from media_runtime.identity.exceptions import (
    MultiViewQaError,
)


class MultiViewFindingValidator:
    def validate(
        self,
        *,
        findings,
        targets,
    ) -> None:
        target_map = {
            item.target_id:
                item
            for item in targets
        }

        for finding in findings:
            target = (
                target_map[
                    finding.target_id
                ]
            )

            if (
                finding.status
                == MultiViewFindingStatus
                .INCONSISTENT
                and not finding.evidence
            ):
                raise MultiViewQaError(
                    "Identity inconsistency "
                    "requires evidence."
                )

            if (
                target.severity
                == ConstraintSeverity.HARD
                and finding.status
                == MultiViewFindingStatus
                .NOT_COMPARABLE
            ):
                # Allowed semantically, but gate
                # must force REVIEW.
                continue
```

---

# 16.27 Multi-view gate policy

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewGatePolicy:
    hard_consistency_min_confidence: (
        float
    ) = 0.80

    soft_consistency_min_confidence: (
        float
    ) = 0.70

    uncertain_requires_review: bool = True

    hard_not_comparable_requires_review: (
        bool
    ) = True

    maximum_soft_inconsistencies: int = 0
```

---

# 16.28 Multi-view gate

`identity/multiview/gate.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.enums import (
    ConstraintSeverity,
)
from media_runtime.identity.enums import (
    MultiViewDecision,
    MultiViewFindingStatus,
)


@dataclass(
    frozen=True,
    slots=True,
)
class MultiViewGateResult:
    decision: MultiViewDecision

    inconsistent_hard_count: int

    inconsistent_soft_count: int

    uncertain_count: int


class MultiViewQaGate:
    def __init__(
        self,
        policy,
    ) -> None:
        self._policy = policy

    def decide(
        self,
        *,
        findings,
        targets,
    ) -> MultiViewGateResult:
        target_map = {
            item.target_id:
                item
            for item in targets
        }

        hard_bad = []
        soft_bad = []
        uncertain = []

        for finding in findings:
            target = target_map[
                finding.target_id
            ]

            if (
                finding.status
                == MultiViewFindingStatus
                .INCONSISTENT
            ):
                if (
                    target.severity
                    == ConstraintSeverity.HARD
                ):
                    hard_bad.append(
                        finding
                    )
                else:
                    soft_bad.append(
                        finding
                    )

                continue

            if finding.status in {
                MultiViewFindingStatus
                .UNCERTAIN,

                MultiViewFindingStatus
                .NOT_COMPARABLE,
            }:
                uncertain.append(
                    finding
                )

                continue

            if (
                finding.status
                == MultiViewFindingStatus
                .CONSISTENT
            ):
                minimum = (
                    self._policy
                    .hard_consistency_min_confidence
                    if target.severity
                    == ConstraintSeverity.HARD
                    else self._policy
                    .soft_consistency_min_confidence
                )

                if (
                    finding.confidence
                    < minimum
                ):
                    uncertain.append(
                        finding
                    )

        if hard_bad:
            return MultiViewGateResult(
                decision=(
                    MultiViewDecision.FAIL
                ),

                inconsistent_hard_count=(
                    len(hard_bad)
                ),

                inconsistent_soft_count=(
                    len(soft_bad)
                ),

                uncertain_count=(
                    len(uncertain)
                ),
            )

        if uncertain:
            return MultiViewGateResult(
                decision=(
                    MultiViewDecision.REVIEW
                ),

                inconsistent_hard_count=0,

                inconsistent_soft_count=(
                    len(soft_bad)
                ),

                uncertain_count=(
                    len(uncertain)
                ),
            )

        if (
            len(soft_bad)
            > self._policy
            .maximum_soft_inconsistencies
        ):
            return MultiViewGateResult(
                decision=(
                    MultiViewDecision.FAIL
                ),

                inconsistent_hard_count=0,

                inconsistent_soft_count=(
                    len(soft_bad)
                ),

                uncertain_count=0,
            )

        return MultiViewGateResult(
            decision=(
                MultiViewDecision.PASS
            ),

            inconsistent_hard_count=0,

            inconsistent_soft_count=0,

            uncertain_count=0,
        )
```

---

# 16.29 Cross-view inconsistency không repair cả pack mặc định

Nếu chỉ:

```text
starboard profile
```

sai tier count, còn 7 ảnh đúng:

```text
repair/regenerate starboard profile
```

Không render lại cả 8.

Need localization.

---

# 16.30 Find problematic views

```python
from __future__ import annotations

from collections import defaultdict

from media_runtime.identity.enums import (
    MultiViewFindingStatus,
)


class MultiViewFailureLocalizer:
    def failed_view_ids(
        self,
        findings,
    ) -> tuple[str, ...]:
        scores: dict[
            str,
            int,
        ] = defaultdict(int)

        for finding in findings:
            if (
                finding.status
                != MultiViewFindingStatus
                .INCONSISTENT
            ):
                continue

            for observation in (
                finding.observations
            ):
                if not (
                    observation.observable
                ):
                    continue

                scores[
                    observation.view_id
                ] += 1

        return tuple(
            sorted(
                scores.keys(),
                key=lambda view_id: (
                    -scores[view_id],
                    view_id,
                ),
            )
        )
```

Nhưng đây chỉ identifies candidate view. Không tự kết luận view nào "sai" nếu discrepancy giữa A/B chưa biết reference nào đúng.

Approved anchor phải là primary truth.

---

# 16.31 Anchor có precedence trong identity comparison

Cross-view QA phải lấy:

```text
approved anchor = primary visual realization
```

Nếu reference view mâu thuẫn anchor:

```text
reference sai
```

Không reinterpret anchor theo majority vote.

Không:

```text
7 reference giống nhau
1 anchor khác
→ anchor sai
```

Anchor đã human-approved.

Nếu anchor sau này bị phát hiện sai thật:

```text
new anchor revision
→ new identity lock
```

không sửa frozen lock.

---

# 16.32 Anchor-specific cross-view target

Vision payload nên đánh dấu:

```json
{
  "anchor_artifact_id": "ANCHOR-1"
}
```

System prompt thêm:

```text
The approved anchor is the primary visual identity
reference. Reference views must remain consistent with
it. Do not revise the anchor identity by majority vote.
```

---

# 16.33 MultiViewQaService

`identity/multiview/service.py`

```python
from __future__ import annotations

import hashlib
import json
import uuid

from dataclasses import replace
from enum import Enum
from typing import Any

from media_runtime.identity.enums import (
    MultiViewDecision,
)
from media_runtime.identity.multiview.request import (
    MultiViewImageInput,
    MultiViewQaRequest,
)
from media_runtime.identity.multiview.result import (
    MultiViewQaReport,
)


class MultiViewQaService:
    VERSION = "multiview-qa-report-v1"

    def __init__(
        self,
        *,
        target_builder,
        prompt_builder,
        vision_provider,
        response_parser,
        finding_validator,
        gate,
    ) -> None:
        self._targets = (
            target_builder
        )

        self._prompt = (
            prompt_builder
        )

        self._provider = (
            vision_provider
        )

        self._parser = (
            response_parser
        )

        self._validator = (
            finding_validator
        )

        self._gate = gate

    def inspect(
        self,
        *,
        pack,
        constraint_set,
        anchor_artifact,
    ) -> MultiViewQaReport:
        targets = self._targets.build(
            constraint_set=(
                constraint_set
            ),

            pack=pack,
        )

        images = tuple(
            MultiViewImageInput(
                view_id=(
                    reference.view_id
                ),

                role=(
                    reference.role.value
                ),

                artifact_id=(
                    reference.artifact_id
                ),

                artifact_hash=(
                    reference.artifact_hash
                ),

                path=(
                    reference
                    .artifact_storage_key
                ),

                mime_type=(
                    reference.mime_type
                ),
            )
            for reference
            in pack.references
        )

        qa_run_id = str(
            uuid.uuid4()
        )

        request = MultiViewQaRequest(
            version=(
                "multiview-qa-request-v1"
            ),

            qa_run_id=qa_run_id,

            pack_id=pack.pack_id,

            canonical_revision_id=(
                pack
                .canonical_revision_id
            ),

            canonical_hash=(
                pack.canonical_hash
            ),

            constraint_set_hash=(
                constraint_set
                .fingerprint()
            ),

            anchor_artifact_id=(
                anchor_artifact
                .artifact_id
            ),

            anchor_artifact_hash=(
                anchor_artifact
                .artifact_hash
            ),

            images=images,

            targets=targets,
        )

        system_prompt, payload = (
            self._prompt.build(
                request
            )
        )

        raw = self._provider.inspect_multi(
            images=images,

            system_prompt=(
                system_prompt
            ),

            input_payload=(
                payload
            ),

            output_schema=(
                MULTIVIEW_QA_SCHEMA
            ),
        )

        findings = self._parser.parse(
            raw=raw,

            targets=targets,

            view_ids=tuple(
                image.view_id
                for image in images
            ),
        )

        self._validator.validate(
            findings=findings,

            targets=targets,
        )

        gate = self._gate.decide(
            findings=findings,

            targets=targets,
        )

        provisional = (
            MultiViewQaReport(
                version=self.VERSION,

                qa_run_id=(
                    qa_run_id
                ),

                pack_id=(
                    pack.pack_id
                ),

                canonical_revision_id=(
                    pack
                    .canonical_revision_id
                ),

                canonical_hash=(
                    pack.canonical_hash
                ),

                constraint_set_hash=(
                    request
                    .constraint_set_hash
                ),

                anchor_artifact_hash=(
                    anchor_artifact
                    .artifact_hash
                ),

                reference_artifact_hashes=tuple(
                    sorted(
                        reference
                        .artifact_hash
                        for reference
                        in pack.references
                    )
                ),

                decision=(
                    gate.decision
                ),

                findings=(
                    findings
                ),

                inconsistent_hard_count=(
                    gate
                    .inconsistent_hard_count
                ),

                inconsistent_soft_count=(
                    gate
                    .inconsistent_soft_count
                ),

                uncertain_count=(
                    gate
                    .uncertain_count
                ),

                report_hash="",

                provider_key=(
                    self._provider
                    .provider_key
                ),

                model_key=(
                    self._provider
                    .model_key
                ),
            )
        )

        report_hash = (
            self._hash(
                provisional
            )
        )

        return replace(
            provisional,
            report_hash=(
                report_hash
            ),
        )

    @classmethod
    def _hash(
        cls,
        report,
    ) -> str:
        payload = (
            cls._plain(
                report
            )
        )

        payload[
            "report_hash"
        ] = ""

        raw = json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        return hashlib.sha256(
            raw
        ).hexdigest()

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(
            value,
            Enum,
        ):
            return value.value

        if hasattr(
            value,
            "__dataclass_fields__",
        ):
            return {
                name:
                    cls._plain(
                        getattr(
                            value,
                            name,
                        )
                    )
                for name
                in value
                .__dataclass_fields__
            }

        if isinstance(
            value,
            dict,
        ):
            return {
                str(key):
                    cls._plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                tuple,
                list,
            ),
        ):
            return [
                cls._plain(item)
                for item in value
            ]

        return value
```

Import thiếu cần có ở file:

```python
from media_runtime.identity.multiview.schema import (
    MULTIVIEW_QA_SCHEMA,
)
```

---

# 16.34 Không dùng filesystem storage key trực tiếp cho Vision Provider

Trong production:

```text
artifact_storage_key
↓
ArtifactResolver
↓
temporary/local readable file
↓
Vision provider
```

`artifact_storage_key` không nhất thiết là path.

Do đó production `MultiViewQaService` nên nhận `artifact_resolver`.

Ví dụ:

```python
resolved = (
    self._artifact_resolver.resolve(
        reference
        .artifact_storage_key
    )
)
```

Rồi:

```python
path=resolved.path
```

Điều này giúp S3/local shared disk không đổi QA contract.

---

# 16.35 Reference pack approval

Không freeze ngay sau automated MultiView PASS.

Cần:

```text
all required single-view QA PASS
+
MultiView QA PASS
+
admin human approval
```

Mới lock.

Vì reference pack là identity infrastructure lâu dài.

---

# 16.36 Identity Lock Manifest

Đây là output quan trọng nhất Part16.

`identity/lock/manifest.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class LockedIdentityAsset:
    role: str

    artifact_id: str
    artifact_hash: str
    storage_key: str

    render_id: str
    request_hash: str

    qa_run_id: str
    qa_report_hash: str


@dataclass(
    frozen=True,
    slots=True,
)
class LockedIdentityConstraint:
    constraint_id: str

    semantic_key: str

    primitive: str

    priority: int

    severity: str

    expected_value: Any


@dataclass(
    frozen=True,
    slots=True,
)
class IdentityLockManifest:
    version: str

    lock_id: str

    project_id: str
    asset_id: str

    canonical_revision_id: str
    canonical_revision: int
    canonical_hash: str

    schema_version: str

    profile_key: str
    profile_version: str

    anchor_id: str

    anchor: LockedIdentityAsset

    reference_pack_id: str

    references: tuple[
        LockedIdentityAsset,
        ...,
    ]

    identity_constraints: tuple[
        LockedIdentityConstraint,
        ...,
    ]

    reference_pack_qa_run_id: str
    reference_pack_qa_report_hash: str

    approved_by: str
    approved_at: str

    identity_lock_hash: str
```

---

# 16.37 Constraints nào vào Identity Lock?

Không cần nhét toàn bộ ConstraintSet.

Lock identity-bearing:

```text
P0
P1
P2
P3
P4
P5
```

P6 state/material có thể thay đổi theo construction → completed → operating.

Không nên identity-lock temporary state.

Ví dụ:

```text
bare steel
```

không phải permanent identity.

Nếu final material itself là identity-critical và invariant, nó vẫn ở Canonical, nhưng identity lock chủ yếu geometry/topology.

---

# 16.38 IdentityConstraintSelector

```python
from __future__ import annotations

from media_runtime.constraints.enums import (
    ConstraintPriority,
)
from media_runtime.identity.lock.manifest import (
    LockedIdentityConstraint,
)


class IdentityConstraintSelector:
    _LOCK_PRIORITIES = {
        ConstraintPriority.P0_IDENTITY,
        ConstraintPriority.P1_COUNT,
        ConstraintPriority.P2_TOPOLOGY,
        ConstraintPriority.P3_PROPORTION,
        ConstraintPriority.P4_GEOMETRY,
        ConstraintPriority.P5_PERMANENT_OPENINGS,
    }

    def select(
        self,
        constraint_set,
    ) -> tuple[
        LockedIdentityConstraint,
        ...,
    ]:
        result = []

        for constraint in (
            constraint_set.constraints
        ):
            if (
                constraint.priority
                not in self._LOCK_PRIORITIES
            ):
                continue

            result.append(
                LockedIdentityConstraint(
                    constraint_id=(
                        constraint.id
                    ),

                    semantic_key=(
                        constraint.semantic_key
                    ),

                    primitive=(
                        constraint
                        .primitive
                        .value
                    ),

                    priority=int(
                        constraint.priority
                    ),

                    severity=(
                        constraint
                        .severity
                        .value
                    ),

                    expected_value=(
                        constraint.value
                    ),
                )
            )

        return tuple(
            sorted(
                result,
                key=lambda item: (
                    item.priority,
                    item.semantic_key,
                    item.constraint_id,
                ),
            )
        )
```

---

# 16.39 Identity lock serializer

Không `asdict()` tùy tiện.

`identity/lock/serializer.py`

```python
from __future__ import annotations

import json

from enum import Enum
from typing import Any


class IdentityLockSerializer:
    VERSION = (
        "identity-lock-json-v1"
    )

    @classmethod
    def plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(
            value,
            Enum,
        ):
            return value.value

        if hasattr(
            value,
            "__dataclass_fields__",
        ):
            return {
                name:
                    cls.plain(
                        getattr(
                            value,
                            name,
                        )
                    )
                for name
                in value
                .__dataclass_fields__
            }

        if isinstance(
            value,
            dict,
        ):
            return {
                str(key):
                    cls.plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                tuple,
                list,
            ),
        ):
            return [
                cls.plain(item)
                for item in value
            ]

        return value

    @classmethod
    def dumps(
        cls,
        value: Any,
    ) -> str:
        return json.dumps(
            cls.plain(value),
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )
```

---

# 16.40 Identity lock hash

`identity/lock/hasher.py`

```python
from __future__ import annotations

import hashlib

from dataclasses import replace

from media_runtime.identity.lock.serializer import (
    IdentityLockSerializer,
)


class IdentityLockHasher:
    VERSION = (
        "identity-lock-hash-v1"
    )

    def hash(
        self,
        manifest,
    ) -> str:
        normalized = replace(
            manifest,
            identity_lock_hash="",
        )

        raw = (
            IdentityLockSerializer
            .dumps(
                normalized
            )
            .encode("utf-8")
        )

        return hashlib.sha256(
            raw
        ).hexdigest()
```

---

# 16.41 Identity lock validator

`identity/lock/validator.py`

```python
from __future__ import annotations

import hashlib
import re

from media_runtime.identity.exceptions import (
    IdentityLineageError,
    IdentityLockError,
)


_HASH = re.compile(
    r"^[a-f0-9]{64}$"
)


class IdentityLockValidator:
    def __init__(
        self,
        hasher,
    ) -> None:
        self._hasher = hasher

    def validate(
        self,
        manifest,
    ) -> None:
        if not manifest.references:
            raise IdentityLockError(
                "Identity lock requires "
                "reference pack."
            )

        if not (
            manifest
            .identity_constraints
        ):
            raise IdentityLockError(
                "Identity lock has no "
                "identity constraints."
            )

        if (
            manifest
            .anchor
            .artifact_hash
            == ""
        ):
            raise IdentityLockError(
                "Anchor hash missing."
            )

        for artifact in (
            manifest.references
        ):
            if not _HASH.fullmatch(
                artifact.artifact_hash
            ):
                raise IdentityLockError(
                    "Invalid reference "
                    "artifact hash."
                )

        if not _HASH.fullmatch(
            manifest.canonical_hash
        ):
            raise IdentityLockError(
                "Invalid canonical hash."
            )

        expected = self._hasher.hash(
            manifest
        )

        if not hashlib.compare_digest(
            expected,
            manifest
            .identity_lock_hash,
        ):
            raise IdentityLockError(
                "Identity lock hash mismatch."
            )

        if (
            manifest.anchor.artifact_id
            in {
                reference.artifact_id
                for reference
                in manifest.references
            }
        ):
            raise IdentityLockError(
                "Anchor artifact must not "
                "be duplicated as reference asset."
            )
```

---

# 16.42 Build Identity Lock

`identity/lock/service.py`

```python
from __future__ import annotations

import hashlib

from dataclasses import replace

from media_runtime.identity.enums import (
    MultiViewDecision,
)
from media_runtime.identity.exceptions import (
    IdentityLockError,
)
from media_runtime.identity.lock.manifest import (
    IdentityLockManifest,
    LockedIdentityAsset,
)


class IdentityLockService:
    VERSION = "identity-lock-v1"

    def __init__(
        self,
        *,
        constraint_selector,
        hasher,
        validator,
    ) -> None:
        self._constraints = (
            constraint_selector
        )

        self._hasher = hasher

        self._validator = validator

    def build(
        self,
        *,
        project_id: str,
        canonical_revision: int,
        schema_version: str,
        profile_key: str,
        profile_version: str,
        anchor,
        reference_pack,
        multiview_report,
        constraint_set,
        approved_by: str,
        approved_at: str,
    ) -> IdentityLockManifest:
        if (
            multiview_report.decision
            != MultiViewDecision.PASS
        ):
            raise IdentityLockError(
                "Cannot freeze identity "
                "without multi-view PASS."
            )

        self._assert_lineage(
            anchor=anchor,
            pack=reference_pack,
            report=multiview_report,
        )

        anchor_asset = (
            LockedIdentityAsset(
                role="anchor",

                artifact_id=(
                    anchor.artifact_id
                ),

                artifact_hash=(
                    anchor.artifact_hash
                ),

                storage_key=(
                    anchor
                    .artifact_storage_key
                ),

                render_id=(
                    anchor.render_id
                ),

                request_hash=(
                    anchor
                    .render_request_hash
                ),

                qa_run_id=(
                    anchor.qa_run_id
                ),

                qa_report_hash=(
                    anchor.qa_report_hash
                ),
            )
        )

        references = tuple(
            LockedIdentityAsset(
                role=(
                    reference.role.value
                ),

                artifact_id=(
                    reference
                    .artifact_id
                ),

                artifact_hash=(
                    reference
                    .artifact_hash
                ),

                storage_key=(
                    reference
                    .artifact_storage_key
                ),

                render_id=(
                    reference.render_id
                ),

                request_hash=(
                    reference.request_hash
                ),

                qa_run_id=(
                    reference.qa_run_id
                ),

                qa_report_hash=(
                    reference
                    .qa_report_hash
                ),
            )
            for reference
            in sorted(
                reference_pack.references,
                key=lambda item: (
                    item.role.value,
                    item.artifact_id,
                ),
            )
        )

        identity_constraints = (
            self._constraints.select(
                constraint_set
            )
        )

        lock_id = self._lock_id(
            anchor=anchor,
            reference_pack=(
                reference_pack
            ),
            multiview_report=(
                multiview_report
            ),
        )

        provisional = (
            IdentityLockManifest(
                version=self.VERSION,

                lock_id=lock_id,

                project_id=(
                    project_id
                ),

                asset_id=(
                    anchor.asset_id
                ),

                canonical_revision_id=(
                    anchor
                    .canonical_revision_id
                ),

                canonical_revision=(
                    canonical_revision
                ),

                canonical_hash=(
                    anchor.canonical_hash
                ),

                schema_version=(
                    schema_version
                ),

                profile_key=(
                    profile_key
                ),

                profile_version=(
                    profile_version
                ),

                anchor_id=(
                    anchor.anchor_id
                ),

                anchor=(
                    anchor_asset
                ),

                reference_pack_id=(
                    reference_pack.pack_id
                ),

                references=(
                    references
                ),

                identity_constraints=(
                    identity_constraints
                ),

                reference_pack_qa_run_id=(
                    multiview_report
                    .qa_run_id
                ),

                reference_pack_qa_report_hash=(
                    multiview_report
                    .report_hash
                ),

                approved_by=(
                    approved_by
                ),

                approved_at=(
                    approved_at
                ),

                identity_lock_hash="",
            )
        )

        digest = self._hasher.hash(
            provisional
        )

        manifest = replace(
            provisional,

            identity_lock_hash=(
                digest
            ),
        )

        self._validator.validate(
            manifest
        )

        return manifest

    @staticmethod
    def _assert_lineage(
        *,
        anchor,
        pack,
        report,
    ) -> None:
        canonical_hashes = {
            anchor.canonical_hash,
            pack.canonical_hash,
            report.canonical_hash,
        }

        if len(
            canonical_hashes
        ) != 1:
            raise IdentityLockError(
                "Identity lock canonical "
                "lineage mismatch."
            )

        if (
            anchor.anchor_id
            != pack.anchor_id
        ):
            raise IdentityLockError(
                "Reference pack belongs "
                "to different anchor."
            )

        if (
            pack.pack_id
            != report.pack_id
        ):
            raise IdentityLockError(
                "Multi-view report belongs "
                "to different reference pack."
            )

    @staticmethod
    def _lock_id(
        *,
        anchor,
        reference_pack,
        multiview_report,
    ) -> str:
        raw = "|".join(
            (
                anchor
                .canonical_hash,

                anchor
                .artifact_hash,

                reference_pack
                .pack_id,

                multiview_report
                .report_hash,
            )
        )

        return (
            "IL-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )
```

---

# 16.43 Một Identity Lock không được update in-place

Sai:

```text
IdentityLock IL-1
anchor X
↓
admin đổi reference #3
↓
UPDATE IL-1
```

Đúng:

```text
IL-1 frozen
↓
new reference / canonical revision
↓
IL-2
↓
IL-1 status = superseded
```

Không phá reproducibility video cũ.

---

# 16.44 Identity Lock persistence Laravel

Migration:

```php
Schema::create(
    'identity_locks',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'project_id'
        )->index();

        $table->string(
            'asset_id',
            190
        )->index();

        $table->uuid(
            'canonical_revision_id'
        );

        $table->unsignedInteger(
            'canonical_revision'
        );

        $table->char(
            'canonical_hash',
            64
        );

        $table->string(
            'schema_version',
            40
        );

        $table->string(
            'profile_key',
            100
        );

        $table->string(
            'profile_version',
            40
        );

        $table->uuid(
            'approved_anchor_id'
        );

        $table->uuid(
            'reference_pack_id'
        );

        $table->uuid(
            'reference_pack_qa_run_id'
        );

        $table->char(
            'reference_pack_qa_report_hash',
            64
        );

        $table->char(
            'identity_lock_hash',
            64
        )->unique();

        /*
         * Exact immutable JSON bytes/string.
         */
        $table->longText(
            'manifest_json'
        );

        $table->string(
            'status',
            40
        )->index();

        $table->string(
            'approved_by',
            190
        );

        $table->timestampTz(
            'approved_at'
        );

        $table->timestampTz(
            'frozen_at'
        );

        $table->uuid(
            'superseded_by_id'
        )->nullable();

        $table->timestampsTz();

        $table->index(
            [
                'project_id',
                'asset_id',
                'status',
            ],
            'identity_lock_project_asset_idx'
        );
    }
);
```

---

# 16.45 Approved anchors table

```php
Schema::create(
    'approved_anchors',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'project_id'
        )->index();

        $table->string(
            'asset_id',
            190
        )->index();

        $table->uuid(
            'canonical_revision_id'
        );

        $table->char(
            'canonical_hash',
            64
        );

        $table->uuid(
            'render_id'
        );

        $table->char(
            'render_request_hash',
            64
        );

        $table->string(
            'artifact_id',
            190
        );

        $table->char(
            'artifact_hash',
            64
        );

        $table->string(
            'artifact_storage_key',
            1024
        );

        $table->uuid(
            'qa_run_id'
        );

        $table->char(
            'qa_report_hash',
            64
        );

        $table->string(
            'status',
            40
        );

        $table->string(
            'approved_by',
            190
        )->nullable();

        $table->timestampTz(
            'approved_at'
        )->nullable();

        $table->timestampsTz();

        $table->unique(
            [
                'project_id',
                'asset_id',
                'canonical_revision_id',
                'artifact_hash',
            ],
            'approved_anchor_identity_uq'
        );
    }
);
```

---

# 16.46 Reference packs table

```php
Schema::create(
    'reference_packs',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'project_id'
        )->index();

        $table->string(
            'asset_id',
            190
        );

        $table->uuid(
            'canonical_revision_id'
        );

        $table->char(
            'canonical_hash',
            64
        );

        $table->uuid(
            'approved_anchor_id'
        );

        $table->string(
            'status',
            40
        );

        $table->string(
            'plan_version',
            80
        );

        $table->json(
            'plan_json'
        );

        $table->uuid(
            'multiview_qa_run_id'
        )->nullable();

        $table->char(
            'multiview_qa_report_hash',
            64
        )->nullable();

        $table->string(
            'approved_by',
            190
        )->nullable();

        $table->timestampTz(
            'approved_at'
        )->nullable();

        $table->timestampsTz();
    }
);
```

---

# 16.47 Reference pack assets

```php
Schema::create(
    'reference_pack_assets',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'reference_pack_id'
        )->index();

        $table->string(
            'view_id',
            100
        );

        $table->string(
            'role',
            100
        );

        $table->unsignedInteger(
            'sort_order'
        );

        $table->boolean(
            'required'
        )->default(true);

        $table->string(
            'status',
            40
        );

        $table->uuid(
            'render_id'
        )->nullable();

        $table->char(
            'request_hash',
            64
        )->nullable();

        $table->string(
            'artifact_id',
            190
        )->nullable();

        $table->char(
            'artifact_hash',
            64
        )->nullable();

        $table->string(
            'artifact_storage_key',
            1024
        )->nullable();

        $table->uuid(
            'qa_run_id'
        )->nullable();

        $table->char(
            'qa_report_hash',
            64
        )->nullable();

        $table->json(
            'view_spec_json'
        );

        $table->timestampsTz();

        $table->unique(
            [
                'reference_pack_id',
                'role',
            ],
            'reference_pack_role_uq'
        );
    }
);
```

---

# 16.48 Multi-view QA table

```php
Schema::create(
    'reference_pack_qa_runs',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'reference_pack_id'
        )->index();

        $table->char(
            'canonical_hash',
            64
        );

        $table->char(
            'constraint_set_hash',
            64
        );

        $table->char(
            'anchor_artifact_hash',
            64
        );

        $table->string(
            'decision',
            40
        )->nullable();

        $table->unsignedInteger(
            'inconsistent_hard_count'
        )->default(0);

        $table->unsignedInteger(
            'inconsistent_soft_count'
        )->default(0);

        $table->unsignedInteger(
            'uncertain_count'
        )->default(0);

        $table->string(
            'vision_provider',
            80
        );

        $table->string(
            'vision_model',
            190
        );

        $table->char(
            'report_hash',
            64
        )->nullable();

        $table->json(
            'report_json'
        )->nullable();

        $table->timestampTz(
            'completed_at'
        )->nullable();

        $table->timestampsTz();
    }
);
```

---

# 16.49 Laravel AnchorApprovalService

Human approval phải idempotent.

```php
<?php

declare(strict_types=1);

namespace App\Video\Identity;

use App\Models\ApprovedAnchor;
use App\Models\Render;
use App\Models\RenderQaRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AnchorApprovalService
{
    public function approve(
        string $renderId,
        string $adminId,
    ): ApprovedAnchor {
        return DB::transaction(
            function () use (
                $renderId,
                $adminId,
            ): ApprovedAnchor {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $render
                        ->execution_status
                        ->value
                    !== 'succeeded'
                ) {
                    throw new RuntimeException(
                        'Only succeeded render '
                        . 'can become anchor.'
                    );
                }

                if (
                    !$render->qa_approved
                ) {
                    throw new RuntimeException(
                        'Anchor requires '
                        . 'QA PASS.'
                    );
                }

                $qa =
                    RenderQaRun::query()
                        ->whereKey(
                            $render
                                ->latest_qa_run_id
                        )
                        ->firstOrFail();

                if (
                    $qa->decision->value
                    !== 'pass'
                ) {
                    throw new RuntimeException(
                        'Latest QA is not PASS.'
                    );
                }

                $artifact =
                    $this
                        ->primaryArtifact(
                            $render
                        );

                $existing =
                    ApprovedAnchor::query()
                        ->where(
                            'project_id',
                            $render
                                ->video_project_id
                        )
                        ->where(
                            'asset_id',
                            $render->asset_id
                        )
                        ->where(
                            'canonical_revision_id',
                            $render
                                ->canonical_concept_revision_id
                        )
                        ->where(
                            'artifact_hash',
                            $artifact[
                                'sha256'
                            ]
                        )
                        ->first();

                if (
                    $existing !== null
                ) {
                    return $existing;
                }

                return (
                    ApprovedAnchor::query()
                        ->create([
                            'project_id' =>
                                $render
                                    ->video_project_id,

                            'asset_id' =>
                                $render
                                    ->asset_id,

                            'canonical_revision_id' =>
                                $render
                                    ->canonical_concept_revision_id,

                            'canonical_hash' =>
                                $render
                                    ->canonical_hash,

                            'render_id' =>
                                $render->id,

                            'render_request_hash' =>
                                $render
                                    ->request_hash,

                            'artifact_id' =>
                                $artifact[
                                    'artifact_id'
                                ],

                            'artifact_hash' =>
                                $artifact[
                                    'sha256'
                                ],

                            'artifact_storage_key' =>
                                $artifact[
                                    'storage_key'
                                ],

                            'qa_run_id' =>
                                $qa->id,

                            'qa_report_hash' =>
                                $qa
                                    ->qa_report_hash,

                            'status' =>
                                'approved',

                            'approved_by' =>
                                $adminId,

                            'approved_at' =>
                                now(),
                        ])
                );
            }
        );
    }

    private function primaryArtifact(
        Render $render,
    ): array {
        $manifest =
            $render->artifact_manifest;

        foreach (
            $manifest[
                'artifacts'
            ] ?? []
            as $artifact
        ) {
            if (
                ($artifact['kind'] ?? null)
                === 'generated_image'
            ) {
                return $artifact;
            }
        }

        throw new RuntimeException(
            'Primary render artifact missing.'
        );
    }
}
```

---

# 16.50 Lưu ý `storage_key`

Part14 examples còn dùng:

```text
path
```

Production nên normalize thành:

```text
storage_key
```

Từ Part16 trở đi tôi khuyên **không lưu absolute path trong DB**.

Local implementation:

```text
storage_key =
sessions/ABC/run1/...
```

S3 implementation:

```text
storage_key =
projects/X/identity/...
```

Artifact resolver mới biến key thành path/URL khi cần.

---

# 16.51 ReferencePack creation Laravel

```php
final class ReferencePackService
{
    public function create(
        ApprovedAnchor $anchor,
        array $plan,
    ): ReferencePack {
        return DB::transaction(
            function () use (
                $anchor,
                $plan,
            ): ReferencePack {
                $pack =
                    ReferencePack::query()
                        ->create([
                            'project_id' =>
                                $anchor
                                    ->project_id,

                            'asset_id' =>
                                $anchor
                                    ->asset_id,

                            'canonical_revision_id' =>
                                $anchor
                                    ->canonical_revision_id,

                            'canonical_hash' =>
                                $anchor
                                    ->canonical_hash,

                            'approved_anchor_id' =>
                                $anchor->id,

                            'status' =>
                                'planned',

                            'plan_version' =>
                                $plan[
                                    'version'
                                ],

                            'plan_json' =>
                                $plan,
                        ]);

                foreach (
                    $plan['views']
                    as $view
                ) {
                    ReferencePackAsset
                        ::query()
                        ->create([
                            'reference_pack_id' =>
                                $pack->id,

                            'view_id' =>
                                $view[
                                    'view_id'
                                ],

                            'role' =>
                                $view[
                                    'role'
                                ],

                            'sort_order' =>
                                $view[
                                    'order'
                                ],

                            'required' =>
                                $view[
                                    'required'
                                ],

                            'status' =>
                                'planned',

                            'view_spec_json' =>
                                $view,
                        ]);
                }

                return $pack;
            }
        );
    }
}
```

---

# 16.52 Reference render dispatch

Laravel không tự viết prompt.

Flow:

```text
ReferencePackAsset PLANNED
↓
send approved anchor + view spec + frozen identity contract to Python
↓
Python builds RenderRequest
↓
returns exact request_json + request_hash
↓
Laravel RenderDispatchService freezes
↓
Part14
```

---

# 16.53 ReferencePack checkpoint service

Khi từng reference QA pass:

```php
final class ReferencePackCheckpointService
{
    public function markReferencePassed(
        ReferencePackAsset $asset,
        Render $render,
        RenderQaRun $qa,
    ): void {
        DB::transaction(
            function () use (
                $asset,
                $render,
                $qa,
            ): void {
                $asset =
                    ReferencePackAsset
                        ::query()
                        ->whereKey(
                            $asset->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $qa->decision->value
                    !== 'pass'
                ) {
                    throw new RuntimeException(
                        'Reference QA not PASS.'
                    );
                }

                $artifact =
                    $this
                        ->primaryArtifact(
                            $render
                        );

                $asset->forceFill([
                    'status' =>
                        'passed',

                    'render_id' =>
                        $render->id,

                    'request_hash' =>
                        $render
                            ->request_hash,

                    'artifact_id' =>
                        $artifact[
                            'artifact_id'
                        ],

                    'artifact_hash' =>
                        $artifact[
                            'sha256'
                        ],

                    'artifact_storage_key' =>
                        $artifact[
                            'storage_key'
                        ],

                    'qa_run_id' =>
                        $qa->id,

                    'qa_report_hash' =>
                        $qa
                            ->qa_report_hash,
                ])->save();
            }
        );
    }
}
```

---

# 16.54 Khi nào chạy Multi-view QA?

Chỉ khi:

```text
all required ReferencePackAssets
status = passed
```

Laravel:

```php
public function readyForMultiViewQa(
    ReferencePack $pack
): bool {
    return !ReferencePackAsset
        ::query()
        ->where(
            'reference_pack_id',
            $pack->id
        )
        ->where(
            'required',
            true
        )
        ->where(
            'status',
            '!=',
            'passed'
        )
        ->exists();
}
```

---

# 16.55 Multi-view QA không cần anchor nằm trong references array

Anchor được truyền riêng:

```text
anchor
+
reference images
```

để semantic role rõ.

Không duplicate anchor trong pack.

---

# 16.56 Human approval pack

Sau automated cross-view PASS:

```text
pack.status = review
```

Admin thấy:

```text
anchor
8 references
single-view QA
multi-view QA
```

và approve.

Không auto-freeze ngay.

---

# 16.57 IdentityLock Laravel service

Laravel phải freeze exact Python manifest bytes/hash.

```php
<?php

declare(strict_types=1);

namespace App\Video\Identity;

use App\Models\IdentityLock;
use App\Models\ReferencePack;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IdentityLockService
{
    public function freeze(
        ReferencePack $pack,
        string $manifestJson,
        string $identityLockHash,
        string $adminId,
    ): IdentityLock {
        return DB::transaction(
            function () use (
                $pack,
                $manifestJson,
                $identityLockHash,
                $adminId,
            ): IdentityLock {
                $pack =
                    ReferencePack::query()
                        ->whereKey(
                            $pack->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $pack->status
                    !== 'approved'
                ) {
                    throw new RuntimeException(
                        'Reference pack must '
                        . 'be approved.'
                    );
                }

                $decoded = json_decode(
                    $manifestJson,
                    true,
                    flags:
                        JSON_THROW_ON_ERROR
                );

                if (
                    !hash_equals(
                        $identityLockHash,
                        $decoded[
                            'identity_lock_hash'
                        ] ?? ''
                    )
                ) {
                    throw new RuntimeException(
                        'Identity lock payload '
                        . 'hash field mismatch.'
                    );
                }

                $existing =
                    IdentityLock::query()
                        ->where(
                            'identity_lock_hash',
                            $identityLockHash
                        )
                        ->first();

                if (
                    $existing !== null
                ) {
                    return $existing;
                }

                /*
                 * Supersede previous active lock
                 * only after new lock is inserted.
                 */
                $lock =
                    IdentityLock::query()
                        ->create([
                            'project_id' =>
                                $pack->project_id,

                            'asset_id' =>
                                $pack->asset_id,

                            'canonical_revision_id' =>
                                $pack
                                    ->canonical_revision_id,

                            'canonical_revision' =>
                                $decoded[
                                    'canonical_revision'
                                ],

                            'canonical_hash' =>
                                $pack
                                    ->canonical_hash,

                            'schema_version' =>
                                $decoded[
                                    'schema_version'
                                ],

                            'profile_key' =>
                                $decoded[
                                    'profile_key'
                                ],

                            'profile_version' =>
                                $decoded[
                                    'profile_version'
                                ],

                            'approved_anchor_id' =>
                                $pack
                                    ->approved_anchor_id,

                            'reference_pack_id' =>
                                $pack->id,

                            'reference_pack_qa_run_id' =>
                                $pack
                                    ->multiview_qa_run_id,

                            'reference_pack_qa_report_hash' =>
                                $pack
                                    ->multiview_qa_report_hash,

                            'identity_lock_hash' =>
                                $identityLockHash,

                            'manifest_json' =>
                                $manifestJson,

                            'status' =>
                                'frozen',

                            'approved_by' =>
                                $adminId,

                            'approved_at' =>
                                now(),

                            'frozen_at' =>
                                now(),
                        ]);

                $old =
                    IdentityLock::query()
                        ->where(
                            'project_id',
                            $pack->project_id
                        )
                        ->where(
                            'asset_id',
                            $pack->asset_id
                        )
                        ->where(
                            'status',
                            'frozen'
                        )
                        ->whereKeyNot(
                            $lock->id
                        )
                        ->lockForUpdate()
                        ->get();

                foreach ($old as $previous) {
                    $previous->forceFill([
                        'status' =>
                            'superseded',

                        'superseded_by_id' =>
                            $lock->id,
                    ])->save();
                }

                return $lock;
            }
        );
    }
}
```

---

# 16.58 Nhưng Laravel phải verify SHA-256 chứ không chỉ field

Quan trọng.

`identity_lock_hash` phải được tính từ exact JSON contract. Vì hash field nằm trong JSON, phải dùng cùng convention:

```text
identity_lock_hash=""
↓
canonical serialization
↓
SHA-256
```

Đừng chỉ trust Python.

Laravel có thể dùng manifest canonical bytes được Python gửi thêm:

```json
{
  "manifest_json": "...",
  "identity_lock_hash": "...",
  "hash_payload_json": "..."
}
```

Nhưng tốt nhất là giống Canonical Part9:

> **Python generates exact canonical manifest bytes. Laravel stores exact bytes and independently verifies using documented identity-lock canonicalizer.**

Nếu không muốn duplicate serializer PHP/Python, freeze endpoint có thể nhận:

```text
manifest_json
hashable_manifest_json
identity_lock_hash
```

Laravel:

```php
$actual =
    hash(
        'sha256',
        $hashableManifestJson
    );
```

và verify decoded hashable manifest khác manifest final chỉ ở:

```text
identity_lock_hash=""
```

Không decode/re-encode để hash.

---

# 16.59 Recommended freeze payload

```json
{
  "manifest_json":
    "{\"...\",\"identity_lock_hash\":\"abc...\"}",

  "hash_payload_json":
    "{\"...\",\"identity_lock_hash\":\"\"}",

  "identity_lock_hash":
    "abc..."
}
```

Laravel:

```php
if (
    !hash_equals(
        hash(
            'sha256',
            $hashPayloadJson
        ),
        $identityLockHash
    )
) {
    throw new RuntimeException(
        'Identity lock SHA-256 mismatch.'
    );
}
```

Sau đó verify:

```php
$manifest[
    'identity_lock_hash'
] === $identityLockHash;
```

Đây production-grade hơn.

---

# 16.60 IdentityLockResolver

Scene pipeline sau này không được lấy “latest random anchor”.

Laravel:

```php
final class IdentityLockResolver
{
    public function frozenForAsset(
        string $projectId,
        string $assetId,
    ): IdentityLock {
        return IdentityLock::query()
            ->where(
                'project_id',
                $projectId
            )
            ->where(
                'asset_id',
                $assetId
            )
            ->where(
                'status',
                'frozen'
            )
            ->latest(
                'frozen_at'
            )
            ->firstOrFail();
    }
}
```

Nhưng khi RenderPlan đã freeze:

```text
render_plan.identity_lock_id
```

phải dùng exact ID đó.

Không resolve “latest” lại mỗi render.

---

# 16.61 RenderPlan phải pin `identity_lock_id`

Khi Part17 build scenes:

```json
{
  "identity": {
    "identity_lock_id": "IL-...",
    "identity_lock_hash": "...",
    "canonical_revision_id": "...",
    "canonical_hash": "..."
  }
}
```

Sau freeze RenderPlan:

```text
identity lock không đổi
```

ngay cả khi admin tạo IL-2 sau đó.

---

# 16.62 Scene input contract

Python scene render về sau nhận:

```json
{
  "identity_lock": {
    "lock_id": "...",
    "identity_lock_hash": "...",
    "manifest_json": "..."
  }
}
```

Python verify hash trước.

Giống Canonical boundary:

```text
don't query DB
don't reinterpret identity
```

---

# 16.63 Verify Identity Lock Python

```python
from __future__ import annotations

import hashlib
import json

from media_runtime.identity.exceptions import (
    IdentityLockError,
)


class FrozenIdentityLockLoader:
    def load(
        self,
        *,
        manifest_json: str,
        expected_hash: str,
    ) -> dict:
        try:
            data = json.loads(
                manifest_json
            )
        except json.JSONDecodeError as exc:
            raise IdentityLockError(
                "Invalid identity lock JSON."
            ) from exc

        declared = data.get(
            "identity_lock_hash"
        )

        if (
            declared
            != expected_hash
        ):
            raise IdentityLockError(
                "Identity lock declared "
                "hash mismatch."
            )

        hash_payload = dict(
            data
        )

        hash_payload[
            "identity_lock_hash"
        ] = ""

        raw = json.dumps(
            hash_payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        actual = hashlib.sha256(
            raw
        ).hexdigest()

        if not hashlib.compare_digest(
            actual,
            expected_hash,
        ):
            raise IdentityLockError(
                "Identity lock SHA-256 "
                "verification failed."
            )

        return data
```

Lưu ý đây chỉ đúng khi manifest serializer chính thức cũng là `sort_keys + compact`. Nếu dùng exact bytes model như Part9 thì nên transport `hash_payload_json` exact bytes thay vì reconstruct.

Tôi khuyên reuse **exact-byte principle**:

```text
frozen bytes = hashed bytes
```

---

# 16.64 ReferenceSelection sau Identity Lock

Không phải mọi scene cần cả 8 references.

Nếu gửi 8 ảnh cho mọi call:

```text
tốn token
provider confusion
reference conflicts
```

Part17 cần deterministic selector.

Nhưng Identity Lock lưu full pack.

Scene sẽ chọn subset:

```text
anchor
+
camera nearest reference
+
maybe opposite geometry reference
```

Không chọn ngẫu nhiên.

---

# 16.65 Reference pack priority

Identity Lock reference pack nên có stable ordering:

```text
anchor = always primary
reference roles ordered by explicit pack order
```

Không alphabetical nếu profile đã có order.

Do đó `LockedIdentityAsset` nên thêm:

```python
order: int
```

Final DTO nên là:

```python
@dataclass(
    frozen=True,
    slots=True,
)
class LockedIdentityAsset:
    role: str

    order: int

    artifact_id: str
    artifact_hash: str
    storage_key: str

    render_id: str
    request_hash: str

    qa_run_id: str
    qa_report_hash: str
```

Anchor:

```python
order=0
```

References:

```python
order=view order
```

Đây là bản tôi khuyên dùng.

---

# 16.66 Preserve pack ordering in hash

Không sort references alphabetically khi constructing manifest nếu order semantic.

Correct:

```python
references=tuple(
    sorted(
        reference_pack.references,
        key=lambda item: (
            view_order_by_role[
                item.role
            ],
            item.artifact_id,
        ),
    )
)
```

Array order được hash giữ nguyên.

---

# 16.67 Anchor reference pack final example

```json
{
  "version": "identity-lock-v1",

  "lock_id": "IL-e943...",

  "asset_id": "master_vessel",

  "canonical_revision_id": "REV-3",

  "canonical_revision": 3,

  "canonical_hash": "a81f...",

  "schema_version": "1.0",

  "profile_key": "marine_vessel",

  "profile_version": "1.0",

  "anchor": {
    "role": "anchor",
    "order": 0,
    "artifact_id": "ANCHOR-001",
    "artifact_hash": "41ac...",
    "request_hash": "1f9d...",
    "qa_report_hash": "93dc..."
  },

  "references": [
    {
      "role": "port_profile",
      "order": 1,
      "artifact_id": "REF-PORT",
      "artifact_hash": "..."
    },

    {
      "role": "starboard_profile",
      "order": 2,
      "artifact_id": "REF-STBD",
      "artifact_hash": "..."
    },

    {
      "role": "bow_head_on",
      "order": 3,
      "artifact_id": "REF-BOW",
      "artifact_hash": "..."
    },

    {
      "role": "stern_head_on",
      "order": 4,
      "artifact_id": "REF-STERN",
      "artifact_hash": "..."
    }
  ],

  "identity_constraints": [
    {
      "constraint_id": "C-...",
      "semantic_key":
        "permanent_geometry.superstructure.primary_tier_count",
      "primitive": "count",
      "priority": 1,
      "severity": "hard",
      "expected_value": 4
    }
  ],

  "reference_pack_qa_report_hash":
    "...",

  "approved_by":
    "admin-id",

  "approved_at":
    "2026-08-30T21:00:00+07:00",

  "identity_lock_hash":
    "..."
}
```

---

# 16.68 Tests — Anchor cannot approve failed QA

```php
public function test_failed_qa_render_cannot_be_anchor(): void
{
    $render =
        Render::factory()
            ->create([
                'execution_status' =>
                    'succeeded',

                'qa_approved' =>
                    false,
            ]);

    $this->expectException(
        RuntimeException::class
    );

    app(
        AnchorApprovalService::class
    )->approve(
        $render->id,
        'admin-1'
    );
}
```

---

# 16.69 Same anchor + same profile = deterministic pack plan

```python
def test_reference_pack_plan_is_deterministic(
    planner,
    anchor,
    profile_config,
):
    first = planner.build(
        anchor=anchor,
        profile_config=(
            profile_config
        ),
    )

    second = planner.build(
        anchor=anchor,
        profile_config=(
            profile_config
        ),
    )

    assert first == second
```

---

# 16.70 Missing required view fails pack

```python
import pytest


def test_missing_required_reference_fails(
    plan,
    pack_without_stern,
):
    with pytest.raises(
        ReferencePackError
    ):
        (
            ReferencePackValidator()
            .validate(
                plan=plan,

                pack=(
                    pack_without_stern
                ),
            )
        )
```

---

# 16.71 Reference render retains canonical hash

```python
def test_reference_view_preserves_canonical_lineage(
    builder,
    pack,
    view,
    anchor,
    anchor_path,
    base_request,
    base_request_hash,
):
    request = builder.build(
        pack=pack,

        view=view,

        anchor=anchor,

        anchor_local_path=(
            anchor_path
        ),

        base_request=(
            base_request
        ),

        base_request_hash=(
            base_request_hash
        ),
    )

    assert (
        request.source_canonical_hash
        == anchor.canonical_hash
    )

    assert (
        request.source_revision_id
        == anchor
        .canonical_revision_id
    )
```

---

# 16.72 Reference render must be EDIT

```python
def test_reference_view_is_reference_guided_edit(
    reference_request,
):
    assert (
        reference_request
        .operation
        == RenderOperation.EDIT
    )

    assert (
        len(
            reference_request
            .references
            .references
        )
        >= 1
    )
```

---

# 16.73 Anchor always first reference

```python
def test_anchor_is_primary_reference(
    reference_request,
    anchor,
):
    first = (
        reference_request
        .references
        .ordered()[0]
    )

    assert (
        first.artifact_id
        == anchor.artifact_id
    )

    assert (
        first.role
        == ReferenceRole.IDENTITY
    )
```

---

# 16.74 Cross-view hard drift fails pack

```python
def test_hard_identity_drift_fails_pack(
    hard_target,
    inconsistent_finding,
):
    gate = (
        MultiViewQaGate(
            MultiViewGatePolicy()
        )
    )

    result = gate.decide(
        findings=(
            inconsistent_finding,
        ),

        targets=(
            hard_target,
        ),
    )

    assert (
        result.decision
        == MultiViewDecision.FAIL
    )
```

---

# 16.75 Not comparable hard target => review

```python
def test_hard_not_comparable_requires_review(
    hard_target,
    not_comparable_finding,
):
    result = (
        MultiViewQaGate(
            MultiViewGatePolicy()
        ).decide(
            findings=(
                not_comparable_finding,
            ),

            targets=(
                hard_target,
            ),
        )
    )

    assert (
        result.decision
        == MultiViewDecision.REVIEW
    )
```

---

# 16.76 Multi-view model cannot invent view

```python
def test_multiview_parser_rejects_unknown_view(
    parser,
    target,
):
    raw = {
        "results": [
            {
                "target_id":
                    target.target_id,

                "status":
                    "consistent",

                "confidence":
                    0.95,

                "mismatch_type":
                    None,

                "evidence":
                    "consistent",

                "observations": [
                    {
                        "view_id":
                            "FAKE",

                        "observable":
                            True,

                        "observed_value":
                            4,

                        "confidence":
                            0.95,

                        "evidence":
                            "visible"
                    }
                ]
            }
        ],

        "overall_notes":
            ""
    }

    with pytest.raises(
        MultiViewQaError
    ):
        parser.parse(
            raw=raw,

            targets=(
                target,
            ),

            view_ids=(
                "real-view",
            ),
        )
```

---

# 16.77 Identity lock requires multiview PASS

```python
def test_identity_lock_requires_multiview_pass(
    lock_service,
    inputs,
):
    failed_report = replace(
        inputs[
            "multiview_report"
        ],

        decision=(
            MultiViewDecision.FAIL
        ),
    )

    with pytest.raises(
        IdentityLockError
    ):
        lock_service.build(
            **{
                **inputs,
                "multiview_report":
                    failed_report,
            }
        )
```

---

# 16.78 Same inputs produce same lock hash

```python
def test_identity_lock_hash_is_deterministic(
    lock_service,
    inputs,
):
    first = lock_service.build(
        **inputs
    )

    second = lock_service.build(
        **inputs
    )

    assert (
        first.identity_lock_hash
        == second.identity_lock_hash
    )
```

---

# 16.79 Change one reference changes identity lock hash

```python
def test_reference_change_changes_lock_hash(
    lock_service,
    inputs,
):
    first = lock_service.build(
        **inputs
    )

    pack = inputs[
        "reference_pack"
    ]

    changed_ref = replace(
        pack.references[0],

        artifact_hash=(
            "f" * 64
        ),
    )

    changed_pack = replace(
        pack,

        references=(
            changed_ref,
            *pack.references[1:],
        ),
    )

    second = lock_service.build(
        **{
            **inputs,
            "reference_pack":
                changed_pack,
        }
    )

    assert (
        first.identity_lock_hash
        != second.identity_lock_hash
    )
```

---

# 16.80 Canonical revision change changes lock

```python
def test_canonical_revision_change_requires_new_identity_lock(
    lock_service,
    inputs,
):
    first = lock_service.build(
        **inputs
    )

    changed_anchor = replace(
        inputs["anchor"],

        canonical_revision_id=(
            "REV-NEW"
        ),

        canonical_hash=(
            "e" * 64
        ),
    )

    with pytest.raises(
        IdentityLockError
    ):
        lock_service.build(
            **{
                **inputs,
                "anchor":
                    changed_anchor,
            }
        )
```

Nếu tất cả pack/report cũng đổi sang revision mới thì build thành lock hash mới.

---

# 16.81 Frozen identity lock cannot mutate

Laravel:

```php
public function update(
    IdentityLock $lock,
    array $changes,
): never {
    if (
        $lock->status
        === 'frozen'
    ) {
        throw new RuntimeException(
            'Frozen identity lock '
            . 'is immutable.'
        );
    }

    throw new RuntimeException(
        'Identity lock update '
        . 'is unsupported.'
    );
}
```

Đơn giản hơn: **không viết update service cho manifest**.

---

# 16.82 Identity Lock không khóa state tạm thời

Ví dụ construction:

```text
canonical final material:
anthracite paint

construction scene:
bare plating
```

Identity Lock vẫn giữ:

```text
hull geometry
tier count
bow/stern geometry
major openings
proportions
topology
```

không ép:

```text
anthracite paint
```

vào construction.

Đây là lý do Part11 `AssetProjection` vẫn cần thiết ngay cả sau Identity Lock.

Identity Lock không thay AssetProjection.

---

# 16.83 Relationship giữa Canonical và Identity Lock

```text
CanonicalDesignSpec
    = semantic identity truth

IdentityLock
    = approved visual realization of that truth
```

Scene render cần cả hai:

```text
Canonical/ConstraintSet
+
IdentityLock references
+
Scene State
+
Camera
```

Không chỉ image anchor.

Nếu chỉ dùng anchor image:

```text
model có thể giữ silhouette nhưng bỏ count/topology
```

Nếu chỉ dùng canonical prompt:

```text
model có thể drift visual identity
```

Hai layer bổ trợ nhau.

---

# 16.84 Multi-view QA không majority vote geometry

Ví dụ:

```text
Anchor: 4 tiers
Port: 4
Starboard: 5
Bow: hard to tell
Rear: 4
```

Result:

```text
starboard inconsistent
```

Không:

```text
4/5 ảnh giống nhau nên canonical = 4
```

Canonical đã là 4 từ trước.

Cross-view QA chỉ verify.

---

# 16.85 Reference repair

Nếu một view fail single-view QA:

```text
Part15 targeted repair
```

Nếu view single-view PASS nhưng cross-view fail:

```text
cross-view reference correction
```

nên regenerate/edit **reference đó từ approved anchor**, không từ chính ảnh drift nếu identity drift mạnh.

Policy:

```python
@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceRepairPolicy:
    max_repair_generations: int = 2

    use_anchor_as_primary_source: bool = True

    preserve_other_passed_references: bool = True

    p0_failure_forces_regeneration: bool = True
```

---

# 16.86 Cross-view repair prompt

Ví dụ:

```text
REFERENCE IDENTITY CORRECTION

Use the supplied approved anchor as the authoritative
identity source.

Regenerate only the STARBOARD PROFILE reference view.

The previous reference drifted on:
- exactly four primary superstructure tiers
- continuous upper shell geometry

Preserve:
- approved anchor silhouette
- bow geometry
- stern geometry
- declared proportions
- all already validated permanent features

Do not redesign the subject.
Change only the camera viewpoint required for the
starboard profile.
```

Không sửa anchor.

---

# 16.87 Identity Lock artifact directory

```text
work/artifacts/
{session}/
{run}/
identity/
├── anchor/
│   ├── anchor.png
│   ├── qa_report.json
│   └── approval.json
│
├── reference_pack/
│   ├── pack_plan.json
│   ├── port_profile.png
│   ├── starboard_profile.png
│   ├── bow_head_on.png
│   ├── stern_head_on.png
│   ├── high_front_three_quarter.png
│   ├── high_rear_three_quarter.png
│   ├── high_overview.png
│   ├── camera_matched.png
│   ├── multiview_qa_report.json
│   └── approval.json
│
└── lock/
    ├── identity_lock.json
    └── identity_lock_hash.txt
```

Nếu S3 thì tương ứng storage keys.

---

# 16.88 Identity lock manifest phải được artifact ledger hóa

Artifact Ledger record:

```json
{
  "kind": "identity_lock_manifest",

  "identity_lock_hash": "...",

  "canonical_hash": "...",

  "anchor_artifact_hash": "...",

  "reference_pack_qa_report_hash": "...",

  "reference_artifact_hashes": [
    "...",
    "..."
  ]
}
```

Không chỉ lưu DB row.

---

# 16.89 Cost accounting Part16

Có ba loại cost:

```text
reference_image_render
reference_single_view_qa
reference_multiview_qa
```

Không gộp thành:

```text
image_render
```

nếu bạn muốn analytics tốt.

Suggested actions:

```text
identity_anchor_render
identity_anchor_qa

identity_reference_render
identity_reference_qa

identity_multiview_qa
```

Render attempt costs vẫn record theo Part14.

QA costs record theo qa_run IDs.

---

# 16.90 Không auto-generate 8 views cho mọi topic

Universal system:

```text
marine_vessel → 8
aircraft → maybe 7
architecture → maybe 6
consumer product → maybe 5
generic physical object → maybe 4
```

Profile quyết định.

Không:

```python
if object_type == "yacht":
```

trong Core.

---

# 16.91 Generic fallback reference pack

Nếu profile `generic_physical_object`:

```json
[
  {
    "role": "port_profile",
    "camera": {
      "view": "left_side_profile"
    }
  },
  {
    "role": "starboard_profile",
    "camera": {
      "view": "right_side_profile"
    }
  },
  {
    "role": "high_front_three_quarter",
    "camera": {
      "view": "front_three_quarter"
    }
  },
  {
    "role": "high_rear_three_quarter",
    "camera": {
      "view": "rear_three_quarter"
    }
  }
]
```

Tên role `port/starboard` không hợp universal object. Vì vậy enum hiện tại hơi marine-centric.

Production tốt hơn đổi Core role thành:

```python
class ReferenceViewRole(StrEnum):
    LEFT_PROFILE = "left_profile"
    RIGHT_PROFILE = "right_profile"

    FRONT = "front"
    REAR = "rear"

    FRONT_THREE_QUARTER = (
        "front_three_quarter"
    )

    REAR_THREE_QUARTER = (
        "rear_three_quarter"
    )

    HIGH_OVERVIEW = "high_overview"

    CAMERA_MATCHED = "camera_matched"
```

Profile marine có metadata:

```text
left = port
right = starboard
front = bow
rear = stern
```

Đây tốt hơn cho mục tiêu hàng nghìn topic.

Tôi khuyên **dùng generic enum này trong production**, không dùng enum marine ở đầu Part16.

---

# 16.92 Final generic ReferenceViewRole

Chốt lại:

```python
class ReferenceViewRole(StrEnum):
    LEFT_PROFILE = "left_profile"

    RIGHT_PROFILE = "right_profile"

    FRONT = "front"

    REAR = "rear"

    FRONT_THREE_QUARTER = (
        "front_three_quarter"
    )

    REAR_THREE_QUARTER = (
        "rear_three_quarter"
    )

    HIGH_OVERVIEW = (
        "high_overview"
    )

    CAMERA_MATCHED = (
        "camera_matched"
    )

    DETAIL_IDENTITY = (
        "detail_identity"
    )
```

Marine profile metadata:

```json
{
  "role": "left_profile",
  "semantic_label": "port profile"
}
```

Aircraft:

```json
{
  "role": "left_profile",
  "semantic_label": "left-side profile"
}
```

Architecture:

```json
{
  "role": "front",
  "semantic_label": "front elevation"
}
```

Đây đúng universal architecture hơn.

---

# 16.93 Full pipeline sau Part16

```text
Article
 ↓
Evidence
 ↓
InspirationBrief
 ↓
Sonnet Canonical Concept
 ↓
CanonicalDesignSpec
 ↓
Validation / Repair
 ↓
Canonical JSON
 ↓
SHA-256
 ↓
Revision Freeze
════════════════════════════════
Laravel semantic boundary
════════════════════════════════
 ↓
AssetProjection
 ↓
ConstraintSet
 ↓
PromptSpec
 ↓
ProviderCapabilityProjection
 ↓
RenderRequest
 ↓
Part14 Orchestrator
 ↓
MASTER ANCHOR
 ↓
Part15 QA
 ↓
QA PASS
 ↓
Human Anchor Approval
 ↓
ReferencePackPlan
 ↓
N identity-preserving reference renders
 ↓
Part14
 ↓
Part15 single-view QA each
 ↓
all required PASS
 ↓
Cross-view MultiView QA
 ↓
      ├─ FAIL
      │    ↓
      │ reference-specific repair
      │
      ├─ REVIEW
      │    ↓
      │ human
      │
      └─ PASS
           ↓
Human Reference Pack Approval
           ↓
IdentityConstraintSelector
           ↓
IdentityLockManifest
           ↓
SHA-256
           ↓
FREEZE
════════════════════════════════
IDENTITY LOCK BOUNDARY
════════════════════════════════
           ↓
Part17 Scene/Shot Pipeline
```

# 16.94 Invariants khóa sau Part16

```text
1. Anchor phải Render SUCCEEDED + QA PASS
   + human approved.

2. Reference views luôn derive từ approved anchor.

3. Reference generation không được redesign subject.

4. Reference view chỉ được thay camera/view intent.

5. Mỗi required reference phải qua Part15 QA.

6. Cross-view QA không thay single-view QA.

7. Anchor là authoritative visual identity source.

8. Multi-view QA không dùng majority vote để sửa anchor.

9. Cross-view P0–P5 inconsistency là identity drift.

10. NOT_COMPARABLE không được biến thành PASS.

11. Hard identity inconsistency làm pack FAIL.

12. Reference-specific drift ưu tiên regenerate
    reference đó, không cả pack.

13. P0 reference identity drift ưu tiên regenerate
    từ anchor thay vì local edit.

14. Identity Lock chỉ được tạo sau:
    single-view PASS + multi-view PASS
    + human approval.

15. IdentityLockManifest là immutable.

16. Thay bất kỳ anchor/reference artifact
    → identity_lock_hash thay đổi.

17. Canonical revision thay đổi
    → phải tạo Identity Lock mới.

18. Frozen Identity Lock không update in-place.

19. RenderPlan phải pin identity_lock_id/hash,
    không resolve latest khi execution.

20. Identity Lock không thay CanonicalDesignSpec.

21. Identity Lock không khóa temporary state.

22. Scene render dùng:
    Canonical constraints
    + Identity Lock references
    + Scene state/camera.

23. Python không query Laravel DB để tự chọn anchor.

24. Exact identity lock bytes/hash phải được verify
    trước downstream execution.

25. Cost của anchor/reference/multiview QA
    được ledger riêng.
```

Với **Phần 16**, ta đã hoàn thành toàn bộ nền tảng **Design Identity**. Từ đây một subject không còn chỉ là “prompt + một ảnh neo”, mà trở thành một artifact có lineage:

```text
Canonical Revision
        +
Approved Anchor
        +
QA-approved Reference Pack
        +
Identity-critical Constraints
        +
Multi-view QA
        ↓
IdentityLockManifest
        ↓
identity_lock_hash
```

Do đó **Phần 17** có thể tập trung hoàn toàn vào `Scene Projection + Scene State Graph + Reference Selection + Scene Image → Video Keyframe → MotionSpec → Veo/Kling`, trong khi hình học/identity đã bị khóa và không còn được Scene Planner hay video provider tự diễn giải lại.
