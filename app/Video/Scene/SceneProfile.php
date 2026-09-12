<?php

declare(strict_types=1);

namespace App\Video\Scene;

use JsonException;

final class SceneProfile
{
    private const MIN_SCENES_FLOOR = 10;

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, array{index: int, phase: string, label: string, required: bool}>  $flat
     */
    private function __construct(
        public readonly string $version,
        public readonly string $sha256,
        public readonly string $subjectClass,
        public readonly string $objective,
        public readonly string $detailLevel,
        public readonly string $scope,
        public readonly int $minScenes,
        public readonly int $maxMilestonesPerScene,
        public readonly array $environments,
        public readonly array $groups,
        private readonly array $flat,
    ) {}

    public static function load(string $dir, string $version): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,59}$/', $version) !== 1) {
            throw new ScenePlanException('Malformed scene profile version: '.$version);
        }

        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$version.'.json';

        if (! is_file($path)) {
            throw new ScenePlanException('Scene planning profile not found: '.$path);
        }

        $bytes = (string) file_get_contents($path);

        try {
            $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ScenePlanException('Scene planning profile is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new ScenePlanException('Scene planning profile must be a JSON object.');
        }

        if (($data['profile_version'] ?? null) !== $version) {
            throw new ScenePlanException('Profile file '.$path.' declares a different version.');
        }

        foreach (['subject_class', 'objective', 'detail_level', 'scope'] as $key) {
            if (! is_string($data[$key] ?? null) || trim((string) $data[$key]) === '') {
                throw new ScenePlanException('Scene planning profile is missing '.$key.'.');
            }
        }

        $minScenes = $data['min_scenes'] ?? null;

        if (! is_int($minScenes) || $minScenes < self::MIN_SCENES_FLOOR) {
            throw new ScenePlanException('min_scenes must be at least '.self::MIN_SCENES_FLOOR.'.');
        }

        $cap = $data['max_milestones_per_scene'] ?? null;

        if (! is_int($cap) || $cap < 1) {
            throw new ScenePlanException('max_milestones_per_scene must be a positive integer.');
        }

        $groups = $data['milestone_groups'] ?? null;

        if (! is_array($groups) || ! array_is_list($groups) || $groups === []) {
            throw new ScenePlanException('milestone_groups must be a non-empty list.');
        }

        $environments = self::readEnvironments($data['environments'] ?? []);

        [$flat, $required] = self::flatten($groups);

        if ($required === 0) {
            throw new ScenePlanException('Scene planning profile has no required milestone.');
        }

        self::checkEnvironmentRefs($groups, $environments);

        return new self(
            environments: $environments,
            version: $version,
            sha256: hash('sha256', $bytes),
            subjectClass: (string) $data['subject_class'],
            objective: (string) $data['objective'],
            detailLevel: (string) $data['detail_level'],
            scope: (string) $data['scope'],
            minScenes: $minScenes,
            maxMilestonesPerScene: $cap,
            groups: array_values($groups),
            flat: $flat,
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<string, array{label: string, prompt: string}>
     */
    private static function readEnvironments($raw): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ScenePlanException('environments must be a list.');
        }

        $environments = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new ScenePlanException('Each environment must be an object.');
            }

            $key = $entry['key'] ?? null;

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{2,59}$/', $key) !== 1) {
                throw new ScenePlanException('Malformed environment key: '.var_export($key, true));
            }

            if (array_key_exists($key, $environments)) {
                throw new ScenePlanException('Environment key repeats: '.$key);
            }

            foreach (['label', 'prompt'] as $field) {
                if (! is_string($entry[$field] ?? null) || trim((string) $entry[$field]) === '') {
                    throw new ScenePlanException('Environment '.$key.' is missing '.$field.'.');
                }
            }

            $environments[$key] = [
                'label' => (string) $entry['label'],
                'prompt' => (string) $entry['prompt'],
            ];
        }

        return $environments;
    }

    /**
     * @param  list<mixed>  $groups
     * @param  array<string, array{label: string, prompt: string}>  $environments
     */
    private static function checkEnvironmentRefs(array $groups, array $environments): void
    {
        if ($environments === []) {
            return;
        }

        foreach ($groups as $group) {
            $phase = (string) $group['key'];

            self::checkEnvironmentRef($group['environment'] ?? null, $environments, $phase, true);

            foreach ($group['milestones'] ?? [] as $milestone) {
                if (is_array($milestone)) {
                    self::checkEnvironmentRef(
                        $milestone['environment'] ?? null, $environments, $phase, false,
                    );
                }
            }
        }
    }

    /**
     * @param  mixed  $key
     * @param  array<string, array{label: string, prompt: string}>  $environments
     */
    private static function checkEnvironmentRef(
        $key,
        array $environments,
        string $phase,
        bool $required,
    ): void {
        if ($key === null) {
            if ($required) {
                throw new ScenePlanException('Group '.$phase.' declares no environment.');
            }

            return;
        }

        if (! is_string($key) || ! array_key_exists($key, $environments)) {
            throw new ScenePlanException(
                'Unknown environment '.var_export($key, true).' on '.$phase.'.'
            );
        }
    }

    /** @return list<string> */
    public function environmentKeys(): array
    {
        return array_keys($this->environments);
    }

    public function environmentLabelOf(string $key): ?string
    {
        return $this->environments[$key]['label'] ?? null;
    }

    public function environmentPromptOf(string $key): ?string
    {
        return $this->environments[$key]['prompt'] ?? null;
    }

    /** @return list<string> */
    public function phaseKeys(): array
    {
        return array_map(static fn (array $group) => (string) $group['key'], $this->groups);
    }

    /** @return list<string> */
    public function milestoneKeys(): array
    {
        return array_keys($this->flat);
    }

    /** @return list<string> */
    public function requiredMilestoneKeys(): array
    {
        return array_keys(array_filter($this->flat, static fn (array $entry) => $entry['required']));
    }

    public function indexOf(string $milestone): ?int
    {
        return $this->flat[$milestone]['index'] ?? null;
    }

    public function phaseOf(string $milestone): ?string
    {
        return $this->flat[$milestone]['phase'] ?? null;
    }

    public function labelOf(string $milestone): ?string
    {
        return $this->flat[$milestone]['label'] ?? null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'profile_version' => $this->version,
            'subject_class' => $this->subjectClass,
            'objective' => $this->objective,
            'detail_level' => $this->detailLevel,
            'scope' => $this->scope,
            'min_scenes' => $this->minScenes,
            'max_milestones_per_scene' => $this->maxMilestonesPerScene,
            'milestone_groups' => $this->groups,
        ];
    }

    /**
     * @param  list<mixed>  $groups
     * @return array{0: array<string, array{index: int, phase: string, label: string, required: bool}>, 1: int}
     */
    private static function flatten(array $groups): array
    {
        $flat = [];
        $phases = [];
        $index = 0;
        $required = 0;

        foreach ($groups as $group) {
            if (! is_array($group) || array_is_list($group)) {
                throw new ScenePlanException('Each milestone group must be an object.');
            }

            $phase = $group['key'] ?? null;

            if (! is_string($phase) || preg_match('/^[a-z][a-z0-9_]{2,59}$/', $phase) !== 1) {
                throw new ScenePlanException('Malformed group key: '.var_export($phase, true));
            }

            if (isset($phases[$phase])) {
                throw new ScenePlanException('Group key repeats: '.$phase);
            }

            $phases[$phase] = true;
            $milestones = $group['milestones'] ?? null;

            if (! is_array($milestones) || ! array_is_list($milestones) || $milestones === []) {
                throw new ScenePlanException('Group "'.$phase.'" has no milestones.');
            }

            foreach ($milestones as $milestone) {
                if (! is_array($milestone) || array_is_list($milestone)) {
                    throw new ScenePlanException('Each milestone must be an object.');
                }

                $key = $milestone['key'] ?? null;

                if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{2,59}$/', $key) !== 1) {
                    throw new ScenePlanException('Malformed milestone key: '.var_export($key, true));
                }

                if (isset($flat[$key])) {
                    throw new ScenePlanException('Milestone key repeats across groups: '.$key);
                }

                $label = $milestone['label'] ?? null;

                if (! is_string($label) || trim($label) === '') {
                    throw new ScenePlanException('Milestone "'.$key.'" has no label.');
                }

                $isRequired = $milestone['required'] ?? null;

                if (! is_bool($isRequired)) {
                    throw new ScenePlanException('Milestone "'.$key.'" must declare required as a boolean.');
                }

                $flat[$key] = [
                    'index' => $index++,
                    'phase' => $phase,
                    'label' => $label,
                    'required' => $isRequired,
                ];

                $required += $isRequired ? 1 : 0;
            }
        }

        return [$flat, $required];
    }
}
