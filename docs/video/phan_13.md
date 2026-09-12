Được. **Phần 13** là bước bắt đầu thực thi render thật, nhưng tôi vẫn giữ đúng boundary đã khóa:

> `Provider Adapter` chỉ chuyển `CompiledPrompt + ReferenceSet + RenderRequest` thành request đúng API của từng provider. Nó **không sửa prompt semantics, không tự chọn lại canonical constraints, không tự thêm/xóa reference**.

Tôi đã kiểm lại API hiện tại trước khi chốt code. OpenAI hiện có `GPT-Image-2` là model image generation/editing chính; image API trả image dạng base64 và usage metadata cho GPT image models. ([OpenAI Platform][1]) Google hiện khuyến nghị **Interactions API** cho project mới từ tháng 6/2026; `gemini-3.1-flash-image` là model stable hỗ trợ text+image input, image output, editing, nhiều reference image và các output option như aspect ratio/image size. ([Google AI for Developers][2])

---

# PHẦN 13 — `Provider Adapter + RenderRequest + ReferenceSet + Request Hash + Artifact Ledger`

Architecture:

```text
CompiledPrompt
      +
ReferenceSet
      +
RenderRequest
      ↓
RenderRequestValidator
      ↓
ProviderRouter
      ↓
ProviderAdapter
      ↓
ProviderRequest
      ↓
RequestHasher
      ↓
ArtifactLedger.begin()
      ↓
Provider API
      ↓
ProviderResponse
      ↓
ArtifactWriter
      ↓
Artifact hash
      ↓
ArtifactLedger.complete()
      ↓
RenderResult
```

Cấu trúc:

```text
media_runtime/
├── render/
│   ├── __init__.py
│   ├── enums.py
│   ├── exceptions.py
│   ├── request.py
│   ├── result.py
│   ├── reference.py
│   ├── reference_set.py
│   ├── request_hash.py
│   ├── validator.py
│   │
│   ├── providers/
│   │   ├── __init__.py
│   │   ├── base.py
│   │   ├── response.py
│   │   ├── registry.py
│   │   ├── openai_image.py
│   │   └── gemini_image.py
│   │
│   ├── artifacts/
│   │   ├── __init__.py
│   │   ├── artifact.py
│   │   ├── writer.py
│   │   ├── manifest.py
│   │   └── ledger.py
│   │
│   └── service.py
│
└── tests/render/
    ├── test_reference_set.py
    ├── test_request_hash.py
    ├── test_openai_adapter.py
    ├── test_gemini_adapter.py
    ├── test_artifact_writer.py
    ├── test_artifact_ledger.py
    └── test_render_service.py
```

---

# 13.1 Render enums

`render/enums.py`

```python
from __future__ import annotations

from enum import StrEnum


class RenderOperation(StrEnum):
    GENERATE = "generate"
    EDIT = "edit"


class RenderMediaType(StrEnum):
    IMAGE = "image"


class RenderStatus(StrEnum):
    PREPARED = "prepared"
    SUBMITTED = "submitted"
    SUCCEEDED = "succeeded"
    FAILED = "failed"


class ImageOutputFormat(StrEnum):
    PNG = "png"
    JPEG = "jpeg"
    WEBP = "webp"


class RenderQuality(StrEnum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    AUTO = "auto"


class ReferenceRole(StrEnum):
    IDENTITY = "identity"
    GEOMETRY = "geometry"
    VIEW = "view"
    STATE = "state"
    ENVIRONMENT = "environment"
    STYLE = "style"


class ArtifactKind(StrEnum):
    REQUEST = "request"
    RESPONSE = "response"

    GENERATED_IMAGE = "generated_image"

    PROMPT = "prompt"
    NEGATIVE_PROMPT = "negative_prompt"

    REFERENCE_IMAGE = "reference_image"

    MANIFEST = "manifest"


class ArtifactStatus(StrEnum):
    CREATED = "created"
    VERIFIED = "verified"
    CORRUPT = "corrupt"
```

---

# 13.2 Exceptions

`render/exceptions.py`

```python
from __future__ import annotations


class RenderRuntimeError(RuntimeError):
    """Base render execution error."""


class RenderRequestError(RenderRuntimeError):
    """RenderRequest is invalid."""


class ReferenceSetError(RenderRuntimeError):
    """ReferenceSet is invalid or inconsistent."""


class ProviderAdapterError(RenderRuntimeError):
    """Provider adapter failed."""


class ProviderResponseError(RenderRuntimeError):
    """Provider returned malformed or unusable output."""


class ArtifactIntegrityError(RenderRuntimeError):
    """Artifact bytes do not match expected hash."""


class ArtifactLedgerError(RenderRuntimeError):
    """Artifact ledger operation failed."""


class ProviderRoutingError(RenderRuntimeError):
    """No compatible provider could execute the request."""
```

---

# 13.3 Reference asset

Ta không truyền file path mơ hồ kiểu:

```python
images=["a.png", "b.png"]
```

Mỗi reference phải có identity + hash + role.

`render/reference.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from media_runtime.render.enums import (
    ReferenceRole,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceAsset:
    artifact_id: str

    path: Path

    sha256: str

    mime_type: str

    role: ReferenceRole

    priority: int

    source_revision_id: str | None

    source_canonical_hash: str | None

    width: int | None = None

    height: int | None = None

    def __post_init__(
        self,
    ) -> None:
        if not self.artifact_id:
            raise ValueError(
                "artifact_id must not be empty"
            )

        if len(self.sha256) != 64:
            raise ValueError(
                "reference sha256 must be 64 hex chars"
            )

        if self.priority < 0:
            raise ValueError(
                "reference priority must be >= 0"
            )

        if not self.mime_type.startswith(
            "image/"
        ):
            raise ValueError(
                "ReferenceAsset must be an image"
            )
```

---

# 13.4 `ReferenceSet`

`render/reference_set.py`

```python
from __future__ import annotations

import hashlib

from dataclasses import dataclass
from pathlib import Path

from media_runtime.render.exceptions import (
    ArtifactIntegrityError,
    ReferenceSetError,
)
from media_runtime.render.reference import (
    ReferenceAsset,
)


@dataclass(
    frozen=True,
    slots=True,
)
class ReferenceSet:
    version: str

    asset_id: str

    source_revision_id: str

    source_canonical_hash: str

    references: tuple[
        ReferenceAsset,
        ...,
    ]

    def __post_init__(
        self,
    ) -> None:
        ids = [
            item.artifact_id
            for item in self.references
        ]

        if len(ids) != len(set(ids)):
            raise ReferenceSetError(
                "Duplicate reference artifact_id"
            )

        for item in self.references:
            if (
                item.source_revision_id
                is not None
                and item.source_revision_id
                != self.source_revision_id
            ):
                raise ReferenceSetError(
                    "Reference revision lineage mismatch: "
                    f"{item.artifact_id}"
                )

            if (
                item.source_canonical_hash
                is not None
                and item.source_canonical_hash
                != self.source_canonical_hash
            ):
                raise ReferenceSetError(
                    "Reference canonical hash mismatch: "
                    f"{item.artifact_id}"
                )

    def ordered(
        self,
    ) -> tuple[ReferenceAsset, ...]:
        return tuple(
            sorted(
                self.references,
                key=lambda item: (
                    item.priority,
                    item.role.value,
                    item.artifact_id,
                ),
            )
        )

    def verify_files(
        self,
    ) -> None:
        for reference in self.references:
            if not reference.path.is_file():
                raise ReferenceSetError(
                    "Reference file missing: "
                    f"{reference.path}"
                )

            actual = self._sha256_file(
                reference.path
            )

            if not hashlib.compare_digest(
                actual,
                reference.sha256,
            ):
                raise ArtifactIntegrityError(
                    "Reference artifact hash mismatch: "
                    f"{reference.artifact_id}"
                )

    @staticmethod
    def _sha256_file(
        path: Path,
    ) -> str:
        digest = hashlib.sha256()

        with path.open("rb") as handle:
            while chunk := handle.read(
                1024 * 1024
            ):
                digest.update(chunk)

        return digest.hexdigest()

    @classmethod
    def empty(
        cls,
        *,
        asset_id: str,
        source_revision_id: str,
        source_canonical_hash: str,
    ) -> "ReferenceSet":
        return cls(
            version="reference-set-v1",
            asset_id=asset_id,
            source_revision_id=source_revision_id,
            source_canonical_hash=(
                source_canonical_hash
            ),
            references=(),
        )
```

---

# 13.5 RenderRequest

`RenderRequest` không chứa provider HTTP payload.

Nó là universal execution contract.

`render/request.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from media_runtime.prompt.compiled_prompt import (
    CompiledPrompt,
)
from media_runtime.render.enums import (
    ImageOutputFormat,
    RenderMediaType,
    RenderOperation,
    RenderQuality,
)
from media_runtime.render.reference_set import (
    ReferenceSet,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RenderRequest:
    version: str

    render_id: str

    run_id: str
    session_code: str

    asset_id: str

    media_type: RenderMediaType

    operation: RenderOperation

    provider_key: str
    model_key: str

    source_revision_id: str
    source_canonical_hash: str

    projection_hash: str
    constraint_set_hash: str
    prompt_spec_hash: str
    provider_prompt_plan_hash: str

    compiled_prompt: CompiledPrompt

    references: ReferenceSet

    width: int | None

    height: int | None

    aspect_ratio: str | None

    quality: RenderQuality

    output_format: ImageOutputFormat

    provider_options: dict[
        str,
        Any,
    ]

    idempotency_key: str

    def __post_init__(
        self,
    ) -> None:
        if not self.render_id:
            raise ValueError(
                "render_id must not be empty"
            )

        if not self.asset_id:
            raise ValueError(
                "asset_id must not be empty"
            )

        if not self.idempotency_key:
            raise ValueError(
                "idempotency_key must not be empty"
            )
```

---

# 13.6 Không cho `provider_options` sửa semantic fields

`provider_options` chỉ chứa low-level knobs kiểu:

```text
background
compression
seed
response format
thinking mode
```

Không được chứa:

```text
prompt
negative_prompt
references
canonical_hash
```

Validator phải chặn.

---

# 13.7 RenderRequestValidator

`render/validator.py`

```python
from __future__ import annotations

from media_runtime.render.enums import (
    RenderMediaType,
    RenderOperation,
)
from media_runtime.render.exceptions import (
    RenderRequestError,
)
from media_runtime.render.request import (
    RenderRequest,
)


class RenderRequestValidator:
    _FORBIDDEN_PROVIDER_OPTION_KEYS = {
        "prompt",
        "negative_prompt",
        "references",
        "reference_images",
        "canonical_json",
        "canonical_hash",
        "compiled_prompt",
    }

    def validate(
        self,
        request: RenderRequest,
    ) -> None:
        if (
            request.media_type
            != RenderMediaType.IMAGE
        ):
            raise RenderRequestError(
                "Part 13 supports image rendering only"
            )

        if (
            request.asset_id
            != request.references.asset_id
        ):
            raise RenderRequestError(
                "RenderRequest/ReferenceSet "
                "asset mismatch"
            )

        if (
            request.source_revision_id
            != request.references
            .source_revision_id
        ):
            raise RenderRequestError(
                "RenderRequest/ReferenceSet "
                "revision mismatch"
            )

        if (
            request.source_canonical_hash
            != request.references
            .source_canonical_hash
        ):
            raise RenderRequestError(
                "RenderRequest/ReferenceSet "
                "canonical hash mismatch"
            )

        if (
            request.compiled_prompt
            .provider_key
            != request.provider_key
        ):
            raise RenderRequestError(
                "CompiledPrompt provider mismatch"
            )

        if (
            request.compiled_prompt
            .model_key
            != request.model_key
        ):
            raise RenderRequestError(
                "CompiledPrompt model mismatch"
            )

        if (
            request.operation
            == RenderOperation.GENERATE
            and request.references.references
        ):
            /*
             * A provider may still support generation
             * with references, but semantically this
             * becomes reference-guided generation/edit.
             *
             * We deliberately require EDIT for V1
             * whenever source images are supplied.
             */
            raise RenderRequestError(
                "Reference-guided image request "
                "must use operation=edit"
            )

        if (
            request.operation
            == RenderOperation.EDIT
            and not request.references.references
        ):
            raise RenderRequestError(
                "Image edit requires at least "
                "one reference image"
            )

        if (
            request.width is not None
            and request.width < 1
        ):
            raise RenderRequestError(
                "width must be positive"
            )

        if (
            request.height is not None
            and request.height < 1
        ):
            raise RenderRequestError(
                "height must be positive"
            )

        forbidden = (
            self._FORBIDDEN_PROVIDER_OPTION_KEYS
            & set(
                request.provider_options.keys()
            )
        )

        if forbidden:
            raise RenderRequestError(
                "provider_options contains "
                "semantic fields: "
                + ", ".join(
                    sorted(forbidden)
                )
            )
```

---

# 13.8 Một chỉnh sửa nhỏ: `generate + references`

Ở abstraction universal của bạn, tôi khuyên:

```text
GENERATE
= text → image, không reference

EDIT
= text + image(s) → image
```

Mặc dù provider có thể gọi tính năng bằng tên khác.

Như vậy internal semantics sạch:

```text
GENERATE
EDIT
```

Provider adapter map sang API thực.

---

# 13.9 Request canonicalization

Hash request phải deterministic.

Không hash API key.

Không hash local absolute file path.

Hash reference **content hashes**.

`render/request_hash.py`

```python
from __future__ import annotations

import hashlib
import json

from enum import Enum
from typing import Any

from media_runtime.render.request import (
    RenderRequest,
)


class RenderRequestHasher:
    VERSION = "render-request-hash-v1"

    def hash(
        self,
        request: RenderRequest,
    ) -> str:
        payload = {
            "version":
                request.version,

            "asset_id":
                request.asset_id,

            "media_type":
                request.media_type.value,

            "operation":
                request.operation.value,

            "provider_key":
                request.provider_key,

            "model_key":
                request.model_key,

            "source_revision_id":
                request.source_revision_id,

            "source_canonical_hash":
                request.source_canonical_hash,

            "projection_hash":
                request.projection_hash,

            "constraint_set_hash":
                request.constraint_set_hash,

            "prompt_spec_hash":
                request.prompt_spec_hash,

            "provider_prompt_plan_hash":
                request.provider_prompt_plan_hash,

            "prompt_hash":
                request.compiled_prompt
                .prompt_hash,

            "negative_prompt_hash":
                request.compiled_prompt
                .negative_prompt_hash,

            "native_controls":
                self._plain(
                    request.compiled_prompt
                    .native_controls
                ),

            "references": [
                {
                    "artifact_id":
                        ref.artifact_id,

                    "sha256":
                        ref.sha256,

                    "mime_type":
                        ref.mime_type,

                    "role":
                        ref.role.value,

                    "priority":
                        ref.priority,
                }
                for ref
                in request.references.ordered()
            ],

            "width":
                request.width,

            "height":
                request.height,

            "aspect_ratio":
                request.aspect_ratio,

            "quality":
                request.quality.value,

            "output_format":
                request.output_format.value,

            "provider_options":
                self._plain(
                    request.provider_options
                ),
        }

        encoded = json.dumps(
            payload,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        return hashlib.sha256(
            encoded
        ).hexdigest()

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(value, Enum):
            return value.value

        if isinstance(value, dict):
            return {
                str(key): cls._plain(child)
                for key, child
                in value.items()
            }

        if isinstance(
            value,
            (
                list,
                tuple,
            ),
        ):
            return [
                cls._plain(item)
                for item in value
            ]

        return value
```

---

# 13.10 Vì sao không hash `render_id`?

Hai logical requests giống hệt nhau nhưng được retry có thể có cùng:

```text
request_hash
```

dù:

```text
render_id
```

khác ở workflow khác.

`request_hash` trả lời:

> exact render semantics + provider settings này có giống nhau không?

`render_id` trả lời:

> execution record nào?

Không trộn hai thứ.

---

# 13.11 Provider response DTO

`render/providers/response.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderUsage:
    input_tokens: int | None = None
    output_tokens: int | None = None

    image_input_tokens: int | None = None
    text_input_tokens: int | None = None

    provider_cost_usd: str | None = None


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderGeneratedArtifact:
    content: bytes

    mime_type: str

    width: int | None = None
    height: int | None = None


@dataclass(
    frozen=True,
    slots=True,
)
class ProviderRenderResponse:
    provider_key: str

    model_key: str

    provider_request_id: str | None

    artifacts: tuple[
        ProviderGeneratedArtifact,
        ...,
    ]

    usage: ProviderUsage

    raw_response: dict[
        str,
        Any,
    ]

    latency_ms: int
```

---

# 13.12 Provider Adapter interface

`render/providers/base.py`

```python
from __future__ import annotations

from abc import ABC, abstractmethod

from media_runtime.render.providers.response import (
    ProviderRenderResponse,
)
from media_runtime.render.request import (
    RenderRequest,
)


class ImageProviderAdapter(
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
    def render(
        self,
        request: RenderRequest,
    ) -> ProviderRenderResponse:
        raise NotImplementedError
```

---

# 13.13 Provider registry

`render/providers/registry.py`

```python
from __future__ import annotations

from media_runtime.render.exceptions import (
    ProviderRoutingError,
)
from media_runtime.render.providers.base import (
    ImageProviderAdapter,
)


class ImageProviderRegistry:
    def __init__(
        self,
        adapters: tuple[
            ImageProviderAdapter,
            ...,
        ],
    ) -> None:
        self._adapters: dict[
            str,
            ImageProviderAdapter,
        ] = {}

        for adapter in adapters:
            key = adapter.provider_key

            if key in self._adapters:
                raise ProviderRoutingError(
                    "Duplicate provider adapter: "
                    + key
                )

            self._adapters[key] = adapter

    def get(
        self,
        *,
        provider_key: str,
        model_key: str,
    ) -> ImageProviderAdapter:
        try:
            adapter = self._adapters[
                provider_key
            ]
        except KeyError as exc:
            raise ProviderRoutingError(
                "No image adapter registered for "
                f"{provider_key}"
            ) from exc

        if not adapter.supports_model(
            model_key
        ):
            raise ProviderRoutingError(
                f"Provider {provider_key} "
                f"does not support model {model_key}"
            )

        return adapter
```

---

# 13.14 OpenAI transport

Đừng trộn API HTTP trực tiếp vào adapter business logic.

Tạo transport:

```python
from __future__ import annotations

from typing import Any, Protocol


class OpenAIImageTransport(
    Protocol
):
    def generate(
        self,
        *,
        model: str,
        prompt: str,
        options: dict[str, Any],
    ) -> dict[str, Any]:
        ...

    def edit(
        self,
        *,
        model: str,
        prompt: str,
        images: tuple[
            tuple[str, bytes, str],
            ...,
        ],
        options: dict[str, Any],
    ) -> dict[str, Any]:
        ...
```

Adapter test fake transport, không gọi internet thật.

---

# 13.15 OpenAI adapter

`render/providers/openai_image.py`

```python
from __future__ import annotations

import base64
import time

from typing import Any

from media_runtime.render.enums import (
    RenderOperation,
)
from media_runtime.render.exceptions import (
    ProviderAdapterError,
    ProviderResponseError,
)
from media_runtime.render.providers.base import (
    ImageProviderAdapter,
)
from media_runtime.render.providers.response import (
    ProviderGeneratedArtifact,
    ProviderRenderResponse,
    ProviderUsage,
)
from media_runtime.render.request import (
    RenderRequest,
)


class OpenAIImageAdapter(
    ImageProviderAdapter
):
    PROVIDER_KEY = "openai"

    def __init__(
        self,
        *,
        transport,
        supported_models: frozenset[str],
    ) -> None:
        self._transport = transport
        self._supported_models = (
            supported_models
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
        return (
            model_key
            in self._supported_models
        )

    def render(
        self,
        request: RenderRequest,
    ) -> ProviderRenderResponse:
        started = time.monotonic()

        options = (
            self._build_options(
                request
            )
        )

        try:
            if (
                request.operation
                == RenderOperation.GENERATE
            ):
                raw = self._transport.generate(
                    model=request.model_key,
                    prompt=(
                        request
                        .compiled_prompt
                        .prompt
                    ),
                    options=options,
                )

            elif (
                request.operation
                == RenderOperation.EDIT
            ):
                raw = self._transport.edit(
                    model=request.model_key,
                    prompt=(
                        request
                        .compiled_prompt
                        .prompt
                    ),
                    images=tuple(
                        (
                            reference
                            .path
                            .name,

                            reference
                            .path
                            .read_bytes(),

                            reference.mime_type,
                        )
                        for reference
                        in request
                        .references
                        .ordered()
                    ),
                    options=options,
                )

            else:
                raise ProviderAdapterError(
                    "Unsupported OpenAI "
                    "render operation"
                )

        except ProviderAdapterError:
            raise

        except Exception as exc:
            raise ProviderAdapterError(
                "OpenAI image request failed"
            ) from exc

        latency_ms = int(
            (
                time.monotonic()
                - started
            )
            * 1000
        )

        return (
            self._parse_response(
                request=request,
                raw=raw,
                latency_ms=latency_ms,
            )
        )

    def _build_options(
        self,
        request: RenderRequest,
    ) -> dict[str, Any]:
        options: dict[str, Any] = {}

        controls = (
            request
            .compiled_prompt
            .native_controls
        )

        if (
            request.width is not None
            and request.height is not None
        ):
            options["size"] = (
                f"{request.width}x"
                f"{request.height}"
            )

        options["quality"] = (
            request.quality.value
        )

        options["output_format"] = (
            request.output_format.value
        )

        /*
         * Provider-specific non-semantic controls
         * already validated upstream.
         */
        options.update(
            request.provider_options
        )

        /*
         * Typed native controls win only if they
         * are valid provider controls.
         *
         * No prompt override allowed.
         */
        for key, value in (
            controls.items()
        ):
            if key in {
                "size",
                "quality",
                "output_format",
                "background",
                "output_compression",
            }:
                options[key] = value

        return options

    def _parse_response(
        self,
        *,
        request: RenderRequest,
        raw: dict[str, Any],
        latency_ms: int,
    ) -> ProviderRenderResponse:
        data = raw.get(
            "data"
        )

        if not isinstance(
            data,
            list,
        ) or not data:
            raise ProviderResponseError(
                "OpenAI image response "
                "contains no data"
            )

        artifacts = []

        for index, item in enumerate(
            data
        ):
            if not isinstance(
                item,
                dict,
            ):
                raise ProviderResponseError(
                    "Malformed OpenAI image item"
                )

            encoded = item.get(
                "b64_json"
            )

            if not isinstance(
                encoded,
                str,
            ):
                raise ProviderResponseError(
                    "OpenAI image response "
                    "missing b64_json"
                )

            try:
                content = (
                    base64.b64decode(
                        encoded,
                        validate=True,
                    )
                )
            except Exception as exc:
                raise ProviderResponseError(
                    "Invalid OpenAI base64 image"
                ) from exc

            if not content:
                raise ProviderResponseError(
                    "OpenAI returned empty image"
                )

            artifacts.append(
                ProviderGeneratedArtifact(
                    content=content,

                    mime_type=(
                        self._mime_type(
                            request
                            .output_format
                            .value
                        )
                    ),

                    width=request.width,

                    height=request.height,
                )
            )

        usage_raw = raw.get(
            "usage"
        )

        usage = self._usage(
            usage_raw
        )

        return ProviderRenderResponse(
            provider_key=self.provider_key,

            model_key=request.model_key,

            provider_request_id=(
                self._request_id(
                    raw
                )
            ),

            artifacts=tuple(
                artifacts
            ),

            usage=usage,

            raw_response=(
                self._sanitize_raw_response(
                    raw
                )
            ),

            latency_ms=latency_ms,
        )

    @staticmethod
    def _usage(
        raw: Any,
    ) -> ProviderUsage:
        if not isinstance(
            raw,
            dict,
        ):
            return ProviderUsage()

        details = raw.get(
            "input_tokens_details"
        )

        if not isinstance(
            details,
            dict,
        ):
            details = {}

        return ProviderUsage(
            input_tokens=raw.get(
                "input_tokens"
            ),

            output_tokens=raw.get(
                "output_tokens"
            ),

            image_input_tokens=(
                details.get(
                    "image_tokens"
                )
            ),

            text_input_tokens=(
                details.get(
                    "text_tokens"
                )
            ),
        )

    @staticmethod
    def _request_id(
        raw: dict[str, Any],
    ) -> str | None:
        value = raw.get("id")

        return (
            value
            if isinstance(value, str)
            else None
        )

    @staticmethod
    def _mime_type(
        output_format: str,
    ) -> str:
        return {
            "png":
                "image/png",

            "jpeg":
                "image/jpeg",

            "webp":
                "image/webp",
        }[output_format]

    @staticmethod
    def _sanitize_raw_response(
        raw: dict[str, Any],
    ) -> dict[str, Any]:
        """
        Do NOT duplicate megabytes of base64 in
        response.json and artifact files.
        """

        clone = dict(raw)

        data = clone.get("data")

        if isinstance(data, list):
            cleaned = []

            for item in data:
                if not isinstance(
                    item,
                    dict,
                ):
                    continue

                entry = dict(item)

                if "b64_json" in entry:
                    entry["b64_json"] = (
                        "<stored-as-artifact>"
                    )

                cleaned.append(
                    entry
                )

            clone["data"] = cleaned

        return clone
```

OpenAI’s current image streaming/reference docs expose base64 image output and usage fields including text/image input tokens and output tokens for GPT image models, so normalizing those into `ProviderUsage` is appropriate. ([OpenAI Platform][1])

---

# 13.16 OpenAI SDK transport implementation

Nếu bạn dùng official SDK, transport có thể kiểu:

```python
from __future__ import annotations

from typing import Any

from openai import OpenAI


class OpenAISdkImageTransport:
    def __init__(
        self,
        client: OpenAI,
    ) -> None:
        self._client = client

    def generate(
        self,
        *,
        model: str,
        prompt: str,
        options: dict[str, Any],
    ) -> dict[str, Any]:
        response = (
            self._client.images.generate(
                model=model,
                prompt=prompt,
                **options,
            )
        )

        return response.model_dump(
            mode="json"
        )

    def edit(
        self,
        *,
        model: str,
        prompt: str,
        images: tuple[
            tuple[str, bytes, str],
            ...,
        ],
        options: dict[str, Any],
    ) -> dict[str, Any]:
        files = []

        for filename, content, _mime in (
            images
        ):
            files.append(
                (
                    filename,
                    content,
                )
            )

        /*
         * Exact SDK image-edit argument shape can
         * evolve independently from our domain
         * contract. This is exactly why it lives
         * inside the transport.
         */
        response = (
            self._client.images.edit(
                model=model,
                prompt=prompt,
                image=files,
                **options,
            )
        )

        return response.model_dump(
            mode="json"
        )
```

Ở production, pin version của `openai` package trong requirements.

---

# 13.17 Gemini transport interface

Google từ June 2026 khuyên project mới dùng Interactions API thay vì `generateContent`; Generate Content vẫn được support nhưng được xem là legacy. ([Google AI for Developers][3])

Do đó Part 13 nên target Interactions:

```python
from __future__ import annotations

from typing import Any, Protocol


class GeminiImageTransport(
    Protocol
):
    def create_interaction(
        self,
        *,
        model: str,
        input_items: list[
            dict[str, Any]
        ],
        response_format: dict[
            str,
            Any,
        ],
        options: dict[
            str,
            Any,
        ],
    ) -> dict[str, Any]:
        ...
```

---

# 13.18 Gemini adapter

`render/providers/gemini_image.py`

```python
from __future__ import annotations

import base64
import time

from typing import Any

from media_runtime.render.enums import (
    RenderOperation,
)
from media_runtime.render.exceptions import (
    ProviderAdapterError,
    ProviderResponseError,
)
from media_runtime.render.providers.base import (
    ImageProviderAdapter,
)
from media_runtime.render.providers.response import (
    ProviderGeneratedArtifact,
    ProviderRenderResponse,
    ProviderUsage,
)
from media_runtime.render.request import (
    RenderRequest,
)


class GeminiImageAdapter(
    ImageProviderAdapter
):
    PROVIDER_KEY = "google"

    def __init__(
        self,
        *,
        transport,
        supported_models: frozenset[str],
    ) -> None:
        self._transport = transport
        self._supported_models = (
            supported_models
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
        return (
            model_key
            in self._supported_models
        )

    def render(
        self,
        request: RenderRequest,
    ) -> ProviderRenderResponse:
        started = time.monotonic()

        inputs = self._build_inputs(
            request
        )

        response_format = (
            self._response_format(
                request
            )
        )

        options = dict(
            request.provider_options
        )

        try:
            raw = (
                self._transport
                .create_interaction(
                    model=request.model_key,
                    input_items=inputs,
                    response_format=(
                        response_format
                    ),
                    options=options,
                )
            )
        except Exception as exc:
            raise ProviderAdapterError(
                "Gemini image request failed"
            ) from exc

        latency_ms = int(
            (
                time.monotonic()
                - started
            )
            * 1000
        )

        return self._parse_response(
            request=request,
            raw=raw,
            latency_ms=latency_ms,
        )

    def _build_inputs(
        self,
        request: RenderRequest,
    ) -> list[dict[str, Any]]:
        result: list[
            dict[str, Any]
        ] = [
            {
                "type": "text",
                "text": (
                    request
                    .compiled_prompt
                    .prompt
                ),
            }
        ]

        if (
            request.operation
            == RenderOperation.EDIT
        ):
            for reference in (
                request
                .references
                .ordered()
            ):
                encoded = base64.b64encode(
                    reference
                    .path
                    .read_bytes()
                ).decode("ascii")

                result.append(
                    {
                        "type": "image",

                        "data": encoded,

                        "mime_type": (
                            reference.mime_type
                        ),
                    }
                )

        return result

    def _response_format(
        self,
        request: RenderRequest,
    ) -> dict[str, Any]:
        output: dict[
            str,
            Any,
        ] = {
            "type": "image",
            "mime_type": (
                self._mime_type(
                    request
                    .output_format
                    .value
                )
            ),
        }

        if request.aspect_ratio:
            output[
                "aspect_ratio"
            ] = request.aspect_ratio

        image_size = (
            request
            .compiled_prompt
            .native_controls
            .get("image_size")
        )

        if image_size is not None:
            output[
                "image_size"
            ] = image_size

        return output

    def _parse_response(
        self,
        *,
        request: RenderRequest,
        raw: dict[str, Any],
        latency_ms: int,
    ) -> ProviderRenderResponse:
        artifacts = []

        /*
         * Interactions API convenience output can
         * expose output_image, while raw response
         * also has model_output steps.
         *
         * Parse steps so multiple output images
         * remain supported.
         */
        steps = raw.get(
            "steps",
            []
        )

        if not isinstance(
            steps,
            list,
        ):
            raise ProviderResponseError(
                "Gemini steps must be list"
            )

        for step in steps:
            if not isinstance(
                step,
                dict,
            ):
                continue

            if (
                step.get("type")
                != "model_output"
            ):
                continue

            content = step.get(
                "content",
                []
            )

            if not isinstance(
                content,
                list,
            ):
                continue

            for block in content:
                if not isinstance(
                    block,
                    dict,
                ):
                    continue

                if (
                    block.get("type")
                    != "image"
                ):
                    continue

                encoded = block.get(
                    "data"
                )

                mime_type = block.get(
                    "mime_type",
                    "image/png",
                )

                if not isinstance(
                    encoded,
                    str,
                ):
                    continue

                try:
                    data = (
                        base64.b64decode(
                            encoded,
                            validate=True,
                        )
                    )
                except Exception as exc:
                    raise ProviderResponseError(
                        "Gemini returned invalid "
                        "base64 image"
                    ) from exc

                if not data:
                    raise ProviderResponseError(
                        "Gemini returned empty image"
                    )

                artifacts.append(
                    ProviderGeneratedArtifact(
                        content=data,
                        mime_type=str(
                            mime_type
                        ),
                        width=request.width,
                        height=request.height,
                    )
                )

        if not artifacts:
            /*
             * Support transport-normalized
             * convenience property.
             */
            output_image = raw.get(
                "output_image"
            )

            if isinstance(
                output_image,
                dict,
            ):
                encoded = output_image.get(
                    "data"
                )

                mime_type = (
                    output_image.get(
                        "mime_type",
                        "image/png",
                    )
                )

                if isinstance(
                    encoded,
                    str,
                ):
                    artifacts.append(
                        ProviderGeneratedArtifact(
                            content=(
                                base64.b64decode(
                                    encoded
                                )
                            ),

                            mime_type=str(
                                mime_type
                            ),

                            width=request.width,

                            height=request.height,
                        )
                    )

        if not artifacts:
            raise ProviderResponseError(
                "Gemini generated no image artifact"
            )

        return ProviderRenderResponse(
            provider_key=(
                self.provider_key
            ),

            model_key=(
                request.model_key
            ),

            provider_request_id=(
                raw.get("id")
                if isinstance(
                    raw.get("id"),
                    str,
                )
                else None
            ),

            artifacts=tuple(
                artifacts
            ),

            usage=(
                self._usage(
                    raw
                )
            ),

            raw_response=(
                self._sanitize(
                    raw
                )
            ),

            latency_ms=latency_ms,
        )

    @staticmethod
    def _usage(
        raw: dict[str, Any],
    ) -> ProviderUsage:
        /*
         * Keep parser tolerant because provider
         * telemetry evolves independently.
         */
        usage = raw.get(
            "usage"
        )

        if not isinstance(
            usage,
            dict,
        ):
            return ProviderUsage()

        return ProviderUsage(
            input_tokens=(
                usage.get(
                    "input_tokens"
                )
            ),

            output_tokens=(
                usage.get(
                    "output_tokens"
                )
            ),
        )

    @staticmethod
    def _mime_type(
        output_format: str,
    ) -> str:
        return {
            "png":
                "image/png",

            "jpeg":
                "image/jpeg",

            "webp":
                "image/webp",
        }[
            output_format
        ]

    @classmethod
    def _sanitize(
        cls,
        raw: dict[str, Any],
    ) -> dict[str, Any]:
        def clean(
            value: Any,
        ) -> Any:
            if isinstance(
                value,
                list,
            ):
                return [
                    clean(item)
                    for item in value
                ]

            if isinstance(
                value,
                dict,
            ):
                result = {}

                for key, child in (
                    value.items()
                ):
                    if (
                        key == "data"
                        and isinstance(
                            child,
                            str,
                        )
                        and len(child)
                        > 1024
                    ):
                        result[key] = (
                            "<stored-as-artifact>"
                        )
                    else:
                        result[key] = (
                            clean(child)
                        )

                return result

            return value

        return clean(raw)
```

Google’s current Interactions image-generation examples send text and image inputs as typed `input` items and specify image output settings through `response_format`, including `aspect_ratio`, `mime_type`, and `image_size`. ([Google AI for Developers][4])

---

# 13.19 Gemini SDK transport

```python
from __future__ import annotations

from typing import Any

from google import genai


class GeminiSdkImageTransport:
    def __init__(
        self,
        client: genai.Client,
    ) -> None:
        self._client = client

    def create_interaction(
        self,
        *,
        model: str,
        input_items: list[
            dict[str, Any]
        ],
        response_format: dict[
            str,
            Any,
        ],
        options: dict[
            str,
            Any,
        ],
    ) -> dict[str, Any]:
        interaction = (
            self._client
            .interactions
            .create(
                model=model,

                input=input_items,

                response_format=(
                    response_format
                ),

                **options,
            )
        )

        /*
         * SDK representation may expose a model
         * dump depending on installed SDK version.
         * Keep conversion isolated here.
         */
        if hasattr(
            interaction,
            "model_dump",
        ):
            return interaction.model_dump(
                mode="json"
            )

        raise RuntimeError(
            "Gemini SDK response cannot "
            "be serialized by configured transport"
        )
```

Pin:

```text
google-genai
```

version trong production requirements thay vì auto-upgrade.

---

# 13.20 Gemini model config hiện tại

Đối với đường production hiện tại, model stable bạn có thể đăng ký:

```python
frozenset({
    "gemini-3.1-flash-image",
    "gemini-3-pro-image",
})
```

Google hiện liệt kê `gemini-3.1-flash-image` stable và hỗ trợ image generation/editing; release notes cũng cho biết các preview trước đó đã bị deprecated/shutdown, nên đừng pin `*-preview`. ([Google AI for Developers][2])

---

# 13.21 Artifact DTO

`render/artifacts/artifact.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from media_runtime.render.enums import (
    ArtifactKind,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RenderArtifact:
    artifact_id: str

    kind: ArtifactKind

    path: Path

    sha256: str

    size_bytes: int

    mime_type: str | None

    source_request_hash: str

    ordinal: int
```

---

# 13.22 Artifact Writer

Artifact write phải atomic.

Không:

```python
open(final_path, "wb")
```

rồi process crash giữa chừng.

Dùng temp file + fsync + atomic rename.

`render/artifacts/writer.py`

```python
from __future__ import annotations

import hashlib
import json
import os
import tempfile

from pathlib import Path
from typing import Any

from media_runtime.render.artifacts.artifact import (
    RenderArtifact,
)
from media_runtime.render.enums import (
    ArtifactKind,
)
from media_runtime.render.exceptions import (
    ArtifactIntegrityError,
)


class ArtifactWriter:
    VERSION = "artifact-writer-v1"

    def __init__(
        self,
        root: Path,
    ) -> None:
        self._root = (
            root.resolve()
        )

    def write_bytes(
        self,
        *,
        relative_path: Path,
        data: bytes,
        artifact_id: str,
        kind: ArtifactKind,
        mime_type: str | None,
        request_hash: str,
        ordinal: int,
    ) -> RenderArtifact:
        destination = self._safe_path(
            relative_path
        )

        destination.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        digest = hashlib.sha256(
            data
        ).hexdigest()

        with tempfile.NamedTemporaryFile(
            mode="wb",
            dir=destination.parent,
            delete=False,
            prefix=".tmp-",
        ) as handle:
            temp_path = Path(
                handle.name
            )

            try:
                handle.write(
                    data
                )

                handle.flush()

                os.fsync(
                    handle.fileno()
                )
            except Exception:
                temp_path.unlink(
                    missing_ok=True
                )
                raise

        os.replace(
            temp_path,
            destination,
        )

        actual = self._sha256_file(
            destination
        )

        if not hashlib.compare_digest(
            actual,
            digest,
        ):
            destination.unlink(
                missing_ok=True
            )

            raise ArtifactIntegrityError(
                "Artifact failed post-write "
                "hash verification"
            )

        return RenderArtifact(
            artifact_id=artifact_id,

            kind=kind,

            path=destination,

            sha256=digest,

            size_bytes=len(data),

            mime_type=mime_type,

            source_request_hash=(
                request_hash
            ),

            ordinal=ordinal,
        )

    def write_json(
        self,
        *,
        relative_path: Path,
        value: Any,
        artifact_id: str,
        kind: ArtifactKind,
        request_hash: str,
        ordinal: int = 0,
    ) -> RenderArtifact:
        raw = json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")

        return self.write_bytes(
            relative_path=relative_path,
            data=raw,
            artifact_id=artifact_id,
            kind=kind,
            mime_type="application/json",
            request_hash=request_hash,
            ordinal=ordinal,
        )

    def write_text(
        self,
        *,
        relative_path: Path,
        value: str,
        artifact_id: str,
        kind: ArtifactKind,
        request_hash: str,
        ordinal: int = 0,
    ) -> RenderArtifact:
        return self.write_bytes(
            relative_path=relative_path,

            data=value.encode(
                "utf-8"
            ),

            artifact_id=artifact_id,

            kind=kind,

            mime_type=(
                "text/plain; charset=utf-8"
            ),

            request_hash=request_hash,

            ordinal=ordinal,
        )

    def _safe_path(
        self,
        relative: Path,
    ) -> Path:
        if relative.is_absolute():
            raise ValueError(
                "Artifact path must be relative"
            )

        candidate = (
            self._root
            / relative
        ).resolve()

        if (
            candidate != self._root
            and self._root
            not in candidate.parents
        ):
            raise ValueError(
                "Artifact path escapes root"
            )

        return candidate

    @staticmethod
    def _sha256_file(
        path: Path,
    ) -> str:
        digest = hashlib.sha256()

        with path.open("rb") as handle:
            while chunk := handle.read(
                1024 * 1024
            ):
                digest.update(chunk)

        return digest.hexdigest()
```

---

# 13.23 Artifact directory layout

Chốt:

```text
work/artifacts/
└── {session_code}/
    └── {run_id}/
        └── renders/
            └── {render_id}/
                ├── request.json
                ├── response.json
                ├── compiled_prompt.txt
                ├── negative_prompt.txt
                ├── references.json
                ├── output_000.png
                ├── output_001.png
                └── manifest.json
```

Không overwrite giữa retries.

Part 14 sẽ thêm attempt directories nếu cần:

```text
attempt_001/
attempt_002/
```

---

# 13.24 Artifact manifest

`render/artifacts/manifest.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.render.artifacts.artifact import (
    RenderArtifact,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RenderArtifactManifest:
    version: str

    render_id: str

    request_hash: str

    canonical_hash: str

    projection_hash: str

    constraint_set_hash: str

    prompt_spec_hash: str

    provider_prompt_plan_hash: str

    prompt_hash: str

    provider_key: str

    model_key: str

    provider_request_id: str | None

    artifacts: tuple[
        RenderArtifact,
        ...
    ]
```

---

# 13.25 Artifact Ledger không nên chính là filesystem

Filesystem chứa bytes.

Ledger chứa:

```text
artifact_id
hash
kind
lineage
provider request
usage
status
```

Ta tạo interface để Part 14 có thể dùng DB/Laravel callback sau.

---

# 13.26 Ledger DTOs

`render/artifacts/ledger.py`

```python
from __future__ import annotations

from dataclasses import dataclass
from datetime import (
    datetime,
    timezone,
)

from media_runtime.render.artifacts.artifact import (
    RenderArtifact,
)
from media_runtime.render.enums import (
    RenderStatus,
)
from media_runtime.render.providers.response import (
    ProviderUsage,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RenderLedgerEntry:
    render_id: str

    request_hash: str

    status: RenderStatus

    provider_key: str

    model_key: str

    source_revision_id: str

    canonical_hash: str

    projection_hash: str

    constraint_set_hash: str

    prompt_spec_hash: str

    provider_prompt_plan_hash: str

    prompt_hash: str

    idempotency_key: str

    provider_request_id: str | None

    artifacts: tuple[
        RenderArtifact,
        ...,
    ]

    usage: ProviderUsage | None

    error_code: str | None

    error_message: str | None

    started_at: datetime

    completed_at: datetime | None


class ArtifactLedger:
    def begin(
        self,
        entry: RenderLedgerEntry,
    ) -> None:
        raise NotImplementedError

    def submitted(
        self,
        *,
        render_id: str,
        provider_request_id: (
            str | None
        ),
    ) -> None:
        raise NotImplementedError

    def complete(
        self,
        *,
        render_id: str,
        artifacts: tuple[
            RenderArtifact,
            ...,
        ],
        usage: ProviderUsage,
        provider_request_id: (
            str | None
        ),
    ) -> None:
        raise NotImplementedError

    def fail(
        self,
        *,
        render_id: str,
        error_code: str,
        error_message: str,
    ) -> None:
        raise NotImplementedError

    def find_by_request_hash(
        self,
        request_hash: str,
    ) -> (
        RenderLedgerEntry
        | None
    ):
        raise NotImplementedError
```

---

# 13.27 File ledger implementation cho Python artifact directory

Laravel DB vẫn là ultimate control plane. Nhưng Python nên có local ledger để crash recovery/debug.

```python
from __future__ import annotations

import json
import threading

from dataclasses import asdict
from datetime import (
    datetime,
    timezone,
)
from enum import Enum
from pathlib import Path
from typing import Any

from media_runtime.render.artifacts.ledger import (
    ArtifactLedger,
    RenderLedgerEntry,
)
from media_runtime.render.enums import (
    RenderStatus,
)


class JsonFileArtifactLedger(
    ArtifactLedger
):
    def __init__(
        self,
        path: Path,
    ) -> None:
        self._path = path

        self._lock = (
            threading.RLock()
        )

        self._entries: dict[
            str,
            RenderLedgerEntry,
        ] = {}

    def begin(
        self,
        entry: RenderLedgerEntry,
    ) -> None:
        with self._lock:
            existing = (
                self._entries.get(
                    entry.render_id
                )
            )

            if existing is not None:
                if (
                    existing.request_hash
                    != entry.request_hash
                ):
                    raise RuntimeError(
                        "render_id reused with "
                        "different request_hash"
                    )

                return

            self._entries[
                entry.render_id
            ] = entry

            self._flush()

    def submitted(
        self,
        *,
        render_id: str,
        provider_request_id: (
            str | None
        ),
    ) -> None:
        from dataclasses import replace

        with self._lock:
            current = self._require(
                render_id
            )

            self._entries[
                render_id
            ] = replace(
                current,

                status=(
                    RenderStatus.SUBMITTED
                ),

                provider_request_id=(
                    provider_request_id
                ),
            )

            self._flush()

    def complete(
        self,
        *,
        render_id: str,
        artifacts,
        usage,
        provider_request_id,
    ) -> None:
        from dataclasses import replace

        with self._lock:
            current = self._require(
                render_id
            )

            self._entries[
                render_id
            ] = replace(
                current,

                status=(
                    RenderStatus.SUCCEEDED
                ),

                artifacts=artifacts,

                usage=usage,

                provider_request_id=(
                    provider_request_id
                ),

                completed_at=(
                    datetime.now(
                        timezone.utc
                    )
                ),
            )

            self._flush()

    def fail(
        self,
        *,
        render_id: str,
        error_code: str,
        error_message: str,
    ) -> None:
        from dataclasses import replace

        with self._lock:
            current = self._require(
                render_id
            )

            self._entries[
                render_id
            ] = replace(
                current,

                status=(
                    RenderStatus.FAILED
                ),

                error_code=error_code,

                error_message=(
                    error_message
                ),

                completed_at=(
                    datetime.now(
                        timezone.utc
                    )
                ),
            )

            self._flush()

    def find_by_request_hash(
        self,
        request_hash: str,
    ) -> RenderLedgerEntry | None:
        with self._lock:
            for entry in (
                self._entries.values()
            ):
                if (
                    entry.request_hash
                    == request_hash
                ):
                    return entry

        return None

    def _require(
        self,
        render_id: str,
    ) -> RenderLedgerEntry:
        try:
            return self._entries[
                render_id
            ]
        except KeyError as exc:
            raise RuntimeError(
                "Unknown render ledger entry: "
                + render_id
            ) from exc

    def _flush(
        self,
    ) -> None:
        self._path.parent.mkdir(
            parents=True,
            exist_ok=True,
        )

        payload = {
            render_id:
                self._plain(
                    entry
                )

            for render_id, entry
            in sorted(
                self._entries.items()
            )
        }

        temp = self._path.with_suffix(
            ".tmp"
        )

        temp.write_text(
            json.dumps(
                payload,
                ensure_ascii=False,
                sort_keys=True,
                separators=(",", ":"),
            ),
            encoding="utf-8",
        )

        temp.replace(
            self._path
        )

    @classmethod
    def _plain(
        cls,
        value: Any,
    ) -> Any:
        if isinstance(value, Enum):
            return value.value

        if isinstance(
            value,
            datetime,
        ):
            return value.isoformat()

        if isinstance(
            value,
            Path,
        ):
            return str(value)

        if hasattr(
            value,
            "__dataclass_fields__",
        ):
            return cls._plain(
                asdict(value)
            )

        if isinstance(value, dict):
            return {
                key:
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

Ở Part 14 ta sẽ nâng local ledger thành resumable attempt ledger + Laravel callback/API persistence.

---

# 13.28 Render result

`render/result.py`

```python
from __future__ import annotations

from dataclasses import dataclass

from media_runtime.render.artifacts.artifact import (
    RenderArtifact,
)
from media_runtime.render.providers.response import (
    ProviderUsage,
)


@dataclass(
    frozen=True,
    slots=True,
)
class RenderResult:
    render_id: str

    request_hash: str

    provider_key: str

    model_key: str

    provider_request_id: str | None

    artifacts: tuple[
        RenderArtifact,
        ...,
    ]

    usage: ProviderUsage

    reused: bool
```

---

# 13.29 RenderService

Đây là façade chính.

`render/service.py`

```python
from __future__ import annotations

import json

from datetime import (
    datetime,
    timezone,
)
from pathlib import Path
from typing import Any

from media_runtime.render.artifacts.ledger import (
    ArtifactLedger,
    RenderLedgerEntry,
)
from media_runtime.render.artifacts.writer import (
    ArtifactWriter,
)
from media_runtime.render.enums import (
    ArtifactKind,
    RenderStatus,
)
from media_runtime.render.exceptions import (
    RenderRuntimeError,
)
from media_runtime.render.providers.registry import (
    ImageProviderRegistry,
)
from media_runtime.render.request import (
    RenderRequest,
)
from media_runtime.render.request_hash import (
    RenderRequestHasher,
)
from media_runtime.render.result import (
    RenderResult,
)
from media_runtime.render.validator import (
    RenderRequestValidator,
)


class ImageRenderService:
    def __init__(
        self,
        *,
        validator: RenderRequestValidator,
        hasher: RenderRequestHasher,
        providers: ImageProviderRegistry,
        artifact_writer: ArtifactWriter,
        ledger: ArtifactLedger,
    ) -> None:
        self._validator = validator

        self._hasher = hasher

        self._providers = providers

        self._artifacts = (
            artifact_writer
        )

        self._ledger = ledger

    def render(
        self,
        request: RenderRequest,
    ) -> RenderResult:
        self._validator.validate(
            request
        )

        request.references.verify_files()

        request_hash = (
            self._hasher.hash(
                request
            )
        )

        /*
         * Part 13 basic idempotency.
         *
         * Part 14 will add claim/lease and
         * retry-state semantics.
         */
        existing = (
            self._ledger
            .find_by_request_hash(
                request_hash
            )
        )

        if (
            existing is not None
            and existing.status
            == RenderStatus.SUCCEEDED
        ):
            return RenderResult(
                render_id=(
                    existing.render_id
                ),

                request_hash=(
                    request_hash
                ),

                provider_key=(
                    existing.provider_key
                ),

                model_key=(
                    existing.model_key
                ),

                provider_request_id=(
                    existing
                    .provider_request_id
                ),

                artifacts=(
                    existing.artifacts
                ),

                usage=(
                    existing.usage
                    or self._empty_usage()
                ),

                reused=True,
            )

        base = Path(
            request.session_code,
            request.run_id,
            "renders",
            request.render_id,
        )

        /*
         * -------------------------------------
         * Checkpoint immutable request inputs.
         * -------------------------------------
         */
        prompt_artifact = (
            self._artifacts.write_text(
                relative_path=(
                    base
                    / "compiled_prompt.txt"
                ),

                value=(
                    request
                    .compiled_prompt
                    .prompt
                ),

                artifact_id=(
                    request.render_id
                    + ":prompt"
                ),

                kind=(
                    ArtifactKind.PROMPT
                ),

                request_hash=(
                    request_hash
                ),
            )
        )

        artifact_records = [
            prompt_artifact
        ]

        if (
            request
            .compiled_prompt
            .negative_prompt
        ):
            artifact_records.append(
                self._artifacts.write_text(
                    relative_path=(
                        base
                        / "negative_prompt.txt"
                    ),

                    value=(
                        request
                        .compiled_prompt
                        .negative_prompt
                    ),

                    artifact_id=(
                        request.render_id
                        + ":negative"
                    ),

                    kind=(
                        ArtifactKind
                        .NEGATIVE_PROMPT
                    ),

                    request_hash=(
                        request_hash
                    ),
                )
            )

        references_json = [
            {
                "artifact_id":
                    item.artifact_id,

                "sha256":
                    item.sha256,

                "mime_type":
                    item.mime_type,

                "role":
                    item.role.value,

                "priority":
                    item.priority,

                "source_revision_id":
                    item.source_revision_id,

                "source_canonical_hash":
                    item.source_canonical_hash,
            }
            for item in (
                request
                .references
                .ordered()
            )
        ]

        artifact_records.append(
            self._artifacts.write_json(
                relative_path=(
                    base
                    / "references.json"
                ),

                value=references_json,

                artifact_id=(
                    request.render_id
                    + ":references"
                ),

                kind=(
                    ArtifactKind.REQUEST
                ),

                request_hash=request_hash,
            )
        )

        request_payload = (
            self._request_manifest(
                request=request,
                request_hash=request_hash,
            )
        )

        artifact_records.append(
            self._artifacts.write_json(
                relative_path=(
                    base
                    / "request.json"
                ),

                value=request_payload,

                artifact_id=(
                    request.render_id
                    + ":request"
                ),

                kind=(
                    ArtifactKind.REQUEST
                ),

                request_hash=request_hash,
            )
        )

        entry = RenderLedgerEntry(
            render_id=request.render_id,

            request_hash=request_hash,

            status=(
                RenderStatus.PREPARED
            ),

            provider_key=(
                request.provider_key
            ),

            model_key=request.model_key,

            source_revision_id=(
                request.source_revision_id
            ),

            canonical_hash=(
                request
                .source_canonical_hash
            ),

            projection_hash=(
                request.projection_hash
            ),

            constraint_set_hash=(
                request.constraint_set_hash
            ),

            prompt_spec_hash=(
                request.prompt_spec_hash
            ),

            provider_prompt_plan_hash=(
                request
                .provider_prompt_plan_hash
            ),

            prompt_hash=(
                request
                .compiled_prompt
                .prompt_hash
            ),

            idempotency_key=(
                request.idempotency_key
            ),

            provider_request_id=None,

            artifacts=tuple(
                artifact_records
            ),

            usage=None,

            error_code=None,

            error_message=None,

            started_at=(
                datetime.now(
                    timezone.utc
                )
            ),

            completed_at=None,
        )

        self._ledger.begin(
            entry
        )

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

        try:
            response = provider.render(
                request
            )

            self._ledger.submitted(
                render_id=request.render_id,

                provider_request_id=(
                    response
                    .provider_request_id
                ),
            )

            response_artifact = (
                self._artifacts.write_json(
                    relative_path=(
                        base
                        / "response.json"
                    ),

                    value=(
                        response.raw_response
                    ),

                    artifact_id=(
                        request.render_id
                        + ":response"
                    ),

                    kind=(
                        ArtifactKind.RESPONSE
                    ),

                    request_hash=(
                        request_hash
                    ),
                )
            )

            artifact_records.append(
                response_artifact
            )

            for index, generated in (
                enumerate(
                    response.artifacts
                )
            ):
                extension = (
                    self._extension(
                        generated.mime_type
                    )
                )

                artifact = (
                    self._artifacts
                    .write_bytes(
                        relative_path=(
                            base
                            / (
                                "output_"
                                f"{index:03d}."
                                f"{extension}"
                            )
                        ),

                        data=(
                            generated.content
                        ),

                        artifact_id=(
                            request.render_id
                            + ":image:"
                            + str(index)
                        ),

                        kind=(
                            ArtifactKind
                            .GENERATED_IMAGE
                        ),

                        mime_type=(
                            generated.mime_type
                        ),

                        request_hash=(
                            request_hash
                        ),

                        ordinal=index,
                    )
                )

                artifact_records.append(
                    artifact
                )

            manifest_payload = {
                "version":
                    "render-manifest-v1",

                "render_id":
                    request.render_id,

                "request_hash":
                    request_hash,

                "canonical_hash":
                    request
                    .source_canonical_hash,

                "projection_hash":
                    request.projection_hash,

                "constraint_set_hash":
                    request
                    .constraint_set_hash,

                "prompt_spec_hash":
                    request.prompt_spec_hash,

                "provider_prompt_plan_hash":
                    request
                    .provider_prompt_plan_hash,

                "prompt_hash":
                    request
                    .compiled_prompt
                    .prompt_hash,

                "provider_key":
                    request.provider_key,

                "model_key":
                    request.model_key,

                "provider_request_id":
                    response
                    .provider_request_id,

                "artifacts": [
                    {
                        "artifact_id":
                            item.artifact_id,

                        "kind":
                            item.kind.value,

                        "path":
                            str(item.path),

                        "sha256":
                            item.sha256,

                        "size_bytes":
                            item.size_bytes,

                        "mime_type":
                            item.mime_type,

                        "ordinal":
                            item.ordinal,
                    }
                    for item in (
                        artifact_records
                    )
                ],
            }

            manifest_artifact = (
                self._artifacts.write_json(
                    relative_path=(
                        base
                        / "manifest.json"
                    ),

                    value=manifest_payload,

                    artifact_id=(
                        request.render_id
                        + ":manifest"
                    ),

                    kind=(
                        ArtifactKind.MANIFEST
                    ),

                    request_hash=(
                        request_hash
                    ),
                )
            )

            artifact_records.append(
                manifest_artifact
            )

            final_artifacts = tuple(
                artifact_records
            )

            self._ledger.complete(
                render_id=request.render_id,

                artifacts=(
                    final_artifacts
                ),

                usage=response.usage,

                provider_request_id=(
                    response
                    .provider_request_id
                ),
            )

            return RenderResult(
                render_id=request.render_id,

                request_hash=request_hash,

                provider_key=(
                    response.provider_key
                ),

                model_key=(
                    response.model_key
                ),

                provider_request_id=(
                    response
                    .provider_request_id
                ),

                artifacts=(
                    final_artifacts
                ),

                usage=response.usage,

                reused=False,
            )

        except Exception as exc:
            self._ledger.fail(
                render_id=request.render_id,

                error_code=(
                    type(exc).__name__
                ),

                error_message=str(
                    exc
                ),
            )

            raise

    @staticmethod
    def _empty_usage():
        from media_runtime.render.providers.response import (
            ProviderUsage,
        )

        return ProviderUsage()

    @staticmethod
    def _extension(
        mime_type: str,
    ) -> str:
        mapping = {
            "image/png": "png",
            "image/jpeg": "jpg",
            "image/webp": "webp",
        }

        try:
            return mapping[
                mime_type
            ]
        except KeyError as exc:
            raise RenderRuntimeError(
                "Unsupported generated MIME type: "
                + mime_type
            ) from exc

    @staticmethod
    def _request_manifest(
        *,
        request: RenderRequest,
        request_hash: str,
    ) -> dict[str, Any]:
        return {
            "version":
                request.version,

            "render_id":
                request.render_id,

            "request_hash":
                request_hash,

            "idempotency_key":
                request.idempotency_key,

            "asset_id":
                request.asset_id,

            "operation":
                request.operation.value,

            "provider_key":
                request.provider_key,

            "model_key":
                request.model_key,

            "source_revision_id":
                request.source_revision_id,

            "source_canonical_hash":
                request
                .source_canonical_hash,

            "projection_hash":
                request.projection_hash,

            "constraint_set_hash":
                request.constraint_set_hash,

            "prompt_spec_hash":
                request.prompt_spec_hash,

            "provider_prompt_plan_hash":
                request
                .provider_prompt_plan_hash,

            "prompt_hash":
                request
                .compiled_prompt
                .prompt_hash,

            "negative_prompt_hash":
                request
                .compiled_prompt
                .negative_prompt_hash,

            "width":
                request.width,

            "height":
                request.height,

            "aspect_ratio":
                request.aspect_ratio,

            "quality":
                request.quality.value,

            "output_format":
                request
                .output_format
                .value,

            "native_controls":
                request
                .compiled_prompt
                .native_controls,

            "references": [
                {
                    "artifact_id":
                        item.artifact_id,

                    "sha256":
                        item.sha256,

                    "role":
                        item.role.value,

                    "priority":
                        item.priority,
                }
                for item in (
                    request
                    .references
                    .ordered()
                )
            ],

            "provider_options":
                request.provider_options,
        }
```

---

# 13.30 Một vấn đề quan trọng: negative prompt với OpenAI/Gemini

Không được tự truyền:

```python
negative_prompt=...
```

cho provider chỉ vì internal `CompiledPrompt` có field đó.

Provider adapter/capability profile phải quyết định.

Nếu provider không có native negative parameter, Part 12 đã phải fallback exclusion vào:

```text
compiled_prompt.prompt
```

và:

```python
negative_prompt is None
```

Do đó adapter không tự nối negative prompt lần nữa.

---

# 13.31 Reference order phải bất biến

Đối với identity consistency, reference order phải:

```text
priority
↓
role
↓
artifact_id
```

không:

```python
os.listdir(...)
```

Nếu một provider có positional semantics thì adapter luôn nhận cùng order.

Ví dụ:

```text
0 identity anchor
1 high 3/4
2 side profile
3 rear reference
```

same input → same request hash.

---

# 13.32 Không hash local path

Rất quan trọng.

Hai server:

```text
D:\work\anchor.png
```

và:

```text
/var/lib/video/anchor.png
```

nhưng bytes giống nhau.

Request phải có cùng semantic hash.

Do đó request hash dùng:

```text
artifact_id
sha256
mime_type
role
priority
```

không dùng path.

---

# 13.33 Provider request hash khác prompt hash

Chain hiện tại:

```text
canonical_hash
      ↓
projection_hash
      ↓
constraint_set_hash
      ↓
prompt_spec_hash
      ↓
provider_prompt_plan_hash
      ↓
prompt_hash
      ↓
render_request_hash
```

`render_request_hash` bao gồm thêm:

```text
provider
model
references
quality
size
format
native controls
provider options
```

Đúng.

---

# 13.34 Idempotency key

Tôi khuyên Laravel tạo:

```text
image-render:
{session_id}:
{asset_id}:
{render_revision}
```

nhưng Python vẫn tính:

```text
request_hash
```

Hai thứ khác nhau.

Ví dụ:

```text
idempotency_key
= "render:session-A:anchor:1"

request_hash
= SHA256(actual exact request semantics)
```

Nếu cùng idempotency key nhưng request hash khác:

```text
FAIL
```

Part 14 sẽ khóa rule này bằng claim table/lease.

---

# 13.35 ReferenceSet từ Part 12

Part 12 có:

```python
ReferenceInput
```

là logical provider planning reference.

Part 13 có:

```python
ReferenceAsset
```

là physical resolved artifact.

Flow:

```text
ReferenceInput
   artifact_id/hash/role
        ↓
Artifact Resolver
        ↓
ReferenceAsset
   + local path/mime/size
        ↓
ReferenceSet
```

Không cho PromptCompiler biết filesystem.

---

# 13.36 Artifact resolver

```python
from __future__ import annotations

from pathlib import Path

from media_runtime.prompt.capabilities.reference_input import (
    ReferenceInput,
)
from media_runtime.render.reference import (
    ReferenceAsset,
)
from media_runtime.render.reference_set import (
    ReferenceSet,
)
from media_runtime.render.enums import (
    ReferenceRole,
)


class ReferenceArtifactResolver:
    def __init__(
        self,
        artifact_repository,
    ) -> None:
        self._artifacts = (
            artifact_repository
        )

    def resolve(
        self,
        *,
        asset_id: str,
        revision_id: str,
        canonical_hash: str,
        inputs: tuple[
            ReferenceInput,
            ...,
        ],
    ) -> ReferenceSet:
        references = []

        for item in inputs:
            artifact = (
                self._artifacts.get(
                    item.artifact_id
                )
            )

            if (
                artifact.sha256
                != item.artifact_hash
            ):
                raise RuntimeError(
                    "ReferenceInput artifact "
                    "hash mismatch"
                )

            references.append(
                ReferenceAsset(
                    artifact_id=(
                        item.artifact_id
                    ),

                    path=Path(
                        artifact.path
                    ),

                    sha256=(
                        item.artifact_hash
                    ),

                    mime_type=(
                        artifact.mime_type
                    ),

                    role=ReferenceRole(
                        item.role.value
                    ),

                    priority=(
                        item.priority
                    ),

                    source_revision_id=(
                        item
                        .source_revision_id
                    ),

                    source_canonical_hash=(
                        item
                        .source_canonical_hash
                    ),

                    width=artifact.width,

                    height=artifact.height,
                )
            )

        return ReferenceSet(
            version="reference-set-v1",

            asset_id=asset_id,

            source_revision_id=(
                revision_id
            ),

            source_canonical_hash=(
                canonical_hash
            ),

            references=tuple(
                references
            ),
        )
```

---

# 13.37 Provider config

Ví dụ:

```python
from dataclasses import dataclass


@dataclass(
    frozen=True,
    slots=True,
)
class ImageProviderConfig:
    openai_models: frozenset[str]

    google_models: frozenset[str]


PROVIDER_CONFIG = (
    ImageProviderConfig(
        openai_models=frozenset({
            "gpt-image-2",
        }),

        google_models=frozenset({
            "gemini-3.1-flash-image",
            "gemini-3-pro-image",
        }),
    )
)
```

OpenAI hiện liệt kê `GPT-Image-2` là model image generation/editing hiện hành; Google liệt kê hai image model stable nói trên. ([OpenAI Platform][5])

---

# 13.38 Bootstrap

```python
from __future__ import annotations

from pathlib import Path

from google import genai
from openai import OpenAI

from media_runtime.render.artifacts.ledger import (
    JsonFileArtifactLedger,
)
from media_runtime.render.artifacts.writer import (
    ArtifactWriter,
)
from media_runtime.render.providers.gemini_image import (
    GeminiImageAdapter,
)
from media_runtime.render.providers.openai_image import (
    OpenAIImageAdapter,
)
from media_runtime.render.providers.registry import (
    ImageProviderRegistry,
)
from media_runtime.render.request_hash import (
    RenderRequestHasher,
)
from media_runtime.render.service import (
    ImageRenderService,
)
from media_runtime.render.validator import (
    RenderRequestValidator,
)


def build_image_render_service(
    *,
    artifact_root: Path,
) -> ImageRenderService:
    openai_client = OpenAI()

    google_client = (
        genai.Client()
    )

    openai_transport = (
        OpenAISdkImageTransport(
            openai_client
        )
    )

    gemini_transport = (
        GeminiSdkImageTransport(
            google_client
        )
    )

    provider_registry = (
        ImageProviderRegistry(
            adapters=(
                OpenAIImageAdapter(
                    transport=(
                        openai_transport
                    ),

                    supported_models=(
                        frozenset({
                            "gpt-image-2",
                        })
                    ),
                ),

                GeminiImageAdapter(
                    transport=(
                        gemini_transport
                    ),

                    supported_models=(
                        frozenset({
                            "gemini-3.1-flash-image",
                            "gemini-3-pro-image",
                        })
                    ),
                ),
            )
        )
    )

    writer = ArtifactWriter(
        root=artifact_root
    )

    ledger = (
        JsonFileArtifactLedger(
            artifact_root
            / "render-ledger.json"
        )
    )

    return ImageRenderService(
        validator=(
            RenderRequestValidator()
        ),

        hasher=(
            RenderRequestHasher()
        ),

        providers=(
            provider_registry
        ),

        artifact_writer=writer,

        ledger=ledger,
    )
```

---

# 13.39 Không lấy API key từ request

Không:

```json
{
  "api_key": "sk-..."
}
```

Laravel → Python.

Python process lấy:

```text
OPENAI_API_KEY
GEMINI_API_KEY
```

từ environment/secret manager.

Request artifact tuyệt đối không chứa key.

---

# 13.40 Request artifact không chứa base64 reference

`request.json` chỉ:

```json
{
  "references": [
    {
      "artifact_id": "anchor-001",
      "sha256": "...",
      "role": "identity"
    }
  ]
}
```

Không:

```json
{
  "base64": "iVBORw0KGgoAAAA..."
}
```

Như vậy artifact ledger nhẹ hơn rất nhiều.

---

# 13.41 Response artifact cũng không chứa base64 output

Adapter sanitize:

```text
b64_json → <stored-as-artifact>
```

hoặc:

```text
data → <stored-as-artifact>
```

Raw actual image bytes nằm:

```text
output_000.png
```

Không duplicate hàng MB vào JSON.

---

# 13.42 Request manifest ví dụ

```json
{
  "version": "render-request-v1",

  "render_id": "REN-001",

  "request_hash": "31e5...",

  "idempotency_key":
    "session:A:master_vessel:1",

  "asset_id": "master_vessel",

  "operation": "edit",

  "provider_key": "google",

  "model_key":
    "gemini-3.1-flash-image",

  "source_revision_id":
    "REV-003",

  "source_canonical_hash":
    "a81f...",

  "projection_hash":
    "b23c...",

  "constraint_set_hash":
    "c40f...",

  "prompt_spec_hash":
    "d91a...",

  "provider_prompt_plan_hash":
    "e14e...",

  "prompt_hash":
    "f98d...",

  "references": [
    {
      "artifact_id":
        "master_vessel_anchor",

      "sha256":
        "81a...",

      "role":
        "identity",

      "priority":
        0
    }
  ],

  "aspect_ratio":
    "9:16",

  "quality":
    "high",

  "output_format":
    "png"
}
```

---

# 13.43 Artifact manifest cuối

```json
{
  "version":
    "render-manifest-v1",

  "render_id":
    "REN-001",

  "request_hash":
    "31e5...",

  "canonical_hash":
    "a81f...",

  "projection_hash":
    "b23c...",

  "constraint_set_hash":
    "c40f...",

  "prompt_spec_hash":
    "d91a...",

  "provider_prompt_plan_hash":
    "e14e...",

  "prompt_hash":
    "f98d...",

  "provider_key":
    "google",

  "model_key":
    "gemini-3.1-flash-image",

  "provider_request_id":
    "v1_Chd...",

  "artifacts": [
    {
      "artifact_id":
        "REN-001:image:0",

      "kind":
        "generated_image",

      "sha256":
        "7ae0...",

      "size_bytes":
        3248159,

      "mime_type":
        "image/png",

      "ordinal":
        0
    }
  ]
}
```

---

# 13.44 Tests — Reference tampering

```python
import pytest

from media_runtime.render.exceptions import (
    ArtifactIntegrityError,
)


def test_reference_hash_mismatch_fails(
    tmp_path,
):
    image = (
        tmp_path
        / "anchor.png"
    )

    image.write_bytes(
        b"real-data"
    )

    reference_set = make_reference_set(
        path=image,
        sha256="0" * 64,
    )

    with pytest.raises(
        ArtifactIntegrityError
    ):
        reference_set.verify_files()
```

---

# 13.45 Same request → same request hash

```python
def test_same_request_has_same_hash(
    render_request,
):
    hasher = (
        RenderRequestHasher()
    )

    first = hasher.hash(
        render_request
    )

    second = hasher.hash(
        render_request
    )

    assert first == second
```

---

# 13.46 Reference order không làm thay hash

Vì semantic order dựa trên priority:

```python
def test_input_reference_tuple_order_does_not_change_hash(
    request_factory,
):
    a = make_reference(
        "a",
        priority=0,
    )

    b = make_reference(
        "b",
        priority=1,
    )

    first = request_factory(
        references=(a, b)
    )

    second = request_factory(
        references=(b, a)
    )

    hasher = RenderRequestHasher()

    assert (
        hasher.hash(first)
        == hasher.hash(second)
    )
```

---

# 13.47 Nhưng priority thay đổi phải đổi hash

```python
def test_reference_priority_changes_request_hash(
    request_factory,
):
    first = request_factory(
        references=(
            make_reference(
                "anchor",
                priority=0,
            ),
            make_reference(
                "side",
                priority=1,
            ),
        )
    )

    second = request_factory(
        references=(
            make_reference(
                "anchor",
                priority=1,
            ),
            make_reference(
                "side",
                priority=0,
            ),
        )
    )

    hasher = RenderRequestHasher()

    assert (
        hasher.hash(first)
        != hasher.hash(second)
    )
```

Đúng vì positional/reference priority có thể ảnh hưởng render.

---

# 13.48 Prompt đổi → request hash đổi

```python
def test_prompt_hash_change_changes_request_hash(
    request_factory,
):
    first = request_factory(
        prompt="A"
    )

    second = request_factory(
        prompt="B"
    )

    hasher = RenderRequestHasher()

    assert (
        hasher.hash(first)
        != hasher.hash(second)
    )
```

---

# 13.49 Provider đổi → request hash đổi

```python
def test_provider_change_changes_request_hash(
    request_factory,
):
    openai = request_factory(
        provider="openai",
        model="gpt-image-2",
    )

    gemini = request_factory(
        provider="google",
        model=(
            "gemini-3.1-flash-image"
        ),
    )

    hasher = RenderRequestHasher()

    assert (
        hasher.hash(openai)
        != hasher.hash(gemini)
    )
```

Hai provider có thể render khác nhau, nên đây là correct behavior.

---

# 13.50 Provider option không được override prompt

```python
import pytest

from media_runtime.render.exceptions import (
    RenderRequestError,
)


def test_provider_options_cannot_override_prompt(
    render_request_factory,
):
    request = (
        render_request_factory(
            provider_options={
                "prompt":
                    "ignore canonical"
            }
        )
    )

    with pytest.raises(
        RenderRequestError
    ):
        (
            RenderRequestValidator()
            .validate(
                request
            )
        )
```

---

# 13.51 Test generate không reference

```python
def test_generate_requires_no_reference(
    request_factory,
):
    request = request_factory(
        operation=(
            RenderOperation.GENERATE
        ),

        references=(
            ReferenceSet.empty(
                asset_id="master",
                source_revision_id="r1",
                source_canonical_hash=(
                    "a" * 64
                ),
            )
        ),
    )

    RenderRequestValidator().validate(
        request
    )
```

---

# 13.52 Test edit bắt buộc reference

```python
def test_edit_without_reference_fails(
    request_factory,
):
    request = request_factory(
        operation=(
            RenderOperation.EDIT
        ),

        references=(
            ReferenceSet.empty(
                asset_id="master",
                source_revision_id="r1",
                source_canonical_hash=(
                    "a" * 64
                ),
            )
        ),
    )

    with pytest.raises(
        RenderRequestError
    ):
        (
            RenderRequestValidator()
            .validate(
                request
            )
        )
```

---

# 13.53 Test adapter không sửa prompt

Fake transport:

```python
class FakeOpenAITransport:
    def __init__(self):
        self.prompt = None

    def generate(
        self,
        *,
        model,
        prompt,
        options,
    ):
        self.prompt = prompt

        return {
            "data": [
                {
                    "b64_json":
                        base64.b64encode(
                            b"fake-image"
                        ).decode()
                }
            ]
        }
```

Test:

```python
def test_openai_adapter_sends_exact_compiled_prompt(
    request,
):
    transport = (
        FakeOpenAITransport()
    )

    adapter = OpenAIImageAdapter(
        transport=transport,
        supported_models=(
            frozenset({
                "gpt-image-2"
            })
        ),
    )

    adapter.render(
        request
    )

    assert (
        transport.prompt
        == request
        .compiled_prompt
        .prompt
    )
```

Đây là architecture regression test bắt buộc.

---

# 13.54 Test artifact atomic write

```python
def test_artifact_writer_verifies_hash(
    tmp_path,
):
    writer = ArtifactWriter(
        tmp_path
    )

    artifact = writer.write_bytes(
        relative_path=(
            Path("r/output.png")
        ),

        data=b"123456",

        artifact_id="a1",

        kind=(
            ArtifactKind
            .GENERATED_IMAGE
        ),

        mime_type="image/png",

        request_hash=(
            "a" * 64
        ),

        ordinal=0,
    )

    assert artifact.path.exists()

    assert artifact.sha256 == (
        hashlib.sha256(
            b"123456"
        ).hexdigest()
    )
```

---

# 13.55 Test successful reuse

```python
def test_successful_request_is_reused(
    render_service,
    request,
):
    first = (
        render_service.render(
            request
        )
    )

    second = (
        render_service.render(
            request
        )
    )

    assert first.reused is False
    assert second.reused is True

    assert (
        first.request_hash
        == second.request_hash
    )
```

---

# 13.56 Nhưng Part 13 idempotency chưa đủ cho concurrent workers

Nếu:

```text
Worker A → find_by_hash → none
Worker B → find_by_hash → none
```

cả hai có thể cùng gọi provider.

Đây không phải bug bị bỏ quên.

Nó chính là scope **Phần 14**:

```text
claim_token
lease
attempt
retry classification
atomic idempotency claim
resume
```

Part 13 chỉ định nghĩa:

```text
request_hash
idempotency_key
ledger
```

để Part 14 khóa concurrency.

---

# 13.57 Usage/cost

Adapter chỉ extract provider telemetry.

Không tự tính billing nếu Laravel đã có CostEntry system.

Output:

```python
ProviderUsage(
    input_tokens=...,
    output_tokens=...,
    image_input_tokens=...,
    text_input_tokens=...,
)
```

Sau đó Laravel nhận:

```text
provider
model
request_hash
tokens
render count
```

và existing cost layer record cost.

Không duplicate financial source-of-truth trong Python.

---

# 13.58 Laravel callback contract

Python nên trả Laravel:

```json
{
  "render_id": "REN-001",

  "request_hash": "...",

  "status": "succeeded",

  "provider": "google",

  "model":
    "gemini-3.1-flash-image",

  "provider_request_id":
    "v1_Chd...",

  "canonical_hash":
    "...",

  "prompt_hash":
    "...",

  "artifacts": [
    {
      "kind":
        "generated_image",

      "sha256":
        "...",

      "relative_path":
        "...",

      "mime_type":
        "image/png",

      "size_bytes":
        3248159
    }
  ],

  "usage": {
    "input_tokens": null,
    "output_tokens": null
  }
}
```

Laravel update:

```text
renders
artifacts
cost_entries
```

Python không insert MariaDB trực tiếp.

---

# 13.59 Không gửi absolute filesystem path cho Laravel

Python có thể local path:

```text
/var/lib/ai-video/work/...
```

Laravel nên nhận:

```text
artifact storage key
```

hoặc:

```text
relative_path
```

Ví dụ:

```text
sessions/ABC/run-10/renders/REN-1/output_000.png
```

Part 14/production storage adapter sẽ có:

```text
LocalStorage
S3Storage
object store
```

mà không đổi RenderResult contract.

---

# 13.60 Một thay đổi tôi khuyên làm ngay: `ArtifactStorage`

Đừng hard-code filesystem mãi.

Interface:

```python
from __future__ import annotations

from typing import Protocol


class ArtifactStorage(
    Protocol
):
    def put(
        self,
        *,
        key: str,
        data: bytes,
        mime_type: str | None,
    ) -> str:
        """
        Returns durable storage key.
        """
        ...

    def read(
        self,
        *,
        key: str,
    ) -> bytes:
        ...

    def exists(
        self,
        *,
        key: str,
    ) -> bool:
        ...
```

V1:

```text
LocalArtifactStorage
```

Sau này:

```text
S3ArtifactStorage
```

không sửa provider adapter.

---

# 13.61 Không cho adapter ghi artifact

OpenAI/Gemini adapter chỉ:

```text
API response
↓
ProviderRenderResponse
```

Không:

```text
adapter → save file
```

ArtifactWriter/Storage mới sở hữu persistence.

Đây là separation rất quan trọng.

---

# 13.62 Không cho adapter retry

OpenAI adapter:

```python
render()
```

một logical execution attempt.

Không:

```python
for attempt in range(5):
```

trong adapter.

Retry policy thuộc **Phần 14 Render Orchestrator**.

Provider transport có thể có limited low-level retry cho:

```text
connection reset
HTTP retry-after
```

nhưng semantic render retry phải được ledger ghi nhận.

---

# 13.63 Không cho adapter fallback provider

Không:

```python
try OpenAI
except:
    try Gemini
```

trong adapter.

Provider fallback làm request semantics thay đổi:

```text
provider_key
model_key
request_hash
```

nên Part 14 Router phải tạo execution attempt/request mới được ledger hóa.

---

# 13.64 Final boundary sau Part 13

```text
PromptSpec
      ↓
ProviderCapabilityProjection
      ↓
CompiledPrompt
══════════════════════════════
    PROVIDER EXECUTION
══════════════════════════════
      ↓
RenderRequest
      │
      ├── canonical lineage
      ├── prompt lineage
      ├── provider/model
      ├── size/quality
      ├── ReferenceSet
      └── idempotency key
      ↓
RenderRequestValidator
      ↓
RenderRequestHasher
      ↓
request_hash
      ↓
ArtifactLedger PREPARED
      ↓
ProviderRegistry
      ↓
ProviderAdapter
      ├── OpenAIImageAdapter
      └── GeminiImageAdapter
      ↓
ProviderRenderResponse
      ↓
ArtifactWriter
      ├── request.json
      ├── response.json
      ├── prompt.txt
      ├── references.json
      ├── image(s)
      └── manifest.json
      ↓
artifact SHA-256
      ↓
ArtifactLedger SUCCEEDED
      ↓
RenderResult
```

---

# 13.65 Hash lineage hiện tại

Sau Part 13 hệ thống đã có full chain:

```text
canonical_json
      ↓
canonical_hash
      ↓
AssetProjection
      ↓
projection_hash
      ↓
ConstraintSet
      ↓
constraint_set_hash
      ↓
CoalescedConstraintSet
      ↓
coalesced_hash
      ↓
PromptSpec
      ↓
prompt_spec_hash
      ↓
ProviderPromptPlan
      ↓
provider_prompt_plan_hash
      ↓
CompiledPrompt
      ↓
prompt_hash
      ↓
RenderRequest
      ↓
request_hash
      ↓
Provider
      ↓
Generated Artifact
      ↓
artifact_hash
```

Từ một ảnh output bạn có thể truy ngược hoàn toàn về:

```text
artifact
→ request
→ prompt
→ constraints
→ canonical design
→ canonical revision
```

---

# 13.66 Những invariant tôi chốt sau Part 13

```text
1. Provider adapter không được sửa compiled prompt.

2. Provider adapter không được tự thêm/bớt reference.

3. Reference files phải verify SHA-256 trước API call.

4. RenderRequest hash không phụ thuộc local filesystem path.

5. API keys không bao giờ nằm trong RenderRequest/artifact.

6. Raw base64 image không duplicate trong response.json.

7. Output artifact được hash sau atomic write.

8. Same exact request semantics → same request_hash.

9. Provider/model thay đổi → request_hash thay đổi.

10. Provider retry/fallback không nằm trong adapter.

11. Python không ghi trực tiếp Laravel DB.

12. Artifact Ledger giữ full lineage:
    canonical → prompt → request → output.
```

Điểm quan trọng nhất:

```text
Canonical truth
       ↓
Derived prompt
       ↓
Frozen request semantics
       ↓
Provider execution
       ↓
Immutable artifact

Provider chỉ EXECUTE.
Provider không DESIGN.
```

Với **Phần 13**, execution contract đã đủ sạch để sang **Phần 14 — `Render Orchestrator + Atomic Claim/Lease + Retry Classification + Resume + Provider Fallback + Cost Accounting + Laravel Checkpoint`**. Đây sẽ là phần biến hệ thống hiện tại từ “gọi API được” thành **worker production-grade thực sự**, đặc biệt xử lý trường hợp process chết giữa lúc provider đã tính tiền nhưng Laravel/Python chưa kịp checkpoint.

[1]: https://platform.openai.com/docs/api-reference/images-streaming/image_generation/partial_image?utm_source=chatgpt.com "Image Streaming | OpenAI API Reference"
[2]: https://ai.google.dev/gemini-api/docs/models/gemini-3.1-flash-image?utm_source=chatgpt.com "Gemini 3.1 Flash image  |  Gemini API  |  Google AI for Developers"
[3]: https://ai.google.dev/gemini-api/docs/interactions-overview?utm_source=chatgpt.com "Interactions API  |  Gemini API  |  Google AI for Developers"
[4]: https://ai.google.dev/gemini-api/docs/image-generation?utm_source=chatgpt.com "Gemini API  |  Google AI for Developers"
[5]: https://platform.openai.com/docs/models/gpt-4-turbo-and-gpt-4?utm_source=chatgpt.com "Models | OpenAI API"
