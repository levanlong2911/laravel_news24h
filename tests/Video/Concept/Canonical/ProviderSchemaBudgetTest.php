<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Concept\Claude\ClaudeSchemaAdapter;
use App\Video\Concept\Schema\EffectiveConceptSchemaBuilder;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use Tests\TestCase;

/**
 * Anthropic tu choi schema bang BA cach khac nhau, ca ba deu KHONG co trong tai
 * lieu va deu chi lo ra khi da gui request di:
 *
 *   400  too many optional parameters (limit: 24)
 *   400  too many parameters with union types (limit: 16)
 *   400  The compiled grammar is too large      <- khong kem con so nao
 *
 * Hai cai dau dem duoc chinh xac. Cai thu ba thi khong: "grammar" la thu
 * Anthropic bien dich, ta khong thay. Nen o day do cac DAI LUONG THAY THE ma ta
 * dieu khien duoc, va ghim tran cho chung — de mot lan them field vao profile
 * khong am tham day schema qua nguong roi chi vo ra luc bam nut.
 *
 * Khi mot bai o day do, doc ngay bang phan bo no in ra: no chi thang phan nao
 * cua schema dang phinh.
 */
class ProviderSchemaBudgetTest extends TestCase
{
    /**
     * Do tu con so THAT da bi tu choi: 42 optional / 31 union.
     * Giu tran duoi han muc de con duong lui.
     */
    private const MAX_OPTIONAL = 24;

    private const MAX_UNION = 16;

    /** @return array<string, mixed> */
    private function schema(string $objectType = 'yacht'): array
    {
        $profile = app(CategoryCreativeProfileResolver::class)->resolve($objectType);

        return app(ClaudeSchemaAdapter::class)->adapt(
            app(EffectiveConceptSchemaBuilder::class)->build($profile)->schema,
        );
    }

    /**
     * Dem theo dung cach provider dem: optional = property khong nam trong
     * `required`; union = property co `anyOf` hoac `type` la mang.
     *
     * @param  array<string, mixed>  $node
     * @return array{optional: list<string>, union: list<string>}
     */
    private function budgets(array $node, string $path = ''): array
    {
        $optional = [];
        $union = [];

        if (($node['type'] ?? null) === 'object' && is_array($node['properties'] ?? null)) {
            $required = is_array($node['required'] ?? null) ? $node['required'] : [];

            foreach ($node['properties'] as $key => $child) {
                $childPath = $path === '' ? $key : $path.'.'.$key;

                if (! in_array($key, $required, true)) {
                    $optional[] = $childPath;
                }

                if (! is_array($child)) {
                    continue;
                }

                if (is_array($child['anyOf'] ?? null) || is_array($child['type'] ?? null)) {
                    $union[] = $childPath;
                }

                $deeper = $this->budgets($child, $childPath);
                $optional = [...$optional, ...$deeper['optional']];
                $union = [...$union, ...$deeper['union']];
            }
        }

        foreach (['items', 'anyOf', 'allOf'] as $keyword) {
            $branch = $node[$keyword] ?? null;

            if (! is_array($branch)) {
                continue;
            }

            $children = array_is_list($branch)
                ? $branch
                : [$branch];

            foreach ($children as $index => $child) {
                if (! is_array($child)) {
                    continue;
                }

                $childPath = array_is_list($branch)
                    ? "{$path}/{$keyword}[{$index}]"
                    : $path.'[]';

                $deeper = $this->budgets($child, $childPath);
                $optional = [...$optional, ...$deeper['optional']];
                $union = [...$union, ...$deeper['union']];
            }
        }

        return ['optional' => $optional, 'union' => $union];
    }

    public function test_the_schema_stays_inside_the_optional_budget(): void
    {
        $optional = $this->budgets($this->schema())['optional'];

        $this->assertLessThanOrEqual(
            self::MAX_OPTIONAL,
            count($optional),
            sprintf("Vuot tran optional (%d/%d):\n  %s",
                count($optional), self::MAX_OPTIONAL, implode("\n  ", $optional)),
        );
    }

    public function test_the_schema_stays_inside_the_union_budget(): void
    {
        $union = $this->budgets($this->schema())['union'];

        $this->assertLessThanOrEqual(
            self::MAX_UNION,
            count($union),
            sprintf("Vuot tran union (%d/%d):\n  %s",
                count($union), self::MAX_UNION, implode("\n  ", $union)),
        );
    }

    /**
     * `pattern` la rang buoc GIA TRI, khong phai HINH DANG. Provider phai dung
     * mot automaton cho moi regex, ma ta dang gui 13 cai — trong do 11 cai la
     * cung mot `^R[0-9]{3}$` lap lai o 11 nhanh quan he.
     *
     * Bo chung KHONG mat bao dam nao: CanonicalSchemaValidator van kiem raw JSON
     * bang core schema, noi pattern con nguyen. Cung ly do ma minLength/maxLength
     * /minimum/maximum da nam san trong REMOVED_KEYWORDS.
     *
     * Bai nay dang DO co chu dich — no ghim khoang trong da biet.
     */
    public function test_value_constraints_do_not_reach_the_grammar_compiler(): void
    {
        $json = json_encode($this->schema(), JSON_THROW_ON_ERROR);

        $this->assertSame(
            0,
            substr_count($json, '"pattern"'),
            'Moi regex la mot automaton trong grammar; pattern thuoc ve core schema, khong thuoc ve provider',
        );
    }

    /**
     * Bang phan bo — chay de BIET phan nao dang phinh, khong phai de pass/fail.
     * Uoc luong: moi property la mot nhanh, moi enum value mot literal, moi
     * anyOf mot alternation, moi pattern mot automaton. KHONG phai cong thuc cua
     * Anthropic, chi de xep hang tuong doi.
     */
    public function test_it_reports_where_the_grammar_weight_sits(): void
    {
        $schema = $this->schema();
        $weights = [];

        foreach ($schema['properties'] as $name => $node) {
            $weights[$name] = is_array($node) ? $this->weigh($node) : 0;
        }

        arsort($weights);
        $total = array_sum($weights);

        $report = "\nPHAN BO GRAMMAR (uoc luong, tong {$total}):\n";

        foreach ($weights as $name => $weight) {
            $report .= sprintf("  %-24s %4d  %5.1f%%\n", $name, $weight, $total > 0 ? $weight * 100 / $total : 0);
        }

        fwrite(STDERR, $report);

        $this->assertGreaterThanOrEqual(0, $total);
    }

    /** @param array<string, mixed> $node */
    private function weigh(array $node): int
    {
        $weight = 0;

        if (is_array($node['properties'] ?? null)) {
            $weight += count($node['properties']);

            foreach ($node['properties'] as $child) {
                if (is_array($child)) {
                    $weight += $this->weigh($child);
                }
            }
        }

        if (is_array($node['enum'] ?? null)) {
            $weight += count($node['enum']);
        }

        if (isset($node['pattern'])) {
            $weight += 5;
        }

        foreach (['anyOf', 'allOf'] as $keyword) {
            if (is_array($node[$keyword] ?? null) && array_is_list($node[$keyword])) {
                $weight += count($node[$keyword]);

                foreach ($node[$keyword] as $branch) {
                    if (is_array($branch)) {
                        $weight += $this->weigh($branch);
                    }
                }
            }
        }

        if (is_array($node['items'] ?? null)) {
            $weight += $this->weigh($node['items']);
        }

        return $weight;
    }
}
