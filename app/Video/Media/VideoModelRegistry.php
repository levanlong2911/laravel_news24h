<?php

namespace App\Video\Media;

use InvalidArgumentException;

/**
 * Cua duy nhat de form va service chap nhan mot provider:model video.
 *
 * Tach khoi MediaModelRegistry vi registry anh mang luat rieng cua Gemini image
 * (shape imageConfig, max_variations = 1, evidence image_config) khong ap duoc
 * cho video, va no doc cung mot nhanh config `media_models.image`.
 *
 * Danh sach rong KHONG phai loi cau hinh: no la trang thai "chua co model nao
 * duoc chung minh". Goi ham phai hieu `available()` truoc khi hoi `defaultFor()`.
 */
final class VideoModelRegistry
{
    /** @var list<string> */
    private const PROVIDERS = ['gemini'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $verified = [];

    /** @var array<string, array<string, mixed>> */
    private array $evidence = [];

    public function available(string $task): bool
    {
        $entries = config('video.media_models.video.'.$task);

        return is_array($entries) && $entries !== [];
    }

    /** @return list<array<string, mixed>> */
    public function forTask(string $task): array
    {
        if (isset($this->verified[$task])) {
            return $this->verified[$task];
        }

        $entries = config('video.media_models.video.'.$task);

        if (! is_array($entries) || $entries === [] || ! array_is_list($entries)) {
            throw new InvalidArgumentException('Chua co model video nao duoc xac minh cho task: '.$task);
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
            $this->assertEvidence("Task {$task}, entry {$index}", $entry);
        }

        return $this->verified[$task] = array_values($entries);
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

        throw new InvalidArgumentException('Khong co model video mac dinh cho task: '.$task);
    }

    /** @param mixed $entry */
    private function assertEntry(string $task, int $index, $entry): void
    {
        $at = "Task {$task}, entry {$index}";

        if (! is_array($entry)) {
            throw new InvalidArgumentException("{$at}: khong phai mang.");
        }

        foreach (['id', 'provider', 'model', 'label', 'api_version', 'mode', 'method'] as $key) {
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

        if (! in_array($entry['api_version'], ['v1', 'v1beta'], true)) {
            throw new InvalidArgumentException("{$at}: api_version phai la v1 hoac v1beta.");
        }

        if ($entry['mode'] !== 'async' || $entry['method'] !== 'predictLongRunning') {
            throw new InvalidArgumentException("{$at}: chi ho tro predictLongRunning bat dong bo.");
        }

        if (! is_bool($entry['default'] ?? null)) {
            throw new InvalidArgumentException("{$at}: default phai la bool.");
        }

        $controls = $entry['controls'] ?? null;

        if (! is_array($controls)) {
            throw new InvalidArgumentException("{$at}: thieu controls.");
        }

        $this->assertChoice($at, $controls, 'durations', 'default_duration');
        $this->assertChoice($at, $controls, 'aspect_ratios', 'default_aspect_ratio');
        $this->assertChoice($at, $controls, 'resolutions', 'default_resolution');

        if (! is_string($entry['evidence']['models'] ?? null) || $entry['evidence']['models'] === '') {
            throw new InvalidArgumentException("{$at}: thieu evidence.models.");
        }
    }

    /** @param array<string, mixed> $controls */
    private function assertChoice(string $at, array $controls, string $listKey, string $defaultKey): void
    {
        $list = $controls[$listKey] ?? null;

        if (! is_array($list) || $list === [] || ! array_is_list($list)) {
            throw new InvalidArgumentException("{$at}: {$listKey} phai la danh sach khong rong.");
        }

        if (! in_array($controls[$defaultKey] ?? null, $list, true)) {
            throw new InvalidArgumentException("{$at}: {$defaultKey} phai nam trong {$listKey}.");
        }
    }

    /** @param array<string, mixed> $entry */
    private function assertEvidence(string $at, array $entry): void
    {
        $file = $this->evidenceFile($at, (string) $entry['evidence']['models']);
        $listed = $file['versions'][$entry['api_version']] ?? [];

        foreach (is_array($listed) ? $listed : [] as $model) {
            if (! is_array($model)) {
                continue;
            }

            if (str_replace('models/', '', (string) ($model['name'] ?? '')) !== $entry['model']) {
                continue;
            }

            $methods = $model['supportedGenerationMethods'] ?? [];

            if (in_array($entry['method'], is_array($methods) ? $methods : [], true)) {
                return;
            }

            throw new InvalidArgumentException(
                "{$at}: {$entry['model']} co trong bang chung nhung khong ho tro {$entry['method']}.",
            );
        }

        throw new InvalidArgumentException(
            "{$at}: {$entry['model']} khong co trong bang chung {$entry['api_version']}.",
        );
    }

    /** @return array<string, mixed> */
    private function evidenceFile(string $at, string $name): array
    {
        if (isset($this->evidence[$name])) {
            return $this->evidence[$name];
        }

        $path = rtrim((string) config('video.provider_evidence_dir'), '/\\').DIRECTORY_SEPARATOR.$name;

        if (! is_file($path)) {
            throw new InvalidArgumentException("{$at}: thieu file bang chung {$name}.");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$at}: bang chung {$name} khong phai JSON hop le.");
        }

        return $this->evidence[$name] = $decoded;
    }
}
