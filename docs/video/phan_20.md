Được. **Part 20** nên là phần cuối của chain production và tuyệt đối không quay ngược sửa `FinalMasterRevision`, `ApprovedShotRevision`, `IdentityLock` hay Canonical.

Sơ đồ chính thức:

```text
Frozen FinalMasterRevision
        +
Publication Requirements
        +
Channel Targets
        ↓
PublicationPackageBuilder
        ↓
PublicationPackage
        ↓
Package Validation
        ↓
Canonical exact-byte serialization
        ↓
package_hash = SHA-256(exact bytes)
        ↓
Freeze PublicationPackageRevision
        ↓
PublicationDispatch
        ↓
Durable Claim / Lease
        ↓
Target Payload Projection
        ↓
PublisherAdapter
        ↓
YouTube / Facebook / TikTok / X / CMS / ...
        ↓
Provider Result
        ↓
Publication Ledger
        ↓
Published Remote Identity
        ↓
Publication
        ↓
Metrics Collection
        ↓
Raw Metrics Snapshot
        ↓
raw_response_hash
        ↓
Normalized Metrics
        ↓
Metrics lineage
        ↓
PublicationMetricSnapshot
        ↓
PublicationMetric[]
```

Điểm cốt lõi:

```text
CANONICAL
→ FINAL MASTER
→ PUBLICATION PACKAGE
→ REMOTE PUBLICATION
→ METRICS

chỉ đi một chiều.
```

Không có:

```text
metrics
→ sửa video

publication
→ mutate FinalMasterRevision

retry publish
→ chọn "video mới nhất"
```

---

# Part 20 — Production contract

Tôi chốt namespace:

```text
app/Video/Publication/
```

Cấu trúc:

```text
app/Video/Publication/
├── DTO/
│   ├── PublicationPackageSpec.php
│   ├── PublicationTargetSpec.php
│   ├── PublicationPayload.php
│   ├── PublicationProviderResult.php
│   └── PublicationMetricsResult.php
│
├── Contracts/
│   ├── PublisherAdapter.php
│   ├── PublisherRegistry.php
│   └── MetricsProvider.php
│
├── Services/
│   ├── PublicationPackageBuilder.php
│   ├── PublicationPackageFreezer.php
│   ├── PublicationDispatchService.php
│   ├── PublicationExecutionService.php
│   ├── PublicationPayloadFactory.php
│   ├── PublicationLedgerService.php
│   ├── PublicationMetricsService.php
│   └── PublicationLineageVerifier.php
│
├── Persistence/
│   └── PublicationCanonicalJson.php
│
├── Providers/
│   ├── AbstractPublisherAdapter.php
│   ├── YouTubePublisherAdapter.php
│   ├── FacebookPublisherAdapter.php
│   └── ...
│
└── Exceptions/
```

Laravel 10 / PHP 8.1 compatible.

---

# 20.1 Database

Tôi dùng 7 tables:

```text
publication_packages
publication_package_assets
publication_dispatches
publications
publication_ledger_entries
publication_metric_snapshots
publication_metrics
```

`publication_packages` là frozen plan.

`publication_dispatches` là durable execution.

`publications` là remote accepted/published identity.

`publication_ledger_entries` là immutable audit ledger.

`publication_metric_snapshots` là từng lần poll provider.

`publication_metrics` là normalized values của snapshot.

---

## Migration 1 — publication_packages

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('video_project_id');
            $table->uuid('video_session_id');

            $table->uuid('final_master_revision_id');

            $table->unsignedInteger('revision');

            $table->string('state', 32);

            /*
             * Exact SHA-256 of frozen package JSON bytes.
             */
            $table->char('package_hash', 64);

            /*
             * Exact frozen JSON.
             * Do not decode/re-encode before comparing hash.
             */
            $table->longText('package_json');

            $table->string('schema_version', 32);

            $table->timestamp('frozen_at')->nullable();

            $table->timestamps();

            $table->unique([
                'video_session_id',
                'revision',
            ]);

            $table->unique('package_hash');

            $table->index('final_master_revision_id');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_packages');
    }
};
```

---

# 20.2 Package assets

Không cho publisher tự tìm:

```text
latest video
latest thumbnail
latest subtitle
```

Package phải khóa asset cụ thể.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publication_package_assets',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('publication_package_id');

                $table->string('role', 64);

                /*
                 * final_master
                 * thumbnail
                 * subtitle
                 * poster
                 * metadata
                 */
                $table->uuid('artifact_id')->nullable();

                $table->char('artifact_hash', 64);

                $table->string(
                    'storage_uri',
                    2048
                );

                $table->string(
                    'mime_type',
                    128
                )->nullable();

                $table->unsignedBigInteger(
                    'byte_size'
                )->nullable();

                $table->timestamps();

                $table->unique([
                    'publication_package_id',
                    'role',
                ]);

                $table->index('artifact_hash');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publication_package_assets'
        );
    }
};
```

---

# 20.3 Publication dispatch

Đây là equivalent của `RenderExecution` Part 14.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publication_dispatches',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid(
                    'publication_package_id'
                );

                $table->string(
                    'target_key',
                    128
                );

                $table->string(
                    'provider',
                    64
                );

                $table->string(
                    'state',
                    32
                );

                /*
                 * Hash of exact provider-bound request.
                 */
                $table->char(
                    'request_hash',
                    64
                );

                /*
                 * Deterministic logical key.
                 */
                $table->char(
                    'idempotency_key',
                    64
                );

                $table->unsignedInteger(
                    'attempt_count'
                )->default(0);

                $table->uuid(
                    'claim_token'
                )->nullable();

                $table->timestamp(
                    'claimed_at'
                )->nullable();

                $table->timestamp(
                    'lease_expires_at'
                )->nullable();

                $table->timestamp(
                    'provider_accepted_at'
                )->nullable();

                $table->timestamp(
                    'completed_at'
                )->nullable();

                /*
                 * If network failed after possible
                 * provider acceptance.
                 */
                $table->boolean(
                    'dispatch_uncertain'
                )->default(false);

                $table->text(
                    'last_error'
                )->nullable();

                $table->timestamps();

                $table->unique([
                    'publication_package_id',
                    'target_key',
                ]);

                $table->index([
                    'state',
                    'lease_expires_at',
                ]);

                $table->index('request_hash');
                $table->index('idempotency_key');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publication_dispatches'
        );
    }
};
```

---

# 20.4 publications

Một row chỉ được tạo khi đã xác định được remote identity.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publications',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid(
                    'publication_dispatch_id'
                );

                $table->uuid(
                    'publication_package_id'
                );

                $table->string(
                    'provider',
                    64
                );

                $table->string(
                    'target_key',
                    128
                );

                /*
                 * Provider-native remote ID.
                 */
                $table->string(
                    'remote_id',
                    512
                );

                $table->string(
                    'remote_url',
                    2048
                )->nullable();

                $table->string(
                    'remote_state',
                    64
                )->nullable();

                $table->timestamp(
                    'published_at'
                )->nullable();

                /*
                 * Exact response bytes hash from
                 * provider acceptance result.
                 */
                $table->char(
                    'provider_response_hash',
                    64
                )->nullable();

                $table->timestamps();

                $table->unique([
                    'provider',
                    'remote_id',
                ]);

                $table->unique(
                    'publication_dispatch_id'
                );

                $table->index(
                    'publication_package_id'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publications'
        );
    }
};
```

---

# 20.5 Immutable publication ledger

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publication_ledger_entries',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid(
                    'publication_package_id'
                );

                $table->uuid(
                    'publication_dispatch_id'
                )->nullable();

                $table->uuid(
                    'publication_id'
                )->nullable();

                $table->string(
                    'event_type',
                    64
                );

                $table->longText(
                    'payload_json'
                );

                $table->char(
                    'payload_hash',
                    64
                );

                /*
                 * Optional previous ledger entry
                 * for hash-chain.
                 */
                $table->uuid(
                    'previous_entry_id'
                )->nullable();

                $table->char(
                    'previous_entry_hash',
                    64
                )->nullable();

                $table->char(
                    'entry_hash',
                    64
                );

                $table->timestamp(
                    'occurred_at'
                );

                $table->timestamps();

                $table->unique('entry_hash');

                $table->index([
                    'publication_package_id',
                    'occurred_at',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publication_ledger_entries'
        );
    }
};
```

Ledger event examples:

```text
PACKAGE_FROZEN
DISPATCH_CREATED
DISPATCH_CLAIMED
PROVIDER_REQUESTED
PROVIDER_ACCEPTED
PROVIDER_UNCERTAIN
PUBLICATION_RECONCILED
PUBLICATION_CONFIRMED
METRICS_CAPTURED
PUBLICATION_REMOVED_REMOTE
```

---

# 20.6 Metrics snapshot

Một provider fetch = một immutable snapshot.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publication_metric_snapshots',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid('publication_id');

                $table->timestamp(
                    'observed_at'
                );

                /*
                 * Exact raw provider response.
                 */
                $table->longText(
                    'raw_response_json'
                );

                $table->char(
                    'raw_response_hash',
                    64
                );

                $table->string(
                    'provider_schema_version',
                    64
                )->nullable();

                $table->string(
                    'normalizer_version',
                    64
                );

                $table->timestamps();

                $table->index([
                    'publication_id',
                    'observed_at',
                ]);

                $table->index(
                    'raw_response_hash'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publication_metric_snapshots'
        );
    }
};
```

---

# 20.7 Normalized metrics

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'publication_metrics',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();

                $table->uuid(
                    'publication_metric_snapshot_id'
                );

                $table->string(
                    'metric_key',
                    128
                );

                $table->decimal(
                    'metric_value',
                    24,
                    6
                )->nullable();

                $table->string(
                    'metric_text',
                    512
                )->nullable();

                $table->string(
                    'unit',
                    32
                )->nullable();

                $table->timestamps();

                $table->unique([
                    'publication_metric_snapshot_id',
                    'metric_key',
                ]);

                $table->index('metric_key');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'publication_metrics'
        );
    }
};
```

---

# 20.8 Publication package DTO

`app/Video/Publication/DTO/PublicationPackageSpec.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\DTO;

final class PublicationPackageSpec
{
    /**
     * @param list<PublicationTargetSpec> $targets
     * @param array<string,mixed> $metadata
     * @param array<string,array<string,mixed>> $assets
     */
    public function __construct(
        public readonly string $schemaVersion,

        public readonly string $videoProjectId,

        public readonly string $videoSessionId,

        public readonly string $finalMasterRevisionId,

        public readonly string $finalMasterHash,

        public readonly array $assets,

        public readonly array $metadata,

        public readonly array $targets,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' =>
                $this->schemaVersion,

            'video_project_id' =>
                $this->videoProjectId,

            'video_session_id' =>
                $this->videoSessionId,

            'final_master_revision_id' =>
                $this->finalMasterRevisionId,

            'final_master_hash' =>
                $this->finalMasterHash,

            'assets' =>
                $this->assets,

            'metadata' =>
                $this->metadata,

            'targets' =>
                array_map(
                    static fn (
                        PublicationTargetSpec $target
                    ): array => $target->toArray(),

                    $this->targets
                ),
        ];
    }
}
```

---

# 20.9 PublicationTargetSpec

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\DTO;

final class PublicationTargetSpec
{
    /**
     * @param array<string,mixed> $settings
     */
    public function __construct(
        public readonly string $key,

        public readonly string $provider,

        public readonly string $accountKey,

        public readonly array $settings,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' =>
                $this->key,

            'provider' =>
                $this->provider,

            /*
             * Reference only.
             * Never secret credentials.
             */
            'account_key' =>
                $this->accountKey,

            'settings' =>
                $this->settings,
        ];
    }
}
```

Không bao giờ freeze:

```text
access_token
refresh_token
client_secret
API key
```

vào publication package.

Chỉ freeze logical reference:

```text
youtube_primary
facebook_page_news24h
tiktok_main
```

Credential resolution nằm ngoài frozen package.

---

# 20.10 Package canonical serialization

`PublicationCanonicalJson.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Persistence;

use JsonException;

final class PublicationCanonicalJson
{
    /**
     * @throws JsonException
     */
    public function encode(
        mixed $value
    ): string {
        return json_encode(
            $this->normalize($value),

            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        );
    }

    private function normalize(
        mixed $value
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $child): mixed =>
                    $this->normalize($child),

                $value
            );
        }

        ksort(
            $value,
            SORT_STRING
        );

        foreach (
            $value as $key => $child
        ) {
            $value[$key] =
                $this->normalize($child);
        }

        return $value;
    }

    public function hash(
        string $exactBytes
    ): string {
        return hash(
            'sha256',
            $exactBytes
        );
    }
}
```

Ở đây quy tắc vẫn giống Parts trước:

```text
serialized bytes
=
validated bytes
=
stored bytes
=
hashed bytes
```

---

# 20.11 PublicationPackageBuilder

Điểm quan trọng nhất: **không query latest artifact**.

Input phải là exact frozen final revision.

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Video\Publication\DTO\PublicationPackageSpec;
use App\Video\Publication\DTO\PublicationTargetSpec;
use RuntimeException;

final class PublicationPackageBuilder
{
    /**
     * @param array<string,mixed> $finalMaster
     * @param array<string,mixed> $requirements
     * @param list<PublicationTargetSpec> $targets
     */
    public function build(
        array $finalMaster,
        array $requirements,
        array $targets,
    ): PublicationPackageSpec {
        $revisionId =
            $finalMaster['revision_id']
            ?? null;

        $masterHash =
            $finalMaster['artifact_hash']
            ?? null;

        $storageUri =
            $finalMaster['storage_uri']
            ?? null;

        if (
            ! is_string($revisionId)
            || $revisionId === ''
        ) {
            throw new RuntimeException(
                'FinalMasterRevision ID is required.'
            );
        }

        if (
            ! is_string($masterHash)
            || strlen($masterHash) !== 64
        ) {
            throw new RuntimeException(
                'Final master hash is invalid.'
            );
        }

        if (
            ! is_string($storageUri)
            || $storageUri === ''
        ) {
            throw new RuntimeException(
                'Final master storage URI is required.'
            );
        }

        return new PublicationPackageSpec(
            schemaVersion:
                'publication-package-v1',

            videoProjectId:
                (string) $requirements[
                    'video_project_id'
                ],

            videoSessionId:
                (string) $requirements[
                    'video_session_id'
                ],

            finalMasterRevisionId:
                $revisionId,

            finalMasterHash:
                $masterHash,

            assets: [
                'final_master' => [
                    'artifact_id' =>
                        $finalMaster[
                            'artifact_id'
                        ] ?? null,

                    'artifact_hash' =>
                        $masterHash,

                    'storage_uri' =>
                        $storageUri,

                    'mime_type' =>
                        $finalMaster[
                            'mime_type'
                        ] ?? 'video/mp4',

                    'byte_size' =>
                        $finalMaster[
                            'byte_size'
                        ] ?? null,
                ],
            ],

            metadata: [
                'title' =>
                    (string) (
                        $requirements['title']
                        ?? ''
                    ),

                'description' =>
                    (string) (
                        $requirements[
                            'description'
                        ] ?? ''
                    ),

                'language' =>
                    $requirements[
                        'language'
                    ] ?? null,

                'visibility' =>
                    $requirements[
                        'visibility'
                    ] ?? 'private',

                'scheduled_at' =>
                    $requirements[
                        'scheduled_at'
                    ] ?? null,
            ],

            targets:
                $targets,
        );
    }
}
```

---

# 20.12 Freezer

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\PublicationPackage;
use App\Video\Publication\DTO\PublicationPackageSpec;
use App\Video\Publication\Persistence\PublicationCanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicationPackageFreezer
{
    public function __construct(
        private readonly PublicationCanonicalJson $canonicalJson,
        private readonly PublicationLedgerService $ledger,
    ) {}

    public function freeze(
        PublicationPackageSpec $spec,
        int $revision
    ): PublicationPackage {
        $bytes =
            $this->canonicalJson
                ->encode(
                    $spec->toArray()
                );

        $hash =
            $this->canonicalJson
                ->hash(
                    $bytes
                );

        return DB::transaction(
            function () use (
                $spec,
                $revision,
                $bytes,
                $hash
            ): PublicationPackage {
                $existing =
                    PublicationPackage::query()
                        ->where(
                            'package_hash',
                            $hash
                        )
                        ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $package =
                    new PublicationPackage();

                $package->id =
                    (string) Str::uuid();

                $package->video_project_id =
                    $spec->videoProjectId;

                $package->video_session_id =
                    $spec->videoSessionId;

                $package->final_master_revision_id =
                    $spec->finalMasterRevisionId;

                $package->revision =
                    $revision;

                $package->state =
                    'FROZEN';

                $package->package_hash =
                    $hash;

                /*
                 * Exact bytes.
                 */
                $package->package_json =
                    $bytes;

                $package->schema_version =
                    $spec->schemaVersion;

                $package->frozen_at =
                    now();

                $package->save();

                $this->ledger->append(
                    packageId: $package->id,

                    dispatchId: null,

                    publicationId: null,

                    eventType:
                        'PACKAGE_FROZEN',

                    payload: [
                        'package_hash' =>
                            $hash,

                        'revision' =>
                            $revision,
                    ]
                );

                return $package;
            }
        );
    }
}
```

---

# 20.13 Publisher contract

Provider adapter không được quyền tự quyết business logic.

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Contracts;

use App\Video\Publication\DTO\PublicationPayload;
use App\Video\Publication\DTO\PublicationProviderResult;

interface PublisherAdapter
{
    public function provider(): string;

    public function publish(
        PublicationPayload $payload
    ): PublicationProviderResult;

    public function findExisting(
        string $idempotencyKey
    ): ?PublicationProviderResult;
}
```

`findExisting()` rất quan trọng.

Vì outbound HTTP có tình huống:

```text
POST provider
↓
provider publish thành công
↓
network timeout trước khi Laravel nhận response
```

Lúc này hệ thống **không được blind retry**.

---

# 20.14 PublicationPayload

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\DTO;

final class PublicationPayload
{
    /**
     * @param array<string,mixed> $body
     */
    public function __construct(
        public readonly string $provider,

        public readonly string $targetKey,

        public readonly string $accountKey,

        public readonly string $mediaPath,

        public readonly string $mediaHash,

        public readonly array $body,

        public readonly string $requestHash,

        public readonly string $idempotencyKey,
    ) {}
}
```

---

# 20.15 Provider result

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\DTO;

final class PublicationProviderResult
{
    public function __construct(
        public readonly bool $accepted,

        public readonly ?string $remoteId,

        public readonly ?string $remoteUrl,

        public readonly ?string $remoteState,

        public readonly string $rawResponse,

        public readonly ?string $error = null,
    ) {}

    public function responseHash(): string
    {
        return hash(
            'sha256',
            $this->rawResponse
        );
    }
}
```

---

# 20.16 PublisherRegistry

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Contracts;

use RuntimeException;

final class PublisherRegistry
{
    /**
     * @var array<string,PublisherAdapter>
     */
    private array $adapters = [];

    /**
     * @param iterable<PublisherAdapter> $adapters
     */
    public function __construct(
        iterable $adapters
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[
                $adapter->provider()
            ] = $adapter;
        }
    }

    public function get(
        string $provider
    ): PublisherAdapter {
        $adapter =
            $this->adapters[
                $provider
            ] ?? null;

        if ($adapter === null) {
            throw new RuntimeException(
                "Publisher adapter not registered: {$provider}"
            );
        }

        return $adapter;
    }
}
```

---

# 20.17 Provider request hash

Request hash phải đại diện cho exact logical request:

```text
package_hash
target_key
provider
account_key
payload
media_hash
```

Không hash access token.

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Video\Publication\Persistence\PublicationCanonicalJson;

final class PublicationRequestHasher
{
    public function __construct(
        private readonly PublicationCanonicalJson $json,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function hash(
        string $packageHash,
        string $targetKey,
        string $provider,
        string $accountKey,
        string $mediaHash,
        array $payload,
    ): string {
        $bytes =
            $this->json->encode([
                'package_hash' =>
                    $packageHash,

                'target_key' =>
                    $targetKey,

                'provider' =>
                    $provider,

                'account_key' =>
                    $accountKey,

                'media_hash' =>
                    $mediaHash,

                'payload' =>
                    $payload,
            ]);

        return hash(
            'sha256',
            $bytes
        );
    }
}
```

Idempotency key:

```php
$idempotencyKey = hash(
    'sha256',
    'publication:'.$requestHash
);
```

---

# 20.18 Payload factory

Provider payload là projection của frozen package.

Không được mutate package.

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Video\Publication\DTO\PublicationPayload;
use RuntimeException;

final class PublicationPayloadFactory
{
    public function __construct(
        private readonly PublicationRequestHasher $hasher,
    ) {}

    /**
     * @param array<string,mixed> $package
     * @param array<string,mixed> $target
     */
    public function create(
        string $packageHash,
        array $package,
        array $target,
    ): PublicationPayload {
        $provider =
            (string) $target['provider'];

        $targetKey =
            (string) $target['key'];

        $accountKey =
            (string) $target['account_key'];

        $master =
            $package['assets'][
                'final_master'
            ] ?? null;

        if (! is_array($master)) {
            throw new RuntimeException(
                'Frozen publication package '
                .'does not contain final_master.'
            );
        }

        $body =
            $this->projectBody(
                provider: $provider,
                metadata:
                    $package['metadata'] ?? [],
                settings:
                    $target['settings'] ?? [],
            );

        $requestHash =
            $this->hasher->hash(
                packageHash:
                    $packageHash,

                targetKey:
                    $targetKey,

                provider:
                    $provider,

                accountKey:
                    $accountKey,

                mediaHash:
                    (string) $master[
                        'artifact_hash'
                    ],

                payload:
                    $body,
            );

        return new PublicationPayload(
            provider:
                $provider,

            targetKey:
                $targetKey,

            accountKey:
                $accountKey,

            mediaPath:
                (string) $master[
                    'storage_uri'
                ],

            mediaHash:
                (string) $master[
                    'artifact_hash'
                ],

            body:
                $body,

            requestHash:
                $requestHash,

            idempotencyKey:
                hash(
                    'sha256',
                    'publication:'
                    .$requestHash
                ),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function projectBody(
        string $provider,
        array $metadata,
        array $settings,
    ): array {
        /*
         * This switch is projection,
         * NOT a second planning system.
         */
        return match ($provider) {
            'youtube' => [
                'title' =>
                    $metadata['title'] ?? '',

                'description' =>
                    $metadata[
                        'description'
                    ] ?? '',

                'privacy_status' =>
                    $settings[
                        'privacy_status'
                    ]
                    ?? $metadata[
                        'visibility'
                    ]
                    ?? 'private',

                'category_id' =>
                    $settings[
                        'category_id'
                    ] ?? null,

                'made_for_kids' =>
                    $settings[
                        'made_for_kids'
                    ] ?? false,
            ],

            'facebook' => [
                'description' =>
                    $metadata[
                        'description'
                    ] ?? '',

                'published' =>
                    $settings[
                        'published'
                    ] ?? true,
            ],

            default => [
                'title' =>
                    $metadata[
                        'title'
                    ] ?? '',

                'description' =>
                    $metadata[
                        'description'
                    ] ?? '',

                'settings' =>
                    $settings,
            ],
        };
    }
}
```

---

# 20.19 Dispatch creation

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\PublicationDispatch;
use App\Models\PublicationPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PublicationDispatchService
{
    public function __construct(
        private readonly PublicationPayloadFactory $payloadFactory,
        private readonly PublicationLedgerService $ledger,
    ) {}

    public function createForTarget(
        PublicationPackage $package,
        string $targetKey
    ): PublicationDispatch {
        if ($package->state !== 'FROZEN') {
            throw new RuntimeException(
                'Publication package must be frozen.'
            );
        }

        $packageData =
            json_decode(
                $package->package_json,
                true,
                flags:
                    JSON_THROW_ON_ERROR
            );

        $target =
            collect(
                $packageData['targets']
            )->firstWhere(
                'key',
                $targetKey
            );

        if (! is_array($target)) {
            throw new RuntimeException(
                "Unknown target {$targetKey}."
            );
        }

        $payload =
            $this->payloadFactory
                ->create(
                    packageHash:
                        $package->package_hash,

                    package:
                        $packageData,

                    target:
                        $target,
                );

        return DB::transaction(
            function () use (
                $package,
                $payload
            ): PublicationDispatch {
                $existing =
                    PublicationDispatch::query()
                        ->where(
                            'publication_package_id',
                            $package->id
                        )
                        ->where(
                            'target_key',
                            $payload->targetKey
                        )
                        ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $dispatch =
                    new PublicationDispatch();

                $dispatch->id =
                    (string) Str::uuid();

                $dispatch->publication_package_id =
                    $package->id;

                $dispatch->target_key =
                    $payload->targetKey;

                $dispatch->provider =
                    $payload->provider;

                $dispatch->state =
                    'PENDING';

                $dispatch->request_hash =
                    $payload->requestHash;

                $dispatch->idempotency_key =
                    $payload->idempotencyKey;

                $dispatch->attempt_count =
                    0;

                $dispatch->save();

                $this->ledger->append(
                    packageId:
                        $package->id,

                    dispatchId:
                        $dispatch->id,

                    publicationId:
                        null,

                    eventType:
                        'DISPATCH_CREATED',

                    payload: [
                        'provider' =>
                            $dispatch->provider,

                        'target_key' =>
                            $dispatch->target_key,

                        'request_hash' =>
                            $dispatch->request_hash,

                        'idempotency_key' =>
                            $dispatch->idempotency_key,
                    ]
                );

                return $dispatch;
            }
        );
    }
}
```

---

# 20.20 Claim / lease

Publication side effect phải có durable claim.

State:

```text
PENDING
→ CLAIMED
→ DISPATCHING
→ PROVIDER_ACCEPTED
→ PUBLISHED

or

DISPATCHING
→ UNCERTAIN

or

DISPATCHING
→ FAILED
```

`UNCERTAIN` rất quan trọng.

Không:

```text
timeout
→ retry POST ngay
```

Mà:

```text
timeout
→ UNCERTAIN
→ reconciliation
→ findExisting()
→ found?
   ├─ yes → PUBLISHED
   └─ no  → retry/new attempt policy
```

---

# 20.21 PublicationExecutionService

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\Publication;
use App\Models\PublicationDispatch;
use App\Models\PublicationPackage;
use App\Video\Publication\Contracts\PublisherRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PublicationExecutionService
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly PublicationPayloadFactory $payloadFactory,
        private readonly PublicationLedgerService $ledger,
    ) {}

    public function execute(
        string $dispatchId,
        string $claimToken
    ): void {
        $dispatch =
            PublicationDispatch::query()
                ->findOrFail(
                    $dispatchId
                );

        if (
            $dispatch->state !== 'CLAIMED'
            || $dispatch->claim_token
                !== $claimToken
        ) {
            throw new RuntimeException(
                'Publication claim is invalid.'
            );
        }

        $package =
            PublicationPackage::query()
                ->findOrFail(
                    $dispatch
                        ->publication_package_id
                );

        /*
         * Verify exact frozen package bytes.
         */
        $actualPackageHash =
            hash(
                'sha256',
                $package->package_json
            );

        if (
            ! hash_equals(
                $package->package_hash,
                $actualPackageHash
            )
        ) {
            throw new RuntimeException(
                'Publication package hash mismatch.'
            );
        }

        $packageData =
            json_decode(
                $package->package_json,
                true,
                flags:
                    JSON_THROW_ON_ERROR
            );

        $target =
            collect(
                $packageData['targets']
            )->firstWhere(
                'key',
                $dispatch->target_key
            );

        if (! is_array($target)) {
            throw new RuntimeException(
                'Publication target missing '
                .'from frozen package.'
            );
        }

        $payload =
            $this->payloadFactory
                ->create(
                    packageHash:
                        $package->package_hash,

                    package:
                        $packageData,

                    target:
                        $target,
                );

        if (
            ! hash_equals(
                $dispatch->request_hash,
                $payload->requestHash
            )
        ) {
            throw new RuntimeException(
                'Publication request lineage mismatch.'
            );
        }

        $adapter =
            $this->publishers
                ->get(
                    $dispatch->provider
                );

        DB::transaction(
            function () use (
                $dispatch,
                $package
            ): void {
                $dispatch->state =
                    'DISPATCHING';

                $dispatch->attempt_count++;

                $dispatch->save();

                $this->ledger->append(
                    packageId:
                        $package->id,

                    dispatchId:
                        $dispatch->id,

                    publicationId:
                        null,

                    eventType:
                        'PROVIDER_REQUESTED',

                    payload: [
                        'attempt' =>
                            $dispatch
                                ->attempt_count,

                        'request_hash' =>
                            $dispatch
                                ->request_hash,
                    ]
                );
            }
        );

        try {
            $result =
                $adapter->publish(
                    $payload
                );
        } catch (Throwable $exception) {
            /*
             * We do not know whether provider
             * accepted the publication.
             */
            $dispatch->state =
                'UNCERTAIN';

            $dispatch->dispatch_uncertain =
                true;

            $dispatch->last_error =
                $exception->getMessage();

            $dispatch->save();

            $this->ledger->append(
                packageId:
                    $package->id,

                dispatchId:
                    $dispatch->id,

                publicationId:
                    null,

                eventType:
                    'PROVIDER_UNCERTAIN',

                payload: [
                    'error' =>
                        $exception
                            ->getMessage(),
                ]
            );

            return;
        }

        if (
            ! $result->accepted
            || $result->remoteId === null
        ) {
            $dispatch->state =
                'FAILED';

            $dispatch->last_error =
                $result->error;

            $dispatch->completed_at =
                now();

            $dispatch->save();

            return;
        }

        DB::transaction(
            function () use (
                $package,
                $dispatch,
                $result
            ): void {
                $dispatch->state =
                    'PROVIDER_ACCEPTED';

                $dispatch->provider_accepted_at =
                    now();

                $dispatch->dispatch_uncertain =
                    false;

                $dispatch->save();

                $publication =
                    Publication::query()
                        ->where(
                            'provider',
                            $dispatch->provider
                        )
                        ->where(
                            'remote_id',
                            $result->remoteId
                        )
                        ->first();

                if ($publication === null) {
                    $publication =
                        new Publication();

                    $publication->id =
                        (string) Str::uuid();

                    $publication
                        ->publication_dispatch_id =
                        $dispatch->id;

                    $publication
                        ->publication_package_id =
                        $package->id;

                    $publication->provider =
                        $dispatch->provider;

                    $publication->target_key =
                        $dispatch->target_key;

                    $publication->remote_id =
                        $result->remoteId;

                    $publication->remote_url =
                        $result->remoteUrl;

                    $publication->remote_state =
                        $result->remoteState;

                    $publication
                        ->provider_response_hash =
                        $result->responseHash();

                    $publication->published_at =
                        now();

                    $publication->save();
                }

                $dispatch->state =
                    'PUBLISHED';

                $dispatch->completed_at =
                    now();

                $dispatch->save();

                $this->ledger->append(
                    packageId:
                        $package->id,

                    dispatchId:
                        $dispatch->id,

                    publicationId:
                        $publication->id,

                    eventType:
                        'PUBLICATION_CONFIRMED',

                    payload: [
                        'remote_id' =>
                            $publication
                                ->remote_id,

                        'remote_url' =>
                            $publication
                                ->remote_url,

                        'provider_response_hash' =>
                            $result
                                ->responseHash(),
                    ]
                );
            }
        );
    }
}
```

---

# 20.22 Uncertain reconciliation

Đây là production requirement, không phải optional nice-to-have.

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\Publication;
use App\Models\PublicationDispatch;
use App\Video\Publication\Contracts\PublisherRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PublicationReconciliationService
{
    public function __construct(
        private readonly PublisherRegistry $publishers,
        private readonly PublicationLedgerService $ledger,
    ) {}

    public function reconcile(
        string $dispatchId
    ): bool {
        $dispatch =
            PublicationDispatch::query()
                ->findOrFail(
                    $dispatchId
                );

        if (
            $dispatch->state
            !== 'UNCERTAIN'
        ) {
            return false;
        }

        $adapter =
            $this->publishers->get(
                $dispatch->provider
            );

        $result =
            $adapter->findExisting(
                $dispatch->idempotency_key
            );

        if (
            $result === null
            || ! $result->accepted
            || $result->remoteId === null
        ) {
            return false;
        }

        DB::transaction(
            function () use (
                $dispatch,
                $result
            ): void {
                $publication =
                    Publication::query()
                        ->firstOrCreate(
                            [
                                'provider' =>
                                    $dispatch
                                        ->provider,

                                'remote_id' =>
                                    $result
                                        ->remoteId,
                            ],
                            [
                                'id' =>
                                    (string)
                                    Str::uuid(),

                                'publication_dispatch_id' =>
                                    $dispatch->id,

                                'publication_package_id' =>
                                    $dispatch
                                        ->publication_package_id,

                                'target_key' =>
                                    $dispatch
                                        ->target_key,

                                'remote_url' =>
                                    $result
                                        ->remoteUrl,

                                'remote_state' =>
                                    $result
                                        ->remoteState,

                                'provider_response_hash' =>
                                    $result
                                        ->responseHash(),

                                'published_at' =>
                                    now(),
                            ]
                        );

                $dispatch->state =
                    'PUBLISHED';

                $dispatch->dispatch_uncertain =
                    false;

                $dispatch->completed_at =
                    now();

                $dispatch->save();

                $this->ledger->append(
                    packageId:
                        $dispatch
                            ->publication_package_id,

                    dispatchId:
                        $dispatch->id,

                    publicationId:
                        $publication->id,

                    eventType:
                        'PUBLICATION_RECONCILED',

                    payload: [
                        'remote_id' =>
                            $result
                                ->remoteId,

                        'response_hash' =>
                            $result
                                ->responseHash(),
                    ]
                );
            }
        );

        return true;
    }
}
```

Không nên tuyên bố:

```text
exactly-once publication
```

nếu YouTube/Facebook/TikTok không có idempotency contract đủ mạnh.

Part 20 chỉ đảm bảo:

```text
durable request identity
+
no blind retry after uncertainty
+
provider reconciliation
+
remote uniqueness
+
append-only audit lineage
```

---

# 20.23 Ledger

`PublicationLedgerService.php`

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\PublicationLedgerEntry;
use App\Video\Publication\Persistence\PublicationCanonicalJson;
use Illuminate\Support\Str;

final class PublicationLedgerService
{
    public function __construct(
        private readonly PublicationCanonicalJson $json,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function append(
        string $packageId,
        ?string $dispatchId,
        ?string $publicationId,
        string $eventType,
        array $payload,
    ): PublicationLedgerEntry {
        $previous =
            PublicationLedgerEntry::query()
                ->where(
                    'publication_package_id',
                    $packageId
                )
                ->orderByDesc('occurred_at')
                ->orderByDesc('created_at')
                ->first();

        $payloadBytes =
            $this->json->encode(
                $payload
            );

        $payloadHash =
            hash(
                'sha256',
                $payloadBytes
            );

        $occurredAt =
            now();

        $entryMaterial =
            $this->json->encode([
                'package_id' =>
                    $packageId,

                'dispatch_id' =>
                    $dispatchId,

                'publication_id' =>
                    $publicationId,

                'event_type' =>
                    $eventType,

                'payload_hash' =>
                    $payloadHash,

                'previous_entry_hash' =>
                    $previous
                        ?->entry_hash,

                'occurred_at' =>
                    $occurredAt
                        ->toISOString(),
            ]);

        $entry =
            new PublicationLedgerEntry();

        $entry->id =
            (string) Str::uuid();

        $entry->publication_package_id =
            $packageId;

        $entry->publication_dispatch_id =
            $dispatchId;

        $entry->publication_id =
            $publicationId;

        $entry->event_type =
            $eventType;

        $entry->payload_json =
            $payloadBytes;

        $entry->payload_hash =
            $payloadHash;

        $entry->previous_entry_id =
            $previous?->id;

        $entry->previous_entry_hash =
            $previous?->entry_hash;

        $entry->entry_hash =
            hash(
                'sha256',
                $entryMaterial
            );

        $entry->occurred_at =
            $occurredAt;

        $entry->save();

        return $entry;
    }
}
```

---

# 20.24 MetricsProvider contract

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Contracts;

use App\Models\Publication;
use App\Video\Publication\DTO\PublicationMetricsResult;

interface MetricsProvider
{
    public function provider(): string;

    public function fetch(
        Publication $publication
    ): PublicationMetricsResult;
}
```

---

# 20.25 Metrics result

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\DTO;

final class PublicationMetricsResult
{
    /**
     * @param array<string,int|float|string|null> $metrics
     */
    public function __construct(
        public readonly string $rawResponse,

        public readonly array $metrics,

        public readonly string $normalizerVersion,

        public readonly ?string $providerSchemaVersion = null,
    ) {}
}
```

---

# 20.26 Metrics registry

Tương tự publisher registry:

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Video\Publication\Contracts\MetricsProvider;
use RuntimeException;

final class MetricsProviderRegistry
{
    /**
     * @var array<string,MetricsProvider>
     */
    private array $providers = [];

    /**
     * @param iterable<MetricsProvider> $providers
     */
    public function __construct(
        iterable $providers
    ) {
        foreach ($providers as $provider) {
            $this->providers[
                $provider->provider()
            ] = $provider;
        }
    }

    public function get(
        string $name
    ): MetricsProvider {
        $provider =
            $this->providers[
                $name
            ] ?? null;

        if ($provider === null) {
            throw new RuntimeException(
                "Metrics provider not registered: {$name}"
            );
        }

        return $provider;
    }
}
```

---

# 20.27 Metrics collection

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\PublicationMetricSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PublicationMetricsService
{
    public function __construct(
        private readonly MetricsProviderRegistry $providers,
        private readonly PublicationLedgerService $ledger,
    ) {}

    public function capture(
        string $publicationId
    ): PublicationMetricSnapshot {
        $publication =
            Publication::query()
                ->findOrFail(
                    $publicationId
                );

        $provider =
            $this->providers
                ->get(
                    $publication->provider
                );

        $result =
            $provider->fetch(
                $publication
            );

        /*
         * Hash exact raw provider response bytes.
         */
        $rawHash =
            hash(
                'sha256',
                $result->rawResponse
            );

        return DB::transaction(
            function () use (
                $publication,
                $result,
                $rawHash
            ): PublicationMetricSnapshot {
                $snapshot =
                    new PublicationMetricSnapshot();

                $snapshot->id =
                    (string) Str::uuid();

                $snapshot->publication_id =
                    $publication->id;

                $snapshot->observed_at =
                    now();

                $snapshot->raw_response_json =
                    $result->rawResponse;

                $snapshot->raw_response_hash =
                    $rawHash;

                $snapshot->provider_schema_version =
                    $result
                        ->providerSchemaVersion;

                $snapshot->normalizer_version =
                    $result
                        ->normalizerVersion;

                $snapshot->save();

                foreach (
                    $result->metrics
                    as $key => $value
                ) {
                    $metric =
                        new PublicationMetric();

                    $metric->id =
                        (string) Str::uuid();

                    $metric
                        ->publication_metric_snapshot_id =
                        $snapshot->id;

                    $metric->metric_key =
                        $key;

                    if (
                        is_int($value)
                        || is_float($value)
                    ) {
                        $metric->metric_value =
                            $value;

                        $metric->metric_text =
                            null;
                    } else {
                        $metric->metric_value =
                            null;

                        $metric->metric_text =
                            $value === null
                                ? null
                                : (string) $value;
                    }

                    $metric->unit =
                        $this->unitFor(
                            $key
                        );

                    $metric->save();
                }

                $this->ledger->append(
                    packageId:
                        $publication
                            ->publication_package_id,

                    dispatchId:
                        $publication
                            ->publication_dispatch_id,

                    publicationId:
                        $publication->id,

                    eventType:
                        'METRICS_CAPTURED',

                    payload: [
                        'snapshot_id' =>
                            $snapshot->id,

                        'raw_response_hash' =>
                            $rawHash,

                        'normalizer_version' =>
                            $result
                                ->normalizerVersion,
                    ]
                );

                return $snapshot;
            }
        );
    }

    private function unitFor(
        string $metric
    ): ?string {
        return match ($metric) {
            'views',
            'likes',
            'comments',
            'shares',
            'subscribers_gained' =>
                'count',

            'watch_time_seconds' =>
                'seconds',

            'average_view_duration_seconds' =>
                'seconds',

            'completion_rate',
            'ctr',
            'engagement_rate' =>
                'ratio',

            default =>
                null,
        };
    }
}
```

---

# 20.28 Metric normalization

Không nên cố ép mọi platform thành cùng một metric giả tạo.

Ví dụ universal subset:

```text
views
likes
comments
shares
watch_time_seconds
average_view_duration_seconds
completion_rate
engagement_rate
impressions
ctr
subscribers_gained
```

Provider-specific metric vẫn có thể lưu như:

```text
youtube.estimated_minutes_watched
facebook.thruplays
tiktok.total_play_time
```

Không mutate historical snapshot khi logic normalizer đổi.

Thay vào đó:

```text
snapshot A
normalizer_version = youtube-v1

snapshot B
normalizer_version = youtube-v2
```

Nếu cần re-normalize historical raw response thì tạo **derived normalization revision mới**, không overwrite row cũ.

---

# 20.29 Metrics lineage

Đường lineage đầy đủ phải truy được:

```text
publication_metric
        ↓
publication_metric_snapshot
        ↓
publication
        ↓
publication_dispatch
        ↓
publication_package
        ↓
final_master_revision
        ↓
approved_shot_revision[]
        ↓
render requests / artifacts
        ↓
IdentityLock
        ↓
CanonicalDesignSpec
```

Vì vậy một con số như:

```text
views = 1,250,000
```

không đứng độc lập.

Bạn có thể truy ra:

```text
metric
→ snapshot
→ remote YouTube video
→ exact publication request
→ exact package_hash
→ exact final_master_hash
→ exact shot revisions
→ exact canonical revision
```

Đó mới là **metrics lineage production-grade**.

---

# 20.30 Lineage verifier

```php
<?php

declare(strict_types=1);

namespace App\Video\Publication\Services;

use App\Models\Publication;
use RuntimeException;

final class PublicationLineageVerifier
{
    public function verify(
        Publication $publication
    ): void {
        $dispatch =
            $publication->dispatch;

        $package =
            $publication->package;

        if ($dispatch === null) {
            throw new RuntimeException(
                'Publication dispatch missing.'
            );
        }

        if ($package === null) {
            throw new RuntimeException(
                'Publication package missing.'
            );
        }

        if (
            $dispatch
                ->publication_package_id
            !== $package->id
        ) {
            throw new RuntimeException(
                'Publication package lineage mismatch.'
            );
        }

        $actualPackageHash =
            hash(
                'sha256',
                $package->package_json
            );

        if (
            ! hash_equals(
                $package->package_hash,
                $actualPackageHash
            )
        ) {
            throw new RuntimeException(
                'Publication package bytes '
                .'do not match package_hash.'
            );
        }

        if (
            $dispatch->state
            !== 'PUBLISHED'
        ) {
            throw new RuntimeException(
                'Publication dispatch '
                .'is not published.'
            );
        }
    }
}
```

---

# 20.31 Eloquent models

Ví dụ `PublicationPackage`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PublicationPackage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'revision' =>
            'integer',

        'frozen_at' =>
            'datetime',
    ];

    public function dispatches(): HasMany
    {
        return $this->hasMany(
            PublicationDispatch::class
        );
    }
}
```

`PublicationDispatch`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class PublicationDispatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'attempt_count' =>
            'integer',

        'dispatch_uncertain' =>
            'boolean',

        'claimed_at' =>
            'datetime',

        'lease_expires_at' =>
            'datetime',

        'provider_accepted_at' =>
            'datetime',

        'completed_at' =>
            'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            PublicationPackage::class,
            'publication_package_id'
        );
    }

    public function publication(): HasOne
    {
        return $this->hasOne(
            Publication::class
        );
    }
}
```

`Publication`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Publication extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'published_at' =>
            'datetime',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(
            PublicationDispatch::class,
            'publication_dispatch_id'
        );
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(
            PublicationPackage::class,
            'publication_package_id'
        );
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(
            PublicationMetricSnapshot::class
        );
    }
}
```

---

# 20.32 Jobs

`PublishDistributionJob.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Video\Publication\Services\PublicationExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PublishDistributionJob
    implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $dispatchId,
        public readonly string $claimToken,
    ) {
        $this->onQueue(
            'publication'
        );
    }

    public function handle(
        PublicationExecutionService $service
    ): void {
        $service->execute(
            dispatchId:
                $this->dispatchId,

            claimToken:
                $this->claimToken,
        );
    }
}
```

Lưu ý:

```php
public int $tries = 1;
```

có chủ ý.

Laravel queue **không được tự retry provider side effect một cách mù quáng**.

Retry policy phải đi qua:

```text
FAILED
or
UNCERTAIN → reconciliation
```

---

# 20.33 Metrics Job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Video\Publication\Services\PublicationMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CapturePublicationMetricsJob
    implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $publicationId,
    ) {
        $this->onQueue(
            'publication-metrics'
        );
    }

    public function handle(
        PublicationMetricsService $service
    ): void {
        $service->capture(
            $this->publicationId
        );
    }
}
```

Metrics GET/read có thể retry bình thường hơn publishing POST side effect.

---

# 20.34 API endpoints

Tôi chốt endpoint semantics:

```text
POST /api/video-sessions/{session}/publication-packages
POST /api/publication-packages/{package}/freeze
POST /api/publication-packages/{package}/dispatch
POST /api/publication-dispatches/{dispatch}/claim
POST /api/publication-dispatches/{dispatch}/reconcile
GET  /api/publication-packages/{package}
GET  /api/publications/{publication}
POST /api/publications/{publication}/metrics
GET  /api/publications/{publication}/metrics
GET  /api/publications/{publication}/lineage
```

Không dùng:

```text
POST /publish-latest-video
```

---

# 20.35 State machine chính thức

```text
PublicationPackage

BUILDING
    ↓
VALIDATED
    ↓
FROZEN
```

Sau khi `FROZEN`:

```text
IMMUTABLE
```

Muốn đổi title, description, thumbnail, target, visibility:

```text
PublicationPackage revision N
        ↓
new requirements
        ↓
PublicationPackage revision N+1
```

Không update package N.

Dispatch:

```text
PENDING
↓
CLAIMED
↓
DISPATCHING
├───────────────┐
↓               ↓
FAILED       UNCERTAIN
                ↓
            RECONCILING
                ↓
          PROVIDER_ACCEPTED
                ↓
            PUBLISHED
```

---

# 20.36 Publication Package JSON thực tế

Ví dụ:

```json
{
  "schema_version": "publication-package-v1",
  "video_project_id": "project-uuid",
  "video_session_id": "session-uuid",
  "final_master_revision_id": "master-revision-uuid",
  "final_master_hash": "8f3c...",
  "assets": {
    "final_master": {
      "artifact_id": "artifact-uuid",
      "artifact_hash": "8f3c...",
      "storage_uri": "artifacts/session/master/final.mp4",
      "mime_type": "video/mp4",
      "byte_size": 184223903
    },
    "thumbnail": {
      "artifact_id": "thumbnail-uuid",
      "artifact_hash": "aae9...",
      "storage_uri": "artifacts/session/master/thumbnail.jpg",
      "mime_type": "image/jpeg",
      "byte_size": 1049923
    }
  },
  "metadata": {
    "title": "Example title",
    "description": "Example description",
    "language": "en",
    "visibility": "private",
    "scheduled_at": null
  },
  "targets": [
    {
      "key": "youtube_primary",
      "provider": "youtube",
      "account_key": "youtube_primary",
      "settings": {
        "privacy_status": "private",
        "category_id": "22",
        "made_for_kids": false
      }
    }
  ]
}
```

---

# 20.37 Full Part 1 → Part 20 chain

Sau khi thêm Part 20, chain tổng thể là:

```text
Article
↓
ArticleNormalizer
↓
EvidenceIndex
↓
Truth Extraction / Gatekeeper
↓
VerifiedWorldGraph
↓
Inspiration
↓
ConceptInput
↓
CanonicalStructuredConceptDesigner
↓
Canonical validation / repair
↓
Exact Canonical bytes
↓
Canonical hash
↓
FROZEN CANONICAL REVISION

↓
AssetProjection
↓
ConstraintSet
↓
PromptCompiler
↓
RenderRequest
↓
Image Render
↓
Vision QA
↓
Approved Anchor
↓
Reference Pack
↓
IdentityLock
↓
FROZEN IDENTITY LOCK

↓
Frozen RenderPlan
↓
SceneProjection
↓
Scene Image
↓
MotionSpec
↓
Video RenderRequest
↓
Video Artifact
↓
Shot QA
↓
ApprovedShotRevision
↓
FROZEN SHOT REVISION

↓
FinalCompositionPlan
↓
Exact CompositionPlan bytes/hash
↓
FFmpeg deterministic assembly
↓
Final QA
↓
FinalMasterManifest
↓
FinalMasterRevision
↓
FROZEN FINAL MASTER

══════════════════════════════
PART 20
══════════════════════════════

↓
PublicationPackageBuilder
↓
PublicationPackage validation
↓
Exact Package bytes
↓
package_hash
↓
FROZEN PUBLICATION PACKAGE

↓
PublicationDispatch
↓
claim / lease
↓
request_hash
↓
idempotency_key
↓
PublisherAdapter
↓
Remote Provider
↓
Provider Result

├── accepted
│      ↓
│   Publication
│      ↓
│   Publication Ledger
│
└── uncertain
       ↓
   Reconciliation
       ↓
   Remote Identity recovery
       ↓
   Publication

↓
MetricsProvider
↓
Raw Metrics Response
↓
raw_response_hash
↓
PublicationMetricSnapshot
↓
Normalized PublicationMetric[]
↓
Metrics Ledger
↓
FULL METRICS LINEAGE
```

Đây vẫn là **ONE production path**. YouTube/Facebook/TikTok chỉ là `PublisherAdapter` implementations, giống OpenAI/Gemini/Veo/Kling ở media layer; chúng không phải các pipeline semantic độc lập.

---

# 20.38 Những invariant tôi chốt cho Part 20

```text
P20-01
A publication package MUST reference
an exact frozen FinalMasterRevision.

P20-02
No publisher may query "latest artifact".

P20-03
package_hash MUST hash exact frozen
package_json bytes.

P20-04
Publishing MUST use a durable
PublicationDispatch.

P20-05
Provider credentials MUST NOT be
stored in frozen publication package.

P20-06
request_hash MUST represent the exact
logical provider-bound request.

P20-07
Network uncertainty after outbound
provider dispatch MUST NOT trigger
blind retry.

P20-08
UNCERTAIN dispatches MUST enter
provider reconciliation.

P20-09
Remote provider identity MUST be
persisted before publication is
considered confirmed.

P20-10
Publication ledger MUST be append-only.

P20-11
Metrics snapshots MUST be append-only.

P20-12
Raw provider metric response MUST be
hashed before normalization.

P20-13
Normalized metric values MUST retain
their source snapshot ID.

P20-14
Normalizer version MUST be persisted.

P20-15
Historical metrics MUST NOT be
overwritten when normalization logic
changes.

P20-16
Publication and metrics MUST NEVER
mutate Canonical, IdentityLock,
RenderPlan, ApprovedShotRevision,
or FinalMasterRevision.
```

---

## Chốt architecture

Sau Part 20, ranh giới trách nhiệm cuối cùng là:

```text
Laravel
─────────────────────────────────
PublicationPackage truth
Package freeze
Distribution intent
Channel targeting
Durable dispatch
Claim / lease
Publication state
Reconciliation state
Publication ledger
Metrics ledger
Metrics lineage
Approval / revision

Provider Adapter
─────────────────────────────────
Provider API projection
Credential resolution
Upload/publish call
Remote status lookup
Remote metrics fetch

Python
─────────────────────────────────
Không cần tham gia Part 20
trừ khi cần media-side preprocessing
đã được frozen thành artifact mới
trước PublicationPackage freeze.
```

Tức là **Part 20 nên gần như hoàn toàn ở Laravel**, vì đây là control-plane + external distribution + durable lineage, không phải media-generation workload.

Với Part 20 này, chain từ **Canonical → Identity → Shots → Final Master → Publication → Metrics** đã khép kín về mặt production architecture và audit lineage.
