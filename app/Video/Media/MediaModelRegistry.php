<?php

namespace App\Video\Media;

use InvalidArgumentException;

final class MediaModelRegistry
{
    /** @var list<string> */
    private const PROVIDERS = ['openai', 'gemini'];

    /** @var list<string> */
    private const PRICING = ['estimated', 'unpriced'];

    /** @var array<string, string> */
    private const SHAPE_VARIANTS = ['image_config' => 'imageConfig'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $verified = [];

    /** @var array<string, array<string, mixed>> */
    private array $evidence = [];

    /** @return list<array<string, mixed>> */
    public function forTask(string $task): array
    {
        if (isset($this->verified[$task])) {
            return $this->verified[$task];
        }

        $entries = config('video.media_models.image.'.$task);

        if (! is_array($entries) || $entries === [] || ! array_is_list($entries)) {
            throw new InvalidArgumentException('Khong co model nao cho task: '.$task);
        }

        $ids = [];
        $defaults = 0;

        foreach ($entries as $index => $entry) {
            $this->assertEntry($task, $index, $entry);

            if (isset($ids[$entry['id']])) {
                throw new InvalidArgumentException("Task {$task}: id lap lai {$entry['id']}.");
            }

            $ids[$entry['id']] = true;
            $defaults += $entry['default'] === true ? 1 : 0;
        }

        if ($defaults !== 1) {
            throw new InvalidArgumentException("Task {$task}: phai co dung 1 model mac dinh, dang co {$defaults}.");
        }

        foreach ($entries as $index => $entry) {
            if ($entry['provider'] === 'gemini') {
                $this->assertEvidence("Task {$task}, entry {$index}", $entry);
            }
        }

        return $this->verified[$task] = $entries;
    }

    /** @return array<string, mixed>|null */
    public function find(string $task, string $id): ?array
    {
        foreach ($this->forTask($task) as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function defaultFor(string $task): array
    {
        foreach ($this->forTask($task) as $entry) {
            if ($entry['default'] === true) {
                return $entry;
            }
        }

        throw new InvalidArgumentException('Khong co model mac dinh cho task: '.$task);
    }

    /** @param mixed $entry */
    private function assertEntry(string $task, int $index, $entry): void
    {
        $at = "Task {$task}, entry {$index}";

        if (! is_array($entry)) {
            throw new InvalidArgumentException("{$at}: khong phai mang.");
        }

        foreach (['id', 'provider', 'model', 'label', 'pricing'] as $key) {
            if (! is_string($entry[$key] ?? null) || $entry[$key] === '') {
                throw new InvalidArgumentException("{$at}: thieu {$key}.");
            }
        }

        if (! in_array($entry['provider'], self::PROVIDERS, true)) {
            throw new InvalidArgumentException("{$at}: provider la {$entry['provider']}.");
        }

        if ($entry['id'] !== $entry['provider'].':'.$entry['model']) {
            throw new InvalidArgumentException("{$at}: id phai la provider:model.");
        }

        if (! in_array($entry['pricing'], self::PRICING, true)) {
            throw new InvalidArgumentException("{$at}: pricing la {$entry['pricing']}.");
        }

        if (! is_bool($entry['default'] ?? null)) {
            throw new InvalidArgumentException("{$at}: default phai la bool.");
        }

        if (! is_int($entry['max_variations'] ?? null) || $entry['max_variations'] < 1) {
            throw new InvalidArgumentException("{$at}: max_variations phai la so nguyen >= 1.");
        }

        $controls = $entry['controls'] ?? null;

        if (! is_array($controls)) {
            throw new InvalidArgumentException("{$at}: thieu controls.");
        }

        if ($entry['provider'] === 'openai') {
            $this->assertChoice($at, $controls, 'sizes', 'default_size');
            $this->assertChoice($at, $controls, 'qualities', 'default_quality');

            return;
        }

        $this->assertGemini($at, $entry, $controls);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $controls
     */
    private function assertGemini(string $at, array $entry, array $controls): void
    {
        if (! in_array($entry['api_version'] ?? null, ['v1', 'v1beta'], true)) {
            throw new InvalidArgumentException("{$at}: api_version phai la v1 hoac v1beta.");
        }

        if (($entry['shape'] ?? null) !== 'image_config') {
            throw new InvalidArgumentException("{$at}: chi ho tro shape image_config.");
        }

        if ($entry['max_variations'] !== 1) {
            throw new InvalidArgumentException("{$at}: Gemini chi sinh 1 anh moi luot.");
        }

        if ($entry['pricing'] !== 'unpriced') {
            throw new InvalidArgumentException("{$at}: Gemini chua co bang gia da xac minh.");
        }


        $this->assertChoice($at, $controls, 'aspect_ratios', 'default_aspect_ratio');
        $this->assertChoice($at, $controls, 'image_sizes', 'default_image_size');

        if (! is_array($entry['evidence'] ?? null)) {
            throw new InvalidArgumentException("{$at}: evidence phai la mang.");
        }

        foreach (['models', 'image_config'] as $kind) {
            if (! is_string($entry['evidence'][$kind] ?? null) || $entry['evidence'][$kind] === '') {
                throw new InvalidArgumentException("{$at}: thieu evidence.{$kind}.");
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function assertEvidence(string $at, array $entry): void
    {
        $models = $this->evidenceFile($at, $entry['evidence']['models']);
        $listed = $models['versions'][$entry['api_version']] ?? [];
        $names = array_map(
            static fn ($model): string => str_replace('models/', '', (string) (is_array($model) ? ($model['name'] ?? '') : '')),
            is_array($listed) ? $listed : [],
        );

        if (! in_array($entry['model'], $names, true)) {
            throw new InvalidArgumentException(
                "{$at}: {$entry['model']} khong co trong {$entry['evidence']['models']} o {$entry['api_version']}.",
            );
        }

        $probe = $this->evidenceFile($at, $entry['evidence']['image_config']);
        $variant = self::SHAPE_VARIANTS[$entry['shape']];
        $verdict = $probe['results'][$entry['api_version']][$variant]['verdict'] ?? null;

        if ($verdict !== 'field_accepted') {
            throw new InvalidArgumentException(
                "{$at}: {$entry['evidence']['image_config']} ghi {$variant} o {$entry['api_version']} la "
                .var_export($verdict, true).', khong phai field_accepted.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function evidenceFile(string $at, string $file): array
    {
        if (preg_match('/^[a-z0-9_]+\.json$/', $file) !== 1) {
            throw new InvalidArgumentException("{$at}: ten file bang chung khong hop le: {$file}.");
        }

        $path = rtrim((string) config('video.gemini.evidence_dir'), '/\\').DIRECTORY_SEPARATOR.$file;

        if (isset($this->evidence[$path])) {
            return $this->evidence[$path];
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$at}: thieu file bang chung {$file}.");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException("{$at}: {$file} khong phai JSON hop le.");
        }

        return $this->evidence[$path] = $data;
    }

    /** @param array<string, mixed> $controls */
    private function assertChoice(string $at, array $controls, string $list, string $default): void
    {
        $choices = $controls[$list] ?? null;

        if (! is_array($choices) || $choices === [] || ! array_is_list($choices)) {
            throw new InvalidArgumentException("{$at}: thieu controls.{$list}.");
        }

        foreach ($choices as $choice) {
            if (! is_string($choice) || trim($choice) === '') {
                throw new InvalidArgumentException("{$at}: controls.{$list} chua gia tri rong hoac khong phai chuoi.");
            }
        }

        if (count(array_unique($choices)) !== count($choices)) {
            throw new InvalidArgumentException("{$at}: controls.{$list} co gia tri lap.");
        }

        if (! in_array($controls[$default] ?? null, $choices, true)) {
            throw new InvalidArgumentException("{$at}: controls.{$default} khong nam trong {$list}.");
        }
    }
}
