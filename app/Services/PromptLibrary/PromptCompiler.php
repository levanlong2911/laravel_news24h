<?php

namespace App\Services\PromptLibrary;

use App\Models\PromptLibrary\PromptBindingSection;

/**
 * Ghep section theo slot. Thuan (khong doc DB) de luat merge kiem duoc rieng.
 *
 * Entry phai den theo thu tu xu ly: lop (scope_order) tang dan, roi priority,
 * roi sort_order. Trong moi slot:
 *   append   noi vao cuoi        prepend  chen vao dau
 *   replace  xoa noi dung slot, chi khi MOI entry dang co trong slot cho phep
 *            override; hai replace cung lop tren mot slot la xung dot.
 * Slot ngoai danh sach config, merge_mode la -> tu choi. Prompt cuoi cung la
 * cac than section noi bang `separator` theo thu tu slot; mot section duy nhat
 * thi bang dung tung byte than cua no.
 */
final class PromptCompiler
{
    /**
     * @param  list<string>  $slots
     */
    public function __construct(
        private readonly array $slots,
        private readonly string $separator,
    ) {}

    public static function fromConfig(): self
    {
        return new self((array) config('prompt_library.slots'), (string) config('prompt_library.separator'));
    }

    /**
     * @param  list<array{layer: int, slot: string, mode: string, allow_override: bool, body: string, ref: array<string, mixed>}>  $entries
     * @return array{0: string, 1: list<array<string, mixed>>} [content, refs theo thu tu xuat hien]
     */
    public function compile(array $entries): array
    {
        $state = array_fill_keys($this->slots, []);
        $replacedAt = [];

        foreach ($entries as $entry) {
            $slot = $entry['slot'];

            if (! array_key_exists($slot, $state)) {
                throw new PromptLibraryException("Slot khong co trong prompt_library.slots: {$slot}");
            }

            switch ($entry['mode']) {
                case PromptBindingSection::APPEND:
                    $state[$slot][] = $entry;
                    break;

                case PromptBindingSection::PREPEND:
                    array_unshift($state[$slot], $entry);
                    break;

                case PromptBindingSection::REPLACE:
                    if (($replacedAt[$slot] ?? null) === $entry['layer']) {
                        throw new PromptLibraryException("Hai section cung replace slot {$slot} o cung mot lop.");
                    }

                    foreach ($state[$slot] as $existing) {
                        if (! $existing['allow_override']) {
                            throw new PromptLibraryException(
                                "Slot {$slot}: section {$existing['ref']['section_key']} khong cho override, khong replace duoc."
                            );
                        }
                    }

                    $state[$slot] = [$entry];
                    $replacedAt[$slot] = $entry['layer'];
                    break;

                default:
                    throw new PromptLibraryException("merge_mode khong hop le: {$entry['mode']}");
            }
        }

        $ordered = array_merge(...array_values($state));

        if ($ordered === []) {
            throw new PromptLibraryException('Binding khong gop duoc section nao.');
        }

        return [
            implode($this->separator, array_map(fn (array $e): string => $e['body'], $ordered)),
            array_map(fn (array $e): array => $e['ref'], $ordered),
        ];
    }
}
