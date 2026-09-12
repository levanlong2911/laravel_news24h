Được. Tôi sẽ bám đúng boundary và contract đã khóa ở **Part 1–16**. Part 17 không tạo một pipeline thứ hai; nó **tái sử dụng CanonicalDesignSpec, IdentityLock, AssetProjection, ConstraintSet, PromptCompiler, RenderRequest/Artifact Ledger, Part 14 Orchestrator và Part 15 QA**.

Điểm cần bổ sung duy nhất vào Part 14 là lifecycle **provider video bất đồng bộ**. Image render thường có thể chờ response trong một worker call; video provider có thể trả `provider_job_id` rồi render trong nhiều phút. Vì vậy worker **không được giữ lease suốt thời gian video render**.

# PHẦN 17 — Architecture

```text
Frozen RenderPlan
      │
      ├── pinned identity_lock_id/hash
      ├── frozen Scene State Graph
      ├── frozen scene order
      ├── camera intent
      ├── motion intent
      └── duration
      ↓
SceneExecutionPacket
      ↓
Python verifies:
canonical_hash
identity_lock_hash
render_plan_hash
      ↓
SceneProjection
      ↓
Scene State Graph Validator
      ↓
ReferenceSelector
      │
      ├── Approved Anchor
      ├── camera-matched identity reference
      ├── optional geometry reference
      └── previous approved scene for state continuity
      ↓
SceneConstraintOverlay
      ↓
ConstraintSet
      ↓
Part 12 Prompt Compiler
      ↓
Scene Image RenderRequest
      ↓
Part 14 Render Orchestrator
      ↓
Scene Image
      ↓
Part 15 Vision QA
      │
      ├── FAIL → targeted repair
      ├── REVIEW → human
      └── PASS
             ↓
        VideoKeyframe
             ↓
        MotionSpec
             ↓
        MotionPromptCompiler
             ↓
        VideoRenderRequest
             ↓
        Provider Capability Projection
             │
             ├── Veo Adapter
             └── Kling Adapter
             ↓
        SUBMIT
             ↓
        provider_job_id
             ↓
        release worker lease
             ↓
        POLL/RESUME
             ↓
        video artifact
             ↓
        SHA-256 / Artifact Ledger
             ↓
        Shot ready
```

Boundary vẫn giữ nguyên:

```text
Laravel
= Scene semantics + scene order + state graph
  + approvals + DB truth + frozen RenderPlan

Python
= projection + reference selection + constraint overlay
  + prompt/motion compilation + provider execution

Veo/Kling
= execute only

Provider không được:
- sửa scene order
- sửa state graph
- redesign identity
- quyết định geometry
- thêm/bớt permanent features
```

---

# 17.1 Package structure

```text
media_runtime/
├── scene/
│   ├── __init__.py
│   ├── enums.py
│   ├── exceptions.py
│   │
│   ├── contract/
│   │   ├── scene_plan.py
│   │   ├── execution_packet.py
│   │   └── scene_projection.py
│   │
│   ├── state/
│   │   ├── fact.py
│   │   ├── snapshot.py
│   │   ├── mutation.py
│   │   ├── transition.py
│   │   ├── graph.py
│   │   └── validator.py
│   │
│   ├── projection/
│   │   ├── projector.py
│   │   ├── serializer.py
│   │   └── hasher.py
│   │
│   ├── references/
│   │   ├── camera_role_mapper.py
│   │   ├── candidate.py
│   │   ├── selection.py
│   │   └── selector.py
│   │
│   ├── constraints/
│   │   ├── overlay.py
│   │   └── composer.py
│   │
│   ├── image/
│   │   ├── prompt_input.py
│   │   ├── request_builder.py
│   │   └── service.py
│   │
│   └── service.py
│
├── keyframe/
│   ├── enums.py
│   ├── keyframe.py
│   ├── set.py
│   ├── validator.py
│   └── builder.py
│
├── motion/
│   ├── enums.py
│   ├── camera.py
│   ├── subject.py
│   ├── environment.py
│   ├── transition.py
│   ├── spec.py
│   ├── compiler.py
│   ├── serializer.py
│   ├── hasher.py
│   └── prompt_compiler.py
│
├── video/
│   ├── enums.py
│   ├── request.py
│   ├── request_hash.py
│   ├── result.py
│   │
│   ├── capabilities/
│   │   ├── profile.py
│   │   ├── registry.py
│   │   └── projector.py
│   │
│   ├── providers/
│   │   ├── base.py
│   │   ├── response.py
│   │   ├── registry.py
│   │   ├── veo.py
│   │   └── kling.py
│   │
│   ├── artifacts/
│   │   └── persister.py
│   │
│   └── execution/
│       ├── submitter.py
│       ├── poller.py
│       └── service.py
│
└── tests/
    ├── scene/
    ├── keyframe/
    ├── motion/
    └── video/
```

Laravel:

```text
app/Video/Scene/
├── DTO/
│   ├── FrozenScene.php
│   ├── FrozenSceneState.php
│   └── SceneExecutionPacket.php
├── Services/
│   ├── SceneExecutionPacketBuilder.php
│   ├── SceneImageCheckpointService.php
│   ├── KeyframeCheckpointService.php
│   └── VideoShotCheckpointService.php
└── Jobs/
    ├── DispatchSceneImageJob.php
    ├── DispatchVideoShotJob.php
    └── PollVideoProviderJob.php
```

---

# 17.2 Scene enums

```python
# media_runtime/scene/enums.py

from __future__ import annotations

from enum import StrEnum


class SceneAssetType(StrEnum):
    SCENE_IMAGE = "scene_image"
    VIDEO_KEYFRAME = "video_keyframe"
    VIDEO_SHOT = "video_shot"


class SceneStatus(StrEnum):
    PLANNED = "planned"
    PROJECTED = "projected"
    IMAGE_QUEUED = "image_queued"
    IMAGE_RENDERING = "image_rendering"
    IMAGE_QA = "image_qa"
    IMAGE_APPROVED = "image_approved"
    KEYFRAME_READY = "keyframe_ready"
    VIDEO_QUEUED = "video_queued"
    VIDEO_RUNNING = "video_running"
    VIDEO_READY = "video_ready"
    FAILED = "failed"
    REVIEW = "review"


class StateMutationKind(StrEnum):
    ADD = "add"
    REMOVE = "remove"
    UPDATE = "update"
    PRESERVE = "preserve"


class StateFactSource(StrEnum):
    CANONICAL = "canonical"
    RENDER_PLAN = "render_plan"
    PREVIOUS_SCENE = "previous_scene"


class ContinuityMode(StrEnum):
    INDEPENDENT = "independent"
    SAME_STATE = "same_state"
    STATE_TRANSITION = "state_transition"


class SceneReferencePurpose(StrEnum):
    IDENTITY = "identity"
    VIEW = "view"
    GEOMETRY = "geometry"
    STATE_CONTINUITY = "state_continuity"
```

---

# 17.3 Scene exceptions

```python
# media_runtime/scene/exceptions.py

from __future__ import annotations


class SceneRuntimeError(RuntimeError):
    pass


class SceneContractError(SceneRuntimeError):
    pass


class SceneStateGraphError(SceneRuntimeError):
    pass


class SceneProjectionError(SceneRuntimeError):
    pass


class SceneReferenceSelectionError(
    SceneRuntimeError
):
    pass


class SceneLineageError(SceneRuntimeError):
    pass


class MotionSpecError(SceneRuntimeError):
    pass


class VideoExecutionError(SceneRuntimeError):
    pass
```

---

# 17.4 StateFact

State graph không chứa prose mơ hồ như:

```text
"the yacht is more complete now"
```

Mỗi state là structured fact.

```python
# media_runtime/scene/state/fact.py

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.scene.enums import (
    StateFactSource,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateFact:
    path: str
    value: Any

    source: StateFactSource

    identity_critical: bool = False

    def __post_init__(self) -> None:
        if not self.path.strip():
            raise ValueError(
                "State fact path cannot be empty."
            )
```

Ví dụ:

```json
{
  "path": "construction.hull.bottom_plating",
  "value": "installed",
  "source": "render_plan",
  "identity_critical": false
}
```

---

# 17.5 State Snapshot

```python
# media_runtime/scene/state/snapshot.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.state.fact import (
    SceneStateFact,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateSnapshot:
    state_id: str

    sequence_index: int

    facts: tuple[
        SceneStateFact,
        ...,
    ]

    def fact_map(
        self,
    ) -> dict:
        return {
            item.path: item.value
            for item in self.facts
        }

    def __post_init__(self) -> None:
        paths = [
            item.path
            for item in self.facts
        ]

        if len(paths) != len(
            set(paths)
        ):
            raise ValueError(
                "Duplicate state fact path."
            )
```

---

# 17.6 State mutation

```python
# media_runtime/scene/state/mutation.py

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.scene.enums import (
    StateMutationKind,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateMutation:
    path: str

    kind: StateMutationKind

    before: Any

    after: Any

    visual: bool

    description: str | None = None
```

---

# 17.7 State transition

```python
# media_runtime/scene/state/transition.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.state.mutation import (
    SceneStateMutation,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateTransition:
    transition_id: str

    from_state_id: str

    to_state_id: str

    mutations: tuple[
        SceneStateMutation,
        ...,
    ]
```

---

# 17.8 Scene State Graph

```python
# media_runtime/scene/state/graph.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.state.snapshot import (
    SceneStateSnapshot,
)
from media_runtime.scene.state.transition import (
    SceneStateTransition,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateGraph:
    version: str

    graph_id: str

    initial_state_id: str

    states: tuple[
        SceneStateSnapshot,
        ...,
    ]

    transitions: tuple[
        SceneStateTransition,
        ...,
    ]

    def state(
        self,
        state_id: str,
    ) -> SceneStateSnapshot:
        for state in self.states:
            if state.state_id == state_id:
                return state

        raise KeyError(
            state_id
        )

    def transition(
        self,
        transition_id: str,
    ) -> SceneStateTransition:
        for transition in self.transitions:
            if (
                transition.transition_id
                == transition_id
            ):
                return transition

        raise KeyError(
            transition_id
        )
```

---

# 17.9 State graph validator

Đây là phần rất quan trọng.

Nếu transition khai báo:

```text
before = absent
after  = installed
```

thì snapshot trước/sau phải đúng.

Không để LLM tạo graph contradictory.

```python
# media_runtime/scene/state/validator.py

from __future__ import annotations

from media_runtime.scene.enums import (
    StateMutationKind,
)
from media_runtime.scene.exceptions import (
    SceneStateGraphError,
)
from media_runtime.scene.state.graph import (
    SceneStateGraph,
)


class SceneStateGraphValidator:
    def validate(
        self,
        graph: SceneStateGraph,
    ) -> None:
        if not graph.states:
            raise SceneStateGraphError(
                "State graph has no states."
            )

        ids = [
            state.state_id
            for state in graph.states
        ]

        if len(ids) != len(
            set(ids)
        ):
            raise SceneStateGraphError(
                "Duplicate state_id."
            )

        if (
            graph.initial_state_id
            not in set(ids)
        ):
            raise SceneStateGraphError(
                "Initial state does not exist."
            )

        ordered = sorted(
            graph.states,
            key=lambda item:
                item.sequence_index,
        )

        sequence = [
            item.sequence_index
            for item in ordered
        ]

        if sequence != list(
            range(len(sequence))
        ):
            raise SceneStateGraphError(
                "State sequence indexes "
                "must be contiguous from zero."
            )

        transition_ids = [
            item.transition_id
            for item in graph.transitions
        ]

        if len(
            transition_ids
        ) != len(
            set(transition_ids)
        ):
            raise SceneStateGraphError(
                "Duplicate transition_id."
            )

        for transition in (
            graph.transitions
        ):
            try:
                before_state = graph.state(
                    transition.from_state_id
                )

                after_state = graph.state(
                    transition.to_state_id
                )
            except KeyError as exc:
                raise SceneStateGraphError(
                    "Transition references "
                    "unknown state."
                ) from exc

            if (
                after_state.sequence_index
                <= before_state.sequence_index
            ):
                raise SceneStateGraphError(
                    "State transition cannot "
                    "move backwards."
                )

            self._validate_transition(
                before_state.fact_map(),
                after_state.fact_map(),
                transition,
            )

    def _validate_transition(
        self,
        before: dict,
        after: dict,
        transition,
    ) -> None:
        mutation_paths = set()

        for mutation in (
            transition.mutations
        ):
            if (
                mutation.path
                in mutation_paths
            ):
                raise SceneStateGraphError(
                    "Duplicate mutation path "
                    + mutation.path
                )

            mutation_paths.add(
                mutation.path
            )

            before_actual = before.get(
                mutation.path
            )

            after_actual = after.get(
                mutation.path
            )

            if (
                mutation.before
                != before_actual
            ):
                raise SceneStateGraphError(
                    "Mutation before-value "
                    "does not match source state: "
                    + mutation.path
                )

            if (
                mutation.after
                != after_actual
            ):
                raise SceneStateGraphError(
                    "Mutation after-value "
                    "does not match target state: "
                    + mutation.path
                )

            if (
                mutation.kind
                == StateMutationKind.PRESERVE
                and mutation.before
                != mutation.after
            ):
                raise SceneStateGraphError(
                    "PRESERVE mutation changes value: "
                    + mutation.path
                )

            if (
                mutation.kind
                == StateMutationKind.ADD
                and mutation.before
                not in (
                    None,
                    False,
                    "absent",
                    "not_present",
                )
            ):
                raise SceneStateGraphError(
                    "ADD mutation has "
                    "non-absent before value: "
                    + mutation.path
                )

            if (
                mutation.kind
                == StateMutationKind.REMOVE
                and mutation.after
                not in (
                    None,
                    False,
                    "absent",
                    "not_present",
                )
            ):
                raise SceneStateGraphError(
                    "REMOVE mutation has "
                    "non-absent after value: "
                    + mutation.path
                )
```

---

# 17.10 Frozen Scene Plan

Laravel quyết định scene semantics. Python không tự invent scene.

```python
# media_runtime/scene/contract/scene_plan.py

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.scene.enums import (
    ContinuityMode,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneCameraIntent:
    view: str

    side: str | None

    elevation: str | None

    pitch: str | None

    movement: str | None

    framing: str

    preferred_reference_role: (
        str | None
    ) = None


@dataclass(
    frozen=True,
    slots=True,
)
class SceneMotionIntent:
    camera_motion: str

    subject_motion: str | None

    environment_motion: str | None

    intensity: float

    temporal_description: (
        str | None
    ) = None


@dataclass(
    frozen=True,
    slots=True,
)
class FrozenScenePlan:
    scene_id: str

    scene_code: str

    sequence_index: int

    beat: str

    visual_goal: str

    duration_seconds: float

    from_state_id: str

    to_state_id: str

    transition_id: str | None

    continuity_mode: ContinuityMode

    camera: SceneCameraIntent

    motion: SceneMotionIntent

    required_paths: tuple[
        str,
        ...,
    ]

    optional_paths: tuple[
        str,
        ...,
    ]

    excluded_paths: tuple[
        str,
        ...,
    ]

    metadata: dict[
        str,
        Any,
    ]
```

---

# 17.11 SceneExecutionPacket

Laravel gửi exact pinned lineage.

```python
# media_runtime/scene/contract/execution_packet.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.contract.scene_plan import (
    FrozenScenePlan,
)
from media_runtime.scene.state.graph import (
    SceneStateGraph,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneExecutionPacket:
    version: str

    project_id: str
    video_session_id: str

    render_plan_revision: int
    render_plan_hash: str

    canonical_revision_id: str
    canonical_hash: str

    identity_lock_id: str
    identity_lock_hash: str

    identity_lock_json: str

    constraint_set_hash: str

    state_graph: SceneStateGraph

    scene: FrozenScenePlan

    previous_scene_artifact_id: (
        str | None
    )

    previous_scene_artifact_hash: (
        str | None
    )
```

---

# 17.12 Scene Projection

Part11 có AssetProjection. SceneProjection là derived asset-specific projection, không phải design truth.

```python
# media_runtime/scene/contract/scene_projection.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.contract.scene_plan import (
    SceneCameraIntent,
    SceneMotionIntent,
)
from media_runtime.scene.state.snapshot import (
    SceneStateSnapshot,
)
from media_runtime.scene.state.transition import (
    SceneStateTransition,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneProjection:
    version: str

    projection_id: str

    project_id: str
    video_session_id: str

    scene_id: str
    scene_code: str
    sequence_index: int

    asset_id: str

    canonical_revision_id: str
    canonical_hash: str

    identity_lock_id: str
    identity_lock_hash: str

    render_plan_revision: int
    render_plan_hash: str

    constraint_set_hash: str

    before_state: SceneStateSnapshot

    after_state: SceneStateSnapshot

    transition: (
        SceneStateTransition | None
    )

    camera: SceneCameraIntent

    motion: SceneMotionIntent

    visual_goal: str

    duration_seconds: float

    required_paths: tuple[
        str,
        ...,
    ]

    optional_paths: tuple[
        str,
        ...,
    ]

    excluded_paths: tuple[
        str,
        ...,
    ]

    previous_scene_artifact_id: (
        str | None
    )

    previous_scene_artifact_hash: (
        str | None
    )

    scene_projection_hash: str
```

---

# 17.13 Scene projector

Không LLM.

```python
# media_runtime/scene/projection/projector.py

from __future__ import annotations

import hashlib

from dataclasses import replace

from media_runtime.scene.contract.scene_projection import (
    SceneProjection,
)
from media_runtime.scene.exceptions import (
    SceneLineageError,
    SceneProjectionError,
)


class SceneProjector:
    VERSION = "scene-projection-v1"

    def __init__(
        self,
        *,
        graph_validator,
        serializer,
    ) -> None:
        self._graph_validator = (
            graph_validator
        )

        self._serializer = serializer

    def project(
        self,
        *,
        packet,
        identity_lock,
        asset_id: str,
    ) -> SceneProjection:
        self._graph_validator.validate(
            packet.state_graph
        )

        self._assert_lineage(
            packet=packet,
            identity_lock=(
                identity_lock
            ),
        )

        scene = packet.scene

        try:
            before_state = (
                packet.state_graph.state(
                    scene.from_state_id
                )
            )

            after_state = (
                packet.state_graph.state(
                    scene.to_state_id
                )
            )
        except KeyError as exc:
            raise SceneProjectionError(
                "Scene references unknown state."
            ) from exc

        transition = None

        if scene.transition_id:
            try:
                transition = (
                    packet
                    .state_graph
                    .transition(
                        scene.transition_id
                    )
                )
            except KeyError as exc:
                raise SceneProjectionError(
                    "Unknown scene transition."
                ) from exc

            if (
                transition.from_state_id
                != before_state.state_id
                or transition.to_state_id
                != after_state.state_id
            ):
                raise SceneProjectionError(
                    "Scene transition/state mismatch."
                )

        projection_id = (
            self._stable_id(
                packet,
                scene.scene_id,
            )
        )

        provisional = SceneProjection(
            version=self.VERSION,

            projection_id=(
                projection_id
            ),

            project_id=(
                packet.project_id
            ),

            video_session_id=(
                packet.video_session_id
            ),

            scene_id=scene.scene_id,

            scene_code=scene.scene_code,

            sequence_index=(
                scene.sequence_index
            ),

            asset_id=asset_id,

            canonical_revision_id=(
                packet
                .canonical_revision_id
            ),

            canonical_hash=(
                packet.canonical_hash
            ),

            identity_lock_id=(
                packet.identity_lock_id
            ),

            identity_lock_hash=(
                packet
                .identity_lock_hash
            ),

            render_plan_revision=(
                packet.render_plan_revision
            ),

            render_plan_hash=(
                packet.render_plan_hash
            ),

            constraint_set_hash=(
                packet.constraint_set_hash
            ),

            before_state=before_state,

            after_state=after_state,

            transition=transition,

            camera=scene.camera,

            motion=scene.motion,

            visual_goal=(
                scene.visual_goal
            ),

            duration_seconds=(
                scene.duration_seconds
            ),

            required_paths=(
                scene.required_paths
            ),

            optional_paths=(
                scene.optional_paths
            ),

            excluded_paths=(
                scene.excluded_paths
            ),

            previous_scene_artifact_id=(
                packet
                .previous_scene_artifact_id
            ),

            previous_scene_artifact_hash=(
                packet
                .previous_scene_artifact_hash
            ),

            scene_projection_hash="",
        )

        digest = hashlib.sha256(
            self._serializer.dumps(
                provisional,
                blank_hash=True,
            ).encode("utf-8")
        ).hexdigest()

        return replace(
            provisional,
            scene_projection_hash=digest,
        )

    @staticmethod
    def _assert_lineage(
        *,
        packet,
        identity_lock,
    ) -> None:
        if (
            packet.canonical_hash
            != identity_lock[
                "canonical_hash"
            ]
        ):
            raise SceneLineageError(
                "Canonical/IdentityLock mismatch."
            )

        if (
            packet.identity_lock_hash
            != identity_lock[
                "identity_lock_hash"
            ]
        ):
            raise SceneLineageError(
                "Identity lock hash mismatch."
            )

        if (
            packet
            .canonical_revision_id
            != identity_lock[
                "canonical_revision_id"
            ]
        ):
            raise SceneLineageError(
                "Canonical revision mismatch."
            )

    @staticmethod
    def _stable_id(
        packet,
        scene_id: str,
    ) -> str:
        raw = "|".join(
            (
                packet.render_plan_hash,
                packet.identity_lock_hash,
                scene_id,
            )
        )

        return (
            "SP-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )
```

---

# 17.14 Scene projection serializer

```python
# media_runtime/scene/projection/serializer.py

from __future__ import annotations

import json

from enum import Enum
from typing import Any


class SceneProjectionSerializer:
    VERSION = "scene-projection-json-v1"

    @classmethod
    def dumps(
        cls,
        value,
        *,
        blank_hash: bool = False,
    ) -> str:
        plain = cls._plain(
            value
        )

        if blank_hash:
            plain[
                "scene_projection_hash"
            ] = ""

        return json.dumps(
            plain,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )

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
                for name in (
                    value
                    .__dataclass_fields__
                )
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

---

# 17.15 Deterministic Reference Selection

Identity Lock có full reference pack nhưng scene không nên gửi cả pack.

V1 policy:

```text
priority 0 = approved anchor
priority 1 = camera-nearest reference
priority 2 = optional secondary geometry reference
priority 3 = previous approved scene image
             only for state continuity
```

Anchor luôn authoritative.

---

# 17.16 Camera role mapper

Dùng generic role Part16:

```python
# media_runtime/scene/references/camera_role_mapper.py

from __future__ import annotations


class CameraReferenceRoleMapper:
    VERSION = "camera-reference-role-map-v1"

    _VIEW_MAP = {
        "left_profile":
            "left_profile",

        "right_profile":
            "right_profile",

        "side_profile_left":
            "left_profile",

        "side_profile_right":
            "right_profile",

        "front":
            "front",

        "head_on_front":
            "front",

        "rear":
            "rear",

        "head_on_rear":
            "rear",

        "front_three_quarter":
            "front_three_quarter",

        "rear_three_quarter":
            "rear_three_quarter",

        "high_overview":
            "high_overview",
    }

    def role_for_camera(
        self,
        camera,
    ) -> str | None:
        if (
            camera
            .preferred_reference_role
        ):
            return (
                camera
                .preferred_reference_role
            )

        return self._VIEW_MAP.get(
            camera.view
        )
```

Không fuzzy-match bằng LLM.

---

# 17.17 Logical reference candidate

```python
# media_runtime/scene/references/candidate.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.enums import (
    SceneReferencePurpose,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneReferenceCandidate:
    artifact_id: str

    artifact_hash: str

    storage_key: str

    role: str

    purpose: SceneReferencePurpose

    priority: int

    source: str
```

---

# 17.18 SelectedReferenceSet

```python
# media_runtime/scene/references/selection.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.scene.references.candidate import (
    SceneReferenceCandidate,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SceneReferenceSelection:
    version: str

    scene_id: str

    identity_lock_id: str
    identity_lock_hash: str

    references: tuple[
        SceneReferenceCandidate,
        ...,
    ]

    def ordered(
        self,
    ) -> tuple[
        SceneReferenceCandidate,
        ...,
    ]:
        return tuple(
            sorted(
                self.references,
                key=lambda item: (
                    item.priority,
                    item.role,
                    item.artifact_id,
                ),
            )
        )
```

---

# 17.19 ReferenceSelector

```python
# media_runtime/scene/references/selector.py

from __future__ import annotations

from media_runtime.scene.enums import (
    ContinuityMode,
    SceneReferencePurpose,
)
from media_runtime.scene.exceptions import (
    SceneReferenceSelectionError,
)
from media_runtime.scene.references.candidate import (
    SceneReferenceCandidate,
)
from media_runtime.scene.references.selection import (
    SceneReferenceSelection,
)


class SceneReferenceSelector:
    VERSION = "scene-reference-selection-v1"

    def __init__(
        self,
        *,
        role_mapper,
        max_identity_references: int = 3,
    ) -> None:
        self._role_mapper = (
            role_mapper
        )

        self._max_identity_refs = (
            max_identity_references
        )

    def select(
        self,
        *,
        projection,
        identity_lock,
        continuity_artifact=None,
    ) -> SceneReferenceSelection:
        anchor = identity_lock[
            "anchor"
        ]

        result = [
            SceneReferenceCandidate(
                artifact_id=(
                    anchor["artifact_id"]
                ),

                artifact_hash=(
                    anchor["artifact_hash"]
                ),

                storage_key=(
                    anchor["storage_key"]
                ),

                role="anchor",

                purpose=(
                    SceneReferencePurpose
                    .IDENTITY
                ),

                priority=0,

                source="identity_lock",
            )
        ]

        preferred_role = (
            self._role_mapper
            .role_for_camera(
                projection.camera
            )
        )

        references = (
            identity_lock.get(
                "references",
                [],
            )
        )

        if preferred_role:
            exact = [
                item
                for item in references
                if (
                    item.get("role")
                    == preferred_role
                )
            ]

            if exact:
                item = sorted(
                    exact,
                    key=lambda value: (
                        int(
                            value.get(
                                "order",
                                999,
                            )
                        ),
                        value[
                            "artifact_id"
                        ],
                    ),
                )[0]

                result.append(
                    self._candidate(
                        item=item,
                        priority=1,
                        purpose=(
                            SceneReferencePurpose
                            .VIEW
                        ),
                    )
                )

        secondary = (
            self._secondary_role(
                preferred_role
            )
        )

        if secondary:
            matches = [
                item
                for item in references
                if item.get("role")
                == secondary
            ]

            if matches:
                item = sorted(
                    matches,
                    key=lambda value:
                        int(
                            value.get(
                                "order",
                                999,
                            )
                        ),
                )[0]

                if (
                    item["artifact_id"]
                    not in {
                        ref.artifact_id
                        for ref in result
                    }
                ):
                    result.append(
                        self._candidate(
                            item=item,

                            priority=2,

                            purpose=(
                                SceneReferencePurpose
                                .GEOMETRY
                            ),
                        )
                    )

        result = result[
            :
            self._max_identity_refs
        ]

        if (
            continuity_artifact
            is not None
            and projection
                .previous_scene_artifact_id
            is not None
        ):
            result.append(
                SceneReferenceCandidate(
                    artifact_id=(
                        continuity_artifact[
                            "artifact_id"
                        ]
                    ),

                    artifact_hash=(
                        continuity_artifact[
                            "artifact_hash"
                        ]
                    ),

                    storage_key=(
                        continuity_artifact[
                            "storage_key"
                        ]
                    ),

                    role=(
                        "previous_scene"
                    ),

                    purpose=(
                        SceneReferencePurpose
                        .STATE_CONTINUITY
                    ),

                    priority=10,

                    source=(
                        "previous_approved_scene"
                    ),
                )
            )

        ids = [
            item.artifact_id
            for item in result
        ]

        if len(ids) != len(
            set(ids)
        ):
            raise SceneReferenceSelectionError(
                "Duplicate selected reference."
            )

        return SceneReferenceSelection(
            version=self.VERSION,

            scene_id=(
                projection.scene_id
            ),

            identity_lock_id=(
                projection.identity_lock_id
            ),

            identity_lock_hash=(
                projection.identity_lock_hash
            ),

            references=tuple(
                result
            ),
        )

    @staticmethod
    def _candidate(
        *,
        item: dict,
        priority: int,
        purpose,
    ) -> SceneReferenceCandidate:
        return SceneReferenceCandidate(
            artifact_id=(
                item["artifact_id"]
            ),

            artifact_hash=(
                item["artifact_hash"]
            ),

            storage_key=(
                item["storage_key"]
            ),

            role=(
                item["role"]
            ),

            purpose=purpose,

            priority=priority,

            source="identity_lock",
        )

    @staticmethod
    def _secondary_role(
        role: str | None,
    ) -> str | None:
        mapping = {
            "left_profile":
                "front_three_quarter",

            "right_profile":
                "rear_three_quarter",

            "front":
                "front_three_quarter",

            "rear":
                "rear_three_quarter",

            "front_three_quarter":
                "left_profile",

            "rear_three_quarter":
                "right_profile",

            "high_overview":
                "front_three_quarter",
        }

        return mapping.get(
            role
        )
```

`_secondary_role()` là **versioned deterministic policy**, không design inference.

Profile sau này có thể override map này.

---

# 17.20 State continuity reference không được override identity

Đây là rule bắt buộc:

```text
anchor                  priority 0
identity reference      priority 1–2
previous scene          priority 10
```

Previous scene giúp:

```text
scaffolding location
construction progress
weather/factory continuity
temporary visible state
```

nhưng không trở thành geometry truth.

---

# 17.21 Scene state overlay

Canonical có final object truth.

Scene state có temporary visible state.

Ví dụ:

```text
Canonical:
finished_materials.hull = painted

Scene 03:
construction.hull.finish = bare_plating
```

Không mutate Canonical.

```python
# media_runtime/scene/constraints/overlay.py

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateOverlayEntry:
    path: str

    value: Any

    source_state_id: str


@dataclass(
    frozen=True,
    slots=True,
)
class SceneStateOverlay:
    version: str

    scene_id: str

    entries: tuple[
        SceneStateOverlayEntry,
        ...,
    ]
```

---

# 17.22 Build state overlay

Scene image thường đại diện **state đầu scene hoặc state mục tiêu** tùy creative plan.

Ta phải explicit.

Thêm vào `FrozenScenePlan`:

```python
image_state_role: str
```

Allowed:

```text
before
after
```

Không đoán.

Builder:

```python
class SceneStateOverlayBuilder:
    VERSION = "scene-state-overlay-v1"

    def build(
        self,
        projection,
        image_state_role: str,
    ) -> SceneStateOverlay:
        if image_state_role == "before":
            state = (
                projection.before_state
            )

        elif image_state_role == "after":
            state = (
                projection.after_state
            )

        else:
            raise ValueError(
                "image_state_role must "
                "be before or after"
            )

        return SceneStateOverlay(
            version=self.VERSION,

            scene_id=(
                projection.scene_id
            ),

            entries=tuple(
                SceneStateOverlayEntry(
                    path=fact.path,

                    value=fact.value,

                    source_state_id=(
                        state.state_id
                    ),
                )
                for fact in state.facts
            ),
        )
```

---

# 17.23 SceneConstraintComposer

Không sửa frozen base constraints.

Tạo derived scene constraint set.

```python
# media_runtime/scene/constraints/composer.py

from __future__ import annotations

from dataclasses import replace

from media_runtime.constraints.constraint import (
    Constraint,
)
from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)


class SceneConstraintComposer:
    VERSION = "scene-constraint-compose-v1"

    def compose(
        self,
        *,
        base: ConstraintSet,
        projection,
        overlay,
    ) -> ConstraintSet:
        constraints = list(
            base.constraints
        )

        for entry in (
            overlay.entries
        ):
            constraints.append(
                Constraint(
                    id=(
                        self._state_constraint_id(
                            projection
                            .scene_projection_hash,

                            entry.path,
                        )
                    ),

                    semantic_key=(
                        "scene_state."
                        + entry.path
                    ),

                    primitive=(
                        self._state_primitive()
                    ),

                    priority=(
                        self._state_priority()
                    ),

                    severity=(
                        self._hard_severity()
                    ),

                    value=entry.value,

                    source_path=(
                        entry.path
                    ),

                    subject_path=(
                        entry.path
                    ),

                    source_kind=(
                        self._execution_state_source()
                    ),

                    visual_verification=True,
                )
            )

        return ConstraintSet(
            version=(
                "scene-constraint-set-v1"
            ),

            asset_id=(
                projection.scene_id
            ),

            source_revision_id=(
                projection
                .canonical_revision_id
            ),

            source_canonical_hash=(
                projection.canonical_hash
            ),

            constraints=tuple(
                constraints
            ),
        )

    @staticmethod
    def _state_constraint_id(
        projection_hash: str,
        path: str,
    ) -> str:
        import hashlib

        return (
            "SC-"
            + hashlib.sha256(
                (
                    projection_hash
                    + "|"
                    + path
                ).encode("utf-8")
            ).hexdigest()[:16]
        )

    @staticmethod
    def _state_primitive():
        from media_runtime.constraints.enums import (
            ConstraintPrimitive,
        )

        return (
            ConstraintPrimitive.STATE
        )

    @staticmethod
    def _state_priority():
        from media_runtime.constraints.enums import (
            ConstraintPriority,
        )

        return (
            ConstraintPriority.P6_STATE
        )

    @staticmethod
    def _hard_severity():
        from media_runtime.constraints.enums import (
            ConstraintSeverity,
        )

        return (
            ConstraintSeverity.HARD
        )

    @staticmethod
    def _execution_state_source():
        from media_runtime.constraints.enums import (
            ConstraintSourceKind,
        )

        return (
            ConstraintSourceKind
            .EXECUTION_STATE
        )
```

Nếu enum hiện tại của project đặt tên hơi khác, giữ enum hiện có; **không tạo enum thứ hai chỉ vì snippet này**.

---

# 17.24 Scene Image Prompt flow

Không tạo `ScenePromptWriter` dùng LLM.

Flow vẫn:

```text
SceneConstraintSet
↓
ConstraintCoalescer
↓
PromptCompilerV1
↓
PromptSpec
↓
ProviderCapabilityProjection
↓
CompiledPrompt
```

Chỉ presentation context được thêm deterministic:

```text
visual_goal
camera
scene state
```

---

# 17.25 Scene Image RenderRequest Builder

```python
# media_runtime/scene/image/request_builder.py

from __future__ import annotations

import uuid

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


class SceneImageRenderRequestBuilder:
    VERSION = "scene-image-request-v1"

    def __init__(
        self,
        *,
        artifact_resolver,
    ) -> None:
        self._resolver = (
            artifact_resolver
        )

    def build(
        self,
        *,
        projection,
        compiled_prompt,
        reference_selection,
        provider_key: str,
        model_key: str,
        run_id: str,
        session_code: str,
        width: int | None,
        height: int | None,
        aspect_ratio: str | None,
        quality,
        output_format,
        provider_options: dict,
        prompt_spec_hash: str,
        provider_prompt_plan_hash: str,
        scene_constraint_set_hash: str,
    ) -> RenderRequest:
        references = []

        for selected in (
            reference_selection.ordered()
        ):
            artifact = (
                self._resolver.resolve(
                    selected.storage_key
                )
            )

            if (
                artifact.sha256
                != selected.artifact_hash
            ):
                raise RuntimeError(
                    "Resolved scene reference "
                    "hash mismatch."
                )

            role = self._map_role(
                selected.purpose
            )

            references.append(
                ReferenceAsset(
                    artifact_id=(
                        selected.artifact_id
                    ),

                    path=artifact.path,

                    sha256=(
                        selected.artifact_hash
                    ),

                    mime_type=(
                        artifact.mime_type
                    ),

                    role=role,

                    priority=(
                        selected.priority
                    ),

                    source_revision_id=(
                        projection
                        .canonical_revision_id
                    ),

                    source_canonical_hash=(
                        projection
                        .canonical_hash
                    ),

                    width=artifact.width,

                    height=artifact.height,
                )
            )

        reference_set = (
            ReferenceSet(
                version=(
                    "reference-set-v1"
                ),

                asset_id=(
                    projection.scene_id
                ),

                source_revision_id=(
                    projection
                    .canonical_revision_id
                ),

                source_canonical_hash=(
                    projection.canonical_hash
                ),

                references=tuple(
                    references
                ),
            )
        )

        operation = (
            RenderOperation.EDIT
            if references
            else RenderOperation.GENERATE
        )

        return RenderRequest(
            version="render-request-v1",

            render_id=str(
                uuid.uuid4()
            ),

            run_id=run_id,

            session_code=(
                session_code
            ),

            asset_id=(
                projection.scene_id
            ),

            media_type=(
                self._image_media_type()
            ),

            operation=operation,

            provider_key=provider_key,

            model_key=model_key,

            source_revision_id=(
                projection
                .canonical_revision_id
            ),

            source_canonical_hash=(
                projection.canonical_hash
            ),

            projection_hash=(
                projection
                .scene_projection_hash
            ),

            constraint_set_hash=(
                scene_constraint_set_hash
            ),

            prompt_spec_hash=(
                prompt_spec_hash
            ),

            provider_prompt_plan_hash=(
                provider_prompt_plan_hash
            ),

            compiled_prompt=(
                compiled_prompt
            ),

            references=(
                reference_set
            ),

            width=width,
            height=height,

            aspect_ratio=(
                aspect_ratio
            ),

            quality=quality,

            output_format=(
                output_format
            ),

            provider_options=dict(
                provider_options
            ),

            idempotency_key=(
                "scene-image:"
                + projection
                .video_session_id
                + ":"
                + projection.scene_id
                + ":"
                + projection
                .scene_projection_hash[
                    :16
                ]
            ),

            parent_render_id=None,
            parent_request_hash=None,
            repair_id=None,
            qa_report_hash=None,
            repair_generation=0,
        )

    @staticmethod
    def _image_media_type():
        from media_runtime.render.enums import (
            RenderMediaType,
        )

        return RenderMediaType.IMAGE

    @staticmethod
    def _map_role(
        purpose,
    ):
        from media_runtime.scene.enums import (
            SceneReferencePurpose,
        )

        if (
            purpose
            == SceneReferencePurpose.IDENTITY
        ):
            return ReferenceRole.IDENTITY

        if (
            purpose
            == SceneReferencePurpose.VIEW
        ):
            return ReferenceRole.VIEW

        if (
            purpose
            == SceneReferencePurpose.GEOMETRY
        ):
            return ReferenceRole.GEOMETRY

        return ReferenceRole.STATE
```

---

# 17.26 Scene Image QA

Không thêm QA mới.

Sau Part14:

```text
scene image SUCCEEDED
↓
Part15 VisionQaService
```

Part15 targets lúc này dùng:

```text
identity P0–P5
+
scene state constraints P6
+
camera/visibility constraints nếu visual_verification
```

Nếu PASS:

```text
SceneStatus = IMAGE_APPROVED
```

Sau đó mới được dùng làm video keyframe.

---

# 17.27 Video Keyframe

Keyframe không phải hình mới nếu không cần.

Ảnh scene QA-approved chính là start keyframe.

```python
# media_runtime/keyframe/enums.py

from __future__ import annotations

from enum import StrEnum


class KeyframeRole(StrEnum):
    START = "start"
    END = "end"


class KeyframeSource(StrEnum):
    APPROVED_SCENE_IMAGE = (
        "approved_scene_image"
    )

    APPROVED_GENERATED_END_FRAME = (
        "approved_generated_end_frame"
    )
```

---

# 17.28 Keyframe DTO

```python
# media_runtime/keyframe/keyframe.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.keyframe.enums import (
    KeyframeRole,
    KeyframeSource,
)


@dataclass(
    frozen=True,
    slots=True,
)
class VideoKeyframe:
    keyframe_id: str

    scene_id: str

    role: KeyframeRole

    source: KeyframeSource

    artifact_id: str
    artifact_hash: str
    storage_key: str

    width: int | None
    height: int | None
    mime_type: str

    render_id: str
    request_hash: str

    qa_run_id: str
    qa_report_hash: str

    canonical_hash: str

    identity_lock_id: str
    identity_lock_hash: str

    scene_projection_hash: str
```

---

# 17.29 Keyframe set

```python
# media_runtime/keyframe/set.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.keyframe.keyframe import (
    VideoKeyframe,
)


@dataclass(
    frozen=True,
    slots=True,
)
class VideoKeyframeSet:
    version: str

    scene_id: str

    start: VideoKeyframe

    end: VideoKeyframe | None
```

---

# 17.30 Keyframe builder

```python
# media_runtime/keyframe/builder.py

from __future__ import annotations

import hashlib

from media_runtime.keyframe.enums import (
    KeyframeRole,
    KeyframeSource,
)
from media_runtime.keyframe.keyframe import (
    VideoKeyframe,
)
from media_runtime.keyframe.set import (
    VideoKeyframeSet,
)


class VideoKeyframeBuilder:
    VERSION = "video-keyframe-set-v1"

    def from_scene_image(
        self,
        *,
        projection,
        scene_render,
        scene_artifact,
        qa_report,
    ) -> VideoKeyframeSet:
        if (
            qa_report.decision.value
            != "pass"
        ):
            raise ValueError(
                "Video keyframe requires "
                "QA-approved scene image."
            )

        if (
            qa_report.artifact_hash
            != scene_artifact[
                "sha256"
            ]
        ):
            raise ValueError(
                "Scene QA artifact mismatch."
            )

        keyframe = VideoKeyframe(
            keyframe_id=(
                self._id(
                    projection.scene_id,
                    scene_artifact[
                        "sha256"
                    ],
                    "start",
                )
            ),

            scene_id=(
                projection.scene_id
            ),

            role=KeyframeRole.START,

            source=(
                KeyframeSource
                .APPROVED_SCENE_IMAGE
            ),

            artifact_id=(
                scene_artifact[
                    "artifact_id"
                ]
            ),

            artifact_hash=(
                scene_artifact[
                    "sha256"
                ]
            ),

            storage_key=(
                scene_artifact[
                    "storage_key"
                ]
            ),

            width=(
                scene_artifact.get(
                    "width"
                )
            ),

            height=(
                scene_artifact.get(
                    "height"
                )
            ),

            mime_type=(
                scene_artifact[
                    "mime_type"
                ]
            ),

            render_id=(
                scene_render["id"]
            ),

            request_hash=(
                scene_render[
                    "request_hash"
                ]
            ),

            qa_run_id=(
                qa_report.qa_run_id
            ),

            qa_report_hash=(
                qa_report.qa_report_hash
            ),

            canonical_hash=(
                projection.canonical_hash
            ),

            identity_lock_id=(
                projection.identity_lock_id
            ),

            identity_lock_hash=(
                projection.identity_lock_hash
            ),

            scene_projection_hash=(
                projection
                .scene_projection_hash
            ),
        )

        return VideoKeyframeSet(
            version=self.VERSION,

            scene_id=(
                projection.scene_id
            ),

            start=keyframe,

            end=None,
        )

    @staticmethod
    def _id(
        scene_id: str,
        artifact_hash: str,
        role: str,
    ) -> str:
        return (
            "KF-"
            + hashlib.sha256(
                (
                    scene_id
                    + "|"
                    + artifact_hash
                    + "|"
                    + role
                ).encode("utf-8")
            ).hexdigest()[:16]
        )
```

---

# 17.31 First/Last Frame

Core support optional `end`.

Nhưng không ép mọi provider dùng end frame.

```text
Veo/Kling capability says:
supports_last_frame = true
→ use start + end

false
→ use start only
```

End frame nếu cần phải là artifact **QA-approved riêng**.

Không synthesize hidden end frame trong adapter.

---

# 17.32 Motion enums

```python
# media_runtime/motion/enums.py

from __future__ import annotations

from enum import StrEnum


class CameraMotionType(StrEnum):
    STATIC = "static"
    PAN_LEFT = "pan_left"
    PAN_RIGHT = "pan_right"
    TILT_UP = "tilt_up"
    TILT_DOWN = "tilt_down"
    DOLLY_IN = "dolly_in"
    DOLLY_OUT = "dolly_out"
    TRUCK_LEFT = "truck_left"
    TRUCK_RIGHT = "truck_right"
    CRANE_UP = "crane_up"
    CRANE_DOWN = "crane_down"
    ORBIT_LEFT = "orbit_left"
    ORBIT_RIGHT = "orbit_right"


class MotionIntensity(StrEnum):
    MINIMAL = "minimal"
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"


class MotionContinuityRule(StrEnum):
    PRESERVE_IDENTITY = "preserve_identity"
    PRESERVE_COUNTS = "preserve_counts"
    PRESERVE_TOPOLOGY = "preserve_topology"
    PRESERVE_PROPORTIONS = "preserve_proportions"
    PRESERVE_OPENINGS = "preserve_openings"
    PRESERVE_STATE_EXCEPT_DECLARED = (
        "preserve_state_except_declared"
    )


class TemporalMutationMode(StrEnum):
    NONE = "none"
    TRANSITION = "transition"
```

---

# 17.33 CameraMotionSpec

```python
# media_runtime/motion/camera.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.motion.enums import (
    CameraMotionType,
    MotionIntensity,
)


@dataclass(
    frozen=True,
    slots=True,
)
class CameraMotionSpec:
    type: CameraMotionType

    intensity: MotionIntensity

    maintain_subject_in_frame: bool

    maintain_horizon: bool

    notes: str | None = None
```

---

# 17.34 SubjectMotionSpec

```python
# media_runtime/motion/subject.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.motion.enums import (
    MotionIntensity,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SubjectMotionSpec:
    action: str | None

    intensity: MotionIntensity

    allowed_changed_paths: tuple[
        str,
        ...,
    ]

    prohibited_changed_paths: tuple[
        str,
        ...,
    ]
```

---

# 17.35 EnvironmentMotionSpec

```python
# media_runtime/motion/environment.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.motion.enums import (
    MotionIntensity,
)


@dataclass(
    frozen=True,
    slots=True,
)
class EnvironmentMotionSpec:
    description: str | None

    intensity: MotionIntensity

    must_not_occlude_identity: bool = True
```

---

# 17.36 TemporalTransitionSpec

```python
# media_runtime/motion/transition.py

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.motion.enums import (
    TemporalMutationMode,
)


@dataclass(
    frozen=True,
    slots=True,
)
class TemporalMutation:
    path: str
    before: Any
    after: Any


@dataclass(
    frozen=True,
    slots=True,
)
class TemporalTransitionSpec:
    mode: TemporalMutationMode

    from_state_id: str

    to_state_id: str

    mutations: tuple[
        TemporalMutation,
        ...,
    ]
```

---

# 17.37 MotionSpec

Đây là semantic motion IR.

Không provider prompt.

```python
# media_runtime/motion/spec.py

from __future__ import annotations

from dataclasses import dataclass

from media_runtime.motion.camera import (
    CameraMotionSpec,
)
from media_runtime.motion.environment import (
    EnvironmentMotionSpec,
)
from media_runtime.motion.enums import (
    MotionContinuityRule,
)
from media_runtime.motion.subject import (
    SubjectMotionSpec,
)
from media_runtime.motion.transition import (
    TemporalTransitionSpec,
)


@dataclass(
    frozen=True,
    slots=True,
)
class MotionSpec:
    version: str

    motion_spec_id: str

    scene_id: str

    duration_seconds: float

    canonical_hash: str

    identity_lock_id: str
    identity_lock_hash: str

    scene_projection_hash: str

    start_keyframe_hash: str
    end_keyframe_hash: str | None

    camera: CameraMotionSpec

    subject: SubjectMotionSpec

    environment: EnvironmentMotionSpec

    temporal_transition: (
        TemporalTransitionSpec
    )

    continuity_rules: tuple[
        MotionContinuityRule,
        ...,
    ]

    prohibited_identity_constraint_ids: tuple[
        str,
        ...,
    ]

    motion_spec_hash: str
```

---

# 17.38 MotionSpecCompiler

Không invent action.

Dùng frozen `SceneMotionIntent`.

```python
# media_runtime/motion/compiler.py

from __future__ import annotations

import hashlib

from dataclasses import replace

from media_runtime.motion.camera import (
    CameraMotionSpec,
)
from media_runtime.motion.environment import (
    EnvironmentMotionSpec,
)
from media_runtime.motion.enums import (
    CameraMotionType,
    MotionContinuityRule,
    MotionIntensity,
    TemporalMutationMode,
)
from media_runtime.motion.spec import (
    MotionSpec,
)
from media_runtime.motion.subject import (
    SubjectMotionSpec,
)
from media_runtime.motion.transition import (
    TemporalMutation,
    TemporalTransitionSpec,
)


class MotionSpecCompiler:
    VERSION = "motion-spec-v1"

    def __init__(
        self,
        *,
        serializer,
    ) -> None:
        self._serializer = serializer

    def compile(
        self,
        *,
        projection,
        keyframes,
        identity_constraints,
    ) -> MotionSpec:
        start = keyframes.start
        end = keyframes.end

        self._assert_lineage(
            projection,
            start,
            end,
        )

        transition = (
            projection.transition
        )

        mutations = ()

        if transition is not None:
            mutations = tuple(
                TemporalMutation(
                    path=item.path,
                    before=item.before,
                    after=item.after,
                )
                for item
                in transition.mutations
                if item.visual
            )

        allowed_paths = tuple(
            item.path
            for item in mutations
        )

        identity_paths = tuple(
            sorted({
                item.semantic_key
                for item
                in identity_constraints
            })
        )

        camera = CameraMotionSpec(
            type=self._camera_motion(
                projection.motion
                .camera_motion
            ),

            intensity=self._intensity(
                projection.motion
                .intensity
            ),

            maintain_subject_in_frame=True,

            maintain_horizon=True,
        )

        subject = SubjectMotionSpec(
            action=(
                projection.motion
                .subject_motion
            ),

            intensity=self._intensity(
                projection.motion
                .intensity
            ),

            allowed_changed_paths=(
                allowed_paths
            ),

            prohibited_changed_paths=(
                identity_paths
            ),
        )

        environment = (
            EnvironmentMotionSpec(
                description=(
                    projection.motion
                    .environment_motion
                ),

                intensity=self._intensity(
                    projection.motion
                    .intensity
                ),

                must_not_occlude_identity=True,
            )
        )

        temporal = (
            TemporalTransitionSpec(
                mode=(
                    TemporalMutationMode
                    .TRANSITION
                    if mutations
                    else TemporalMutationMode.NONE
                ),

                from_state_id=(
                    projection
                    .before_state
                    .state_id
                ),

                to_state_id=(
                    projection
                    .after_state
                    .state_id
                ),

                mutations=mutations,
            )
        )

        continuity = (
            MotionContinuityRule
            .PRESERVE_IDENTITY,

            MotionContinuityRule
            .PRESERVE_COUNTS,

            MotionContinuityRule
            .PRESERVE_TOPOLOGY,

            MotionContinuityRule
            .PRESERVE_PROPORTIONS,

            MotionContinuityRule
            .PRESERVE_OPENINGS,

            MotionContinuityRule
            .PRESERVE_STATE_EXCEPT_DECLARED,
        )

        provisional = MotionSpec(
            version=self.VERSION,

            motion_spec_id=(
                self._id(
                    projection,
                    start,
                )
            ),

            scene_id=(
                projection.scene_id
            ),

            duration_seconds=(
                projection
                .duration_seconds
            ),

            canonical_hash=(
                projection.canonical_hash
            ),

            identity_lock_id=(
                projection.identity_lock_id
            ),

            identity_lock_hash=(
                projection.identity_lock_hash
            ),

            scene_projection_hash=(
                projection
                .scene_projection_hash
            ),

            start_keyframe_hash=(
                start.artifact_hash
            ),

            end_keyframe_hash=(
                end.artifact_hash
                if end
                else None
            ),

            camera=camera,

            subject=subject,

            environment=environment,

            temporal_transition=(
                temporal
            ),

            continuity_rules=(
                continuity
            ),

            prohibited_identity_constraint_ids=tuple(
                sorted(
                    item.constraint_id
                    for item
                    in identity_constraints
                )
            ),

            motion_spec_hash="",
        )

        digest = hashlib.sha256(
            self._serializer.dumps(
                provisional,
                blank_hash=True,
            ).encode("utf-8")
        ).hexdigest()

        return replace(
            provisional,
            motion_spec_hash=digest,
        )

    @staticmethod
    def _assert_lineage(
        projection,
        start,
        end,
    ) -> None:
        if (
            start.identity_lock_hash
            != projection
            .identity_lock_hash
        ):
            raise ValueError(
                "Keyframe/IdentityLock mismatch."
            )

        if (
            start.scene_projection_hash
            != projection
            .scene_projection_hash
        ):
            raise ValueError(
                "Keyframe/SceneProjection mismatch."
            )

        if (
            end is not None
            and (
                end.identity_lock_hash
                != start.identity_lock_hash
            )
        ):
            raise ValueError(
                "Start/end identity mismatch."
            )

    @staticmethod
    def _camera_motion(
        raw: str,
    ) -> CameraMotionType:
        try:
            return CameraMotionType(
                raw
            )
        except ValueError as exc:
            raise ValueError(
                "Unsupported camera motion: "
                + raw
            ) from exc

    @staticmethod
    def _intensity(
        value: float,
    ) -> MotionIntensity:
        if value <= 0.15:
            return MotionIntensity.MINIMAL

        if value <= 0.40:
            return MotionIntensity.LOW

        if value <= 0.75:
            return MotionIntensity.MEDIUM

        return MotionIntensity.HIGH

    @staticmethod
    def _id(
        projection,
        start,
    ) -> str:
        raw = "|".join(
            (
                projection
                .scene_projection_hash,

                start.artifact_hash,
            )
        )

        return (
            "MS-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )
```

Intensity mapping là deterministic execution mapping, không design decision.

---

# 17.39 Motion serializer

```python
# media_runtime/motion/serializer.py

from __future__ import annotations

import json

from enum import Enum
from typing import Any


class MotionSpecSerializer:
    VERSION = "motion-spec-json-v1"

    @classmethod
    def dumps(
        cls,
        value,
        *,
        blank_hash: bool = False,
    ) -> str:
        plain = cls._plain(
            value
        )

        if blank_hash:
            plain[
                "motion_spec_hash"
            ] = ""

        return json.dumps(
            plain,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        )

    @classmethod
    def _plain(
        cls,
        value: Any,
    ):
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
                for name in (
                    value
                    .__dataclass_fields__
                )
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

---

# 17.40 MotionPromptCompiler

Provider không được tự diễn giải JSON MotionSpec.

Python compile deterministic prompt.

```python
# media_runtime/motion/prompt_compiler.py

from __future__ import annotations

import hashlib

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class CompiledMotionPrompt:
    version: str

    motion_spec_hash: str

    prompt: str

    negative_prompt: str | None

    prompt_hash: str

    negative_prompt_hash: (
        str | None
    )


class MotionPromptCompiler:
    VERSION = "motion-prompt-v1"

    def compile(
        self,
        spec,
    ) -> CompiledMotionPrompt:
        lines = [
            "Animate the supplied approved keyframe.",

            (
                "The physical subject identity must "
                "remain unchanged throughout the clip."
            ),

            (
                "Duration: "
                f"{spec.duration_seconds:g} seconds."
            ),

            "",
            "CAMERA MOTION",

            (
                "Camera motion: "
                + spec.camera.type.value
                + "."
            ),

            (
                "Motion intensity: "
                + spec.camera.intensity.value
                + "."
            ),
        ]

        if (
            spec.camera
            .maintain_subject_in_frame
        ):
            lines.append(
                "Keep the complete required subject "
                "framing stable and readable."
            )

        if spec.camera.maintain_horizon:
            lines.append(
                "Maintain a physically stable horizon."
            )

        if spec.subject.action:
            lines.extend([
                "",
                "SUBJECT MOTION",

                (
                    "Subject action: "
                    + spec.subject.action
                    + "."
                ),
            ])

        if spec.environment.description:
            lines.extend([
                "",
                "ENVIRONMENT MOTION",

                spec.environment.description
                + ".",
            ])

        if (
            spec
            .temporal_transition
            .mutations
        ):
            lines.extend([
                "",
                "DECLARED STATE TRANSITION",
            ])

            for mutation in (
                spec
                .temporal_transition
                .mutations
            ):
                lines.append(
                    (
                        mutation.path
                        + ": "
                        + repr(
                            mutation.before
                        )
                        + " -> "
                        + repr(
                            mutation.after
                        )
                        + "."
                    )
                )

        lines.extend([
            "",
            "IDENTITY PRESERVATION",

            (
                "Do not change permanent counts, "
                "major proportions, topology, "
                "silhouette-defining geometry or "
                "permanent openings."
            ),

            (
                "Only declared temporal state "
                "mutations are allowed."
            ),

            (
                "Do not introduce new permanent "
                "features."
            ),

            (
                "Do not remove established permanent "
                "features."
            ),

            (
                "Do not morph, stretch, bend or "
                "re-proportion the subject."
            ),
        ])

        prompt = "\n".join(
            lines
        ).strip()

        negative = (
            "identity drift; geometry morphing; "
            "count changes; topology changes; "
            "proportion changes; duplicated permanent "
            "features; disappearing permanent features; "
            "unintended camera jump; subject deformation"
        )

        return CompiledMotionPrompt(
            version=self.VERSION,

            motion_spec_hash=(
                spec.motion_spec_hash
            ),

            prompt=prompt,

            negative_prompt=negative,

            prompt_hash=hashlib.sha256(
                prompt.encode("utf-8")
            ).hexdigest(),

            negative_prompt_hash=(
                hashlib.sha256(
                    negative.encode(
                        "utf-8"
                    )
                ).hexdigest()
            ),
        )
```

---

# 17.41 Video provider capabilities

Veo/Kling không được hard-code vào MotionSpec.

```python
# media_runtime/video/capabilities/profile.py

from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class VideoProviderCapabilityProfile:
    provider_key: str

    model_key: str

    version: str

    supports_image_to_video: bool

    supports_first_last_frame: bool

    supports_negative_prompt: bool

    supports_native_camera_motion: bool

    supports_seed: bool

    allowed_durations_seconds: tuple[
        float,
        ...,
    ]

    maximum_reference_images: int
```

---

# 17.42 Capability registry

```python
# media_runtime/video/capabilities/registry.py

from __future__ import annotations


class VideoCapabilityRegistry:
    def __init__(
        self,
        profiles,
    ) -> None:
        self._profiles = {
            (
                item.provider_key,
                item.model_key,
            ):
                item
            for item in profiles
        }

    def get(
        self,
        provider_key: str,
        model_key: str,
    ):
        try:
            return self._profiles[
                (
                    provider_key,
                    model_key,
                )
            ]
        except KeyError as exc:
            raise KeyError(
                "No video capability profile "
                f"for {provider_key}/{model_key}"
            ) from exc
```

Models/durations belong config, not universal code.

---

# 17.43 Video Provider Plan

```python
# media_runtime/video/capabilities/projector.py

from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class VideoProviderPlan:
    version: str

    provider_key: str
    model_key: str

    duration_seconds: float

    use_start_frame: bool
    use_end_frame: bool

    prompt: str

    negative_prompt: str | None

    native_controls: dict


class VideoCapabilityProjector:
    VERSION = "video-provider-plan-v1"

    def project(
        self,
        *,
        capability,
        motion_spec,
        compiled_motion_prompt,
        keyframes,
    ) -> VideoProviderPlan:
        if not (
            capability
            .supports_image_to_video
        ):
            raise RuntimeError(
                "Provider does not support "
                "image-to-video."
            )

        duration = (
            self._duration(
                motion_spec
                .duration_seconds,

                capability
                .allowed_durations_seconds,
            )
        )

        use_end = (
            keyframes.end is not None
            and capability
            .supports_first_last_frame
        )

        negative = (
            compiled_motion_prompt
            .negative_prompt
            if capability
            .supports_negative_prompt
            else None
        )

        prompt = (
            compiled_motion_prompt.prompt
        )

        if (
            compiled_motion_prompt
            .negative_prompt
            and not capability
            .supports_negative_prompt
        ):
            prompt += (
                "\n\nPROHIBITED CHANGES\n"
                + compiled_motion_prompt
                .negative_prompt
            )

        return VideoProviderPlan(
            version=self.VERSION,

            provider_key=(
                capability.provider_key
            ),

            model_key=(
                capability.model_key
            ),

            duration_seconds=(
                duration
            ),

            use_start_frame=True,

            use_end_frame=(
                use_end
            ),

            prompt=prompt,

            negative_prompt=(
                negative
            ),

            native_controls={},
        )

    @staticmethod
    def _duration(
        requested: float,
        allowed: tuple[
            float,
            ...,
        ],
    ) -> float:
        if not allowed:
            return requested

        if requested in allowed:
            return requested

        raise RuntimeError(
            "Requested duration "
            f"{requested} unsupported by "
            "selected provider/model."
        )
```

Không silently round 6s thành 5s. Nếu provider không hỗ trợ frozen duration:

```text
FAIL capability projection
```

hoặc Laravel tạo RenderPlan revision mới.

---

# 17.44 VideoRenderRequest

```python
# media_runtime/video/request.py

from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class VideoRenderRequest:
    version: str

    render_id: str

    project_id: str
    video_session_id: str

    scene_id: str
    scene_code: str

    provider_key: str
    model_key: str

    canonical_revision_id: str
    canonical_hash: str

    identity_lock_id: str
    identity_lock_hash: str

    render_plan_hash: str

    scene_projection_hash: str

    constraint_set_hash: str

    start_keyframe_id: str
    start_keyframe_hash: str
    start_keyframe_storage_key: str

    end_keyframe_id: str | None
    end_keyframe_hash: str | None
    end_keyframe_storage_key: (
        str | None
    )

    motion_spec_hash: str

    motion_prompt_hash: str

    prompt: str

    negative_prompt: str | None

    duration_seconds: float

    aspect_ratio: str

    resolution: str

    native_controls: dict

    provider_options: dict

    idempotency_key: str

    video_request_hash: str
```

---

# 17.45 Video request hasher

Hash exact video execution semantics.

```python
# media_runtime/video/request_hash.py

from __future__ import annotations

import hashlib
import json

from dataclasses import replace
from enum import Enum
from typing import Any


class VideoRenderRequestHasher:
    VERSION = "video-render-request-hash-v1"

    def hash(
        self,
        request,
    ) -> str:
        payload = self._plain(
            replace(
                request,
                video_request_hash="",
            )
        )

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
    ):
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
                for name in (
                    value
                    .__dataclass_fields__
                )
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

---

# 17.46 Video request builder

```python
from __future__ import annotations

import uuid

from dataclasses import replace

from media_runtime.video.request import (
    VideoRenderRequest,
)


class VideoRenderRequestBuilder:
    VERSION = "video-render-request-v1"

    def __init__(
        self,
        *,
        hasher,
    ) -> None:
        self._hasher = hasher

    def build(
        self,
        *,
        projection,
        keyframes,
        motion_spec,
        provider_plan,
        constraint_set_hash: str,
        aspect_ratio: str,
        resolution: str,
        provider_options: dict,
    ) -> VideoRenderRequest:
        start = keyframes.start
        end = keyframes.end

        provisional = (
            VideoRenderRequest(
                version=self.VERSION,

                render_id=str(
                    uuid.uuid4()
                ),

                project_id=(
                    projection.project_id
                ),

                video_session_id=(
                    projection
                    .video_session_id
                ),

                scene_id=(
                    projection.scene_id
                ),

                scene_code=(
                    projection.scene_code
                ),

                provider_key=(
                    provider_plan
                    .provider_key
                ),

                model_key=(
                    provider_plan.model_key
                ),

                canonical_revision_id=(
                    projection
                    .canonical_revision_id
                ),

                canonical_hash=(
                    projection.canonical_hash
                ),

                identity_lock_id=(
                    projection.identity_lock_id
                ),

                identity_lock_hash=(
                    projection.identity_lock_hash
                ),

                render_plan_hash=(
                    projection.render_plan_hash
                ),

                scene_projection_hash=(
                    projection
                    .scene_projection_hash
                ),

                constraint_set_hash=(
                    constraint_set_hash
                ),

                start_keyframe_id=(
                    start.keyframe_id
                ),

                start_keyframe_hash=(
                    start.artifact_hash
                ),

                start_keyframe_storage_key=(
                    start.storage_key
                ),

                end_keyframe_id=(
                    end.keyframe_id
                    if end
                    else None
                ),

                end_keyframe_hash=(
                    end.artifact_hash
                    if end
                    else None
                ),

                end_keyframe_storage_key=(
                    end.storage_key
                    if end
                    else None
                ),

                motion_spec_hash=(
                    motion_spec
                    .motion_spec_hash
                ),

                motion_prompt_hash=(
                    self._prompt_hash(
                        provider_plan.prompt
                    )
                ),

                prompt=(
                    provider_plan.prompt
                ),

                negative_prompt=(
                    provider_plan
                    .negative_prompt
                ),

                duration_seconds=(
                    provider_plan
                    .duration_seconds
                ),

                aspect_ratio=(
                    aspect_ratio
                ),

                resolution=(
                    resolution
                ),

                native_controls=dict(
                    provider_plan
                    .native_controls
                ),

                provider_options=dict(
                    provider_options
                ),

                idempotency_key=(
                    "video-shot:"
                    + projection
                    .video_session_id
                    + ":"
                    + projection.scene_id
                    + ":"
                    + motion_spec
                    .motion_spec_hash[:16]
                ),

                video_request_hash="",
            )
        )

        digest = (
            self._hasher.hash(
                provisional
            )
        )

        return replace(
            provisional,
            video_request_hash=digest,
        )

    @staticmethod
    def _prompt_hash(
        text: str,
    ) -> str:
        import hashlib

        return hashlib.sha256(
            text.encode("utf-8")
        ).hexdigest()
```

---

# 17.47 Video provider lifecycle

Đây là thay đổi quan trọng so với image provider.

Không giả định:

```text
submit()
→ bytes video ngay
```

Core:

```text
submit()
→ provider_job_id

poll(provider_job_id)
→ queued/running/succeeded/failed

fetch result
→ video bytes/url
```

---

# 17.48 Provider result DTOs

```python
# media_runtime/video/providers/response.py

from __future__ import annotations

from dataclasses import dataclass
from enum import StrEnum


class ProviderVideoStatus(StrEnum):
    QUEUED = "queued"
    RUNNING = "running"
    SUCCEEDED = "succeeded"
    FAILED = "failed"


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderVideoSubmission:
    provider_key: str

    model_key: str

    provider_job_id: str

    status: ProviderVideoStatus

    raw_response: dict


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderVideoPollResult:
    provider_key: str

    provider_job_id: str

    status: ProviderVideoStatus

    progress: float | None

    result_url: str | None

    error_code: str | None

    error_message: str | None

    raw_response: dict


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderVideoArtifact:
    content: bytes

    mime_type: str

    provider_job_id: str
```

---

# 17.49 Video provider adapter interface

```python
# media_runtime/video/providers/base.py

from __future__ import annotations

from abc import ABC, abstractmethod


class VideoProviderAdapter(
    ABC
):
    @property
    @abstractmethod
    def provider_key(
        self,
    ) -> str:
        raise NotImplementedError

    @abstractmethod
    def supports_model(
        self,
        model_key: str,
    ) -> bool:
        raise NotImplementedError

    @abstractmethod
    def submit(
        self,
        *,
        request,
        start_frame,
        end_frame=None,
    ):
        raise NotImplementedError

    @abstractmethod
    def poll(
        self,
        *,
        provider_job_id: str,
    ):
        raise NotImplementedError

    @abstractmethod
    def fetch_artifact(
        self,
        *,
        poll_result,
    ):
        raise NotImplementedError
```

Adapter không retry, không fallback, không write DB.

---

# 17.50 Veo transport contract

Transport chứa exact API/SDK mapping.

Core không biết endpoint.

```python
# media_runtime/video/providers/veo.py

from __future__ import annotations

from typing import Protocol


class VeoTransport(
    Protocol
):
    def submit_image_to_video(
        self,
        *,
        model: str,
        prompt: str,
        image: bytes,
        image_mime_type: str,
        last_frame: bytes | None,
        last_frame_mime_type: (
            str | None
        ),
        duration_seconds: float,
        aspect_ratio: str,
        negative_prompt: str | None,
        options: dict,
    ) -> dict:
        ...

    def poll(
        self,
        *,
        job_id: str,
    ) -> dict:
        ...

    def download(
        self,
        *,
        result_url: str,
    ) -> bytes:
        ...
```

---

# 17.51 Veo adapter

```python
from __future__ import annotations

from media_runtime.video.providers.base import (
    VideoProviderAdapter,
)
from media_runtime.video.providers.response import (
    ProviderVideoArtifact,
    ProviderVideoPollResult,
    ProviderVideoStatus,
    ProviderVideoSubmission,
)


class VeoVideoAdapter(
    VideoProviderAdapter
):
    PROVIDER_KEY = "google_veo"

    def __init__(
        self,
        *,
        transport,
        supported_models: frozenset[
            str
        ],
    ) -> None:
        self._transport = transport
        self._models = supported_models

    @property
    def provider_key(
        self,
    ) -> str:
        return self.PROVIDER_KEY

    def supports_model(
        self,
        model_key: str,
    ) -> bool:
        return model_key in self._models

    def submit(
        self,
        *,
        request,
        start_frame,
        end_frame=None,
    ) -> ProviderVideoSubmission:
        raw = (
            self._transport
            .submit_image_to_video(
                model=request.model_key,

                prompt=request.prompt,

                image=(
                    start_frame.content
                ),

                image_mime_type=(
                    start_frame.mime_type
                ),

                last_frame=(
                    end_frame.content
                    if end_frame
                    else None
                ),

                last_frame_mime_type=(
                    end_frame.mime_type
                    if end_frame
                    else None
                ),

                duration_seconds=(
                    request
                    .duration_seconds
                ),

                aspect_ratio=(
                    request.aspect_ratio
                ),

                negative_prompt=(
                    request
                    .negative_prompt
                ),

                options={
                    **request.native_controls,
                    **request.provider_options,
                },
            )
        )

        job_id = self._job_id(
            raw
        )

        return ProviderVideoSubmission(
            provider_key=(
                self.provider_key
            ),

            model_key=(
                request.model_key
            ),

            provider_job_id=(
                job_id
            ),

            status=self._status(
                raw
            ),

            raw_response=(
                self._sanitize(
                    raw
                )
            ),
        )

    def poll(
        self,
        *,
        provider_job_id: str,
    ) -> ProviderVideoPollResult:
        raw = self._transport.poll(
            job_id=provider_job_id
        )

        status = self._status(
            raw
        )

        return ProviderVideoPollResult(
            provider_key=(
                self.provider_key
            ),

            provider_job_id=(
                provider_job_id
            ),

            status=status,

            progress=(
                self._progress(
                    raw
                )
            ),

            result_url=(
                self._result_url(
                    raw
                )
            ),

            error_code=(
                self._error_code(
                    raw
                )
            ),

            error_message=(
                self._error_message(
                    raw
                )
            ),

            raw_response=(
                self._sanitize(
                    raw
                )
            ),
        )

    def fetch_artifact(
        self,
        *,
        poll_result,
    ) -> ProviderVideoArtifact:
        if (
            poll_result.status
            != ProviderVideoStatus
            .SUCCEEDED
        ):
            raise RuntimeError(
                "Cannot fetch unfinished "
                "Veo job."
            )

        if not poll_result.result_url:
            raise RuntimeError(
                "Veo result has no URL."
            )

        content = (
            self._transport.download(
                result_url=(
                    poll_result
                    .result_url
                )
            )
        )

        if not content:
            raise RuntimeError(
                "Empty Veo video."
            )

        return ProviderVideoArtifact(
            content=content,

            mime_type="video/mp4",

            provider_job_id=(
                poll_result
                .provider_job_id
            ),
        )

    @staticmethod
    def _job_id(
        raw: dict,
    ) -> str:
        value = (
            raw.get("job_id")
            or raw.get("name")
            or raw.get("id")
        )

        if not isinstance(
            value,
            str,
        ) or not value:
            raise RuntimeError(
                "Veo response has no "
                "provider job ID."
            )

        return value

    @staticmethod
    def _status(
        raw: dict,
    ):
        value = str(
            raw.get(
                "status",
                "queued",
            )
        ).lower()

        mapping = {
            "queued":
                ProviderVideoStatus.QUEUED,

            "pending":
                ProviderVideoStatus.QUEUED,

            "running":
                ProviderVideoStatus.RUNNING,

            "processing":
                ProviderVideoStatus.RUNNING,

            "succeeded":
                ProviderVideoStatus.SUCCEEDED,

            "completed":
                ProviderVideoStatus.SUCCEEDED,

            "failed":
                ProviderVideoStatus.FAILED,
        }

        return mapping.get(
            value,
            ProviderVideoStatus.RUNNING,
        )

    @staticmethod
    def _progress(
        raw,
    ):
        value = raw.get(
            "progress"
        )

        return (
            float(value)
            if isinstance(
                value,
                (int, float),
            )
            and not isinstance(
                value,
                bool,
            )
            else None
        )

    @staticmethod
    def _result_url(
        raw,
    ):
        value = raw.get(
            "result_url"
        )

        return (
            value
            if isinstance(
                value,
                str,
            )
            else None
        )

    @staticmethod
    def _error_code(
        raw,
    ):
        error = raw.get(
            "error"
        )

        if isinstance(
            error,
            dict,
        ):
            value = error.get(
                "code"
            )

            return (
                str(value)
                if value is not None
                else None
            )

        return None

    @staticmethod
    def _error_message(
        raw,
    ):
        error = raw.get(
            "error"
        )

        if isinstance(
            error,
            dict,
        ):
            value = error.get(
                "message"
            )

            return (
                str(value)
                if value
                else None
            )

        return None

    @staticmethod
    def _sanitize(
        raw: dict,
    ) -> dict:
        return dict(raw)
```

Transport phải normalize raw Veo API thành keys stable như `status`, `job_id`, `result_url`. Adapter core không parse hàng chục phiên bản API.

---

# 17.52 Kling transport

```python
class KlingTransport(
    Protocol
):
    def submit_image_to_video(
        self,
        *,
        model: str,
        prompt: str,
        image: bytes,
        image_mime_type: str,
        last_frame: bytes | None,
        last_frame_mime_type: (
            str | None
        ),
        duration_seconds: float,
        aspect_ratio: str,
        negative_prompt: str | None,
        options: dict,
    ) -> dict:
        ...

    def poll(
        self,
        *,
        job_id: str,
    ) -> dict:
        ...

    def download(
        self,
        *,
        result_url: str,
    ) -> bytes:
        ...
```

---

# 17.53 Kling adapter

Contract giống Veo, implementation provider-specific tách riêng.

```python
class KlingVideoAdapter(
    VideoProviderAdapter
):
    PROVIDER_KEY = "kling"

    def __init__(
        self,
        *,
        transport,
        supported_models,
    ):
        self._transport = transport
        self._models = (
            frozenset(
                supported_models
            )
        )

    @property
    def provider_key(
        self,
    ) -> str:
        return self.PROVIDER_KEY

    def supports_model(
        self,
        model_key: str,
    ) -> bool:
        return model_key in self._models

    def submit(
        self,
        *,
        request,
        start_frame,
        end_frame=None,
    ):
        raw = (
            self._transport
            .submit_image_to_video(
                model=request.model_key,

                prompt=request.prompt,

                image=(
                    start_frame.content
                ),

                image_mime_type=(
                    start_frame.mime_type
                ),

                last_frame=(
                    end_frame.content
                    if end_frame
                    else None
                ),

                last_frame_mime_type=(
                    end_frame.mime_type
                    if end_frame
                    else None
                ),

                duration_seconds=(
                    request
                    .duration_seconds
                ),

                aspect_ratio=(
                    request.aspect_ratio
                ),

                negative_prompt=(
                    request
                    .negative_prompt
                ),

                options={
                    **request.native_controls,
                    **request.provider_options,
                },
            )
        )

        job_id = self._job_id(
            raw
        )

        return ProviderVideoSubmission(
            provider_key=self.provider_key,

            model_key=request.model_key,

            provider_job_id=job_id,

            status=self._status(
                raw
            ),

            raw_response=dict(
                raw
            ),
        )

    def poll(
        self,
        *,
        provider_job_id: str,
    ):
        raw = self._transport.poll(
            job_id=provider_job_id
        )

        status = self._status(
            raw
        )

        return ProviderVideoPollResult(
            provider_key=self.provider_key,

            provider_job_id=(
                provider_job_id
            ),

            status=status,

            progress=(
                raw.get("progress")
                if isinstance(
                    raw.get("progress"),
                    (int, float),
                )
                else None
            ),

            result_url=(
                raw.get("result_url")
                if isinstance(
                    raw.get("result_url"),
                    str,
                )
                else None
            ),

            error_code=(
                str(
                    raw.get("error_code")
                )
                if raw.get(
                    "error_code"
                ) is not None
                else None
            ),

            error_message=(
                str(
                    raw.get(
                        "error_message"
                    )
                )
                if raw.get(
                    "error_message"
                )
                else None
            ),

            raw_response=dict(raw),
        )

    def fetch_artifact(
        self,
        *,
        poll_result,
    ):
        if (
            poll_result.status
            != ProviderVideoStatus
            .SUCCEEDED
        ):
            raise RuntimeError(
                "Kling job not complete."
            )

        if not poll_result.result_url:
            raise RuntimeError(
                "Kling result URL missing."
            )

        content = (
            self._transport.download(
                result_url=(
                    poll_result
                    .result_url
                )
            )
        )

        return ProviderVideoArtifact(
            content=content,

            mime_type="video/mp4",

            provider_job_id=(
                poll_result
                .provider_job_id
            ),
        )

    @staticmethod
    def _job_id(
        raw: dict,
    ) -> str:
        value = (
            raw.get("job_id")
            or raw.get("task_id")
            or raw.get("id")
        )

        if not isinstance(
            value,
            str,
        ) or not value:
            raise RuntimeError(
                "Kling job ID missing."
            )

        return value

    @staticmethod
    def _status(
        raw: dict,
    ):
        value = str(
            raw.get(
                "status",
                "queued",
            )
        ).lower()

        mapping = {
            "queued":
                ProviderVideoStatus.QUEUED,

            "submitted":
                ProviderVideoStatus.QUEUED,

            "processing":
                ProviderVideoStatus.RUNNING,

            "running":
                ProviderVideoStatus.RUNNING,

            "success":
                ProviderVideoStatus.SUCCEEDED,

            "succeeded":
                ProviderVideoStatus.SUCCEEDED,

            "completed":
                ProviderVideoStatus.SUCCEEDED,

            "failed":
                ProviderVideoStatus.FAILED,
        }

        return mapping.get(
            value,
            ProviderVideoStatus.RUNNING,
        )
```

Các alias raw này nên nằm trong **transport normalization** ở production. Adapter phía trên cho thấy boundary.

---

# 17.54 Part14 cần extension cho async video

Part14 hiện có:

```text
SUBMITTING
SUBMITTED
ARTIFACT_WRITING
```

Video cần thêm:

```text
PROVIDER_RUNNING
POLLING
```

Laravel enum:

```php
enum RenderStatus: string
{
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case PREPARING = 'preparing';
    case SUBMITTING = 'submitting';

    case PROVIDER_RUNNING =
        'provider_running';

    case POLLING =
        'polling';

    case PROVIDER_UNKNOWN =
        'provider_unknown';

    case ARTIFACT_WRITING =
        'artifact_writing';

    case CHECKPOINTING =
        'checkpointing';

    case SUCCEEDED =
        'succeeded';

    case RETRY_WAIT =
        'retry_wait';

    case FAILED =
        'failed';

    case CANCELLED =
        'cancelled';
}
```

---

# 17.55 DB additions for async provider jobs

Additive migration vào `renders`:

```php
Schema::table(
    'renders',
    function (Blueprint $table): void {
        $table
            ->string(
                'provider_job_id',
                255
            )
            ->nullable()
            ->index();

        $table
            ->timestampTz(
                'next_poll_at'
            )
            ->nullable()
            ->index();

        $table
            ->unsignedInteger(
                'poll_count'
            )
            ->default(0);

        $table
            ->decimal(
                'provider_progress',
                6,
                5
            )
            ->nullable();

        $table
            ->timestampTz(
                'provider_submitted_at'
            )
            ->nullable();

        $table
            ->timestampTz(
                'provider_completed_at'
            )
            ->nullable();

        $table
            ->string(
                'execution_media_type',
                20
            )
            ->default('image')
            ->index();
    }
);
```

---

# 17.56 Important: Poll không phải provider attempt mới

Đây là rule bắt buộc:

```text
attempt_count
= number of provider SUBMISSIONS

poll_count
= number of status polls
```

Không:

```text
poll #5
→ attempt_count = 5
```

Một provider video generation chỉ là **một billable submission attempt**.

---

# 17.57 Mark video provider running

Laravel:

```php
final class VideoProviderSubmissionService
{
    public function markRunning(
        string $renderId,
        string $claimToken,
        string $providerJobId,
        int $pollAfterSeconds = 10,
    ): void {
        DB::transaction(
            function () use (
                $renderId,
                $claimToken,
                $providerJobId,
                $pollAfterSeconds,
            ): void {
                $render =
                    Render::query()
                        ->whereKey(
                            $renderId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    !hash_equals(
                        (string)
                        $render->claim_token,
                        $claimToken
                    )
                ) {
                    throw new RuntimeException(
                        'Claim token mismatch.'
                    );
                }

                $now = now();

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus
                            ::PROVIDER_RUNNING,

                    'provider_job_id' =>
                        $providerJobId,

                    'provider_submitted_at' =>
                        $now,

                    'next_poll_at' =>
                        $now->copy()
                            ->addSeconds(
                                $pollAfterSeconds
                            ),

                    /*
                     * Release execution worker.
                     */
                    'claim_token' =>
                        null,

                    'claimed_by' =>
                        null,

                    'claimed_at' =>
                        null,

                    'heartbeat_at' =>
                        null,

                    'lease_expires_at' =>
                        null,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();
            }
        );
    }
}
```

Worker có thể xử lý render khác ngay.

---

# 17.58 Poll claim

Không dùng normal `claimNext()` vì normal claim tăng `attempt_count`.

Tạo riêng:

```php
final class VideoPollClaimService
{
    public function claimNext(
        string $workerId,
    ): ?RenderPollClaim {
        return DB::transaction(
            function () use (
                $workerId
            ): ?RenderPollClaim {
                $now = now();

                $render =
                    Render::query()
                        ->where(
                            'execution_status',
                            RenderStatus
                                ::PROVIDER_RUNNING
                                ->value
                        )
                        ->where(
                            'next_poll_at',
                            '<=',
                            $now
                        )
                        ->whereNotNull(
                            'provider_job_id'
                        )
                        ->orderBy(
                            'next_poll_at'
                        )
                        ->lock(
                            'for update skip locked'
                        )
                        ->first();

                if ($render === null) {
                    return null;
                }

                $token =
                    (string)
                    Str::uuid();

                $render->forceFill([
                    'execution_status' =>
                        RenderStatus::POLLING,

                    'claim_token' =>
                        $token,

                    'claimed_by' =>
                        $workerId,

                    'claimed_at' =>
                        $now,

                    'heartbeat_at' =>
                        $now,

                    'lease_expires_at' =>
                        $now->copy()
                            ->addSeconds(120),

                    'poll_count' =>
                        $render
                            ->poll_count
                        + 1,

                    'execution_version' =>
                        $render
                            ->execution_version
                        + 1,
                ])->save();

                return new RenderPollClaim(
                    renderId:
                        $render->id,

                    claimToken:
                        $token,

                    workerId:
                        $workerId,

                    providerKey:
                        $render->provider,

                    modelKey:
                        $render->model,

                    providerJobId:
                        $render
                            ->provider_job_id,

                    requestHash:
                        $render
                            ->request_hash,
                );
            }
        );
    }
}
```

DTO:

```php
final class RenderPollClaim
{
    public function __construct(
        public readonly string $renderId,
        public readonly string $claimToken,
        public readonly string $workerId,
        public readonly string $providerKey,
        public readonly string $modelKey,
        public readonly string $providerJobId,
        public readonly string $requestHash,
    ) {
    }
}
```

---

# 17.59 Poll result: still running

```php
public function scheduleNextPoll(
    string $renderId,
    string $claimToken,
    ?float $progress,
    int $afterSeconds = 10,
): void {
    DB::transaction(
        function () use (
            $renderId,
            $claimToken,
            $progress,
            $afterSeconds,
        ): void {
            $render =
                Render::query()
                    ->whereKey($renderId)
                    ->lockForUpdate()
                    ->firstOrFail();

            if (
                !hash_equals(
                    (string)
                    $render->claim_token,
                    $claimToken
                )
            ) {
                throw new RuntimeException(
                    'Poll claim mismatch.'
                );
            }

            $render->forceFill([
                'execution_status' =>
                    RenderStatus
                        ::PROVIDER_RUNNING,

                'provider_progress' =>
                    $progress,

                'next_poll_at' =>
                    now()
                    ->addSeconds(
                        $afterSeconds
                    ),

                'claim_token' =>
                    null,

                'claimed_by' =>
                    null,

                'claimed_at' =>
                    null,

                'heartbeat_at' =>
                    null,

                'lease_expires_at' =>
                    null,

                'execution_version' =>
                    $render
                        ->execution_version
                    + 1,
            ])->save();
        }
    );
}
```

---

# 17.60 Python submitter

```python
# media_runtime/video/execution/submitter.py

from __future__ import annotations


class VideoSubmissionService:
    def __init__(
        self,
        *,
        provider_registry,
        artifact_resolver,
    ) -> None:
        self._providers = (
            provider_registry
        )

        self._artifacts = (
            artifact_resolver
        )

    def submit(
        self,
        request,
    ):
        provider = (
            self._providers.get(
                provider_key=(
                    request.provider_key
                ),

                model_key=(
                    request.model_key
                ),
            )
        )

        start = (
            self._artifacts.resolve(
                request
                .start_keyframe_storage_key
            )
        )

        if (
            start.sha256
            != request.start_keyframe_hash
        ):
            raise RuntimeError(
                "Start keyframe hash mismatch."
            )

        end = None

        if (
            request
            .end_keyframe_storage_key
            is not None
        ):
            end = (
                self._artifacts.resolve(
                    request
                    .end_keyframe_storage_key
                )
            )

            if (
                end.sha256
                != request
                .end_keyframe_hash
            ):
                raise RuntimeError(
                    "End keyframe hash mismatch."
                )

        return provider.submit(
            request=request,

            start_frame=start,

            end_frame=end,
        )
```

---

# 17.61 Python poller

```python
# media_runtime/video/execution/poller.py

from __future__ import annotations


class VideoPollService:
    def __init__(
        self,
        *,
        provider_registry,
    ) -> None:
        self._providers = (
            provider_registry
        )

    def poll(
        self,
        *,
        provider_key: str,
        model_key: str,
        provider_job_id: str,
    ):
        provider = (
            self._providers.get(
                provider_key=(
                    provider_key
                ),

                model_key=(
                    model_key
                ),
            )
        )

        result = provider.poll(
            provider_job_id=(
                provider_job_id
            )
        )

        if result.status.value != (
            "succeeded"
        ):
            return result, None

        artifact = (
            provider.fetch_artifact(
                poll_result=result
            )
        )

        return (
            result,
            artifact,
        )
```

---

# 17.62 Video artifact persister

Reuses Part13 `ArtifactWriter`.

```python
# media_runtime/video/artifacts/persister.py

from __future__ import annotations

from pathlib import Path


class VideoArtifactPersister:
    VERSION = "video-artifact-v1"

    def __init__(
        self,
        *,
        writer,
    ) -> None:
        self._writer = writer

    def persist(
        self,
        *,
        request,
        artifact,
        attempt_no: int,
    ):
        base = Path(
            request.video_session_id,
            "video",
            request.scene_code,
            request.render_id,
            f"attempt_{attempt_no:03d}",
        )

        video_artifact = (
            self._writer.write_bytes(
                relative_path=(
                    base
                    / "clip.mp4"
                ),

                data=artifact.content,

                artifact_id=(
                    request.render_id
                    + ":video:0"
                ),

                kind=(
                    self._video_kind()
                ),

                mime_type=(
                    artifact.mime_type
                ),

                request_hash=(
                    request
                    .video_request_hash
                ),

                ordinal=0,
            )
        )

        manifest = {
            "version":
                self.VERSION,

            "render_id":
                request.render_id,

            "scene_id":
                request.scene_id,

            "scene_code":
                request.scene_code,

            "video_request_hash":
                request
                .video_request_hash,

            "canonical_hash":
                request.canonical_hash,

            "identity_lock_hash":
                request
                .identity_lock_hash,

            "scene_projection_hash":
                request
                .scene_projection_hash,

            "motion_spec_hash":
                request
                .motion_spec_hash,

            "start_keyframe_hash":
                request
                .start_keyframe_hash,

            "end_keyframe_hash":
                request
                .end_keyframe_hash,

            "provider_key":
                request.provider_key,

            "model_key":
                request.model_key,

            "provider_job_id":
                artifact
                .provider_job_id,

            "artifact": {
                "artifact_id":
                    video_artifact
                    .artifact_id,

                "sha256":
                    video_artifact
                    .sha256,

                "size_bytes":
                    video_artifact
                    .size_bytes,

                "mime_type":
                    video_artifact
                    .mime_type,
            },
        }

        return (
            video_artifact,
            manifest,
        )

    @staticmethod
    def _video_kind():
        from media_runtime.render.enums import (
            ArtifactKind,
        )

        # Add this enum member in Part17.
        return ArtifactKind.GENERATED_VIDEO
```

Add Part13 enum:

```python
class ArtifactKind(StrEnum):
    # existing...
    GENERATED_IMAGE = "generated_image"
    GENERATED_VIDEO = "generated_video"
    MOTION_SPEC = "motion_spec"
    VIDEO_REQUEST = "video_request"
```

---

# 17.63 Async execution flow

Python worker main loop giờ có hai claim lanes:

```python
def run_once(self) -> bool:
    poll_claim = (
        self._checkpoint
        .claim_video_poll(
            worker_id=self._worker_id
        )
    )

    if poll_claim is not None:
        self._execute_poll(
            poll_claim
        )

        return True

    render_claim = (
        self._checkpoint
        .claim_next(
            worker_id=self._worker_id
        )
    )

    if render_claim is not None:
        self._execute_render(
            render_claim
        )

        return True

    return False
```

Tôi ưu tiên poll trước submit mới để completed provider jobs được thu hồi artifact sớm.

---

# 17.64 Poll execution

```python
def _execute_poll(
    self,
    claim,
) -> None:
    try:
        result, artifact = (
            self._video_poller.poll(
                provider_key=(
                    claim.provider_key
                ),

                model_key=(
                    claim.model_key
                ),

                provider_job_id=(
                    claim.provider_job_id
                ),
            )
        )

        if (
            result.status.value
            in (
                "queued",
                "running",
            )
        ):
            self._checkpoint
                .schedule_next_video_poll(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),

                    progress=(
                        result.progress
                    ),

                    after_seconds=10,
                )

            return

        if (
            result.status.value
            == "failed"
        ):
            self._checkpoint
                .video_provider_failed(
                    render_id=(
                        claim.render_id
                    ),

                    claim_token=(
                        claim.claim_token
                    ),

                    error_code=(
                        result.error_code
                        or "video_provider_failed"
                    ),

                    message=(
                        result.error_message
                        or "Video provider failed."
                    ),
                )

            return

        if artifact is None:
            raise RuntimeError(
                "Provider succeeded without "
                "video artifact."
            )

        request = (
            self._video_request_loader
            .load_for_render(
                claim.render_id
            )
        )

        persisted, manifest = (
            self._video_artifacts
            .persist(
                request=request,

                artifact=artifact,

                attempt_no=(
                    claim.attempt_no
                ),
            )
        )

        self._checkpoint
            .complete_video(
                render_id=(
                    claim.render_id
                ),

                claim_token=(
                    claim.claim_token
                ),

                provider_job_id=(
                    claim.provider_job_id
                ),

                request_hash=(
                    claim.request_hash
                ),

                artifact_manifest=(
                    manifest
                ),
            )

    except Exception as exc:
        self._handle_poll_failure(
            claim,
            exc,
        )
```

Important: `load_for_render()` không được query DB trực tiếp. Trong production, poll claim payload phải bao gồm exact frozen `video_request_json` hoặc checkpoint endpoint riêng trả nó.

---

# 17.65 Better poll claim contract

Laravel gửi:

```json
{
  "render_id": "...",
  "claim_token": "...",
  "attempt_no": 1,
  "provider_key": "google_veo",
  "model_key": "...",
  "provider_job_id": "...",
  "request_hash": "...",
  "video_request_json": "{...exact frozen request...}"
}
```

Python verify:

```text
hash(video_request_json-derived request)
==
request_hash
```

Không query Laravel DB.

---

# 17.66 Video provider timeout handling

Khác image synchronous.

Nếu `submit()` timeout:

```text
provider có thể đã tạo job
```

→ Part14 rule vẫn đúng:

```text
PROVIDER_UNKNOWN
```

Không submit lại blind.

Nếu `poll()` timeout:

```text
safe to poll again
```

vì polling không tạo generation mới.

Do đó:

```text
submit timeout = ambiguous
poll timeout   = safe retry poll
```

Rất quan trọng.

---

# 17.67 Poll retry budget

Không dùng `max_attempts`.

Add:

```php
$table
    ->unsignedInteger(
        'max_poll_count'
    )
    ->default(120);
```

Nếu poll quá lâu:

```text
provider_running
→ reconciliation/review
```

Không tạo generation mới.

---

# 17.68 Scene State progression

Một scene video chỉ được đánh dấu state transition committed sau clip accepted.

Không:

```text
dispatch scene
→ immediately advance world state
```

Đúng:

```text
Scene 03
from S2
to S3
↓
scene image approved
↓
video rendered
↓
shot accepted
↓
commit Scene 03 state = S3
```

Nếu clip fail/rejected:

```text
current committed state vẫn S2
```

Đây là cách tránh scene 4 render theo một state mà scene 3 chưa thực sự tồn tại.

---

# 17.69 Laravel shot state fields

Bạn đã có `shots`.

Add:

```php
Schema::table(
    'shots',
    function (Blueprint $table): void {
        $table
            ->string(
                'scene_code',
                100
            )
            ->nullable()
            ->index();

        $table
            ->unsignedInteger(
                'scene_index'
            )
            ->nullable();

        $table
            ->string(
                'from_state_id',
                100
            )
            ->nullable();

        $table
            ->string(
                'to_state_id',
                100
            )
            ->nullable();

        $table
            ->string(
                'transition_id',
                100
            )
            ->nullable();

        $table
            ->uuid(
                'identity_lock_id'
            )
            ->nullable();

        $table
            ->char(
                'identity_lock_hash',
                64
            )
            ->nullable();

        $table
            ->char(
                'scene_projection_hash',
                64
            )
            ->nullable();

        $table
            ->uuid(
                'scene_image_render_id'
            )
            ->nullable();

        $table
            ->string(
                'keyframe_artifact_id',
                190
            )
            ->nullable();

        $table
            ->char(
                'keyframe_artifact_hash',
                64
            )
            ->nullable();

        $table
            ->char(
                'motion_spec_hash',
                64
            )
            ->nullable();

        $table
            ->longText(
                'motion_spec_json'
            )
            ->nullable();

        $table
            ->uuid(
                'video_render_id'
            )
            ->nullable();

        $table
            ->char(
                'video_request_hash',
                64
            )
            ->nullable();

        $table
            ->string(
                'video_artifact_id',
                190
            )
            ->nullable();

        $table
            ->char(
                'video_artifact_hash',
                64
            )
            ->nullable();

        $table
            ->string(
                'execution_status',
                40
            )
            ->default(
                'planned'
            )
            ->index();

        $table
            ->timestampTz(
                'state_committed_at'
            )
            ->nullable();
    }
);
```

---

# 17.70 RenderPlan identity/state contract

Frozen RenderPlan should now look like:

```json
{
  "render_plan_version": "1.0",
  "render_plan_hash": "...",

  "identity": {
    "identity_lock_id": "IL-...",
    "identity_lock_hash": "...",
    "canonical_revision_id": "REV-3",
    "canonical_hash": "..."
  },

  "state_graph": {
    "version": "scene-state-graph-v1",

    "initial_state_id": "S0",

    "states": [
      {
        "state_id": "S0",
        "sequence_index": 0,
        "facts": []
      },

      {
        "state_id": "S1",
        "sequence_index": 1,
        "facts": []
      }
    ],

    "transitions": [
      {
        "transition_id": "T0",
        "from_state_id": "S0",
        "to_state_id": "S1",
        "mutations": []
      }
    ]
  },

  "scenes": [
    {
      "scene_id": "SCENE-01",
      "scene_code": "S01",
      "sequence_index": 0,

      "from_state_id": "S0",
      "to_state_id": "S1",
      "transition_id": "T0",

      "image_state_role": "before",

      "duration_seconds": 6,

      "camera": {
        "view": "front_three_quarter",
        "preferred_reference_role":
          "front_three_quarter"
      },

      "motion": {
        "camera_motion": "dolly_in",
        "subject_motion": null,
        "environment_motion":
          "subtle worker activity",
        "intensity": 0.25
      }
    }
  ]
}
```

---

# 17.71 Scene execution must respect predecessor

Laravel:

```php
final class SceneExecutionEligibility
{
    public function canExecute(
        Shot $shot
    ): bool {
        if (
            $shot->scene_index === 0
        ) {
            return true;
        }

        $previous =
            Shot::query()
                ->where(
                    'video_session_id',
                    $shot->video_session_id
                )
                ->where(
                    'scene_index',
                    $shot->scene_index - 1
                )
                ->first();

        if ($previous === null) {
            return false;
        }

        return (
            $previous->state_committed_at
            !== null
        );
    }
}
```

Đây là strict sequential state mode.

Nếu sau này scenes không phụ thuộc state nhau, graph có thể cho phép parallel branches. V1 construction/documentary nên strict hơn.

---

# 17.72 SceneExecutionPacketBuilder Laravel

```php
final class SceneExecutionPacketBuilder
{
    public function build(
        VideoSession $session,
        Shot $shot,
        IdentityLock $identityLock,
    ): array {
        $plan =
            $session->render_plan;

        if (
            !hash_equals(
                $plan['identity'][
                    'identity_lock_hash'
                ],
                $identityLock
                    ->identity_lock_hash
            )
        ) {
            throw new RuntimeException(
                'RenderPlan IdentityLock mismatch.'
            );
        }

        $scene =
            collect(
                $plan['scenes']
            )->firstWhere(
                'scene_id',
                $shot->scene_id
            );

        if ($scene === null) {
            throw new RuntimeException(
                'Scene missing from frozen plan.'
            );
        }

        $previous =
            $shot->scene_index > 0
            ? Shot::query()
                ->where(
                    'video_session_id',
                    $shot
                        ->video_session_id
                )
                ->where(
                    'scene_index',
                    $shot->scene_index - 1
                )
                ->first()
            : null;

        return [
            'version' =>
                'scene-execution-packet-v1',

            'project_id' =>
                $session
                    ->video_project_id,

            'video_session_id' =>
                $session->id,

            'render_plan_revision' =>
                $session
                    ->plan_revision,

            'render_plan_hash' =>
                $session
                    ->render_plan_hash,

            'canonical_revision_id' =>
                $plan['identity'][
                    'canonical_revision_id'
                ],

            'canonical_hash' =>
                $plan['identity'][
                    'canonical_hash'
                ],

            'identity_lock_id' =>
                $identityLock->id,

            'identity_lock_hash' =>
                $identityLock
                    ->identity_lock_hash,

            /*
             * Send exact stored manifest string.
             */
            'identity_lock_json' =>
                $identityLock
                    ->manifest_json,

            'constraint_set_hash' =>
                $shot
                    ->constraint_set_hash,

            'state_graph' =>
                $plan[
                    'state_graph'
                ],

            'scene' =>
                $scene,

            'previous_scene_artifact_id' =>
                $previous
                    ?->video_artifact_id,

            'previous_scene_artifact_hash' =>
                $previous
                    ?->video_artifact_hash,
        ];
    }
}
```

For scene image continuity you may prefer previous **approved scene image artifact**, not previous video artifact. Add a separate `scene_image_artifact_id/hash` column rather than reusing clip.

---

# 17.73 Scene image checkpoint

```php
final class SceneImageCheckpointService
{
    public function complete(
        Shot $shot,
        Render $render,
        RenderQaRun $qa,
    ): void {
        DB::transaction(
            function () use (
                $shot,
                $render,
                $qa,
            ): void {
                $shot =
                    Shot::query()
                        ->whereKey(
                            $shot->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $qa->decision->value
                    !== 'pass'
                ) {
                    throw new RuntimeException(
                        'Scene image QA not PASS.'
                    );
                }

                if (
                    !hash_equals(
                        $shot
                            ->identity_lock_hash,

                        $render
                            ->identity_lock_hash
                    )
                ) {
                    throw new RuntimeException(
                        'Scene identity lineage mismatch.'
                    );
                }

                $artifact =
                    $this
                        ->primaryImageArtifact(
                            $render
                        );

                $shot->forceFill([
                    'scene_image_render_id' =>
                        $render->id,

                    'scene_image_artifact_id' =>
                        $artifact[
                            'artifact_id'
                        ],

                    'scene_image_artifact_hash' =>
                        $artifact[
                            'sha256'
                        ],

                    'keyframe_artifact_id' =>
                        $artifact[
                            'artifact_id'
                        ],

                    'keyframe_artifact_hash' =>
                        $artifact[
                            'sha256'
                        ],

                    'execution_status' =>
                        'keyframe_ready',
                ])->save();
            }
        );
    }
}
```

Add fields `scene_image_artifact_id/hash/storage_key` to shots if not already.

---

# 17.74 MotionSpec checkpoint

Laravel stores exact bytes/hash from Python:

```php
final class MotionSpecCheckpointService
{
    public function freeze(
        Shot $shot,
        string $motionSpecJson,
        string $motionSpecHash,
    ): void {
        DB::transaction(
            function () use (
                $shot,
                $motionSpecJson,
                $motionSpecHash,
            ): void {
                $shot =
                    Shot::query()
                        ->whereKey(
                            $shot->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $shot->motion_spec_hash
                    !== null
                ) {
                    if (
                        !hash_equals(
                            $shot
                                ->motion_spec_hash,

                            $motionSpecHash
                        )
                    ) {
                        throw new RuntimeException(
                            'Frozen MotionSpec '
                            . 'cannot change.'
                        );
                    }

                    return;
                }

                $shot->forceFill([
                    'motion_spec_json' =>
                        $motionSpecJson,

                    'motion_spec_hash' =>
                        $motionSpecHash,

                    'execution_status' =>
                        'video_queued',
                ])->save();
            }
        );
    }
}
```

---

# 17.75 Video render checkpoint

When Part17 creates VideoRenderRequest:

```php
$shot->forceFill([
    'video_render_id' =>
        $render->id,

    'video_request_hash' =>
        $render->request_hash,

    'execution_status' =>
        'video_running',
])->save();
```

---

# 17.76 Video completion checkpoint

```php
final class VideoShotCheckpointService
{
    public function complete(
        Shot $shot,
        Render $render,
    ): void {
        DB::transaction(
            function () use (
                $shot,
                $render,
            ): void {
                $shot =
                    Shot::query()
                        ->whereKey(
                            $shot->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $render
                        ->execution_status
                    !== RenderStatus::SUCCEEDED
                ) {
                    throw new RuntimeException(
                        'Video render is not succeeded.'
                    );
                }

                if (
                    !hash_equals(
                        (string)
                        $shot
                            ->video_request_hash,

                        (string)
                        $render
                            ->request_hash
                    )
                ) {
                    throw new RuntimeException(
                        'Video request hash mismatch.'
                    );
                }

                $artifact =
                    $this
                        ->videoArtifact(
                            $render
                        );

                $shot->forceFill([
                    'video_artifact_id' =>
                        $artifact[
                            'artifact_id'
                        ],

                    'video_artifact_hash' =>
                        $artifact[
                            'sha256'
                        ],

                    'execution_status' =>
                        'video_ready',
                ])->save();
            }
        );
    }
}
```

Không `state_committed_at` ngay nếu còn human shot approval.

---

# 17.77 State commit

Sau video shot được approve:

```php
final class ShotStateCommitService
{
    public function commit(
        Shot $shot,
        string $adminId,
    ): void {
        DB::transaction(
            function () use (
                $shot,
                $adminId,
            ): void {
                $shot =
                    Shot::query()
                        ->whereKey(
                            $shot->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $shot
                        ->execution_status
                    !== 'video_ready'
                ) {
                    throw new RuntimeException(
                        'Shot is not ready.'
                    );
                }

                if (
                    !$shot->approved
                ) {
                    throw new RuntimeException(
                        'Shot must be approved.'
                    );
                }

                if (
                    $shot
                        ->state_committed_at
                    !== null
                ) {
                    return;
                }

                $shot->forceFill([
                    'state_committed_at' =>
                        now(),

                    'state_committed_by' =>
                        $adminId,

                    'execution_status' =>
                        'approved',
                ])->save();
            }
        );
    }
}
```

---

# 17.78 Chain shots bạn đã có

Bạn đang có:

```text
chain_key
chain_index
requires_state
proves_state
source_shot_code
```

Part17 không loại bỏ chúng.

Mapping:

```text
requires_state
→ from_state_id / required state facts

proves_state
→ to_state_id / resulting state facts

chain_index
→ state graph sequence
```

Nên giữ các fields hiện tại cho editorial/debugging, nhưng **state graph frozen trong RenderPlan là semantic source-of-truth**.

---

# 17.79 Scene image và video không nhất thiết cùng state representation

Ví dụ scene mô tả:

```text
start: bottom plates absent
end: bottom plates installed
```

Scene image start keyframe:

```text
image_state_role = before
```

MotionSpec:

```text
transition before → after
```

Video kết thúc gần `after`.

Scene sau dùng:

```text
from_state = after
```

Đây là state progression đúng.

---

# 17.80 Nếu cảnh không thể thể hiện fabrication transition trong 6s?

Planner phải quyết định trước khi freeze.

Python không tự đổi:

```text
6s → 12s
```

hay tách scene thành 2.

Nếu provider capability không thực hiện được:

```text
CAPABILITY_INCOMPATIBLE
```

Laravel phải:

```text
new planning revision
```

Không để executor redesign timeline.

---

# 17.81 MotionSpec không được chứa marketing/cinematic invention

Được:

```text
camera_motion = dolly_in
environment_motion = subtle worker movement
```

Không được Python tự thêm:

```text
dramatic sparks explode everywhere
sunset cinematic glow
helicopter orbit
```

nếu FrozenScenePlan không yêu cầu.

Motion compiler là translator.

---

# 17.82 Video identity preservation

Video provider chỉ nhận một start frame có thể morph geometry theo thời gian.

Vì vậy MotionSpec luôn chứa preservation rules:

```text
PRESERVE_IDENTITY
PRESERVE_COUNTS
PRESERVE_TOPOLOGY
PRESERVE_PROPORTIONS
PRESERVE_OPENINGS
```

và prohibited identity constraint IDs từ Part16.

Sau này video QA có thể map lại:

```text
constraint ID
→ sampled frames
```

---

# 17.83 Scene image reference selection example

Ví dụ camera:

```text
front_three_quarter
```

Selection:

```text
0 anchor
1 front_three_quarter reference
2 left_profile
10 previous approved scene image
```

Không gửi:

```text
all 8 reference images
```

trừ khi provider/profile chứng minh cần thiết.

---

# 17.84 Scene Image request hash lineage

Bây giờ:

```text
canonical_hash
↓
identity_lock_hash
↓
render_plan_hash
↓
scene_projection_hash
↓
scene_constraint_set_hash
↓
prompt_spec_hash
↓
prompt_hash
↓
scene_image_request_hash
↓
scene_image_artifact_hash
↓
qa_report_hash
↓
keyframe_hash
```

Sau đó:

```text
keyframe_hash
+
motion_spec_hash
+
provider/model
↓
video_request_hash
↓
provider_job_id
↓
video_artifact_hash
```

---

# 17.85 Test state transition mismatch

```python
import pytest


def test_transition_before_value_must_match_state(
    graph_factory,
):
    graph = graph_factory(
        before_value="absent",

        mutation_before="installed",
    )

    with pytest.raises(
        SceneStateGraphError
    ):
        (
            SceneStateGraphValidator()
            .validate(
                graph
            )
        )
```

---

# 17.86 State cannot move backward

```python
def test_state_graph_cannot_move_backward(
    graph_factory,
):
    graph = graph_factory(
        transition_from="S2",
        transition_to="S1",
    )

    with pytest.raises(
        SceneStateGraphError
    ):
        (
            SceneStateGraphValidator()
            .validate(
                graph
            )
        )
```

---

# 17.87 Scene projection preserves IdentityLock

```python
def test_scene_projection_keeps_identity_lock(
    projector,
    packet,
    identity_lock,
):
    projection = (
        projector.project(
            packet=packet,

            identity_lock=(
                identity_lock
            ),

            asset_id="subject",
        )
    )

    assert (
        projection.identity_lock_hash
        == packet.identity_lock_hash
    )

    assert (
        projection.canonical_hash
        == packet.canonical_hash
    )
```

---

# 17.88 Scene cannot use a different identity revision

```python
def test_scene_rejects_identity_lock_from_other_revision(
    projector,
    packet,
    identity_lock,
):
    identity_lock[
        "canonical_hash"
    ] = "f" * 64

    with pytest.raises(
        SceneLineageError
    ):
        projector.project(
            packet=packet,

            identity_lock=(
                identity_lock
            ),

            asset_id="subject",
        )
```

---

# 17.89 Anchor always first

```python
def test_scene_reference_anchor_is_primary(
    selector,
    projection,
    identity_lock,
):
    selection = selector.select(
        projection=projection,

        identity_lock=(
            identity_lock
        ),
    )

    first = (
        selection.ordered()[0]
    )

    assert first.role == "anchor"

    assert first.priority == 0
```

---

# 17.90 Previous scene cannot outrank identity

```python
def test_state_reference_cannot_override_identity(
    selector,
    projection,
    identity_lock,
    continuity_artifact,
):
    selection = selector.select(
        projection=projection,

        identity_lock=(
            identity_lock
        ),

        continuity_artifact=(
            continuity_artifact
        ),
    )

    ordered = selection.ordered()

    assert (
        ordered[0].purpose.value
        == "identity"
    )

    state_refs = [
        item
        for item in ordered
        if (
            item.purpose.value
            == "state_continuity"
        )
    ]

    assert all(
        item.priority > 2
        for item in state_refs
    )
```

---

# 17.91 Keyframe requires QA PASS

```python
def test_failed_scene_image_cannot_be_keyframe(
    builder,
    projection,
    render,
    artifact,
    qa_report,
):
    failed = replace(
        qa_report,
        decision=QaDecision.FAIL,
    )

    with pytest.raises(
        ValueError
    ):
        builder.from_scene_image(
            projection=projection,

            scene_render=render,

            scene_artifact=(
                artifact
            ),

            qa_report=failed,
        )
```

---

# 17.92 MotionSpec same input = same hash

```python
def test_motion_spec_is_deterministic(
    compiler,
    projection,
    keyframes,
    identity_constraints,
):
    first = compiler.compile(
        projection=projection,

        keyframes=keyframes,

        identity_constraints=(
            identity_constraints
        ),
    )

    second = compiler.compile(
        projection=projection,

        keyframes=keyframes,

        identity_constraints=(
            identity_constraints
        ),
    )

    assert (
        first.motion_spec_hash
        == second.motion_spec_hash
    )
```

---

# 17.93 MotionSpec cannot use wrong keyframe identity

```python
def test_motion_spec_rejects_foreign_keyframe(
    compiler,
    projection,
    keyframes,
    identity_constraints,
):
    foreign = replace(
        keyframes.start,

        identity_lock_hash=(
            "f" * 64
        ),
    )

    broken = replace(
        keyframes,
        start=foreign,
    )

    with pytest.raises(
        ValueError
    ):
        compiler.compile(
            projection=projection,

            keyframes=broken,

            identity_constraints=(
                identity_constraints
            ),
        )
```

---

# 17.94 Duration capability mismatch fails

```python
def test_video_provider_does_not_silently_change_duration(
    projector,
    capability,
    motion_spec,
    compiled_motion_prompt,
    keyframes,
):
    capability = replace(
        capability,

        allowed_durations_seconds=(
            5.0,
            10.0,
        ),
    )

    motion_spec = replace(
        motion_spec,
        duration_seconds=6.0,
    )

    with pytest.raises(
        RuntimeError
    ):
        projector.project(
            capability=capability,

            motion_spec=motion_spec,

            compiled_motion_prompt=(
                compiled_motion_prompt
            ),

            keyframes=keyframes,
        )
```

---

# 17.95 Same VideoRenderRequest = same hash

```python
def test_video_request_hash_is_deterministic(
    video_request,
):
    hasher = (
        VideoRenderRequestHasher()
    )

    assert (
        hasher.hash(video_request)
        == hasher.hash(video_request)
    )
```

---

# 17.96 Provider/model change changes hash

```python
def test_video_provider_change_changes_hash(
    video_request,
):
    hasher = (
        VideoRenderRequestHasher()
    )

    other = replace(
        video_request,

        provider_key="kling",

        video_request_hash="",
    )

    assert (
        hasher.hash(video_request)
        != hasher.hash(other)
    )
```

---

# 17.97 Start keyframe change changes request hash

```python
def test_start_keyframe_change_changes_video_request(
    video_request,
):
    hasher = (
        VideoRenderRequestHasher()
    )

    changed = replace(
        video_request,

        start_keyframe_hash=(
            "f" * 64
        ),

        video_request_hash="",
    )

    assert (
        hasher.hash(video_request)
        != hasher.hash(changed)
    )
```

---

# 17.98 Submit timeout is ambiguous

Test Part14 execution classifier:

```python
def test_video_submit_timeout_is_ambiguous(
    classifier,
):
    decision = (
        classifier.classify(
            phase="submitting",
            error=TimeoutError(),
        )
    )

    assert (
        decision.disposition.value
        == "ambiguous"
    )
```

---

# 17.99 Poll timeout is safe

```python
def test_video_poll_timeout_is_safe_to_poll_again(
    classifier,
):
    decision = (
        classifier.classify_poll(
            TimeoutError()
        )
    )

    assert (
        decision.disposition.value
        == "retry_safe"
    )
```

Không tạo provider submission mới.

---

# 17.100 Worker death while provider running

Nếu DB đã có:

```text
provider_job_id = X
status = PROVIDER_RUNNING
```

worker chết không thành ambiguous generation.

Provider job vẫn tồn tại.

Scheduler chỉ poll lại:

```text
same provider_job_id X
```

Đây là lý do async lifecycle production tốt hơn worker chờ 3 phút.

---

# 17.101 Cost accounting video

Video cost phải gắn với **provider submission attempt**, không poll.

```text
Video submit attempt #1
↓
provider job X
↓
poll 1
poll 2
poll 3
↓
success

CostEntry = ONE provider generation
```

Không:

```text
4 API calls
= 4 render costs
```

Poll requests có thể có infrastructure telemetry nhưng không phải generation cost.

---

# 17.102 `cost_entries` action

Suggested:

```text
scene_image_render
scene_image_qa

video_generation
video_provider_poll    optional telemetry only
```

Video generation cost key:

```text
video-render-attempt:{attempt_id}:provider-generation
```

---

# 17.103 Artifact layout

```text
work/artifacts/
└── {session}/
    └── {run}/
        └── scenes/
            └── S03/
                ├── projection/
                │   └── scene_projection.json
                │
                ├── image/
                │   ├── request.json
                │   ├── prompt.txt
                │   ├── output.png
                │   └── qa_report.json
                │
                ├── keyframes/
                │   └── start.json
                │
                ├── motion/
                │   ├── motion_spec.json
                │   └── motion_prompt.txt
                │
                └── video/
                    └── {render_id}/
                        └── attempt_001/
                            ├── submit_response.json
                            ├── poll_001.json
                            ├── poll_002.json
                            ├── clip.mp4
                            └── manifest.json
```

Không overwrite poll/provider responses.

---

# 17.104 Poll artifact ledger

Nên lưu từng poll nhẹ:

```json
{
  "provider_job_id": "...",
  "poll_no": 3,
  "status": "running",
  "progress": 0.61,
  "observed_at": "...",
  "response_hash": "..."
}
```

Đừng lưu base64/video bytes trong poll JSON.

---

# 17.105 SceneService tổng

```python
# media_runtime/scene/service.py

from __future__ import annotations


class SceneExecutionCompiler:
    def __init__(
        self,
        *,
        identity_loader,
        scene_projector,
        reference_selector,
        artifact_resolver,
        state_overlay_builder,
        constraint_composer,
        prompt_pipeline,
        scene_image_request_builder,
    ) -> None:
        self._identity_loader = (
            identity_loader
        )

        self._projector = (
            scene_projector
        )

        self._references = (
            reference_selector
        )

        self._artifact_resolver = (
            artifact_resolver
        )

        self._overlay = (
            state_overlay_builder
        )

        self._constraints = (
            constraint_composer
        )

        self._prompts = (
            prompt_pipeline
        )

        self._image_requests = (
            scene_image_request_builder
        )

    def compile_scene_image(
        self,
        *,
        packet,
        base_constraint_set,
        asset_id: str,
        image_state_role: str,
        image_provider_key: str,
        image_model_key: str,
        run_id: str,
        session_code: str,
        render_options,
    ):
        identity = (
            self._identity_loader.load(
                manifest_json=(
                    packet
                    .identity_lock_json
                ),

                expected_hash=(
                    packet
                    .identity_lock_hash
                ),
            )
        )

        projection = (
            self._projector.project(
                packet=packet,

                identity_lock=(
                    identity
                ),

                asset_id=asset_id,
            )
        )

        continuity_artifact = None

        if (
            packet
            .previous_scene_artifact_id
        ):
            continuity_artifact = (
                self._artifact_resolver
                .by_id(
                    packet
                    .previous_scene_artifact_id
                )
            )

            if (
                continuity_artifact.sha256
                != packet
                .previous_scene_artifact_hash
            ):
                raise RuntimeError(
                    "Previous scene artifact "
                    "hash mismatch."
                )

        selection = (
            self._references.select(
                projection=projection,

                identity_lock=identity,

                continuity_artifact=(
                    continuity_artifact
                ),
            )
        )

        overlay = self._overlay.build(
            projection,

            image_state_role=(
                image_state_role
            ),
        )

        scene_constraints = (
            self._constraints.compose(
                base=(
                    base_constraint_set
                ),

                projection=projection,

                overlay=overlay,
            )
        )

        prompt_result = (
            self._prompts.compile(
                constraint_set=(
                    scene_constraints
                ),

                provider_key=(
                    image_provider_key
                ),

                model_key=(
                    image_model_key
                ),

                presentation={
                    "visual_goal":
                        projection
                        .visual_goal,

                    "camera":
                        projection.camera,
                },

                references=selection,
            )
        )

        request = (
            self._image_requests.build(
                projection=projection,

                compiled_prompt=(
                    prompt_result
                    .compiled_prompt
                ),

                reference_selection=(
                    selection
                ),

                provider_key=(
                    image_provider_key
                ),

                model_key=(
                    image_model_key
                ),

                run_id=run_id,

                session_code=(
                    session_code
                ),

                width=(
                    render_options.width
                ),

                height=(
                    render_options.height
                ),

                aspect_ratio=(
                    render_options
                    .aspect_ratio
                ),

                quality=(
                    render_options.quality
                ),

                output_format=(
                    render_options
                    .output_format
                ),

                provider_options=(
                    render_options
                    .provider_options
                ),

                prompt_spec_hash=(
                    prompt_result
                    .prompt_spec_hash
                ),

                provider_prompt_plan_hash=(
                    prompt_result
                    .provider_prompt_plan_hash
                ),

                scene_constraint_set_hash=(
                    scene_constraints
                    .fingerprint()
                ),
            )
        )

        return {
            "projection":
                projection,

            "reference_selection":
                selection,

            "scene_constraint_set":
                scene_constraints,

            "prompt_result":
                prompt_result,

            "render_request":
                request,
        }
```

---

# 17.106 Video compilation service

```python
class VideoShotCompiler:
    def __init__(
        self,
        *,
        keyframe_builder,
        motion_compiler,
        motion_prompt_compiler,
        capability_registry,
        capability_projector,
        video_request_builder,
    ) -> None:
        self._keyframes = (
            keyframe_builder
        )

        self._motion = (
            motion_compiler
        )

        self._motion_prompts = (
            motion_prompt_compiler
        )

        self._capabilities = (
            capability_registry
        )

        self._provider_projection = (
            capability_projector
        )

        self._requests = (
            video_request_builder
        )

    def compile(
        self,
        *,
        projection,
        scene_render,
        scene_artifact,
        scene_qa_report,
        identity_constraints,
        constraint_set_hash: str,
        provider_key: str,
        model_key: str,
        aspect_ratio: str,
        resolution: str,
        provider_options: dict,
    ):
        keyframes = (
            self._keyframes
            .from_scene_image(
                projection=projection,

                scene_render=(
                    scene_render
                ),

                scene_artifact=(
                    scene_artifact
                ),

                qa_report=(
                    scene_qa_report
                ),
            )
        )

        motion_spec = (
            self._motion.compile(
                projection=projection,

                keyframes=keyframes,

                identity_constraints=(
                    identity_constraints
                ),
            )
        )

        motion_prompt = (
            self._motion_prompts
            .compile(
                motion_spec
            )
        )

        capability = (
            self._capabilities.get(
                provider_key,
                model_key,
            )
        )

        provider_plan = (
            self._provider_projection
            .project(
                capability=capability,

                motion_spec=(
                    motion_spec
                ),

                compiled_motion_prompt=(
                    motion_prompt
                ),

                keyframes=keyframes,
            )
        )

        request = (
            self._requests.build(
                projection=projection,

                keyframes=keyframes,

                motion_spec=motion_spec,

                provider_plan=(
                    provider_plan
                ),

                constraint_set_hash=(
                    constraint_set_hash
                ),

                aspect_ratio=(
                    aspect_ratio
                ),

                resolution=resolution,

                provider_options=(
                    provider_options
                ),
            )
        )

        return {
            "keyframes":
                keyframes,

            "motion_spec":
                motion_spec,

            "motion_prompt":
                motion_prompt,

            "provider_plan":
                provider_plan,

            "video_request":
                request,
        }
```

---

# 17.107 Full execution flow của một Scene

Ví dụ Scene 03:

```text
Laravel Frozen RenderPlan
    ↓
Scene S03
from_state = S02
to_state   = S03
transition = T03
identity_lock = IL-A
    ↓
SceneExecutionPacket
    ↓
Python verify IL-A
    ↓
SceneProjection SP03
    ↓
ReferenceSelector
    ├── Anchor
    ├── matching reference
    └── S02 approved scene image
    ↓
Scene State Overlay
    ↓
SceneConstraintSet
    ↓
PromptCompilerV1
    ↓
Scene Image RenderRequest IR03
    ↓
Part14
    ↓
scene_03.png
    ↓
Part15
    ↓
PASS
    ↓
VideoKeyframe KF03
    ↓
MotionSpec MS03
    from S02
    to S03
    ↓
MotionPrompt
    ↓
Veo/Kling capability projection
    ↓
VideoRenderRequest VR03
    ↓
Part14 SUBMIT
    ↓
provider_job_id
    ↓
PROVIDER_RUNNING
    ↓
worker free
    ↓
POLL
    ↓
clip_03.mp4
    ↓
Artifact SHA-256
    ↓
shot.video_ready
    ↓
human/shot approval
    ↓
commit state S03
    ↓
Scene S04 eligible
```

---

# 17.108 Những gì Python tuyệt đối không được làm

```text
Không đổi scene count.
Không đổi scene order.
Không đổi duration vì provider thích duration khác.
Không invent state transition.
Không thêm construction step.
Không bỏ state transition.
Không tự chọn IdentityLock mới.
Không lấy latest anchor.
Không dùng scene trước làm identity truth.
Không redesign failed scene.
Không tự fallback provider trong adapter.
Không commit world state.
```

---

# 17.109 State graph giúp giải quyết continuity thật

Trước đây continuity có thể chỉ là:

```text
"same yacht as previous scene"
```

Part17 biến thành:

```text
S03 requires:
bottom plating = installed
bottom frames  = installed
vertical frames = absent

S03 transition:
vertical frames:
absent → installed

S04 requires:
vertical frames = installed
```

Nếu Scene 03 chưa approved:

```text
S04 cannot execute
```

Đây mới là production state continuity.

---

# 17.110 Identity và State tách riêng

Một nguyên tắc cực quan trọng:

```text
IDENTITY
= thứ không được thay giữa scenes

STATE
= thứ được phép thay theo timeline
```

Ví dụ:

```text
IDENTITY:
length / beam
bow
stern
deck tiers
window topology
permanent openings

STATE:
bare plate
installed frames
scaffolding
paint stage
doors open/closed
workers/equipment
construction progress
```

Do đó:

```text
IdentityLock
+
StateGraph
```

giải quyết hai bài toán khác nhau.

---

# 17.111 Part17 invariant set

```text
1. RenderPlan quyết định scene semantics.
2. Python không thay scene count/order.
3. RenderPlan pin exact IdentityLock.
4. State graph được freeze cùng RenderPlan.
5. SceneProjection là derived IR, không design truth.
6. Scene state không mutate CanonicalDesignSpec.
7. Identity constraints không mutate theo state.
8. Scene image chỉ dùng approved IdentityLock refs.
9. Anchor luôn reference priority cao nhất.
10. Previous scene chỉ là state-continuity reference.
11. Previous scene không override identity.
12. Scene Image phải Part15 PASS trước keyframe.
13. Start keyframe là exact approved artifact.
14. MotionSpec chỉ derive từ frozen motion/state intent.
15. MotionSpec không invent cinematic actions.
16. Only declared state mutations may change.
17. P0–P5 identity constraints are prohibited changes.
18. Provider capability mismatch does not silently change duration.
19. Veo/Kling adapter execute only.
20. Provider submit retry/fallback remains Part14 policy.
21. Video submit timeout is ambiguous.
22. Video poll timeout is safe to poll again.
23. Poll does not increment provider attempt_count.
24. Async provider jobs release worker lease.
25. provider_job_id is persisted before future polling.
26. Video artifact is SHA-256 verified.
27. Scene state advances only after accepted shot.
28. Failed/rejected shot does not advance state.
29. Scene N+1 cannot consume uncommitted state N.
30. Same frozen input/version => same SceneProjection/MotionSpec/request hashes.
```

---

# 17.112 Hash lineage sau Part17

Toàn bộ chain giờ thành:

```text
Canonical JSON
│
└─ canonical_hash
      ↓
IdentityLockManifest
│
└─ identity_lock_hash
      ↓
Frozen RenderPlan
│
└─ render_plan_hash
      ↓
SceneProjection
│
└─ scene_projection_hash
      ↓
SceneConstraintSet
│
└─ constraint_set_hash
      ↓
PromptSpec
│
└─ prompt_spec_hash
      ↓
Compiled Scene Prompt
│
└─ prompt_hash
      ↓
Scene Image Request
│
└─ request_hash
      ↓
Scene Image
│
└─ artifact_hash
      ↓
QAReport
│
└─ qa_report_hash
      ↓
VideoKeyframe
│
└─ keyframe artifact_hash
      ↓
MotionSpec
│
└─ motion_spec_hash
      ↓
Video Provider Plan
      ↓
VideoRenderRequest
│
└─ video_request_hash
      ↓
Veo / Kling job
│
└─ provider_job_id
      ↓
Video Artifact
│
└─ video_artifact_hash
```

Từ `clip_03.mp4` bạn có thể truy ngược:

```text
video_artifact_hash
→ provider job
→ video_request_hash
→ MotionSpec
→ keyframe
→ scene image QA
→ SceneProjection
→ StateGraph
→ RenderPlan
→ IdentityLock
→ Canonical revision
```

Đây là lineage đủ mạnh để debug trường hợp kiểu:

> “Tại sao Scene 7 tự nhiên tàu có 5 tầng trong khi anchor là 4?”

Hệ thống có thể xác định drift xảy ra ở **scene-image render**, **video animation**, hay bản thân frozen semantic plan — thay vì chỉ nhìn output cuối và đoán.

Phần còn lại sau Part17 là **Part 18 — `Final Composition + Shot Selection + Audio/Subtitles + Immutable Final Manifest + FFmpeg Composition + End-to-End Integration + Production Tests`**. Part18 sẽ không tạo lại scene hay identity nữa; nó chỉ chọn **approved clip artifacts theo exact hashes**, ghép theo frozen timeline, đóng băng `FinalCompositionManifest`, rồi xuất final video reproducible.
