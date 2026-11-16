<?php

namespace App\Console\Commands;

use App\Models\VideoProject;
use App\Services\Video\MotoCharacterReferenceService;
use Illuminate\Console\Command;
use RuntimeException;

class VideoMotoCharacterMaster extends Command
{
    protected $signature = 'video:moto-character-master
        {project : Video project id}
        {--choose=* : CHARACTER=IMAGE_CODE, the approved master to use for who the character is when several hold its identity cells; CHARACTER= drops the choice}
        {--stamp : Give every valid choice of an identity cell stored before identities were kept the identity its cell has now, so a later change to how a look is shown does not take the master out of use}
        {--apply : Store what the preview shows}';

    protected $description = 'Preview (or with --apply store) which approved master gives who each character of a moto project is. Lists, per character, the approved masters that hold its angle cells and portrait and what a shot would take now. Nothing is rendered, no approval changes and no model is called.';

    public function __construct(private readonly MotoCharacterReferenceService $characters)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $projectId = (string) $this->argument('project');

        if (VideoProject::query()->find($projectId) === null) {
            $this->error('Project not found.');

            return self::FAILURE;
        }

        try {
            $report = $this->characters->identityReport($projectId);
        } catch (RuntimeException $e) {
            $this->error('The approved shot plan cannot be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $choose = [];

        foreach ((array) $this->option('choose') as $entry) {
            [$character, $code] = array_pad(explode('=', (string) $entry, 2), 2, '');

            if (! isset($report[$character])) {
                $this->error("{$entry}: the approved script has no character {$character}.");

                return self::FAILURE;
            }

            if ($code === '') {
                $choose[$character] = null;

                continue;
            }

            $master = collect($report[$character]['masters'])->firstWhere('code', $code);

            if ($master === null) {
                $this->error("{$entry}: {$code} is not an approved master of this project that holds an identity cell of {$character}.");

                return self::FAILURE;
            }

            $choose[$character] = (string) $master['artifact_id'];
        }

        $apply = (bool) $this->option('apply');
        $stamp = (bool) $this->option('stamp');
        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $unstamped = 0;

        foreach ($report as $character => $entry) {
            $this->line("{$character}: ".($entry['masters'] === [] ? 'no approved master holds an identity cell' : count($entry['masters']).' master(s)').($entry['chosen'] === null ? '' : ' · chosen '.$entry['chosen']));

            foreach ($entry['masters'] as $master) {
                $unstamped += count($master['unstamped']);
                $this->line("  {$master['code']} — cells ".implode(', ', $master['cells']).($master['unstamped'] === [] ? '' : ' · '.count($master['unstamped']).' without a stored identity'));
            }

            if (array_key_exists($character, $choose)) {
                $this->line('  → '.($choose[$character] === null ? 'the choice is dropped' : 'will use '.$choose[$character]));
            } elseif ($entry['problem'] !== null) {
                $this->line('  ! '.$entry['problem']);
            }
        }

        $this->line('Identity cells without a stored identity: '.$unstamped.($stamp ? ' — will be stamped' : ' — run with --stamp to keep them valid through later changes to the looks'));

        if (! $apply) {
            $this->info('Run again with --apply to store it with the project.');

            return self::SUCCESS;
        }

        try {
            $this->characters->saveIdentity($projectId, $choose, $stamp);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Stored.');

        return self::SUCCESS;
    }
}
