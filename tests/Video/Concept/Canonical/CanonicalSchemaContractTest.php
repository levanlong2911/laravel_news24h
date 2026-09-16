<?php

declare(strict_types=1);

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\Canonical\DesignIdentity;
use App\Video\Concept\Canonical\DesignThesis;
use App\Video\Concept\Canonical\Exclusion;
use App\Video\Concept\Canonical\Invariant;
use App\Video\Concept\Canonical\ProvenanceEntry;
use App\Video\Concept\Claude\CanonicalSchemaProvider;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Khoa hop dong giua DTO va schema mà PRODUCTION doc.
 *
 * Truoc day co HAI ban schema canonical: mot trong `resources/` (production doc) va
 * mot ban sao trong `contracts/` (test doc). Chung troi khoi nhau o
 * `identity.finish_identity_basis`, va 16 test do trong nhieu ngay ma khong ai doc
 * ra nguyen nhan — vi khong co gi doi chieu hai ben.
 *
 * Ban sao da bi xoa. File nay ton tai de neu schema lai bi cat mat mot truong ma DTO
 * dang can, thi cai do la MOT test do noi thang ten truong, khong phai mot dong
 * "Unknown property" giua muoi sau lan that bai khac.
 */
class CanonicalSchemaContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return (new CanonicalSchemaProvider(
            (string) config('canonical_concept.schema.path'),
        ))->schema();
    }

    public function test_the_schema_production_reads_is_the_only_one_in_the_repository(): void
    {
        $copies = [];
        $root = base_path();
        // Cat nhanh TRUOC khi di xuong, khong loc sau: quet ca vendor/ va storage/ ton
        // gan bay giay cho mot test doc mot dong. CATCH_GET_CHILD vi repo co thu muc
        // tam khong doc duoc, va mot test hop dong khong duoc do vi quyen he thong tep.
        $skip = ['vendor', 'node_modules', '.git', 'storage', 'public', 'bootstrap'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => ! $file->isDir()
                    || ! in_array($file->getFilename(), $skip, true),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (basename($path) === 'canonical_design_spec_v1.json') {
                $copies[] = str_replace(str_replace('\\', '/', $root).'/', '', $path);
            }
        }

        // Ky vong lay tu CHINH config production, khong go lai bang tay: doi path hop le
        // thi test nay phai di theo, khong duoc do sai.
        //
        // Chuan hoa dau gach TRUOC roi moi cat tien to: tren Windows config tra ve `\`,
        // nen cat tien to dang `/` se khong khop gi ca.
        $expected = str_replace(
            str_replace('\\', '/', base_path()).'/',
            '',
            str_replace('\\', '/', (string) config('canonical_concept.schema.path')),
        );

        $this->assertSame(
            [$expected],
            $copies,
            'hai ban schema se troi khoi nhau — do la cach 16 test do suot nhieu ngay',
        );
    }

    /**
     * Moi tham so constructor cua DTO phai co mat trong schema VA duoc khai `required`.
     *
     * Phu ca DTO long chu khong chi DTO goc: `finish_identity_basis` — truong da lam hai
     * ban schema troi khoi nhau — nam o `DesignIdentity`, nen mot test chi soi
     * `CanonicalDesignSpec` se khong bao gio thay no.
     *
     * @dataProvider dtoToSchemaPath
     */
    public function test_every_constructor_argument_of_a_dto_is_a_required_property(
        string $dto,
        string $path,
    ): void {
        $node = $this->schema();

        foreach (array_filter(explode('.', $path)) as $segment) {
            $node = (array) ($node[$segment] ?? []);
        }

        $required = (array) ($node['required'] ?? []);
        $properties = array_keys((array) ($node['properties'] ?? []));

        foreach ((new ReflectionClass($dto))->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                continue;
            }

            $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName()));
            $key = self::ALIASES[$key] ?? $key;
            $where = class_basename($dto);

            $this->assertContains($key, $properties, "schema thieu property `{$key}` ma {$where} doi");
            $this->assertContains($key, $required, "schema khong bat buoc `{$key}` ma {$where} doi");
        }
    }

    /**
     * Ten tham so khong phai luc nao cung bang ten truong schema.
     *
     * `relationshipId` la `id` trong `$defs`: class dat ten dai de doc duoc trong PHP,
     * con schema giu ten ngan. Day la anh xa CO CHU DICH, khong phai drift.
     */
    private const ALIASES = [
        'relationship_id' => 'id',
    ];

    /**
     * Danh sach nay mot phan la QUY TAC chu khong phai bang ke: moi class relationship
     * duoc tim thay tren dia deu phai co `$defs` tuong ung. Them mot relationship moi ma
     * quen khai schema thi test nay do ngay, khong cho ai phai nho cap nhat danh sach.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function dtoToSchemaPath(): array
    {
        $cases = [
            'CanonicalDesignSpec' => [CanonicalDesignSpec::class, ''],
            'DesignIdentity' => [DesignIdentity::class, 'properties.identity'],
            'DesignThesis' => [DesignThesis::class, 'properties.design_thesis'],
            'Invariant' => [Invariant::class, 'properties.invariants.items'],
            'Exclusion' => [Exclusion::class, 'properties.exclusions.items'],
            'ProvenanceEntry' => [ProvenanceEntry::class, 'properties.provenance.items'],
        ];

        foreach (glob(__DIR__.'/../../../../app/Video/Concept/Canonical/Relationships/*Relationship.php') ?: [] as $file) {
            $short = basename($file, '.php');

            if ($short === 'Relationship') {
                continue;
            }

            $cases[$short] = [
                'App\\Video\\Concept\\Canonical\\Relationships\\'.$short,
                '$defs.'.lcfirst($short),
            ];
        }

        return $cases;
    }

    public function test_identity_declares_finish_identity_basis_and_requires_it(): void
    {
        $identity = (array) ($this->schema()['properties']['identity'] ?? []);

        $this->assertArrayHasKey(
            'finish_identity_basis',
            (array) ($identity['properties'] ?? []),
            'day dung la truong da lam hai ban schema troi khoi nhau',
        );

        $this->assertContains('finish_identity_basis', (array) ($identity['required'] ?? []));
    }

    /**
     * Object nao da khai `properties` va `required` thi phai dong.
     *
     * Mo mot object nhu the nghia la model tra ve truong la cung qua duoc cong, roi
     * DTO lang le bo no — dung kieu mat du lieu ma Truth Layer da dinh mot lan.
     *
     * @dataProvider closedObjects
     */
    public function test_a_declared_object_refuses_unknown_properties(string $path): void
    {
        $node = $this->schema();

        foreach (array_filter(explode('.', $path)) as $segment) {
            $node = (array) ($node[$segment] ?? []);
        }

        $this->assertFalse(
            $node['additionalProperties'] ?? null,
            "`".($path !== '' ? $path : 'root')."` phai dong: mo no ra thi truong la di qua duoc cong roi bi bo im lang",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function closedObjects(): array
    {
        return [
            'root' => [''],
            'design_thesis' => ['properties.design_thesis'],
            'identity' => ['properties.identity'],
        ];
    }
}
