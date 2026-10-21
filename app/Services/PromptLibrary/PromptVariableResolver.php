<?php

namespace App\Services\PromptLibrary;

/**
 * Chen bien dang {{ ten }} vao prompt da ghep. Hai ngoac de khong dung cham
 * placeholder mot ngoac {video_length} ma tai lieu v6.3 dung lam van ban.
 *
 * Chi chen khi noi goi truyen bien. Khong truyen thi tra nguyen van: prompt
 * dang chay co chu {{SOURCE_MATERIAL}} la van ban gui model (character_reference),
 * khong phai bien cua thu vien. Da truyen bien thi moi {{...}} phai co gia tri,
 * con thieu thi tu choi, khong gui prompt do dang.
 */
final class PromptVariableResolver
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /** @param array<string, scalar|null> $variables */
    public function render(string $template, array $variables): string
    {
        if ($variables === []) {
            return $template;
        }

        $missing = [];

        $rendered = (string) preg_replace_callback(self::PATTERN, function (array $m) use ($variables, &$missing): string {
            if (! array_key_exists($m[1], $variables) || $variables[$m[1]] === null) {
                $missing[$m[1]] = true;

                return $m[0];
            }

            return (string) $variables[$m[1]];
        }, $template);

        if ($missing !== []) {
            throw new PromptLibraryException('Thieu gia tri bien prompt: '.implode(', ', array_keys($missing)));
        }

        return $rendered;
    }
}
