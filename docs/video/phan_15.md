Dưới đây là **Phần 15 hoàn chỉnh** theo đúng architecture đã khóa. Phần này không đánh giá kiểu “ảnh đẹp hay xấu”; nó kiểm từng constraint có `visual_verification=true`, tạo `QAReport` có lineage, định vị lỗi, rồi chỉ tạo **targeted repair** cho các constraint fail.

Một nguyên tắc phải khóa trước:

```text
Vision QA KHÔNG được:
- redesign object
- sửa CanonicalDesignSpec
- sửa ConstraintSet
- tự thay đổi count/topology/proportion
- tự sinh prompt mới từ đầu
- tự quyết định provider fallback

Vision QA chỉ:
Rendered Artifact
   +
Frozen visual constraints
   ↓
Verification
   ↓
QAReport
   ↓
PASS / FAIL / REVIEW
```

Repair cũng chỉ là derived render request mới:

```text
failed constraints
        ↓
RepairScope
        ↓
RepairPromptSpec
        ↓
Targeted Repair RenderRequest
        ↓
new request_hash
        ↓
render
        ↓
Vision QA again
```

Canonical revision vẫn nguyên vẹn.

---

# PHẦN 15 — package structure

```text
media_runtime/
├── qa/
│   ├── __init__.py
│   ├── enums.py
│   ├── exceptions.py
│   │
│   ├── contract/
│   │   ├── __init__.py
│   │   ├── visual_constraint.py
│   │   ├── verification_target.py
│   │   ├── qa_request.py
│   │   └── qa_report.py
│   │
│   ├── extraction/
│   │   ├── __init__.py
│   │   ├── verification_target_builder.py
│   │   └── source_constraint_index.py
│   │
│   ├── vision/
│   │   ├── __init__.py
│   │   ├── provider.py
│   │   ├── schema.py
│   │   ├── prompt_builder.py
│   │   ├── response_parser.py
│   │   └── service.py
│   │
│   ├── verification/
│   │   ├── __init__.py
│   │   ├── result.py
│   │   ├── deterministic_validator.py
│   │   ├── report_builder.py
│   │   └── gate.py
│   │
│   ├── localization/
│   │   ├── __init__.py
│   │   ├── failure_cluster.py
│   │   ├── failure_localizer.py
│   │   └── repair_scope.py
│   │
│   ├── repair/
│   │   ├── __init__.py
│   │   ├── enums.py
│   │   ├── policy.py
│   │   ├── repair_plan.py
│   │   ├── repair_prompt_compiler.py
│   │   ├── repair_request_builder.py
│   │   └── service.py
│   │
│   ├── artifact/
│   │   ├── __init__.py
│   │   ├── hashing.py
│   │   ├── serializer.py
│   │   └── writer.py
│   │
│   └── service.py
│
└── tests/qa/
    ├── test_target_builder.py
    ├── test_qa_parser.py
    ├── test_deterministic_validator.py
    ├── test_qa_gate.py
    ├── test_failure_localizer.py
    ├── test_repair_scope.py
    ├── test_repair_prompt_compiler.py
    ├── test_repair_request_builder.py
    └── test_end_to_end_qa.py
```

Laravel:

```text
app/Video/QA/
├── Enums/
│   ├── QaStatus.php
│   ├── QaDecision.php
│   └── QaRepairStatus.php
│
├── Models/
│   ├── RenderQaRun.php
│   ├── RenderQaFinding.php
│   └── RenderRepair.php
│
├── DTO/
│   ├── QaCheckpointPayload.php
│   └── RepairDispatchPayload.php
│
├── Services/
│   ├── QaCheckpointService.php
│   ├── QaDecisionService.php
│   ├── RepairDispatchService.php
│   └── QaApprovalService.php
│
└── Controllers/
    └── RenderQaController.php
```

---

# 15.1 QA enums

`media_runtime/qa/enums.py`

```python
from __future__ import annotations

from enum import StrEnum


class QaDecision(StrEnum):
    PASS = "pass"
    FAIL = "fail"
    REVIEW = "review"


class ConstraintVerificationStatus(StrEnum):
    PASS = "pass"
    FAIL = "fail"
    UNCERTAIN = "uncertain"
    NOT_VISIBLE = "not_visible"
    NOT_APPLICABLE = "not_applicable"


class QaSeverity(StrEnum):
    HARD = "hard"
    SOFT = "soft"


class QaFailureType(StrEnum):
    COUNT_MISMATCH = "count_mismatch"
    PROPORTION_MISMATCH = "proportion_mismatch"
    POSITION_MISMATCH = "position_mismatch"
    TOPOLOGY_MISMATCH = "topology_mismatch"
    GEOMETRY_MISMATCH = "geometry_mismatch"
    VISIBILITY_MISMATCH = "visibility_mismatch"
    MATERIAL_MISMATCH = "material_mismatch"
    STATE_MISMATCH = "state_mismatch"
    EXCLUSION_VIOLATION = "exclusion_violation"
    CAMERA_MISMATCH = "camera_mismatch"
    IDENTITY_DRIFT = "identity_drift"
    NOT_VISIBLE = "not_visible"
    UNCERTAIN = "uncertain"
    UNKNOWN = "unknown"


class QaRepairDecision(StrEnum):
    NO_REPAIR = "no_repair"
    TARGETED_REPAIR = "targeted_repair"
    FULL_RERENDER = "full_rerender"
    HUMAN_REVIEW = "human_review"


class QaEvidenceType(StrEnum):
    VISUAL_OBSERVATION = "visual_observation"
    COUNT_OBSERVATION = "count_observation"
    SPATIAL_OBSERVATION = "spatial_observation"
    MATERIAL_OBSERVATION = "material_observation"
    STATE_OBSERVATION = "state_observation"
```

---

# 15.2 Exceptions

`qa/exceptions.py`

```python
from __future__ import annotations


class QaRuntimeError(RuntimeError):
    pass


class QaInputIntegrityError(QaRuntimeError):
    pass


class QaProviderError(QaRuntimeError):
    pass


class QaResponseValidationError(QaRuntimeError):
    pass


class QaLineageError(QaRuntimeError):
    pass


class RepairPlanningError(QaRuntimeError):
    pass


class RepairBudgetExceeded(QaRuntimeError):
    pass
```

---

# 15.3 Visual verification constraint

Ta không đưa toàn bộ `ConstraintSet` sang Vision model.

Chỉ những constraint:

```python
visual_verification is True
```

và các constraint cần để giữ identity context.

`qa/contract/visual_constraint.py`

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
class VisualConstraint:
    constraint_id: str

    semantic_key: str

    primitive: ConstraintPrimitive

    priority: ConstraintPriority

    severity: ConstraintSeverity

    expected_value: Any

    description: str | None

    source_path: str | None

    source_ids: tuple[str, ...]

    prompt_instruction_ids: tuple[str, ...]

    visual_verification: bool

    def __post_init__(self) -> None:
        if not self.constraint_id:
            raise ValueError(
                "constraint_id cannot be empty"
            )

        if not self.semantic_key:
            raise ValueError(
                "semantic_key cannot be empty"
            )

        if not self.visual_verification:
            raise ValueError(
                "VisualConstraint requires "
                "visual_verification=true"
            )
```

---

# 15.4 VerificationTarget

Một QA target có thể gom nhiều source constraints đã coalesce.

`qa/contract/verification_target.py`

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
class VerificationTarget:
    target_id: str

    semantic_key: str

    primitive: ConstraintPrimitive

    priority: ConstraintPriority

    severity: ConstraintSeverity

    expected_value: Any

    verification_question: str

    pass_criteria: str

    fail_criteria: str

    source_constraint_ids: tuple[str, ...]

    source_instruction_ids: tuple[str, ...]

    source_paths: tuple[str, ...]
```

Vision model kiểm target, không tự tạo target.

---

# 15.5 Source constraint index

`qa/extraction/source_constraint_index.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)


@dataclass(
    frozen=True,
    slots=True,
)
class SourceConstraintIndex:
    instruction_ids_by_constraint: dict[
        str,
        tuple[str, ...],
    ]


class SourceConstraintIndexBuilder:
    def build(
        self,
        *,
        constraint_set: ConstraintSet,
        prompt_spec: PromptSpec,
    ) -> SourceConstraintIndex:
        valid_constraint_ids = {
            item.id
            for item in constraint_set.constraints
        }

        mapping: dict[
            str,
            list[str],
        ] = {
            constraint_id: []
            for constraint_id
            in valid_constraint_ids
        }

        for section in prompt_spec.sections:
            for instruction in (
                section.instructions
            ):
                for constraint_id in (
                    instruction
                    .source_constraint_ids
                ):
                    if (
                        constraint_id
                        not in valid_constraint_ids
                    ):
                        continue

                    mapping[
                        constraint_id
                    ].append(
                        instruction.id
                    )

        return SourceConstraintIndex(
            instruction_ids_by_constraint={
                key: tuple(
                    sorted(
                        set(value)
                    )
                )
                for key, value
                in mapping.items()
            }
        )
```

---

# 15.6 Verification target builder

Đây là deterministic.

Không dùng AI để quyết định cần kiểm gì.

`qa/extraction/verification_target_builder.py`

```python
from __future__ import annotations

import hashlib

from media_runtime.constraints.constraint_set import (
    ConstraintSet,
)
from media_runtime.constraints.enums import (
    ConstraintPrimitive,
)
from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)
from media_runtime.qa.contract.verification_target import (
    VerificationTarget,
)
from media_runtime.qa.extraction.source_constraint_index import (
    SourceConstraintIndexBuilder,
)


class VerificationTargetBuilder:
    VERSION = "qa-target-builder-v1"

    def __init__(self) -> None:
        self._index_builder = (
            SourceConstraintIndexBuilder()
        )

    def build(
        self,
        *,
        constraint_set: ConstraintSet,
        prompt_spec: PromptSpec,
    ) -> tuple[
        VerificationTarget,
        ...,
    ]:
        index = self._index_builder.build(
            constraint_set=constraint_set,
            prompt_spec=prompt_spec,
        )

        targets: list[
            VerificationTarget
        ] = []

        for constraint in (
            constraint_set.constraints
        ):
            if not (
                constraint
                .visual_verification
            ):
                continue

            targets.append(
                VerificationTarget(
                    target_id=(
                        self._stable_target_id(
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

                    verification_question=(
                        self._question(
                            constraint.primitive,
                            constraint.semantic_key,
                        )
                    ),

                    pass_criteria=(
                        self._pass_criteria(
                            constraint.primitive
                        )
                    ),

                    fail_criteria=(
                        self._fail_criteria(
                            constraint.primitive
                        )
                    ),

                    source_constraint_ids=(
                        constraint.id,
                    ),

                    source_instruction_ids=(
                        index
                        .instruction_ids_by_constraint
                        .get(
                            constraint.id,
                            (),
                        )
                    ),

                    source_paths=tuple(
                        item
                        for item in (
                            constraint.source_path,
                            constraint.subject_path,
                        )
                        if item
                    ),
                )
            )

        return tuple(
            sorted(
                targets,
                key=lambda item: (
                    int(item.priority),
                    item.semantic_key,
                    item.target_id,
                ),
            )
        )

    @staticmethod
    def _stable_target_id(
        constraint_id: str,
        semantic_key: str,
    ) -> str:
        raw = (
            constraint_id
            + "|"
            + semantic_key
        )

        digest = hashlib.sha256(
            raw.encode("utf-8")
        ).hexdigest()[:16]

        return "QT-" + digest

    @staticmethod
    def _question(
        primitive: ConstraintPrimitive,
        semantic_key: str,
    ) -> str:
        label = semantic_key.replace(
            "_",
            " ",
        )

        questions = {
            ConstraintPrimitive.COUNT:
                (
                    "Does the rendered image show "
                    "the exact declared count for "
                    f"{label}?"
                ),

            ConstraintPrimitive.PROPORTION:
                (
                    "Does the visible proportion "
                    "match the declared proportion "
                    f"for {label}?"
                ),

            ConstraintPrimitive.POSITION:
                (
                    "Is the declared position or "
                    "relative placement correct for "
                    f"{label}?"
                ),

            ConstraintPrimitive.CONNECTIVITY:
                (
                    "Is the declared physical or "
                    "visual connectivity preserved "
                    f"for {label}?"
                ),

            ConstraintPrimitive.CONTINUITY:
                (
                    "Is the declared continuous "
                    "geometry visibly continuous "
                    f"for {label}?"
                ),

            ConstraintPrimitive.GEOMETRY:
                (
                    "Does the visible geometry match "
                    "the declared geometry for "
                    f"{label}?"
                ),

            ConstraintPrimitive.MATERIAL:
                (
                    "Does the visible material state "
                    "match the declaration for "
                    f"{label}?"
                ),

            ConstraintPrimitive.STATE:
                (
                    "Does the rendered subject show "
                    "the declared current state for "
                    f"{label}?"
                ),

            ConstraintPrimitive.EXCLUSION:
                (
                    "Is the forbidden feature absent "
                    f"for {label}?"
                ),

            ConstraintPrimitive.VISIBILITY:
                (
                    "Does the visibility condition "
                    "match the declared requirement "
                    f"for {label}?"
                ),
        }

        return questions.get(
            primitive,
            (
                "Does the rendered image visually "
                "satisfy the declared constraint "
                f"for {label}?"
            ),
        )

    @staticmethod
    def _pass_criteria(
        primitive: ConstraintPrimitive,
    ) -> str:
        if (
            primitive
            == ConstraintPrimitive.COUNT
        ):
            return (
                "The required visible count is "
                "unambiguous and equals the expected "
                "value."
            )

        if (
            primitive
            == ConstraintPrimitive.EXCLUSION
        ):
            return (
                "The forbidden feature is not visibly "
                "present."
            )

        return (
            "The visible evidence is consistent with "
            "the expected structured value and no "
            "contradicting feature is visible."
        )

    @staticmethod
    def _fail_criteria(
        primitive: ConstraintPrimitive,
    ) -> str:
        if (
            primitive
            == ConstraintPrimitive.COUNT
        ):
            return (
                "A different visible count is clearly "
                "observable."
            )

        if (
            primitive
            == ConstraintPrimitive.EXCLUSION
        ):
            return (
                "The forbidden feature is clearly "
                "visible."
            )

        return (
            "The visible evidence clearly contradicts "
            "the expected structured value."
        )
```

---

# 15.7 QA request

`qa/contract/qa_request.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.qa.contract.verification_target import (
    VerificationTarget,
)


@dataclass(
    frozen=True,
    slots=True,
)
class QaImageArtifact:
    artifact_id: str

    path: str

    sha256: str

    mime_type: str


@dataclass(
    frozen=True,
    slots=True,
)
class QaRequest:
    version: str

    qa_run_id: str

    render_id: str

    asset_id: str

    source_revision_id: str

    canonical_hash: str

    constraint_set_hash: str

    prompt_spec_hash: str

    request_hash: str

    artifact: QaImageArtifact

    targets: tuple[
        VerificationTarget,
        ...,
    ]
```

---

# 15.8 Verify artifact trước Vision QA

Không trust ảnh chỉ vì render DB nói succeeded.

`qa/artifact/hashing.py`

```python
from __future__ import annotations

import hashlib
from pathlib import Path

from media_runtime.qa.exceptions import (
    QaInputIntegrityError,
)


class QaArtifactIntegrityVerifier:
    def verify(
        self,
        *,
        path: Path,
        expected_sha256: str,
    ) -> None:
        if not path.is_file():
            raise QaInputIntegrityError(
                "QA image artifact does not exist: "
                + str(path)
            )

        digest = hashlib.sha256()

        with path.open("rb") as handle:
            while chunk := handle.read(
                1024 * 1024
            ):
                digest.update(chunk)

        actual = digest.hexdigest()

        if not hashlib.compare_digest(
            actual,
            expected_sha256,
        ):
            raise QaInputIntegrityError(
                "QA artifact SHA-256 mismatch."
            )
```

---

# 15.9 Vision output contract

Vision model tuyệt đối không được trả prose tự do.

Ta yêu cầu structured result.

`qa/vision/schema.py`

```python
from __future__ import annotations


VISION_QA_SCHEMA = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "artifact_observable",
        "overall_notes",
        "results",
    ],
    "properties": {
        "artifact_observable": {
            "type": "boolean",
        },

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
                    "observed_value",
                    "evidence",
                    "failure_type",
                ],

                "properties": {
                    "target_id": {
                        "type": "string",
                    },

                    "status": {
                        "type": "string",

                        "enum": [
                            "pass",
                            "fail",
                            "uncertain",
                            "not_visible",
                            "not_applicable",
                        ],
                    },

                    "confidence": {
                        "type": "number",
                        "minimum": 0,
                        "maximum": 1,
                    },

                    "observed_value": {},

                    "evidence": {
                        "type": "string",
                    },

                    "failure_type": {
                        "type": [
                            "string",
                            "null",
                        ],

                        "enum": [
                            None,
                            "count_mismatch",
                            "proportion_mismatch",
                            "position_mismatch",
                            "topology_mismatch",
                            "geometry_mismatch",
                            "visibility_mismatch",
                            "material_mismatch",
                            "state_mismatch",
                            "exclusion_violation",
                            "camera_mismatch",
                            "identity_drift",
                            "not_visible",
                            "uncertain",
                            "unknown",
                        ],
                    },
                },
            },
        },
    },
}
```

---

# 15.10 Vision provider abstraction

Không gắn QA core với GPT/Gemini.

`qa/vision/provider.py`

```python
from __future__ import annotations

from typing import (
    Any,
    Protocol,
)


class VisionQaProvider(
    Protocol
):
    @property
    def provider_key(
        self,
    ) -> str:
        ...

    @property
    def model_key(
        self,
    ) -> str:
        ...

    def inspect(
        self,
        *,
        image_path: str,
        mime_type: str,
        system_prompt: str,
        input_payload: dict[
            str,
            Any,
        ],
        output_schema: dict[
            str,
            Any,
        ],
    ) -> dict[
        str,
        Any,
    ]:
        ...
```

---

# 15.11 Vision QA prompt

Đây là chỗ rất quan trọng.

Vision model phải đóng vai **verifier**, không creative reviewer.

`qa/vision/prompt_builder.py`

```python
from __future__ import annotations

from typing import Any

from media_runtime.qa.contract.qa_request import (
    QaRequest,
)


class VisionQaPromptBuilder:
    VERSION = "vision-qa-prompt-v1"

    SYSTEM_PROMPT = """
You are a strict visual verification engine.

Your only task is to inspect the supplied rendered
image against a predefined list of verification
targets.

You are not a designer.
You are not a creative critic.
You must not redesign the subject.
You must not suggest new features.
You must not infer requirements that were not supplied.

For every target:

1. Compare only visible evidence against the supplied
   expected value and pass/fail criteria.

2. Return PASS only when visible evidence supports the
   requirement with sufficient confidence.

3. Return FAIL only when visible evidence clearly
   contradicts the requirement.

4. Return NOT_VISIBLE when the camera/view prevents
   reliable inspection.

5. Return UNCERTAIN when the relevant feature is
   visible but evidence is insufficient for reliable
   judgment.

6. Never convert NOT_VISIBLE into FAIL merely because
   you cannot see the feature.

7. Do not use real-world assumptions to fill missing
   information.

8. Respect exact counts. Do not approximate "about"
   when an exact count is required.

9. For exclusions, PASS means the prohibited feature
   is visibly absent to the degree allowed by the
   image. If the relevant region cannot be inspected,
   return NOT_VISIBLE rather than PASS.

10. Evaluate every target_id exactly once.

Return only the structured object required by the
provided output schema.
""".strip()

    def build(
        self,
        request: QaRequest,
    ) -> tuple[
        str,
        dict[str, Any],
    ]:
        targets = []

        for target in (
            request.targets
        ):
            targets.append(
                {
                    "target_id":
                        target.target_id,

                    "semantic_key":
                        target.semantic_key,

                    "primitive":
                        target.primitive.value,

                    "severity":
                        target.severity.value,

                    "priority":
                        int(
                            target.priority
                        ),

                    "expected_value":
                        target.expected_value,

                    "verification_question":
                        (
                            target
                            .verification_question
                        ),

                    "pass_criteria":
                        target.pass_criteria,

                    "fail_criteria":
                        target.fail_criteria,
                }
            )

        payload = {
            "qa_run_id":
                request.qa_run_id,

            "render_id":
                request.render_id,

            "artifact_id":
                request.artifact.artifact_id,

            "verification_targets":
                targets,
        }

        return (
            self.SYSTEM_PROMPT,
            payload,
        )
```

---

# 15.12 Raw result DTO

`qa/verification/result.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
    QaFailureType,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ConstraintVerificationResult:
    target_id: str

    status: ConstraintVerificationStatus

    confidence: float

    observed_value: Any

    evidence: str

    failure_type: (
        QaFailureType | None
    )


@dataclass(
    frozen=True,
    slots=True,
)
class VisionQaResult:
    artifact_observable: bool

    overall_notes: str

    results: tuple[
        ConstraintVerificationResult,
        ...,
    ]

    provider_key: str

    model_key: str
```

---

# 15.13 Strict response parser

`qa/vision/response_parser.py`

```python
from __future__ import annotations

from typing import Any

from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
    QaFailureType,
)
from media_runtime.qa.exceptions import (
    QaResponseValidationError,
)
from media_runtime.qa.verification.result import (
    ConstraintVerificationResult,
    VisionQaResult,
)


class VisionQaResponseParser:
    def parse(
        self,
        *,
        raw: dict[str, Any],
        expected_target_ids: tuple[
            str,
            ...,
        ],
        provider_key: str,
        model_key: str,
    ) -> VisionQaResult:
        if not isinstance(
            raw,
            dict,
        ):
            raise QaResponseValidationError(
                "Vision QA response must be object."
            )

        observable = raw.get(
            "artifact_observable"
        )

        if not isinstance(
            observable,
            bool,
        ):
            raise QaResponseValidationError(
                "artifact_observable must be boolean."
            )

        notes = raw.get(
            "overall_notes"
        )

        if not isinstance(
            notes,
            str,
        ):
            raise QaResponseValidationError(
                "overall_notes must be string."
            )

        raw_results = raw.get(
            "results"
        )

        if not isinstance(
            raw_results,
            list,
        ):
            raise QaResponseValidationError(
                "results must be list."
            )

        expected = set(
            expected_target_ids
        )

        seen: set[str] = set()

        results = []

        for item in raw_results:
            if not isinstance(
                item,
                dict,
            ):
                raise QaResponseValidationError(
                    "QA result item must be object."
                )

            target_id = item.get(
                "target_id"
            )

            if not isinstance(
                target_id,
                str,
            ):
                raise QaResponseValidationError(
                    "target_id must be string."
                )

            if target_id not in expected:
                raise QaResponseValidationError(
                    "Vision returned unknown "
                    f"target_id={target_id}"
                )

            if target_id in seen:
                raise QaResponseValidationError(
                    "Duplicate QA target result: "
                    + target_id
                )

            seen.add(
                target_id
            )

            try:
                status = (
                    ConstraintVerificationStatus(
                        item["status"]
                    )
                )
            except Exception as exc:
                raise QaResponseValidationError(
                    "Invalid QA status for "
                    + target_id
                ) from exc

            confidence = item.get(
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
                or confidence < 0
                or confidence > 1
            ):
                raise QaResponseValidationError(
                    "Invalid confidence for "
                    + target_id
                )

            evidence = item.get(
                "evidence"
            )

            if not isinstance(
                evidence,
                str,
            ):
                raise QaResponseValidationError(
                    "Evidence must be string."
                )

            raw_failure = item.get(
                "failure_type"
            )

            failure_type = None

            if raw_failure is not None:
                try:
                    failure_type = (
                        QaFailureType(
                            raw_failure
                        )
                    )
                except ValueError as exc:
                    raise QaResponseValidationError(
                        "Invalid failure_type."
                    ) from exc

            if (
                status
                == ConstraintVerificationStatus.FAIL
                and failure_type is None
            ):
                raise QaResponseValidationError(
                    "FAIL result requires failure_type."
                )

            results.append(
                ConstraintVerificationResult(
                    target_id=target_id,

                    status=status,

                    confidence=float(
                        confidence
                    ),

                    observed_value=(
                        item.get(
                            "observed_value"
                        )
                    ),

                    evidence=evidence.strip(),

                    failure_type=(
                        failure_type
                    ),
                )
            )

        missing = expected - seen

        if missing:
            raise QaResponseValidationError(
                "Vision omitted QA targets: "
                + ", ".join(
                    sorted(missing)
                )
            )

        return VisionQaResult(
            artifact_observable=(
                observable
            ),

            overall_notes=notes.strip(),

            results=tuple(
                sorted(
                    results,
                    key=lambda item:
                        item.target_id,
                )
            ),

            provider_key=provider_key,

            model_key=model_key,
        )
```

---

# 15.14 Vision service

`qa/vision/service.py`

```python
from __future__ import annotations

from media_runtime.qa.contract.qa_request import (
    QaRequest,
)
from media_runtime.qa.vision.prompt_builder import (
    VisionQaPromptBuilder,
)
from media_runtime.qa.vision.response_parser import (
    VisionQaResponseParser,
)
from media_runtime.qa.vision.schema import (
    VISION_QA_SCHEMA,
)


class VisionQaInspectionService:
    def __init__(
        self,
        *,
        provider,
        prompt_builder: VisionQaPromptBuilder,
        parser: VisionQaResponseParser,
    ) -> None:
        self._provider = provider
        self._prompt_builder = (
            prompt_builder
        )
        self._parser = parser

    def inspect(
        self,
        request: QaRequest,
    ):
        system_prompt, payload = (
            self._prompt_builder.build(
                request
            )
        )

        raw = self._provider.inspect(
            image_path=(
                request.artifact.path
            ),

            mime_type=(
                request
                .artifact
                .mime_type
            ),

            system_prompt=(
                system_prompt
            ),

            input_payload=payload,

            output_schema=(
                VISION_QA_SCHEMA
            ),
        )

        return self._parser.parse(
            raw=raw,

            expected_target_ids=tuple(
                item.target_id
                for item
                in request.targets
            ),

            provider_key=(
                self._provider
                .provider_key
            ),

            model_key=(
                self._provider
                .model_key
            ),
        )
```

---

# 15.15 Không để Vision tự quyết định PASS tổng

Vision chỉ trả từng target.

**Final QA decision phải deterministic.**

Đây là cực kỳ quan trọng.

---

# 15.16 QA policy

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class QaGatePolicy:
    hard_pass_min_confidence: float = 0.80

    soft_pass_min_confidence: float = 0.70

    uncertain_requires_review: bool = True

    hard_not_visible_requires_review: bool = True

    soft_not_visible_allowed: bool = True

    maximum_soft_failures: int = 0

    max_targeted_repairs: int = 2
```

---

# 15.17 Deterministic verification validator

Vision có thể trả:

```text
status=PASS
confidence=.32
```

Ta không nhận.

`qa/verification/deterministic_validator.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.enums import (
    ConstraintSeverity,
)
from media_runtime.qa.contract.verification_target import (
    VerificationTarget,
)
from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
)
from media_runtime.qa.exceptions import (
    QaResponseValidationError,
)
from media_runtime.qa.verification.result import (
    VisionQaResult,
)


class DeterministicQaValidator:
    def validate(
        self,
        *,
        targets: tuple[
            VerificationTarget,
            ...,
        ],
        result: VisionQaResult,
    ) -> None:
        target_map = {
            item.target_id: item
            for item in targets
        }

        if not result.artifact_observable:
            return

        for verification in (
            result.results
        ):
            target = target_map[
                verification.target_id
            ]

            if (
                verification.status
                == ConstraintVerificationStatus.FAIL
                and not verification.evidence
            ):
                raise QaResponseValidationError(
                    "Failed QA result has no evidence."
                )

            if (
                target.severity
                == ConstraintSeverity.HARD
                and verification.status
                == ConstraintVerificationStatus.NOT_APPLICABLE
            ):
                raise QaResponseValidationError(
                    "Hard visual constraint cannot "
                    "be marked not_applicable by "
                    "Vision model."
                )
```

---

# 15.18 QA finding DTO

`qa/contract/qa_report.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.constraints.enums import (
    ConstraintPriority,
    ConstraintPrimitive,
    ConstraintSeverity,
)
from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
    QaDecision,
    QaFailureType,
    QaRepairDecision,
)


@dataclass(
    frozen=True,
    slots=True,
)
class QaFinding:
    target_id: str

    semantic_key: str

    primitive: ConstraintPrimitive

    priority: ConstraintPriority

    severity: ConstraintSeverity

    status: ConstraintVerificationStatus

    confidence: float

    expected_value: Any

    observed_value: Any

    evidence: str

    failure_type: (
        QaFailureType | None
    )

    source_constraint_ids: tuple[
        str,
        ...,
    ]

    source_instruction_ids: tuple[
        str,
        ...,
    ]

    source_paths: tuple[
        str,
        ...,
    ]


@dataclass(
    frozen=True,
    slots=True,
)
class QaReport:
    version: str

    qa_run_id: str

    render_id: str

    asset_id: str

    source_revision_id: str

    canonical_hash: str

    constraint_set_hash: str

    prompt_spec_hash: str

    request_hash: str

    artifact_id: str

    artifact_hash: str

    vision_provider_key: str

    vision_model_key: str

    decision: QaDecision

    repair_decision: QaRepairDecision

    findings: tuple[
        QaFinding,
        ...,
    ]

    hard_fail_count: int

    soft_fail_count: int

    uncertain_count: int

    not_visible_count: int

    qa_report_hash: str
```

---

# 15.19 QA gate

`qa/verification/gate.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.constraints.enums import (
    ConstraintSeverity,
)
from media_runtime.qa.contract.qa_report import (
    QaFinding,
)
from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
    QaDecision,
    QaRepairDecision,
)
from media_runtime.qa.policy import (
    QaGatePolicy,
)


@dataclass(
    frozen=True,
    slots=True,
)
class QaGateResult:
    decision: QaDecision

    repair_decision: QaRepairDecision

    hard_fail_count: int

    soft_fail_count: int

    uncertain_count: int

    not_visible_count: int


class QaGate:
    def __init__(
        self,
        policy: QaGatePolicy,
    ) -> None:
        self._policy = policy

    def decide(
        self,
        findings: tuple[
            QaFinding,
            ...,
        ],
    ) -> QaGateResult:
        hard_failures = []
        soft_failures = []
        uncertain = []
        not_visible = []

        for finding in findings:
            status = finding.status

            if (
                status
                == ConstraintVerificationStatus.FAIL
            ):
                if (
                    finding.severity
                    == ConstraintSeverity.HARD
                ):
                    hard_failures.append(
                        finding
                    )
                else:
                    soft_failures.append(
                        finding
                    )

            elif (
                status
                == ConstraintVerificationStatus.UNCERTAIN
            ):
                uncertain.append(
                    finding
                )

            elif (
                status
                == ConstraintVerificationStatus.NOT_VISIBLE
            ):
                not_visible.append(
                    finding
                )

            elif (
                status
                == ConstraintVerificationStatus.PASS
            ):
                min_confidence = (
                    self._policy
                    .hard_pass_min_confidence
                    if finding.severity
                    == ConstraintSeverity.HARD
                    else self._policy
                    .soft_pass_min_confidence
                )

                if (
                    finding.confidence
                    < min_confidence
                ):
                    uncertain.append(
                        finding
                    )

        if hard_failures:
            return QaGateResult(
                decision=QaDecision.FAIL,

                repair_decision=(
                    QaRepairDecision
                    .TARGETED_REPAIR
                ),

                hard_fail_count=len(
                    hard_failures
                ),

                soft_fail_count=len(
                    soft_failures
                ),

                uncertain_count=len(
                    uncertain
                ),

                not_visible_count=len(
                    not_visible
                ),
            )

        hard_not_visible = [
            item
            for item in not_visible
            if item.severity
            == ConstraintSeverity.HARD
        ]

        if (
            hard_not_visible
            and self._policy
            .hard_not_visible_requires_review
        ):
            return QaGateResult(
                decision=(
                    QaDecision.REVIEW
                ),

                repair_decision=(
                    QaRepairDecision
                    .HUMAN_REVIEW
                ),

                hard_fail_count=0,

                soft_fail_count=len(
                    soft_failures
                ),

                uncertain_count=len(
                    uncertain
                ),

                not_visible_count=len(
                    not_visible
                ),
            )

        if (
            uncertain
            and self._policy
            .uncertain_requires_review
        ):
            return QaGateResult(
                decision=(
                    QaDecision.REVIEW
                ),

                repair_decision=(
                    QaRepairDecision
                    .HUMAN_REVIEW
                ),

                hard_fail_count=0,

                soft_fail_count=len(
                    soft_failures
                ),

                uncertain_count=len(
                    uncertain
                ),

                not_visible_count=len(
                    not_visible
                ),
            )

        if (
            len(soft_failures)
            > self._policy
            .maximum_soft_failures
        ):
            return QaGateResult(
                decision=QaDecision.FAIL,

                repair_decision=(
                    QaRepairDecision
                    .TARGETED_REPAIR
                ),

                hard_fail_count=0,

                soft_fail_count=len(
                    soft_failures
                ),

                uncertain_count=0,

                not_visible_count=len(
                    not_visible
                ),
            )

        return QaGateResult(
            decision=QaDecision.PASS,

            repair_decision=(
                QaRepairDecision.NO_REPAIR
            ),

            hard_fail_count=0,

            soft_fail_count=len(
                soft_failures
            ),

            uncertain_count=0,

            not_visible_count=len(
                not_visible
            ),
        )
```

---

# 15.20 Report builder

`qa/verification/report_builder.py`

```python
from __future__ import annotations

import hashlib
import json

from dataclasses import replace
from enum import Enum
from typing import Any

from media_runtime.qa.contract.qa_report import (
    QaFinding,
    QaReport,
)


class QaReportBuilder:
    VERSION = "qa-report-v1"

    def build(
        self,
        *,
        qa_request,
        vision_result,
        gate_result,
    ) -> QaReport:
        targets = {
            item.target_id: item
            for item in qa_request.targets
        }

        findings = []

        for verification in (
            vision_result.results
        ):
            target = targets[
                verification.target_id
            ]

            findings.append(
                QaFinding(
                    target_id=(
                        target.target_id
                    ),

                    semantic_key=(
                        target.semantic_key
                    ),

                    primitive=(
                        target.primitive
                    ),

                    priority=(
                        target.priority
                    ),

                    severity=(
                        target.severity
                    ),

                    status=(
                        verification.status
                    ),

                    confidence=(
                        verification.confidence
                    ),

                    expected_value=(
                        target.expected_value
                    ),

                    observed_value=(
                        verification
                        .observed_value
                    ),

                    evidence=(
                        verification.evidence
                    ),

                    failure_type=(
                        verification
                        .failure_type
                    ),

                    source_constraint_ids=(
                        target
                        .source_constraint_ids
                    ),

                    source_instruction_ids=(
                        target
                        .source_instruction_ids
                    ),

                    source_paths=(
                        target.source_paths
                    ),
                )
            )

        findings_tuple = tuple(
            sorted(
                findings,
                key=lambda item: (
                    int(item.priority),
                    item.semantic_key,
                    item.target_id,
                ),
            )
        )

        provisional = QaReport(
            version=self.VERSION,

            qa_run_id=(
                qa_request.qa_run_id
            ),

            render_id=(
                qa_request.render_id
            ),

            asset_id=(
                qa_request.asset_id
            ),

            source_revision_id=(
                qa_request
                .source_revision_id
            ),

            canonical_hash=(
                qa_request
                .canonical_hash
            ),

            constraint_set_hash=(
                qa_request
                .constraint_set_hash
            ),

            prompt_spec_hash=(
                qa_request
                .prompt_spec_hash
            ),

            request_hash=(
                qa_request.request_hash
            ),

            artifact_id=(
                qa_request
                .artifact
                .artifact_id
            ),

            artifact_hash=(
                qa_request
                .artifact
                .sha256
            ),

            vision_provider_key=(
                vision_result
                .provider_key
            ),

            vision_model_key=(
                vision_result
                .model_key
            ),

            decision=(
                gate_result.decision
            ),

            repair_decision=(
                gate_result
                .repair_decision
            ),

            findings=findings_tuple,

            hard_fail_count=(
                gate_result
                .hard_fail_count
            ),

            soft_fail_count=(
                gate_result
                .soft_fail_count
            ),

            uncertain_count=(
                gate_result
                .uncertain_count
            ),

            not_visible_count=(
                gate_result
                .not_visible_count
            ),

            qa_report_hash="",
        )

        digest = self.hash_report(
            provisional
        )

        return replace(
            provisional,
            qa_report_hash=digest,
        )

    def hash_report(
        self,
        report: QaReport,
    ) -> str:
        payload = self._plain(
            report
        )

        payload[
            "qa_report_hash"
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
                field_name:
                    cls._plain(
                        getattr(
                            value,
                            field_name,
                        )
                    )
                for field_name
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

---

# 15.21 Failure localization

Một render có thể fail:

```text
C01 tier_count
C07 tier_order
C11 superstructure continuity
```

Không nên tạo 3 repair độc lập nếu chúng cùng nằm trên cùng structural region.

Ta cluster deterministic theo semantic path.

---

# 15.22 Failure cluster

`qa/localization/failure_cluster.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.qa.contract.qa_report import (
    QaFinding,
)


@dataclass(
    frozen=True,
    slots=True,
)
class FailureCluster:
    cluster_id: str

    region_key: str

    findings: tuple[
        QaFinding,
        ...,
    ]
```

---

# 15.23 FailureLocalizer

Không dùng heuristic kiểu NLP tên feature tùy ý.

Dựa path hierarchy.

`qa/localization/failure_localizer.py`

```python
from __future__ import annotations

import hashlib

from collections import defaultdict

from media_runtime.qa.contract.qa_report import (
    QaFinding,
    QaReport,
)
from media_runtime.qa.enums import (
    ConstraintVerificationStatus,
)
from media_runtime.qa.localization.failure_cluster import (
    FailureCluster,
)


class FailureLocalizer:
    VERSION = "qa-failure-localizer-v1"

    def localize(
        self,
        report: QaReport,
    ) -> tuple[
        FailureCluster,
        ...,
    ]:
        failed = [
            finding
            for finding
            in report.findings
            if finding.status
            == ConstraintVerificationStatus.FAIL
        ]

        groups: dict[
            str,
            list[QaFinding],
        ] = defaultdict(list)

        for finding in failed:
            region = self._region_key(
                finding
            )

            groups[
                region
            ].append(
                finding
            )

        clusters = []

        for region in sorted(
            groups.keys()
        ):
            items = tuple(
                sorted(
                    groups[region],
                    key=lambda item: (
                        int(item.priority),
                        item.semantic_key,
                    ),
                )
            )

            clusters.append(
                FailureCluster(
                    cluster_id=(
                        self._cluster_id(
                            report.qa_report_hash,
                            region,
                            items,
                        )
                    ),

                    region_key=region,

                    findings=items,
                )
            )

        return tuple(
            clusters
        )

    @staticmethod
    def _region_key(
        finding: QaFinding,
    ) -> str:
        paths = [
            path
            for path in finding.source_paths
            if path
        ]

        if paths:
            path = sorted(
                paths
            )[0]
        else:
            path = finding.semantic_key

        parts = path.split(".")

        if len(parts) <= 2:
            return path

        # V1 deterministic structural region:
        # permanent_geometry.superstructure.foo
        # -> permanent_geometry.superstructure
        return ".".join(
            parts[:2]
        )

    @staticmethod
    def _cluster_id(
        report_hash: str,
        region: str,
        findings: tuple[
            QaFinding,
            ...,
        ],
    ) -> str:
        raw = "|".join(
            [
                report_hash,
                region,
                *[
                    item.target_id
                    for item in findings
                ],
            ]
        )

        digest = hashlib.sha256(
            raw.encode("utf-8")
        ).hexdigest()[:16]

        return "QFC-" + digest
```

Về sau broad profile có thể cung cấp `repair_region_map`; không nhét domain-specific logic vào Core.

---

# 15.24 Repair scope

Repair phải biết:

```text
sửa gì
giữ nguyên gì
không được chạm gì
```

`qa/localization/repair_scope.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.qa.contract.qa_report import (
    QaFinding,
)
from media_runtime.qa.localization.failure_cluster import (
    FailureCluster,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RepairScope:
    scope_id: str

    failure_cluster_id: str

    target_region: str

    failed_findings: tuple[
        QaFinding,
        ...,
    ]

    failed_constraint_ids: tuple[
        str,
        ...,
    ]

    failed_instruction_ids: tuple[
        str,
        ...,
    ]

    preserve_constraint_ids: tuple[
        str,
        ...,
    ]


class RepairScopeBuilder:
    def build(
        self,
        *,
        cluster: FailureCluster,
        all_findings: tuple[
            QaFinding,
            ...,
        ],
    ) -> RepairScope:
        failed_constraint_ids = {
            constraint_id
            for finding in cluster.findings
            for constraint_id
            in finding.source_constraint_ids
        }

        failed_instruction_ids = {
            instruction_id
            for finding in cluster.findings
            for instruction_id
            in finding.source_instruction_ids
        }

        passed_constraint_ids = {
            constraint_id
            for finding in all_findings
            if finding not in cluster.findings
            and finding.status.value == "pass"
            for constraint_id
            in finding.source_constraint_ids
        }

        return RepairScope(
            scope_id=(
                "RS-"
                + cluster.cluster_id[
                    4:
                ]
            ),

            failure_cluster_id=(
                cluster.cluster_id
            ),

            target_region=(
                cluster.region_key
            ),

            failed_findings=(
                cluster.findings
            ),

            failed_constraint_ids=tuple(
                sorted(
                    failed_constraint_ids
                )
            ),

            failed_instruction_ids=tuple(
                sorted(
                    failed_instruction_ids
                )
            ),

            preserve_constraint_ids=tuple(
                sorted(
                    passed_constraint_ids
                )
            ),
        )
```

---

# 15.25 Targeted repair policy

`qa/repair/policy.py`

```python
from __future__ import annotations

from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class TargetedRepairPolicy:
    max_repairs_per_render: int = 2

    preserve_passed_hard_constraints: bool = True

    preserve_camera_by_default: bool = True

    preserve_reference_set: bool = True

    allow_provider_change: bool = False

    allow_model_change: bool = False

    require_identity_reference_for_edit: bool = True
```

---

# 15.26 Repair plan DTO

`qa/repair/repair_plan.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.qa.localization.repair_scope import (
    RepairScope,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RepairDirective:
    semantic_key: str

    directive: str

    failed_constraint_ids: tuple[
        str,
        ...,
    ]


@dataclass(
    frozen=True,
    slots=True,
)
class PreserveDirective:
    semantic_key: str

    directive: str

    source_constraint_ids: tuple[
        str,
        ...,
    ]


@dataclass(
    frozen=True,
    slots=True,
)
class TargetedRepairPlan:
    version: str

    repair_id: str

    parent_render_id: str

    parent_request_hash: str

    parent_artifact_id: str

    parent_artifact_hash: str

    source_qa_report_hash: str

    scope: RepairScope

    repair_directives: tuple[
        RepairDirective,
        ...,
    ]

    preserve_directives: tuple[
        PreserveDirective,
        ...,
    ]
```

---

# 15.27 Repair prompt compiler

Không cho LLM viết repair prompt.

Deterministic.

`qa/repair/repair_prompt_compiler.py`

```python
from __future__ import annotations

import hashlib

from media_runtime.prompt.spec.prompt_spec import (
    PromptSpec,
)
from media_runtime.qa.contract.qa_report import (
    QaReport,
)
from media_runtime.qa.localization.repair_scope import (
    RepairScope,
)
from media_runtime.qa.repair.repair_plan import (
    PreserveDirective,
    RepairDirective,
    TargetedRepairPlan,
)


class RepairPromptCompiler:
    VERSION = "targeted-repair-v1"

    def compile(
        self,
        *,
        parent_render_id: str,
        parent_request_hash: str,
        parent_artifact_id: str,
        parent_artifact_hash: str,
        report: QaReport,
        scope: RepairScope,
        prompt_spec: PromptSpec,
    ) -> TargetedRepairPlan:
        instruction_map = {
            instruction.id:
                instruction
            for section in prompt_spec.sections
            for instruction
            in section.instructions
        }

        repair_directives = []

        for finding in scope.failed_findings:
            source_directives = [
                instruction_map[
                    instruction_id
                ]
                for instruction_id
                in finding
                .source_instruction_ids
                if instruction_id
                in instruction_map
            ]

            if source_directives:
                canonical_directive = (
                    source_directives[0]
                    .directive
                )
            else:
                canonical_directive = (
                    self._fallback_directive(
                        finding
                    )
                )

            repair_directives.append(
                RepairDirective(
                    semantic_key=(
                        finding.semantic_key
                    ),

                    directive=(
                        "Correct only this failed "
                        "requirement: "
                        + canonical_directive
                    ),

                    failed_constraint_ids=(
                        finding
                        .source_constraint_ids
                    ),
                )
            )

        preserve = []

        for section in prompt_spec.sections:
            for instruction in (
                section.instructions
            ):
                if not set(
                    instruction
                    .source_constraint_ids
                ).intersection(
                    set(
                        scope
                        .preserve_constraint_ids
                    )
                ):
                    continue

                preserve.append(
                    PreserveDirective(
                        semantic_key=(
                            instruction
                            .semantic_key
                        ),

                        directive=(
                            "Preserve unchanged: "
                            + instruction.directive
                        ),

                        source_constraint_ids=(
                            instruction
                            .source_constraint_ids
                        ),
                    )
                )

        repair_id = (
            self._repair_id(
                report.qa_report_hash,
                scope.scope_id,
            )
        )

        return TargetedRepairPlan(
            version=self.VERSION,

            repair_id=repair_id,

            parent_render_id=(
                parent_render_id
            ),

            parent_request_hash=(
                parent_request_hash
            ),

            parent_artifact_id=(
                parent_artifact_id
            ),

            parent_artifact_hash=(
                parent_artifact_hash
            ),

            source_qa_report_hash=(
                report.qa_report_hash
            ),

            scope=scope,

            repair_directives=tuple(
                sorted(
                    repair_directives,
                    key=lambda item:
                        item.semantic_key,
                )
            ),

            preserve_directives=tuple(
                sorted(
                    preserve,
                    key=lambda item:
                        item.semantic_key,
                )
            ),
        )

    @staticmethod
    def _fallback_directive(
        finding,
    ) -> str:
        return (
            "Make "
            + finding.semantic_key
            + " match the expected value "
            + repr(
                finding.expected_value
            )
            + "."
        )

    @staticmethod
    def _repair_id(
        report_hash: str,
        scope_id: str,
    ) -> str:
        raw = (
            report_hash
            + "|"
            + scope_id
        )

        return (
            "REP-"
            + hashlib.sha256(
                raw.encode("utf-8")
            ).hexdigest()[:16]
        )
```

---

# 15.28 Repair prompt text

Ta không thay original prompt bằng vài câu repair.

Phải dùng:

```text
Original Compiled Prompt
+
Repair Boundary
+
Failed directives
+
Preserve directives
```

`qa/repair/text.py`

```python
from __future__ import annotations

from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.qa.repair.repair_plan import (
    TargetedRepairPlan,
)


class TargetedRepairTextRenderer:
    VERSION = "targeted-repair-text-v1"

    def render(
        self,
        *,
        original: CompiledPrompt,
        plan: TargetedRepairPlan,
    ) -> str:
        lines = [
            original.prompt.strip(),

            "",

            "TARGETED REPAIR",

            (
                "Edit the supplied rendered image. "
                "Do not redesign the subject."
            ),

            (
                "Correct only the failed requirements "
                "listed below."
            ),

            (
                "Preserve all other established "
                "identity, geometry, topology, "
                "proportions, camera framing and "
                "validated features unchanged."
            ),

            "",
            "FAILED REQUIREMENTS TO CORRECT",
        ]

        for directive in (
            plan.repair_directives
        ):
            lines.append(
                directive.directive
            )

        if plan.preserve_directives:
            lines.extend([
                "",
                "VALIDATED FEATURES TO PRESERVE",
            ])

            for directive in (
                plan.preserve_directives
            ):
                lines.append(
                    directive.directive
                )

        lines.extend([
            "",
            (
                "Do not alter regions unrelated to "
                f"{plan.scope.target_region} unless "
                "strictly required to make the failed "
                "constraint physically coherent."
            ),
        ])

        return "\n".join(
            lines
        ).strip()
```

---

# 15.29 Ví dụ targeted repair prompt

Nếu ảnh fail:

```text
primary_tier_count expected 4
observed 5
```

repair không phải:

```text
Generate the yacht again with four tiers...
```

mà:

```text
TARGETED REPAIR

Edit the supplied rendered image. Do not redesign the subject.
Correct only the failed requirements listed below.
Preserve all other established identity, geometry, topology,
proportions, camera framing and validated features unchanged.

FAILED REQUIREMENTS TO CORRECT
Correct only this failed requirement:
Maintain exactly four primary superstructure tiers.

VALIDATED FEATURES TO PRESERVE
Preserve unchanged: Maintain a near-plumb stem.
Preserve unchanged: Maintain the broad flat transom.
Preserve unchanged: Preserve the declared length-to-beam ratio.

Do not alter regions unrelated to
permanent_geometry.superstructure unless strictly required...
```

Đây mới đúng repair semantics.

---

# 15.30 Repair phải dùng edit operation

Không:

```text
QA FAIL
↓
text-to-image generate lại
```

nếu provider hỗ trợ image edit.

Targeted repair V1:

```text
parent rendered image
+
identity references
+
targeted repair prompt
↓
EDIT
```

---

# 15.31 Repair request builder

`qa/repair/repair_request_builder.py`

```python
from __future__ import annotations

import hashlib
import uuid

from dataclasses import replace

from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.qa.repair.repair_plan import (
    TargetedRepairPlan,
)
from media_runtime.qa.repair.text import (
    TargetedRepairTextRenderer,
)
from media_runtime.render.enums import (
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


class TargetedRepairRequestBuilder:
    VERSION = "repair-request-builder-v1"

    def __init__(
        self,
        text_renderer: (
            TargetedRepairTextRenderer
        ),
    ) -> None:
        self._renderer = text_renderer

    def build(
        self,
        *,
        original_request: RenderRequest,
        original_artifact: ReferenceAsset,
        repair_plan: TargetedRepairPlan,
        existing_references: ReferenceSet,
        repair_generation: int,
    ) -> RenderRequest:
        repair_prompt_text = (
            self._renderer.render(
                original=(
                    original_request
                    .compiled_prompt
                ),

                plan=repair_plan,
            )
        )

        compiled = (
            self._repair_compiled_prompt(
                original_request
                .compiled_prompt,

                repair_prompt_text,
            )
        )

        references = self._repair_references(
            original_artifact=(
                original_artifact
            ),

            existing=(
                existing_references
            ),

            original_request=(
                original_request
            ),
        )

        render_id = (
            str(
                uuid.uuid4()
            )
        )

        return RenderRequest(
            version=(
                "render-request-v1"
            ),

            render_id=render_id,

            run_id=(
                original_request.run_id
            ),

            session_code=(
                original_request
                .session_code
            ),

            asset_id=(
                original_request.asset_id
            ),

            media_type=(
                original_request.media_type
            ),

            operation=(
                RenderOperation.EDIT
            ),

            provider_key=(
                original_request
                .provider_key
            ),

            model_key=(
                original_request.model_key
            ),

            source_revision_id=(
                original_request
                .source_revision_id
            ),

            source_canonical_hash=(
                original_request
                .source_canonical_hash
            ),

            projection_hash=(
                original_request
                .projection_hash
            ),

            constraint_set_hash=(
                original_request
                .constraint_set_hash
            ),

            prompt_spec_hash=(
                original_request
                .prompt_spec_hash
            ),

            provider_prompt_plan_hash=(
                original_request
                .provider_prompt_plan_hash
            ),

            compiled_prompt=(
                compiled
            ),

            references=references,

            width=(
                original_request.width
            ),

            height=(
                original_request.height
            ),

            aspect_ratio=(
                original_request
                .aspect_ratio
            ),

            quality=(
                original_request.quality
            ),

            output_format=(
                original_request
                .output_format
            ),

            provider_options=dict(
                original_request
                .provider_options
            ),

            idempotency_key=(
                original_request
                .idempotency_key
                + ":qa-repair:"
                + str(
                    repair_generation
                )
            ),
        )

    @staticmethod
    def _repair_compiled_prompt(
        original: CompiledPrompt,
        prompt_text: str,
    ) -> CompiledPrompt:
        prompt_hash = (
            CompiledPrompt
            .sha256_text(
                prompt_text
            )
        )

        return CompiledPrompt(
            version=(
                "targeted-repair-text-v1"
            ),

            provider_key=(
                original.provider_key
            ),

            model_key=(
                original.model_key
            ),

            source_prompt_spec_hash=(
                original
                .source_prompt_spec_hash
            ),

            source_provider_plan_hash=(
                original
                .source_provider_plan_hash
            ),

            prompt=prompt_text,

            negative_prompt=(
                original.negative_prompt
            ),

            native_controls=dict(
                original.native_controls
            ),

            prompt_hash=prompt_hash,

            negative_prompt_hash=(
                original
                .negative_prompt_hash
            ),
        )

    @staticmethod
    def _repair_references(
        *,
        original_artifact: ReferenceAsset,
        existing: ReferenceSet,
        original_request: RenderRequest,
    ) -> ReferenceSet:
        repair_source = ReferenceAsset(
            artifact_id=(
                original_artifact
                .artifact_id
            ),

            path=(
                original_artifact.path
            ),

            sha256=(
                original_artifact.sha256
            ),

            mime_type=(
                original_artifact
                .mime_type
            ),

            role=(
                original_artifact.role
            ),

            priority=-1,

            source_revision_id=(
                original_request
                .source_revision_id
            ),

            source_canonical_hash=(
                original_request
                .source_canonical_hash
            ),

            width=(
                original_artifact.width
            ),

            height=(
                original_artifact.height
            ),
        )

        combined = [
            repair_source,
            *existing.references,
        ]

        unique = {}

        for item in combined:
            unique[
                item.artifact_id
            ] = item

        return ReferenceSet(
            version="reference-set-v1",

            asset_id=(
                original_request.asset_id
            ),

            source_revision_id=(
                original_request
                .source_revision_id
            ),

            source_canonical_hash=(
                original_request
                .source_canonical_hash
            ),

            references=tuple(
                unique.values()
            ),
        )
```

Có một điểm: Part 13 đang validate:

```python
priority >= 0
```

nên không dùng `priority=-1`.

Bản production đúng phải dùng:

```python
priority=0
```

và shift references cũ lên một bậc deterministic.

Sửa `_repair_references()`:

```python
repair_source = ReferenceAsset(
    artifact_id=original_artifact.artifact_id,
    path=original_artifact.path,
    sha256=original_artifact.sha256,
    mime_type=original_artifact.mime_type,
    role=original_artifact.role,
    priority=0,
    source_revision_id=(
        original_request.source_revision_id
    ),
    source_canonical_hash=(
        original_request.source_canonical_hash
    ),
    width=original_artifact.width,
    height=original_artifact.height,
)

shifted = []

for item in existing.ordered():
    if (
        item.artifact_id
        == repair_source.artifact_id
    ):
        continue

    shifted.append(
        ReferenceAsset(
            artifact_id=item.artifact_id,
            path=item.path,
            sha256=item.sha256,
            mime_type=item.mime_type,
            role=item.role,
            priority=item.priority + 1,
            source_revision_id=(
                item.source_revision_id
            ),
            source_canonical_hash=(
                item.source_canonical_hash
            ),
            width=item.width,
            height=item.height,
        )
    )

return ReferenceSet(
    version="reference-set-v1",
    asset_id=original_request.asset_id,
    source_revision_id=(
        original_request.source_revision_id
    ),
    source_canonical_hash=(
        original_request.source_canonical_hash
    ),
    references=(
        repair_source,
        *shifted,
    ),
)
```

Đây là bản cần dùng.

---

# 15.32 Một thay đổi production cần thêm vào `RenderRequest`

Để lineage repair đầy đủ, thêm optional fields:

```python
parent_render_id: str | None = None

parent_request_hash: str | None = None

repair_id: str | None = None

qa_report_hash: str | None = None

repair_generation: int = 0
```

Final:

```python
@dataclass(
    frozen=True,
    slots=True,
)
class RenderRequest:
    # existing fields...

    parent_render_id: str | None = None

    parent_request_hash: str | None = None

    repair_id: str | None = None

    qa_report_hash: str | None = None

    repair_generation: int = 0
```

Hasher Part 13 phải thêm:

```python
"parent_render_id":
    request.parent_render_id,

"parent_request_hash":
    request.parent_request_hash,

"repair_id":
    request.repair_id,

"qa_report_hash":
    request.qa_report_hash,

"repair_generation":
    request.repair_generation,
```

Như vậy repair request hash không thể collide với original.

---

# 15.33 Repair request builder final lineage

Trong return:

```python
parent_render_id=(
    original_request.render_id
),

parent_request_hash=(
    repair_plan.parent_request_hash
),

repair_id=(
    repair_plan.repair_id
),

qa_report_hash=(
    repair_plan.source_qa_report_hash
),

repair_generation=(
    repair_generation
),
```

---

# 15.34 Không reuse infrastructure retry counter

Repair #1:

```text
original render
   infrastructure attempts: 2
↓
QA fail
↓
repair generation 1
   infrastructure attempts reset
```

Repair generation là visual correction lineage, không phải provider retry.

---

# 15.35 QA orchestration service

`qa/service.py`

```python
from __future__ import annotations

import uuid
from pathlib import Path

from media_runtime.qa.artifact.hashing import (
    QaArtifactIntegrityVerifier,
)
from media_runtime.qa.contract.qa_request import (
    QaImageArtifact,
    QaRequest,
)
from media_runtime.qa.enums import (
    QaDecision,
)
from media_runtime.qa.extraction.verification_target_builder import (
    VerificationTargetBuilder,
)
from media_runtime.qa.localization.failure_localizer import (
    FailureLocalizer,
)
from media_runtime.qa.localization.repair_scope import (
    RepairScopeBuilder,
)
from media_runtime.qa.verification.deterministic_validator import (
    DeterministicQaValidator,
)
from media_runtime.qa.verification.report_builder import (
    QaReportBuilder,
)


class VisionQaService:
    def __init__(
        self,
        *,
        integrity_verifier: (
            QaArtifactIntegrityVerifier
        ),
        target_builder: (
            VerificationTargetBuilder
        ),
        vision_inspector,
        deterministic_validator: (
            DeterministicQaValidator
        ),
        gate,
        report_builder: QaReportBuilder,
        failure_localizer: (
            FailureLocalizer
        ),
        repair_scope_builder: (
            RepairScopeBuilder
        ),
    ) -> None:
        self._integrity = (
            integrity_verifier
        )

        self._targets = target_builder

        self._vision = (
            vision_inspector
        )

        self._validator = (
            deterministic_validator
        )

        self._gate = gate

        self._reports = report_builder

        self._localizer = (
            failure_localizer
        )

        self._repair_scopes = (
            repair_scope_builder
        )

    def inspect(
        self,
        *,
        render_id: str,
        asset_id: str,
        source_revision_id: str,
        canonical_hash: str,
        constraint_set_hash: str,
        prompt_spec_hash: str,
        request_hash: str,
        artifact_id: str,
        artifact_path: Path,
        artifact_hash: str,
        artifact_mime_type: str,
        constraint_set,
        prompt_spec,
    ):
        self._integrity.verify(
            path=artifact_path,

            expected_sha256=(
                artifact_hash
            ),
        )

        targets = self._targets.build(
            constraint_set=(
                constraint_set
            ),

            prompt_spec=(
                prompt_spec
            ),
        )

        qa_run_id = str(
            uuid.uuid4()
        )

        request = QaRequest(
            version="qa-request-v1",

            qa_run_id=qa_run_id,

            render_id=render_id,

            asset_id=asset_id,

            source_revision_id=(
                source_revision_id
            ),

            canonical_hash=(
                canonical_hash
            ),

            constraint_set_hash=(
                constraint_set_hash
            ),

            prompt_spec_hash=(
                prompt_spec_hash
            ),

            request_hash=(
                request_hash
            ),

            artifact=QaImageArtifact(
                artifact_id=artifact_id,

                path=str(
                    artifact_path
                ),

                sha256=(
                    artifact_hash
                ),

                mime_type=(
                    artifact_mime_type
                ),
            ),

            targets=targets,
        )

        vision_result = (
            self._vision.inspect(
                request
            )
        )

        self._validator.validate(
            targets=targets,

            result=(
                vision_result
            ),
        )

        provisional_findings = (
            self._findings_for_gate(
                request=request,
                vision_result=(
                    vision_result
                ),
            )
        )

        gate_result = (
            self._gate.decide(
                provisional_findings
            )
        )

        report = (
            self._reports.build(
                qa_request=request,

                vision_result=(
                    vision_result
                ),

                gate_result=(
                    gate_result
                ),
            )
        )

        clusters = (
            self._localizer.localize(
                report
            )
        )

        scopes = tuple(
            self._repair_scopes.build(
                cluster=cluster,

                all_findings=(
                    report.findings
                ),
            )
            for cluster in clusters
        )

        return (
            report,
            scopes,
        )

    @staticmethod
    def _findings_for_gate(
        *,
        request,
        vision_result,
    ):
        from media_runtime.qa.contract.qa_report import (
            QaFinding,
        )

        target_map = {
            item.target_id:
                item
            for item in request.targets
        }

        result = []

        for verification in (
            vision_result.results
        ):
            target = target_map[
                verification.target_id
            ]

            result.append(
                QaFinding(
                    target_id=(
                        target.target_id
                    ),

                    semantic_key=(
                        target.semantic_key
                    ),

                    primitive=(
                        target.primitive
                    ),

                    priority=(
                        target.priority
                    ),

                    severity=(
                        target.severity
                    ),

                    status=(
                        verification.status
                    ),

                    confidence=(
                        verification.confidence
                    ),

                    expected_value=(
                        target.expected_value
                    ),

                    observed_value=(
                        verification
                        .observed_value
                    ),

                    evidence=(
                        verification.evidence
                    ),

                    failure_type=(
                        verification
                        .failure_type
                    ),

                    source_constraint_ids=(
                        target
                        .source_constraint_ids
                    ),

                    source_instruction_ids=(
                        target
                        .source_instruction_ids
                    ),

                    source_paths=(
                        target.source_paths
                    ),
                )
            )

        return tuple(result)
```

---

# 15.36 Better refactor

`_findings_for_gate()` và `QaReportBuilder` đang duplicate mapping.

Production nên extract:

```text
QaFindingFactory
```

Code:

```python
class QaFindingFactory:
    def build(
        self,
        *,
        targets,
        vision_result,
    ):
        target_map = {
            item.target_id: item
            for item in targets
        }

        findings = []

        for verification in (
            vision_result.results
        ):
            target = target_map[
                verification.target_id
            ]

            findings.append(
                QaFinding(
                    target_id=target.target_id,
                    semantic_key=target.semantic_key,
                    primitive=target.primitive,
                    priority=target.priority,
                    severity=target.severity,
                    status=verification.status,
                    confidence=verification.confidence,
                    expected_value=(
                        target.expected_value
                    ),
                    observed_value=(
                        verification.observed_value
                    ),
                    evidence=verification.evidence,
                    failure_type=(
                        verification.failure_type
                    ),
                    source_constraint_ids=(
                        target.source_constraint_ids
                    ),
                    source_instruction_ids=(
                        target.source_instruction_ids
                    ),
                    source_paths=target.source_paths,
                )
            )

        return tuple(
            sorted(
                findings,
                key=lambda item: (
                    int(item.priority),
                    item.semantic_key,
                ),
            )
        )
```

Sau đó:

```text
Vision result
↓
QaFindingFactory
↓
QaGate
↓
QaReportBuilder
```

Tôi khuyên dùng bản refactor này.

---

# 15.37 QAReport artifact

Lưu:

```text
renders/{render_id}/
└── qa/
    └── {qa_run_id}/
        ├── qa_request.json
        ├── vision_request.json
        ├── vision_response.json
        ├── qa_report.json
        ├── failure_clusters.json
        └── repair_scopes.json
```

---

# 15.38 QA serializer

Không dùng `asdict()` nếu nested structure về sau có immutable mappings.

```python
from __future__ import annotations

import json
from enum import Enum
from typing import Any


class QaJsonSerializer:
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
                field_name:
                    cls.plain(
                        getattr(
                            value,
                            field_name,
                        )
                    )
                for field_name
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

# 15.39 Laravel QA migrations

`render_qa_runs`

```php
Schema::create(
    'render_qa_runs',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'render_id'
        )->index();

        $table->unsignedInteger(
            'qa_generation'
        );

        $table->string(
            'status',
            40
        );

        $table->string(
            'decision',
            40
        )->nullable();

        $table->string(
            'repair_decision',
            40
        )->nullable();

        $table->uuid(
            'canonical_revision_id'
        );

        $table->char(
            'canonical_hash',
            64
        );

        $table->char(
            'constraint_set_hash',
            64
        );

        $table->char(
            'prompt_spec_hash',
            64
        );

        $table->char(
            'request_hash',
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
            'vision_provider',
            80
        );

        $table->string(
            'vision_model',
            190
        );

        $table->char(
            'qa_report_hash',
            64
        )->nullable();

        $table->unsignedInteger(
            'hard_fail_count'
        )->default(0);

        $table->unsignedInteger(
            'soft_fail_count'
        )->default(0);

        $table->unsignedInteger(
            'uncertain_count'
        )->default(0);

        $table->unsignedInteger(
            'not_visible_count'
        )->default(0);

        $table->json(
            'report_json'
        )->nullable();

        $table->timestampTz(
            'completed_at'
        )->nullable();

        $table->timestampsTz();

        $table->unique(
            [
                'render_id',
                'qa_generation',
            ],
            'render_qa_generation_uq'
        );

        $table->foreign(
            'render_id'
        )
            ->references('id')
            ->on('renders')
            ->cascadeOnDelete();
    }
);
```

---

# 15.40 QA findings table

```php
Schema::create(
    'render_qa_findings',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'qa_run_id'
        )->index();

        $table->string(
            'target_id',
            100
        );

        $table->string(
            'semantic_key',
            500
        );

        $table->string(
            'primitive',
            80
        );

        $table->unsignedSmallInteger(
            'priority'
        );

        $table->string(
            'severity',
            20
        );

        $table->string(
            'status',
            40
        );

        $table->decimal(
            'confidence',
            5,
            4
        );

        $table->json(
            'expected_value'
        )->nullable();

        $table->json(
            'observed_value'
        )->nullable();

        $table->text(
            'evidence'
        )->nullable();

        $table->string(
            'failure_type',
            80
        )->nullable();

        $table->json(
            'source_constraint_ids'
        );

        $table->json(
            'source_instruction_ids'
        );

        $table->json(
            'source_paths'
        );

        $table->timestampsTz();

        $table->unique(
            [
                'qa_run_id',
                'target_id',
            ],
            'qa_target_uq'
        );

        $table->foreign(
            'qa_run_id'
        )
            ->references('id')
            ->on('render_qa_runs')
            ->cascadeOnDelete();
    }
);
```

---

# 15.41 Render repairs table

```php
Schema::create(
    'render_repairs',
    function (Blueprint $table): void {
        $table->uuid('id')->primary();

        $table->uuid(
            'qa_run_id'
        )->index();

        $table->uuid(
            'parent_render_id'
        )->index();

        $table->uuid(
            'repair_render_id'
        )
            ->nullable()
            ->index();

        $table->unsignedInteger(
            'repair_generation'
        );

        $table->string(
            'status',
            40
        );

        $table->string(
            'repair_scope_id',
            100
        );

        $table->char(
            'source_qa_report_hash',
            64
        );

        $table->char(
            'parent_request_hash',
            64
        );

        $table->char(
            'parent_artifact_hash',
            64
        );

        $table->json(
            'repair_plan_json'
        );

        $table->char(
            'repair_request_hash',
            64
        )->nullable();

        $table->timestampsTz();

        $table->unique(
            [
                'qa_run_id',
                'repair_scope_id',
            ],
            'qa_repair_scope_uq'
        );
    }
);
```

---

# 15.42 Laravel QA enums

```php
enum QaDecision: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case REVIEW = 'review';
}
```

```php
enum QaStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
```

```php
enum QaRepairStatus: string
{
    case PLANNED = 'planned';
    case DISPATCHED = 'dispatched';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
```

---

# 15.43 QA checkpoint endpoint

Python hoàn thành QA:

```text
POST /api/internal/render-qa/{qaRun}/complete
```

Payload:

```json
{
  "qa_report_hash": "...",
  "render_id": "...",
  "request_hash": "...",
  "artifact_hash": "...",
  "decision": "fail",
  "repair_decision": "targeted_repair",
  "vision_provider": "openai",
  "vision_model": "...",
  "report": {
    "...": "..."
  }
}
```

---

# 15.44 QaCheckpointService

```php
final class QaCheckpointService
{
    public function complete(
        RenderQaRun $qaRun,
        array $payload,
    ): RenderQaRun {
        return DB::transaction(
            function () use (
                $qaRun,
                $payload
            ): RenderQaRun {
                $qaRun =
                    RenderQaRun::query()
                        ->whereKey(
                            $qaRun->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $qaRun->status
                    === QaStatus::COMPLETED
                ) {
                    if (
                        !hash_equals(
                            $qaRun->qa_report_hash,
                            $payload[
                                'qa_report_hash'
                            ]
                        )
                    ) {
                        throw new RuntimeException(
                            'QA replay hash mismatch.'
                        );
                    }

                    return $qaRun;
                }

                if (
                    !hash_equals(
                        $qaRun->request_hash,
                        $payload[
                            'request_hash'
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        'QA request hash mismatch.'
                    );
                }

                if (
                    !hash_equals(
                        $qaRun->artifact_hash,
                        $payload[
                            'artifact_hash'
                        ]
                    )
                ) {
                    throw new RuntimeException(
                        'QA artifact hash mismatch.'
                    );
                }

                $report =
                    $payload['report'];

                $qaRun->forceFill([
                    'status' =>
                        QaStatus::COMPLETED,

                    'decision' =>
                        QaDecision::from(
                            $payload[
                                'decision'
                            ]
                        ),

                    'repair_decision' =>
                        $payload[
                            'repair_decision'
                        ],

                    'qa_report_hash' =>
                        $payload[
                            'qa_report_hash'
                        ],

                    'vision_provider' =>
                        $payload[
                            'vision_provider'
                        ],

                    'vision_model' =>
                        $payload[
                            'vision_model'
                        ],

                    'hard_fail_count' =>
                        $report[
                            'hard_fail_count'
                        ],

                    'soft_fail_count' =>
                        $report[
                            'soft_fail_count'
                        ],

                    'uncertain_count' =>
                        $report[
                            'uncertain_count'
                        ],

                    'not_visible_count' =>
                        $report[
                            'not_visible_count'
                        ],

                    'report_json' =>
                        $report,

                    'completed_at' =>
                        now(),
                ])->save();

                foreach (
                    $report['findings']
                    as $finding
                ) {
                    RenderQaFinding::query()
                        ->updateOrCreate(
                            [
                                'qa_run_id' =>
                                    $qaRun->id,

                                'target_id' =>
                                    $finding[
                                        'target_id'
                                    ],
                            ],

                            [
                                'semantic_key' =>
                                    $finding[
                                        'semantic_key'
                                    ],

                                'primitive' =>
                                    $finding[
                                        'primitive'
                                    ],

                                'priority' =>
                                    $finding[
                                        'priority'
                                    ],

                                'severity' =>
                                    $finding[
                                        'severity'
                                    ],

                                'status' =>
                                    $finding[
                                        'status'
                                    ],

                                'confidence' =>
                                    $finding[
                                        'confidence'
                                    ],

                                'expected_value' =>
                                    $finding[
                                        'expected_value'
                                    ],

                                'observed_value' =>
                                    $finding[
                                        'observed_value'
                                    ],

                                'evidence' =>
                                    $finding[
                                        'evidence'
                                    ],

                                'failure_type' =>
                                    $finding[
                                        'failure_type'
                                    ],

                                'source_constraint_ids' =>
                                    $finding[
                                        'source_constraint_ids'
                                    ],

                                'source_instruction_ids' =>
                                    $finding[
                                        'source_instruction_ids'
                                    ],

                                'source_paths' =>
                                    $finding[
                                        'source_paths'
                                    ],
                            ]
                        );
                }

                return $qaRun;
            }
        );
    }
}
```

---

# 15.45 QA decision không dựa vào Vision prose

Laravel trust:

```text
QAReport.decision
```

vì Python deterministic gate đã tính.

Nhưng Laravel vẫn validate counts:

```php
$actualHardFailCount =
    collect(
        $report['findings']
    )
        ->where(
            'status',
            'fail'
        )
        ->where(
            'severity',
            'hard'
        )
        ->count();

if (
    $actualHardFailCount
    !== $report['hard_fail_count']
) {
    throw new RuntimeException(
        'QA report hard fail count mismatch.'
    );
}
```

Tương tự soft/uncertain/not_visible.

---

# 15.46 Automatic repair policy Laravel

Không phải FAIL nào cũng auto repair.

```php
final class QaDecisionService
{
    public function nextAction(
        RenderQaRun $qaRun,
    ): string {
        if (
            $qaRun->decision
            === QaDecision::PASS
        ) {
            return 'approve_candidate';
        }

        if (
            $qaRun->decision
            === QaDecision::REVIEW
        ) {
            return 'human_review';
        }

        $repairCount =
            RenderRepair::query()
                ->where(
                    'parent_render_id',
                    $qaRun->render_id
                )
                ->count();

        if ($repairCount >= 2) {
            return 'human_review';
        }

        if (
            $qaRun->repair_decision
            === 'targeted_repair'
        ) {
            return 'targeted_repair';
        }

        return 'human_review';
    }
}
```

Chốt:

```text
repair tối đa 2 lần
↓
vẫn fail
↓
human review
```

Không loop vô hạn.

---

# 15.47 Repair lineage trong DB

Ví dụ:

```text
Render R0
request_hash = A
artifact_hash = X
    ↓
QA Q0
qa_report_hash = Q
FAIL count
    ↓
Repair RP1
parent_render_id = R0
parent_request_hash = A
parent_artifact_hash = X
source_qa_report_hash = Q
    ↓
Render R1
request_hash = B
repair_generation = 1
    ↓
QA Q1
```

Canonical:

```text
canonical_hash = SAME
```

Projection:

```text
projection_hash = SAME
```

Constraint set:

```text
constraint_set_hash = SAME
```

Prompt repair text:

```text
prompt_hash = NEW
```

Request:

```text
request_hash = NEW
```

Đây là chính xác.

---

# 15.48 Failure localization example

QA output:

```json
{
  "target_id": "QT-01",
  "status": "fail",
  "confidence": 0.97,
  "observed_value": 5,
  "expected_value": 4,
  "failure_type": "count_mismatch",
  "semantic_key":
    "permanent_geometry.superstructure.primary_tier_count"
}
```

Lineage:

```text
QT-01
↓
constraint C-9e...
↓
PromptInstruction PI-ab...
↓
relationship R003 / invariant I004
↓
canonical path
permanent_geometry.superstructure.primary_tier_count
↓
canonical revision 3
```

Ta biết chính xác lỗi thuộc đâu.

---

# 15.49 Không dùng bounding box bắt buộc trong V1

Có thể hỏi Vision:

```json
"region": {
  "x": ...,
  "y": ...,
  "width": ...,
  "height": ...
}
```

nhưng tôi **không khuyên đưa vào Core V1 ngay** vì Vision localization coordinates giữa providers không nhất quán.

V1 dùng:

```text
semantic region
+
evidence
+
source path
```

ổn hơn.

Sau này Part hardening có thể thêm optional normalized bounding boxes.

---

# 15.50 Count verification phải strict

Ví dụ expected:

```json
{
  "primitive": "count",
  "expected_value": 4
}
```

Vision trả:

```text
"I can see around four or five."
```

Không PASS.

Phải:

```text
UNCERTAIN
```

hoặc:

```text
NOT_VISIBLE
```

nếu bị che.

---

# 15.51 `NOT_VISIBLE` khác `FAIL`

Điều này cực quan trọng.

Ví dụ:

```text
exactly 2 exterior staircases
```

nhưng camera port-side chỉ thấy 1.

Không thể nói:

```text
FAIL because only one visible.
```

Phải:

```text
NOT_VISIBLE
```

nếu second staircase nằm mặt starboard không quan sát được.

Đây là lý do Vision prompt phải phân biệt rõ.

---

# 15.52 Multi-view QA

Với anchor pack nhiều ảnh, một hard constraint có thể verify trên ảnh khác.

Sau Part16:

```text
Constraint:
exactly 2 exterior staircases

front 3/4 port
→ NOT_VISIBLE

starboard profile
→ partial evidence

high overview
→ PASS
```

Ta sẽ có cross-view QA aggregate.

Part15 V1 là **single-artifact QA**; contract đã đủ để Part16 aggregate nhiều QAReport.

---

# 15.53 Identity drift check

Count/geometry checks chưa đủ.

Với edit/reference workflow cần identity integrity.

Có thể thêm synthetic QA target derived từ P0 constraints:

```text
identity consistency across:
- silhouette
- major permanent geometry
- major proportions
- topology
```

Nhưng không dùng face/image similarity heuristic chung.

Builder có thể tạo aggregate identity target:

```python
def build_identity_target(
    self,
    constraints,
):
    identity_constraints = [
        item
        for item in constraints
        if int(
            item.priority
        ) == 0
        and item.visual_verification
    ]

    if not identity_constraints:
        return None
```

Tôi khuyên làm aggregate ở Part16 khi đã có anchor reference.

V1 Part15 giữ từng P0 constraint cụ thể.

---

# 15.54 Vision QA không được dùng original article

QA inputs:

```text
render artifact
verification targets
```

Không:

```text
article
source URL
marketing copy
```

Vì Vision có thể bị nhiễu và kiểm sai theo article thay vì frozen Canonical.

---

# 15.55 Vision QA không cần toàn Canonical JSON

Chỉ cần target expected values.

Không truyền một JSON canonical 20KB nếu ảnh chỉ cần check 17 constraints.

Lợi ích:

```text
ít token
ít confusion
ít reinterpretation
```

---

# 15.56 Vision QA không sửa semantic value

Nếu Vision nói:

```text
"five tiers may look more realistic"
```

ignore.

Expected vẫn:

```text
4
```

QAReport ghi observed=5 → FAIL.

---

# 15.57 Targeted repair không dùng Vision prose trực tiếp

Sai:

```text
Vision:
"The yacht seems too bulky and perhaps should..."
↓
paste whole text into repair prompt
```

Đúng:

```text
QAFinding:
semantic_key
expected_value
observed_value
failure_type
source constraint IDs
↓
RepairPromptCompiler deterministic
```

`evidence` chỉ audit, không phải authoritative repair instruction.

---

# 15.58 QA artifact hash

QA report phải khóa vào exact bytes:

```text
request_hash = R
artifact_hash = A
qa_report_hash = Q
```

Nếu image file bị thay:

```text
artifact_hash != A
```

report Q không còn valid.

---

# 15.59 Repair relation hash

Có thể tính:

```python
def repair_lineage_hash(
    *,
    qa_report_hash: str,
    parent_artifact_hash: str,
    repair_id: str,
) -> str:
    return hashlib.sha256(
        (
            qa_report_hash
            + "|"
            + parent_artifact_hash
            + "|"
            + repair_id
        ).encode("utf-8")
    ).hexdigest()
```

Lưu vào ledger nếu muốn forensic audit.

---

# 15.60 QAReport sample

```json
{
  "version": "qa-report-v1",

  "qa_run_id": "QA-...",

  "render_id": "REN-001",

  "asset_id": "master_vessel",

  "canonical_hash": "a81f...",

  "constraint_set_hash": "c40f...",

  "prompt_spec_hash": "d91a...",

  "request_hash": "f17c...",

  "artifact_id": "REN-001:a1:image:0",

  "artifact_hash": "811c...",

  "vision_provider_key": "openai",

  "vision_model_key": "...",

  "decision": "fail",

  "repair_decision": "targeted_repair",

  "hard_fail_count": 1,

  "soft_fail_count": 0,

  "uncertain_count": 0,

  "not_visible_count": 1,

  "findings": [
    {
      "target_id": "QT-ab12",

      "semantic_key":
        "permanent_geometry.superstructure.primary_tier_count",

      "primitive": "count",

      "priority": 1,

      "severity": "hard",

      "status": "fail",

      "confidence": 0.97,

      "expected_value": 4,

      "observed_value": 5,

      "failure_type":
        "count_mismatch",

      "evidence":
        "Five distinct primary superstructure levels are visibly separated.",

      "source_constraint_ids": [
        "C-ab..."
      ],

      "source_instruction_ids": [
        "PI-92..."
      ]
    }
  ],

  "qa_report_hash": "..."
}
```

---

# 15.61 Tests — only visual constraints included

```python
def test_non_visual_constraint_not_sent_to_vision(
    constraint_set,
    prompt_spec,
):
    targets = (
        VerificationTargetBuilder()
        .build(
            constraint_set=(
                constraint_set
            ),
            prompt_spec=prompt_spec,
        )
    )

    target_ids = {
        item.semantic_key
        for item in targets
    }

    assert (
        "non_visual.test"
        not in target_ids
    )
```

---

# 15.62 Every target exactly once

```python
import pytest


def test_vision_must_return_every_target_once():
    parser = (
        VisionQaResponseParser()
    )

    with pytest.raises(
        QaResponseValidationError
    ):
        parser.parse(
            raw={
                "artifact_observable":
                    True,

                "overall_notes":
                    "",

                "results": [
                    {
                        "target_id":
                            "QT-1",

                        "status":
                            "pass",

                        "confidence":
                            0.9,

                        "observed_value":
                            4,

                        "evidence":
                            "Four visible.",

                        "failure_type":
                            None,
                    }
                ],
            },

            expected_target_ids=(
                "QT-1",
                "QT-2",
            ),

            provider_key="test",

            model_key="vision",
        )
```

---

# 15.63 Unknown target rejected

```python
def test_vision_cannot_invent_qa_target():
    with pytest.raises(
        QaResponseValidationError
    ):
        VisionQaResponseParser().parse(
            raw={
                "artifact_observable":
                    True,

                "overall_notes":
                    "",

                "results": [
                    {
                        "target_id":
                            "INVENTED",

                        "status":
                            "fail",

                        "confidence":
                            1.0,

                        "observed_value":
                            None,

                        "evidence":
                            "invented",

                        "failure_type":
                            "unknown",
                    }
                ],
            },

            expected_target_ids=(
                "QT-real",
            ),

            provider_key="test",
            model_key="vision",
        )
```

---

# 15.64 Hard FAIL => overall FAIL

```python
def test_hard_failure_fails_render(
    hard_failed_finding,
):
    gate = QaGate(
        QaGatePolicy()
    )

    result = gate.decide(
        (
            hard_failed_finding,
        )
    )

    assert (
        result.decision
        == QaDecision.FAIL
    )

    assert (
        result.repair_decision
        == QaRepairDecision
        .TARGETED_REPAIR
    )
```

---

# 15.65 Hard NOT_VISIBLE => REVIEW

```python
def test_hard_not_visible_requires_review(
    hard_not_visible_finding,
):
    result = QaGate(
        QaGatePolicy()
    ).decide(
        (
            hard_not_visible_finding,
        )
    )

    assert (
        result.decision
        == QaDecision.REVIEW
    )
```

---

# 15.66 Low confidence PASS => REVIEW

```python
def test_low_confidence_hard_pass_is_not_accepted(
    hard_pass_finding,
):
    weak = replace(
        hard_pass_finding,
        confidence=0.55,
    )

    result = QaGate(
        QaGatePolicy(
            hard_pass_min_confidence=0.80
        )
    ).decide(
        (weak,)
    )

    assert (
        result.decision
        == QaDecision.REVIEW
    )
```

---

# 15.67 Failed constraints only in repair list

```python
def test_repair_plan_only_corrects_failed_constraints(
    scope,
    report,
    prompt_spec,
):
    plan = (
        RepairPromptCompiler()
        .compile(
            parent_render_id="R1",

            parent_request_hash=(
                "a" * 64
            ),

            parent_artifact_id="A1",

            parent_artifact_hash=(
                "b" * 64
            ),

            report=report,

            scope=scope,

            prompt_spec=prompt_spec,
        )
    )

    repair_ids = {
        constraint_id
        for directive
        in plan.repair_directives
        for constraint_id
        in directive.failed_constraint_ids
    }

    assert repair_ids == set(
        scope.failed_constraint_ids
    )
```

---

# 15.68 Passed constraints explicitly preserved

```python
def test_passed_constraints_are_preserved(
    plan,
):
    preserve_ids = {
        constraint_id
        for directive
        in plan.preserve_directives
        for constraint_id
        in directive.source_constraint_ids
    }

    assert preserve_ids
```

---

# 15.69 Repair request uses same Canonical

```python
def test_repair_cannot_change_canonical_lineage(
    original_request,
    repair_request,
):
    assert (
        repair_request
        .source_revision_id
        == original_request
        .source_revision_id
    )

    assert (
        repair_request
        .source_canonical_hash
        == original_request
        .source_canonical_hash
    )

    assert (
        repair_request
        .projection_hash
        == original_request
        .projection_hash
    )

    assert (
        repair_request
        .constraint_set_hash
        == original_request
        .constraint_set_hash
    )
```

---

# 15.70 Repair prompt changes request hash

```python
def test_targeted_repair_creates_new_request_hash(
    original_request,
    repair_request,
):
    hasher = (
        RenderRequestHasher()
    )

    assert (
        hasher.hash(
            original_request
        )
        != hasher.hash(
            repair_request
        )
    )
```

---

# 15.71 Parent artifact must be first reference

```python
def test_repair_source_is_first_reference(
    repair_request,
    parent_artifact,
):
    ordered = (
        repair_request
        .references
        .ordered()
    )

    assert (
        ordered[0].artifact_id
        == parent_artifact.artifact_id
    )
```

---

# 15.72 Max repair count

```python
def test_more_than_two_repairs_goes_to_human_review():
    policy = TargetedRepairPolicy(
        max_repairs_per_render=2
    )

    current_generation = 2

    assert (
        current_generation
        >= policy.max_repairs_per_render
    )
```

Laravel mới là authoritative enforcement:

```php
if ($repairGeneration >= 2) {
    return 'human_review';
}
```

---

# 15.73 QA cost accounting

Vision QA cũng tốn API money.

Không ghi chung với image render attempt.

Action:

```text
vision_qa
```

Cost lineage:

```text
qa_run_id
provider
model
input tokens
image tokens
output tokens
cost
```

`cost_idempotency_key`:

```text
vision-qa:{qa_run_id}
```

Repair render cost vẫn là:

```text
render-attempt:{repair_attempt_id}:provider-usage
```

Tách rõ.

---

# 15.74 Laravel cost entry QA

```php
$costKey =
    'vision-qa:'
    . $qaRun->id;

CostEntry::query()
    ->firstOrCreate(
        [
            'cost_idempotency_key' =>
                $costKey,
        ],

        [
            'source_type' =>
                'render_qa_run',

            'source_id' =>
                $qaRun->id,

            'action' =>
                'vision_qa',

            'provider' =>
                $qaRun
                    ->vision_provider,

            'model' =>
                $qaRun
                    ->vision_model,

            // usage fields...
        ]
    );
```

---

# 15.75 Full Part15 flow

```text
RENDER SUCCEEDED
      ↓
primary image artifact
      ↓
verify artifact SHA-256
      ↓
ConstraintSet
      ↓
filter visual_verification=true
      ↓
PromptSpec lineage index
      ↓
VerificationTargetBuilder
      ↓
QaRequest
      ↓
Vision Provider
      ↓
structured per-target observations
      ↓
VisionQaResponseParser
      ↓
DeterministicQaValidator
      ↓
QaFindingFactory
      ↓
QaGate
      │
      ├── PASS
      │     ↓
      │   candidate accepted
      │
      ├── REVIEW
      │     ↓
      │   human review
      │
      └── FAIL
            ↓
        FailureLocalizer
            ↓
        FailureCluster(s)
            ↓
        RepairScopeBuilder
            ↓
        repair generation check
            │
            ├── >= 2
            │      ↓
            │   human review
            │
            └── < 2
                   ↓
            RepairPromptCompiler
                   ↓
            TargetedRepairPlan
                   ↓
            original rendered artifact
            + original references
            + repair prompt
                   ↓
            RenderRequest operation=EDIT
                   ↓
            NEW request_hash
                   ↓
            Part14 RenderOrchestrator
                   ↓
            repaired artifact
                   ↓
            QA again
```

---

# 15.76 Hash lineage sau Part15

Bây giờ ta có:

```text
canonical_hash
      ↓
projection_hash
      ↓
constraint_set_hash
      ↓
prompt_spec_hash
      ↓
prompt_hash
      ↓
render_request_hash R0
      ↓
artifact_hash A0
      ↓
qa_report_hash Q0
      │
      ├── PASS
      │
      └── FAIL
            ↓
        repair_plan
            ↓
        repair_prompt_hash H1
            ↓
        repair_request_hash R1
            ↓
        artifact_hash A1
            ↓
        qa_report_hash Q1
```

Canonical vẫn:

```text
C
```

không đổi.

---

# 15.77 Các lỗi nào target repair tốt?

Tốt:

```text
count sai
feature thừa
feature thiếu
bow shape sai
tier count sai
window continuity sai
material/state sai
temporary feature xuất hiện
opening bị thêm
camera framing hơi sai
```

Khó repair cục bộ:

```text
toàn silhouette sai
length-to-beam lệch mạnh
identity drift toàn thân
topology tổng thể sai
perspective làm biến dạng toàn object
```

Những lỗi này nên:

```text
FULL_RERENDER
```

thay vì edit local.

---

# 15.78 Repair decision classifier

Ta thêm deterministic classifier:

```python
from media_runtime.constraints.enums import (
    ConstraintPriority,
)
from media_runtime.qa.enums import (
    QaFailureType,
    QaRepairDecision,
)


class RepairDecisionClassifier:
    _FULL_RERENDER_FAILURES = {
        QaFailureType.IDENTITY_DRIFT,
    }

    def decide(
        self,
        findings,
    ) -> QaRepairDecision:
        failed = [
            item
            for item in findings
            if item.status.value
            == "fail"
        ]

        if not failed:
            return (
                QaRepairDecision.NO_REPAIR
            )

        if any(
            item.failure_type
            in self._FULL_RERENDER_FAILURES
            for item in failed
        ):
            return (
                QaRepairDecision
                .FULL_RERENDER
            )

        p0_fail = any(
            int(
                item.priority
            ) == 0
            for item in failed
        )

        if p0_fail:
            return (
                QaRepairDecision
                .FULL_RERENDER
            )

        return (
            QaRepairDecision
            .TARGETED_REPAIR
        )
```

Sau đó `QaGate` có thể dùng classifier thay vì luôn `TARGETED_REPAIR`.

Tôi khuyên **production dùng classifier này**.

---

# 15.79 P0 failure không targeted repair

Đây là rule quan trọng.

Nếu:

```text
P0 identity integrity
FAIL
```

thường đối tượng đã drift đáng kể.

Không nên vá:

```text
một chi tiết local
```

Mà:

```text
FULL_RERENDER
using locked references
```

vẫn cùng CanonicalDesignSpec.

---

# 15.80 Full rerender khác repair

`FULL_RERENDER`:

```text
same canonical
same asset projection
same constraints
same PromptSpec
same reference set
same provider unless policy changes
new render generation
```

Không dùng failed artifact làm primary edit source.

Targeted repair:

```text
failed artifact
= first reference
```

Đây là distinction rất hữu ích.

---

# 15.81 QA status trong `renders`

Có thể thêm fields nhanh để query:

```php
$table
    ->string(
        'qa_status',
        40
    )
    ->nullable()
    ->index();

$table
    ->uuid(
        'latest_qa_run_id'
    )
    ->nullable();

$table
    ->boolean(
        'qa_approved'
    )
    ->default(false);
```

Nhưng detailed truth vẫn ở:

```text
render_qa_runs
render_qa_findings
```

---

# 15.82 Khi QA PASS

Laravel:

```php
$render->forceFill([
    'qa_status' => 'passed',
    'qa_approved' => true,
    'latest_qa_run_id' =>
        $qaRun->id,
])->save();
```

Nhưng nếu asset là anchor, **chưa khóa identity ngay tại Part15**.

Part16 mới xử lý:

```text
QA PASS
+
human/admin approval
↓
Anchor Lock
```

---

# 15.83 QA retry khác repair

Vision provider lỗi 429:

```text
QA infrastructure retry
```

không tính:

```text
repair_generation
```

QA result FAIL:

```text
visual repair
```

mới tăng:

```text
repair_generation
```

Giống triết lý Part14.

---

# 15.84 Không dùng cùng Vision model làm “judge” vô hạn

Để tránh hallucinated repeated failures:

```text
repair 1 fail
repair 2 fail
↓
human review
```

Không:

```text
repair 3...
repair 10...
```

Vừa tốn tiền vừa có thể identity drift.

---

# 15.85 Production bootstrap

```python
from media_runtime.qa.artifact.hashing import (
    QaArtifactIntegrityVerifier,
)
from media_runtime.qa.extraction.verification_target_builder import (
    VerificationTargetBuilder,
)
from media_runtime.qa.localization.failure_localizer import (
    FailureLocalizer,
)
from media_runtime.qa.localization.repair_scope import (
    RepairScopeBuilder,
)
from media_runtime.qa.policy import (
    QaGatePolicy,
)
from media_runtime.qa.verification.deterministic_validator import (
    DeterministicQaValidator,
)
from media_runtime.qa.verification.gate import (
    QaGate,
)
from media_runtime.qa.verification.report_builder import (
    QaReportBuilder,
)
from media_runtime.qa.vision.prompt_builder import (
    VisionQaPromptBuilder,
)
from media_runtime.qa.vision.response_parser import (
    VisionQaResponseParser,
)
from media_runtime.qa.vision.service import (
    VisionQaInspectionService,
)
from media_runtime.qa.service import (
    VisionQaService,
)


def build_vision_qa_service(
    *,
    vision_provider,
) -> VisionQaService:
    inspector = (
        VisionQaInspectionService(
            provider=vision_provider,

            prompt_builder=(
                VisionQaPromptBuilder()
            ),

            parser=(
                VisionQaResponseParser()
            ),
        )
    )

    return VisionQaService(
        integrity_verifier=(
            QaArtifactIntegrityVerifier()
        ),

        target_builder=(
            VerificationTargetBuilder()
        ),

        vision_inspector=(
            inspector
        ),

        deterministic_validator=(
            DeterministicQaValidator()
        ),

        gate=QaGate(
            QaGatePolicy(
                hard_pass_min_confidence=0.80,
                soft_pass_min_confidence=0.70,
                uncertain_requires_review=True,
                hard_not_visible_requires_review=True,
                maximum_soft_failures=0,
                max_targeted_repairs=2,
            )
        ),

        report_builder=(
            QaReportBuilder()
        ),

        failure_localizer=(
            FailureLocalizer()
        ),

        repair_scope_builder=(
            RepairScopeBuilder()
        ),
    )
```

---

# 15.86 Production flow giữa Part14 và Part15

Chốt integration:

```text
Part14 Render
      ↓
RenderStatus::SUCCEEDED
      ↓
primary artifact selected
      ↓
DispatchVisionQaJob
      ↓
Python VisionQaService
      ↓
QAReport
      ↓
POST Laravel checkpoint
      ↓
Laravel QaDecisionService
      │
      ├── PASS
      │      ↓
      │   ready_for_approval
      │
      ├── REVIEW
      │      ↓
      │   admin review queue
      │
      └── FAIL
             ↓
         repair generation check
             ↓
         build TargetedRepairPlan
             ↓
         build new RenderRequest
             ↓
         freeze new request
             ↓
         Part14
             ↓
         render
             ↓
         Part15 again
```

---

# 15.87 Các invariant chốt chính thức sau Part15

```text
1. Vision model không quyết định requirement cần kiểm.
   VerificationTargetBuilder quyết định deterministic.

2. Vision model không quyết định final PASS/FAIL.
   QaGate quyết định deterministic.

3. Chỉ constraint có visual_verification=true
   mới được kiểm bằng Vision.

4. QAReport luôn khóa vào exact:
   canonical_hash
   constraint_set_hash
   prompt_spec_hash
   request_hash
   artifact_hash.

5. NOT_VISIBLE không được biến thành FAIL.

6. UNCERTAIN không được biến thành PASS.

7. Hard constraint confidence thấp không được PASS.

8. Vision không được invent target mới.

9. Vision phải trả exactly one result cho mỗi target.

10. QA failure không được mutate CanonicalDesignSpec.

11. QA failure không được mutate ConstraintSet.

12. Repair chỉ dùng failed constraints làm correction target.

13. Passed constraints được đưa vào preservation set.

14. Targeted repair dùng EDIT + failed artifact
    + locked references.

15. P0 identity failure ưu tiên FULL_RERENDER,
    không local repair.

16. Repair request luôn có request_hash mới.

17. Infrastructure retry không tăng repair_generation.

18. Repair tối đa 2 vòng rồi human review.

19. Vision evidence chỉ là audit evidence,
    không trở thành design truth.

20. QA cost và render cost được account riêng.
```

Với **Phần 15**, pipeline hiện tại đã đạt:

```text
Canonical truth
        ↓
deterministic rendering constraints
        ↓
provider render
        ↓
immutable artifact
        ↓
constraint-by-constraint visual verification
        ↓
deterministic QA gate
        ↓
localized failure
        ↓
targeted repair
        ↓
re-render without redesign
```

Bước tiếp theo là **Phần 16 — `Anchor Approval + Reference Pack Generation + Multi-view QA + Identity Lock`**. Đây sẽ là phần rất quan trọng cho hệ thống của bạn vì sau khi một `master anchor` PASS QA và được duyệt, ta sẽ sinh bộ reference views, kiểm cross-view consistency rồi tạo một **Identity Lock Manifest**; từ thời điểm đó scene/video phía sau chỉ được dùng revision/reference pack đã khóa, không còn tự chọn lại hình dạng đối tượng.
