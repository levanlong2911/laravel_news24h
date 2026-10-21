<?php

namespace App\Services\PromptLibrary\Import;

use App\Services\PromptLibrary\PromptLibraryException;

/**
 * Tach Markdown thanh cay section ma KHONG mat byte nao, dua tren
 * MarkdownPromptParser cua superyacht-pipeline-starter nhung sua ba cho lam
 * mat noi dung cua no (bo phan truoc "##" dau tien, bo dong heading, trim than).
 *
 *   preamble       moi thu truoc "##" dau tien (neu co)
 *   "## X"         section cap 2, than gom ca dong heading toi truoc "##" ke tiep
 *   "### Y"        section con "<cha>.<y>"; phan giua "## X" va "### " dau tien la "<cha>.intro"
 *
 * Bat bien kiem ngay trong parse(): noi cac section cap 0/2 lai bang dung noi
 * dung vao; voi moi cha co con, intro + cac con noi lai bang dung than cha.
 * Heading trong khoi ``` / ~~~ khong tinh. Khoa trung nhau thi tu choi.
 */
final class MarkdownSectionParser
{
    /** @return list<ParsedSection> */
    public function parse(string $content): array
    {
        $lines = $content === '' ? [] : preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $marks = $this->headingMarks($lines);
        $sections = [];
        $topLevel = [];

        $firstTop = $this->firstIndex($marks, 2);
        $preambleEnd = $firstTop ?? count($lines);

        if ($preambleEnd > 0) {
            $topLevel[] = $sections[] = $this->section('preamble', null, null, 0, 'preamble', $lines, 0, $preambleEnd);
        }

        $tops = array_values(array_filter($marks, fn (array $m): bool => $m['level'] === 2));

        foreach ($tops as $i => $top) {
            $end = $tops[$i + 1]['index'] ?? count($lines);
            $key = $this->topKey($top['title']);
            $parent = $this->section($key, null, $top['title'], 2, $this->topKind($top['title']), $lines, $top['index'], $end);
            $topLevel[] = $sections[] = $parent;

            $children = array_values(array_filter(
                $marks,
                fn (array $m): bool => $m['level'] === 3 && $m['index'] > $top['index'] && $m['index'] < $end,
            ));

            if ($children === []) {
                continue;
            }

            $family = [$this->section($key.'.intro', $key, null, 3, 'intro', $lines, $top['index'], $children[0]['index'])];

            foreach ($children as $j => $child) {
                $childEnd = $children[$j + 1]['index'] ?? $end;
                $family[] = $this->section(
                    $key.'.'.$this->slug($child['title']), $key, $child['title'], 3,
                    $this->childKind($child['title']), $lines, $child['index'], $childEnd,
                );
            }

            $this->assertJoins($family, $parent->body, "section {$key}");
            array_push($sections, ...$family);
        }

        $this->assertJoins($topLevel, implode('', $lines), 'tai lieu');
        $this->assertUniqueKeys($sections);

        return $sections;
    }

    public function slug(string $text): string
    {
        $text = str_replace(['đ', 'Đ'], ['d', 'D'], $text);

        if (class_exists(\Normalizer::class)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_D));
        }

        $text = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text));

        return substr(trim($text, '-'), 0, 120) ?: 'section';
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{index: int, level: int, title: string}>
     */
    private function headingMarks(array $lines): array
    {
        $marks = [];
        $inFence = false;

        foreach ($lines as $index => $line) {
            $text = rtrim($line, "\r\n");

            if (preg_match('/^\s*(```|~~~)/', $text)) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence) {
                continue;
            }

            if (preg_match('/^(#{2,3})\s+(.+?)\s*#*\s*$/u', $text, $m)) {
                $marks[] = ['index' => $index, 'level' => strlen($m[1]), 'title' => $this->stripInline($m[2])];
            }
        }

        return $marks;
    }

    /** @param list<array{index: int, level: int, title: string}> $marks */
    private function firstIndex(array $marks, int $level): ?int
    {
        foreach ($marks as $mark) {
            if ($mark['level'] === $level) {
                return $mark['index'];
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function section(string $key, ?string $parent, ?string $heading, int $level, string $kind, array $lines, int $from, int $to): ParsedSection
    {
        return new ParsedSection(
            key: $key,
            parentKey: $parent,
            heading: $heading,
            level: $level,
            kind: $kind,
            startLine: $from + 1,
            endLine: max($from + 1, $to),
            body: implode('', array_slice($lines, $from, $to - $from)),
        );
    }

    private function topKey(string $title): string
    {
        return preg_match('/^Phase\s+(\d{1,2}[A-Z]?)\b/u', $title, $m) ? 'phase-'.strtolower($m[1]) : $this->slug($title);
    }

    private function topKind(string $title): string
    {
        return match (true) {
            (bool) preg_match('/^Phase\s+\d/u', $title) => 'phase',
            (bool) preg_match('/^System prompt\b/iu', $title) => 'system',
            default => 'section',
        };
    }

    private function childKind(string $title): string
    {
        return match (true) {
            (bool) preg_match('/^(Quality gate|Gates?)\b/iu', $title) => 'gate',
            (bool) preg_match('/^Output\b/iu', $title) => 'output',
            (bool) preg_match('/^Absolute rules\b/iu', $title) => 'rules',
            default => 'section',
        };
    }

    private function stripInline(string $text): string
    {
        $text = (string) preg_replace('/\[(.*?)\]\([^)]*\)/u', '$1', $text);

        return trim(str_replace(['**', '__', '`', '\\'], '', $text));
    }

    /** @param list<ParsedSection> $parts */
    private function assertJoins(array $parts, string $whole, string $what): void
    {
        $joined = implode('', array_map(fn (ParsedSection $s): string => $s->body, $parts));

        if ($joined !== $whole) {
            throw new PromptLibraryException("Parser lam mat noi dung cua {$what}: noi cac section khong ra dung ban goc.");
        }
    }

    /** @param list<ParsedSection> $sections */
    private function assertUniqueKeys(array $sections): void
    {
        $seen = [];

        foreach ($sections as $section) {
            if (isset($seen[$section->key])) {
                throw new PromptLibraryException("Trung khoa section: {$section->key} (dong {$seen[$section->key]} va {$section->startLine}).");
            }

            $seen[$section->key] = $section->startLine;
        }
    }
}
