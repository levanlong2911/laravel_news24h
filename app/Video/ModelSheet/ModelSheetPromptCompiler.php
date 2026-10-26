<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

use RuntimeException;

/**
 * Ghep prompt master cuoi tu cac khoi QA da qua ModelSheetQaValidator. So lieu do code chen
 * (the {{F12}} -> ModelSheetFacts::render); bang chung, duong dan va bao cao QA khong vao
 * prompt anh. Vuot nguong hoac con the chua thay thi bao loi — khong cat chuoi.
 */
final class ModelSheetPromptCompiler
{
    public const VERSION = 'model-sheet-compiler-v2';

    /**
     * @param  array<string, mixed>  $config  video.superyacht.model_sheet
     * @param  array<string, array<string, mixed>>  $facts  id => du kien
     */
    public function __construct(
        private readonly array $config,
        private readonly array $facts,
    ) {}

    /**
     * @param  array<string, mixed>  $qa  ket qua QA da kiem
     * @param  list<array{id: string, view: string, row: int, column: int, x: int, y: int, width: int, height: int}>  $views
     * @param  array<string, mixed>  $ms8  ModelSheetFacts::ms8Target()
     */
    public function compile(array $qa, array $views, bool $guide, array $ms8): string
    {
        $master = (array) $this->config['master'];
        $text = (array) $master['prompt'];
        [$width, $height] = array_map('intval', explode('x', (string) $master['size']));
        $byView = [];

        foreach ((array) $qa['views'] as $view) {
            $byView[(string) $view['view_id']] = $this->sentences((array) $view['sentences']);
        }

        $layout = [self::fill((string) $text['layout'], [
            'width' => $width, 'height' => $height, 'rows' => $master['rows'], 'columns' => $master['columns'],
            'cell_width' => $master['cell']['width'], 'cell_height' => $master['cell']['height'],
        ])];

        usort($views, static fn (array $a, array $b): int => [$a['row'], $a['column']] <=> [$b['row'], $b['column']]);

        foreach ($views as $view) {
            $layout[] = trim(self::fill((string) $text['panel'], [
                'row' => $view['row'], 'column' => $view['column'],
                'x' => $view['x'], 'x_end' => $view['x'] + $view['width'], 'y' => $view['y'], 'y_end' => $view['y'] + $view['height'],
                'id' => $view['id'],
                'view' => $view['view'],
                'camera' => self::camera((array) $this->config['cameras'], $view['id'], $ms8),
            ]).' '.($byView[$view['id']] ?? ''));
        }

        $layout[] = (string) $text['framing'];

        foreach ((array) $master['detail_views'] as $id) {
            $layout[] = self::fill((string) $text['detail_framing'], ['id' => $id]);
        }

        $states = array_map(fn (array $state): string => ucfirst(trim((string) $state['part'])).': '.$this->resolve(rtrim(trim((string) $state['state']), '.').'.'), (array) $qa['display_state']);
        $rules = [...array_map('strval', (array) $text['rules']), (string) $this->config['occlusion']];

        if ($guide) {
            $rules[] = (string) $text['guide_caption'];
        }

        $prompt = implode("\n\n", array_values(array_filter([
            $layout[0],
            $this->sentences((array) $qa['shared']),
            trim($this->sentences((array) $qa['surfaces']).($states === [] ? '' : ' Shown state: '.implode(' ', $states))),
            implode("\n", array_slice($layout, 1)),
            $this->sentences((array) $qa['critical_absences']),
            implode("\n", $rules),
            trim((string) $text['presentation'].' '.ucfirst((string) $this->config['suffix'])),
        ], static fn (string $block): bool => trim($block) !== '')));

        if (preg_match('/\{\{|\}\}/', $prompt) === 1) {
            throw new RuntimeException('Prompt cuối còn thẻ dữ kiện chưa thay.');
        }

        $limit = (int) ($text['max_chars'] ?? 0);

        if ($limit > 0 && mb_strlen($prompt) > $limit) {
            throw new RuntimeException('Prompt cuối dài '.mb_strlen($prompt)." ký tự, vượt giới hạn {$limit} — cần rút gọn ở bước QA, không cắt chuỗi.");
        }

        return $prompt;
    }

    /**
     * Cau camera cua goc; mau {target} lay muc tieu MS8 cua thiet ke. Thieu muc tieu thi bao loi.
     *
     * @param  array<string, string>  $cameras
     * @param  array<string, mixed>  $ms8
     */
    public static function camera(array $cameras, string $id, array $ms8): string
    {
        $camera = (string) ($cameras[$id] ?? '');

        if (str_contains($camera, '{target}')) {
            $target = trim((string) ($ms8['camera_target'] ?? ''));
            $target !== '' || throw new RuntimeException("Góc {$id} cần mục tiêu chi tiết nhưng thiết kế không có innovation nào.");
            $camera = str_replace('{target}', $target, $camera);
        }

        return $camera;
    }

    /** @param list<array<string, mixed>> $sentences */
    private function sentences(array $sentences): string
    {
        return implode(' ', array_map(fn (array $s): string => $this->resolve(trim((string) $s['text'])), $sentences));
    }

    private function resolve(string $text): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_:.\-]+)\s*\}\}/', function (array $m): string {
            $fact = $this->facts[$m[1]] ?? throw new RuntimeException("Thẻ {{{$m[1]}}} không phải mã dữ kiện.");

            return (string) $fact['render'];
        }, $text);
    }

    /** @param array<string, int|string> $values */
    private static function fill(string $template, array $values): string
    {
        return strtr($template, array_combine(
            array_map(static fn (string $key): string => '{'.$key.'}', array_keys($values)),
            array_map('strval', array_values($values)),
        ));
    }
}
