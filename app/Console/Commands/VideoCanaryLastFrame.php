<?php

namespace App\Console\Commands;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Services\Video\DesignImageStore;
use App\Services\VideoProjectService;
use App\Video\Media\VideoModelRegistry;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Render\VideoProviderCheckpointService;
use Illuminate\Console\Command;
use Throwable;


class VideoCanaryLastFrame extends Command
{
    protected $signature = 'video:canary-last-frame
        {--scene= : ID cua scene se render clip (video_render_scenes.id)}
        {--model= : Model clip; bo trong thi dung mac dinh cua registry}
        {--duration= : Thoi luong giay; bo trong thi dung mac dinh cua registry}
        {--end-artifact= : ID anh cuoi MONG DOI — chi de doi chieu, khac resolver la dung}
        {--force : Bo qua xac nhan tuong tac}';

    protected $description = 'Gui MOT request Veo co lastFrame de xac thuc hop dong, tach khoi clip production';

    public function handle(
        SceneClipDispatchService $clips,
        SceneShotFactory $shots,
        VideoModelRegistry $registry,
        VideoRenderExecutionService $execution,
        VideoProjectService $projects,
        VideoProviderCheckpointService $provider,
    ): int {
        $sceneId = (string) $this->option('scene');

        if ($sceneId === '') {
            $this->error('Thieu --scene=');

            return self::FAILURE;
        }

        $scene = VideoRenderScene::query()->whereKey($sceneId)->first();

        if ($scene === null) {
            $this->error('Khong thay scene: '.$sceneId);

            return self::FAILURE;
        }

        $source = $this->approvedArtifact($scene);

        if ($source === null) {
            $this->error('Scene nay chua co anh duyet — khong co anh dau de gui.');

            return self::FAILURE;
        }

        $modelId = (string) ($this->option('model') ?: $this->defaultModel($registry));

        if ($modelId === '') {
            $this->error('Registry khong co model clip nao.');

            return self::FAILURE;
        }

        $controls = [];

        if ($this->option('duration') !== null && (string) $this->option('duration') !== '') {
            $controls['duration_seconds'] = (int) $this->option('duration');
        }

        $row = $this->planRow($projects, $scene);

        if ($row === null) {
            $this->error('Scene nay khong nam trong ban ke hoach dang hien cua du an.');

            return self::FAILURE;
        }

        try {
            $shot = $shots->forScene($scene, $row);
            $preflight = $clips->preflight($shot, $source, $modelId, $controls);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $request = $preflight['request'];
        $end = $request['end_frame'] ?? null;
        $expected = (string) $this->option('end-artifact');

        if ($expected !== '' && $expected !== (string) ($end['artifact_id'] ?? '')) {
            $this->error(sprintf(
                'Anh cuoi resolver chon (%s) khac cai ban mong doi (%s) — CHUA tao hang nao.',
                (string) ($end['artifact_id'] ?? 'khong co'),
                $expected,
            ));

            return self::FAILURE;
        }

        $this->preview($scene, $request);

        if (! $this->option('force') && ! $this->confirm('Gui luot canary nay?')) {
            $this->warn('Da huy — chua tao hang nao.');

            return self::FAILURE;
        }

        try {
            $render = $clips->createCanary($shot, $source, $modelId, $controls, $preflight['request_hash']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Da tao hang canary: '.$render->id);

        [$ok, $reason] = $execution->submit($render->id);

        $ok ? $this->info($reason) : $this->warn($reason);

        $this->newLine();
        $this->whatNext($provider, $render->fresh(), $reason);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function whatNext(VideoProviderCheckpointService $provider, ?VideoRender $render, string $reason): void
    {
        // KHONG doc `$render->provider_job_id` truc tiep: cot do chi co khi CAS chinh
        // thanh cong. `ownership_lost_after_submit` la THAT BAI nhung provider DA nhan
        // job — luc do job nam tren attempt va tren bien lai, va day dung la luc phai
        // poll nhat vi tien da di.
        $jobId = $render === null ? '' : (string) $provider->knownJobId($render);

        if ($jobId !== '') {
            $this->line('Provider da nhan job: '.$jobId);
            $this->line('Theo doi tiep:  php artisan video:poll-provider '.$render->id);
            $this->line('Bang chung: render_request_json / provider_submit_response_json cua hang tren.');
            $this->line('Ghi file evidence trong resources/ai/providers la viec TAY, sau khi ban xem ket qua.');

            return;
        }

        if ($reason === 'provider_unknown') {
            $this->error('Mat ket noi giua chung — KHONG biet provider co nhan job hay khong.');
            $this->warn('Dung gui lai canh nay. Doi soat o Google truoc; gui lai co the la tra tien lan hai.');

            return;
        }

        $this->warn('Khong co job nao ben provider — poll se khong co gi de hoi.');
        $this->line('Sua nguyen nhan o tren roi chay lai lenh nay.');
    }

    /**
     * Moi thu sap roi khoi may, in ra TRUOC khi hoi.
     *
     * @param  array<string, mixed>  $request
     */
    private function preview(VideoRenderScene $scene, array $request): void
    {
        $start = $request['source_artifact'];
        $end = $request['end_frame'] ?? null;

        $this->newLine();
        $this->line('Scene      : '.(string) ($scene->title ?: $scene->scene_code).'  ['.(string) $scene->scene_code.']');
        $this->line('Model      : '.(string) $request['model'].'  ('.(string) $request['api_version'].')');
        $this->line('Contract   : '.(string) $request['provider_payload_version']);
        $this->line('Thoi luong : '.(int) $request['duration_seconds'].'s   Ti le: '
            .(string) $request['aspect_ratio'].'   Do phan giai: '.(string) $request['resolution']);
        $this->line('personGeneration: '.(string) $request['person_generation']);

        $this->newLine();
        $this->line('Anh dau  : '.$this->frameLine($start));
        $this->line('Anh cuoi : '.($end === null ? 'KHONG CO' : $this->frameLine($end)));

        $this->newLine();
        $this->line('Prompt gui di:');
        $this->line('  '.(string) $request['compiled_prompt']['prompt']);

        $this->newLine();
        // Veo khong tra gia trong response, nen khong co so nao de uoc tinh. Bia ra mot
        // con so o day con te hon la khong in gi.
        $this->warn('Veo khong tra gia — khong uoc tinh duoc chi phi. Luot nay VAN tinh tien tren tai khoan Google.');
        $this->warn('Va no gui mot payload (`lastFrame`) chua tung duoc provider xac nhan.');
    }

    /** @param array<string, mixed> $frame */
    private function frameLine(array $frame): string
    {
        return sprintf(
            '%s  scene=%s  %dx%d  %s  %d bytes',
            (string) $frame['artifact_id'],
            (string) ($frame['scene_id'] ?? '-'),
            (int) $frame['width'],
            (int) $frame['height'],
            (string) $frame['mime'],
            (int) $frame['bytes'],
        );
    }

    private function approvedArtifact(VideoRenderScene $scene): ?VideoArtifact
    {
        $keyframe = VideoDesignImage::query()
            ->where('render_scene_id', $scene->id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->first();

        if ($keyframe === null || $keyframe->selected_artifact_id === null) {
            return null;
        }

        return VideoArtifact::query()
            ->whereKey($keyframe->selected_artifact_id)
            ->where('design_image_id', $keyframe->id)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function planRow(VideoProjectService $projects, VideoRenderScene $scene): ?array
    {
        $plan = $projects->latestScenePlan((string) $scene->project_id);

        foreach ((array) ($plan['scenes'] ?? []) as $row) {
            if ((string) ($row['scene_id'] ?? '') === (string) $scene->id) {
                return $row;
            }
        }

        return null;
    }

    private function defaultModel(VideoModelRegistry $registry): string
    {
        foreach ($registry->forTask(SceneClipDispatchService::TASK) as $entry) {
            if (($entry['default'] ?? false) === true) {
                return (string) $entry['id'];
            }
        }

        return '';
    }
}
