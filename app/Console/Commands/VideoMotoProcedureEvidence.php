<?php

namespace App\Console\Commands;

use App\Models\VideoProject;
use App\Services\Video\ShotSourceService;
use App\Video\Moto\MotoScriptCheck;
use App\Video\Moto\MotoShots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VideoMotoProcedureEvidence extends Command
{
    protected $signature = 'video:moto-procedure-evidence
        {project : Video project id}
        {--set=* : One record: LIMIT:procedure=kind for a state that holds at the moment judged (stationary, engine_off, side_stand_down), or LIMIT:procedure=enters_configuration@BEAT/EVENT for the event that moves the part into the position the limit governs}
        {--reason= : Why these procedures are confirmed this way}
        {--apply : Store the confirmation with the project and keep the one it replaces in the history}';

    protected $description = 'Preview (or with --apply store) the confirmation of how procedures of the design\'s operational limits are established for the sources a moto project uses now. Each record is for one limit; a record of the act of entering a position is read from the script: the event must open on one configuration of the part and end on the configuration the limit governs. Bound to those limits and the events of the script; nothing is generated and no model is called.';

    public function __construct(private readonly ShotSourceService $shots)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $projectId = (string) $this->argument('project');
        $project = VideoProject::query()->find($projectId);

        if ($project === null) {
            $this->error('Project not found.');

            return self::FAILURE;
        }

        try {
            $adapter = $this->shots->workflowFor($projectId)['adapter'];

            if (! $adapter instanceof MotoShots) {
                $this->error('This project does not plan moto shots.');

                return self::FAILURE;
            }

            $context = $adapter->inputs($project, '')['context'];
        } catch (\RuntimeException $e) {
            $this->error('The sources of the shot plan cannot be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $records = [];

        foreach ((array) $this->option('set') as $entry) {
            [$record, $problem] = self::record((string) $entry, $context);

            if ($record === null) {
                $this->error("{$entry}: {$problem}");

                return self::FAILURE;
            }

            $records[] = $record;
        }

        $reason = trim((string) $this->option('reason'));

        if ($records === [] || $reason === '') {
            $this->error('Give at least one --set record and a --reason.');

            return self::FAILURE;
        }

        usort($records, static fn (array $a, array $b): int => [$a['limit'], $a['procedure']] <=> [$b['limit'], $b['procedure']]);
        $confirmation = ['version' => MotoShots::EVIDENCE_VERSION, 'source' => MotoShots::evidenceSource($context), 'records' => $records];
        $hash = MotoShots::evidenceHash($confirmation);
        $apply = (bool) $this->option('apply');

        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $this->line('Bound to limits and events: '.$confirmation['source']);
        $this->line('Confirmation hash: '.$hash);

        foreach ($records as $record) {
            $this->line("  {$record['limit']} {$record['procedure']} = {$record['kind']}".($record['kind'] === MotoScriptCheck::BY_ENTERING ? "  ({$record['part']} {$record['from']} -> {$record['to']} at {$record['beat']} {$record['event']})" : ''));
        }

        foreach ((array) ($context['limits'] ?? []) as $limit) {
            $named = array_column(MotoScriptCheck::evidenceFor($records, (string) $limit['id']), 'procedure');

            foreach (array_diff((array) ($limit['procedures'] ?? []), $named) as $procedure) {
                $this->line("  {$limit['id']} {$procedure}: not confirmed — established only by a situation the script states or by a change of state it shows");
            }
        }

        if (! $apply) {
            $this->info('Run again with --apply to store it with the project.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($projectId, $confirmation, $hash, $reason): void {
            $locked = VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $metadata = (array) $locked->metadata_json;
            $stored = $confirmation + ['hash' => $hash, 'reason' => $reason, 'confirmed_by' => get_current_user() ?: null, 'confirmed_at' => now()->toIso8601String()];
            $history = array_values((array) ($metadata[MotoShots::EVIDENCE_HISTORY_KEY] ?? []));
            $previous = $metadata[MotoShots::EVIDENCE_KEY] ?? null;

            if (is_array($previous) && ! in_array($previous['hash'] ?? null, array_column($history, 'hash'), true)) {
                $history[] = $previous;
            }

            if (! in_array($hash, array_column($history, 'hash'), true)) {
                $history[] = $stored;
            }

            $locked->forceFill(['metadata_json' => [...$metadata, MotoShots::EVIDENCE_KEY => $stored, MotoShots::EVIDENCE_HISTORY_KEY => $history]])->save();
        });
        $this->info('Stored, and kept in the history of the project. Shot plans of these sources are judged with it from now on; a stored run keeps its answer and is judged again when it is shown or approved, and an approval records the confirmation it was judged with.');

        return self::SUCCESS;
    }

    /**
     * @param  string  $entry  one --set value
     * @param  array<string, mixed>  $context  the check context of the shot plan built from the sources the project uses now
     * @return array{0: ?array<string, string>, 1: ?string} the record it names, or why it names none: an unknown limit, a procedure the limit does not have, an unknown kind, or — for the act of entering a position — a beat or an event the script does not have, or an event that does not move one part from another configuration into a configuration the limit governs
     */
    private static function record(string $entry, array $context): array
    {
        if (preg_match('/^([^:=@]+):([^:=@]+)=([^:=@]+)(?:@([^\/@]+)\/([^\/@]+))?$/', $entry, $match) !== 1) {
            return [null, 'write LIMIT:procedure=kind or LIMIT:procedure=enters_configuration@BEAT/EVENT.'];
        }

        [, $limitId, $procedure, $kind] = $match;
        $limit = collect((array) ($context['limits'] ?? []))->firstWhere('id', $limitId);

        if (! is_array($limit)) {
            return [null, "{$limitId} is not an operational limit of the design (".implode(', ', array_column((array) ($context['limits'] ?? []), 'id')).').'];
        }

        if (! in_array($procedure, (array) ($limit['procedures'] ?? []), true)) {
            return [null, "{$procedure} is not a procedure of {$limitId} (".implode(', ', (array) ($limit['procedures'] ?? [])).').'];
        }

        if (! in_array($kind, MotoScriptCheck::EVIDENCE_KINDS, true)) {
            return [null, "{$kind} is not a kind of evidence (".implode(', ', MotoScriptCheck::EVIDENCE_KINDS).').'];
        }

        $record = ['limit' => $limitId, 'procedure' => $procedure, 'kind' => $kind];

        if ($kind !== MotoScriptCheck::BY_ENTERING) {
            return isset($match[4]) ? [null, "{$kind} is a state: it names no beat or event."] : [$record, null];
        }

        if (! isset($match[4], $match[5])) {
            return [null, 'enters_configuration names the beat and the event that enters the position: @BEAT/EVENT.'];
        }

        $beat = collect((array) ($context['beats'] ?? []))->firstWhere('id', $match[4]);
        $event = is_array($beat) ? collect((array) ($beat['events'] ?? []))->firstWhere('id', $match[5]) : null;

        if (! is_array($event)) {
            return [null, "{$match[5]} is not an event of {$match[4]} in the script."];
        }

        $start = (array) ($event['operating_start']['configurations'] ?? []);
        $entered = array_values(array_filter((array) ($event['operating_end']['configurations'] ?? []), static fn (string $id): bool => in_array($id, (array) $limit['governs'], true) && ! in_array($id, $start, true)));

        if (count($entered) !== 1) {
            return [null, "{$match[5]} does not move one part into a configuration {$limitId} governs (".implode(', ', (array) $limit['governs']).').'];
        }

        $part = $context['configurations'][$entered[0]]['part'] ?? null;
        $left = array_values(array_filter($start, static fn (string $id): bool => ($context['configurations'][$id]['part'] ?? null) === $part));

        if (! is_string($part) || count($left) !== 1) {
            return [null, "{$match[5]} does not open on one configuration of the part of {$entered[0]}."];
        }

        return [$record + ['part' => $part, 'from' => $left[0], 'to' => $entered[0], 'beat' => (string) $match[4], 'event' => (string) $match[5]], null];
    }
}
