<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoArtifact;
use App\Models\VideoReviewDecision;
use App\Models\VideoSocialChannel;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use App\Video\Social\SocialPackageDocument;
use App\Video\Social\SocialPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Approving a package revision, and taking an approval back. An approval is of one revision by its hash: the film, the
 * final text, the cover, the target, the format, the language, the disclosure choices and the metadata of a posting.
 * It is a decision of a person recorded by the server, and it posts nothing.
 *
 * Taking an approval back stops what has not been sent. It does not claim anything about what a platform already
 * holds: a posting that is out stays out, a posting whose fate is unknown stays unknown until it is looked up.
 */
final class SocialApprovalService
{
    public const ENTITY_TYPE = 'social_package';

    public function __construct(
        private readonly SocialSourceService $sources,
        private readonly SocialPackageService $packages,
    ) {}

    /**
     * @return array{0: VideoReviewDecision, 1: string} the decision and approved|replayed
     *
     * @throws RuntimeException when the revision is not the one the page showed, is not a draft, is about another film than the one approved now, was written under another platform policy, or carries something the platform would refuse
     */
    public function approve(string $projectId, string $packageId, string $expectedHash, string $operationId, ?string $reviewerId): array
    {
        $package = $this->packages->owned($projectId, $packageId);
        // The film and the cover are hashed before any lock is taken.
        $current = $this->sources->read($projectId)['snapshot']['hash'];
        $cover = self::coverProblem($package);

        return DB::transaction(function () use ($projectId, $packageId, $expectedHash, $operationId, $reviewerId, $current, $cover): array {
            $replayed = VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $packageId)->where('metadata_json->operation_id', $operationId)->first();

            if ($replayed !== null) {
                return [$replayed, 'replayed'];
            }

            // The revisions of the package are held in the order an edit holds them — newest first — and the one approved is
            // taken from among them: an approval and a save of one package wait for each other, never hold each other's rows the other way round.
            $group = VideoSocialPackage::query()->whereKey($packageId)->where('project_id', $projectId)->value('package_group_id') ?? throw (new ModelNotFoundException)->setModel(VideoSocialPackage::class, [$packageId]);
            $package = VideoSocialPackage::query()->where('package_group_id', $group)->lockForUpdate()->orderByDesc('revision')->get()->firstWhere('id', $packageId)
                ?? throw (new ModelNotFoundException)->setModel(VideoSocialPackage::class, [$packageId]);
            $contract = $this->sources->policy($projectId);
            $this->sources->hold($projectId, $contract);
            $problems = $this->problems($package, $expectedHash, $current, $contract['contract'], $cover);
            // The file was hashed before this transaction; that the project still hands on that very film is proved here, under a lock on what selects it.
            $moved = $problems === [] ? $this->sources->selected($projectId, (array) $package->source_json['pins']) : null;

            if ($moved !== null) {
                $problems[] = 'Phim đã duyệt của dự án vừa đổi trong lúc duyệt ('.$moved.') — không duyệt. Bấm "Cập nhật theo nguồn hiện hành" để tạo revision cho phim hiện hành rồi duyệt bản đó.';
            }

            if ($problems !== []) {
                throw new RuntimeException(implode(' ', $problems));
            }

            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $package->id,
                'decision' => 'approved',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $package->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => 'Duyệt gói nội dung để xuất và đăng.',
                'metadata_json' => [
                    'operation_id' => $operationId,
                    'package_group_id' => (string) $package->package_group_id,
                    'package_revision' => (int) $package->revision,
                    'package_hash' => (string) $package->package_hash,
                    'source_hash' => (string) $package->source_hash,
                    'final_id' => (string) $package->final_id,
                    'artifact_sha256' => (string) $package->source_json['pins']['artifact_sha256'],
                    'channel_id' => (string) $package->channel_id,
                    'format' => (string) $package->format,
                    'language' => (string) $package->language,
                    'policy' => $contract['contract'],
                    'cover_sha256' => $package->document_json['artwork']['sha256'] ?? null,
                ],
            ]);
            $package->forceFill(['status' => VideoSocialPackage::APPROVED, 'approved_at' => now()])->save();
            VideoSocialEvent::record($projectId, 'package_approved', (string) $package->id, null, $reviewerId, ['decision_id' => (string) $decision->id, 'package_hash' => (string) $package->package_hash]);

            return [$decision, 'approved'];
        }, attempts: 3);
    }

    /**
     * Takes the approval of a revision back, then deals with its postings one by one.
     *
     * @return array{0: VideoReviewDecision, 1: list<string>} the decision, and what became of each posting of the revision — said as it is, never as cancelled before a cancellation is confirmed
     *
     * @throws RuntimeException when the revision is not approved
     */
    public function revoke(string $projectId, string $packageId, string $reason, string $operationId, ?string $reviewerId): array
    {
        $decision = DB::transaction(function () use ($projectId, $packageId, $reason, $operationId, $reviewerId): VideoReviewDecision {
            $replayed = VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $packageId)->where('metadata_json->operation_id', $operationId)->first();

            if ($replayed !== null) {
                return $replayed;
            }

            $package = VideoSocialPackage::query()->whereKey($packageId)->where('project_id', $projectId)->lockForUpdate()->firstOrFail();

            if ($package->status !== VideoSocialPackage::APPROVED) {
                throw new RuntimeException('Bản này không ở trạng thái đã duyệt — không có gì để thu hồi.');
            }

            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $package->id,
                'decision' => 'revoked',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $package->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => trim($reason) === '' ? 'Thu hồi duyệt gói.' : trim($reason),
                'metadata_json' => ['operation_id' => $operationId, 'package_hash' => (string) $package->package_hash, 'package_group_id' => (string) $package->package_group_id, 'package_revision' => (int) $package->revision],
            ]);
            $package->forceFill(['status' => VideoSocialPackage::REVOKED, 'revoked_at' => now()])->save();
            VideoSocialEvent::record($projectId, 'package_revoked', (string) $package->id, null, $reviewerId, ['decision_id' => (string) $decision->id, 'reason' => $decision->reason]);

            return $decision;
        }, attempts: 3);

        return [$decision, app(SocialPublicationService::class)->afterRevoke($projectId, $packageId, $reviewerId)];
    }

    /**
     * @return ?VideoReviewDecision the decision a revision is approved by: the newest one of the revision, when it approves that very hash
     */
    public static function decisionOf(VideoSocialPackage $package): ?VideoReviewDecision
    {
        $decision = VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $package->id)->orderByDesc('revision')->first();

        return $decision !== null && $decision->decision === 'approved' && hash_equals((string) $package->package_hash, (string) ($decision->metadata_json['package_hash'] ?? '')) ? $decision : null;
    }

    /**
     * @return bool whether a revision may be exported and posted now: its status says approved and a decision approves that very hash
     */
    public static function approved(VideoSocialPackage $package): bool
    {
        return $package->status === VideoSocialPackage::APPROVED && self::decisionOf($package) !== null;
    }

    /**
     * @param  ?string  $current  the source hash of the film approved now
     * @param  array{phase_id: string, phase_revision: int, policy_hash: string}  $policy  the platform policy in force
     * @return list<string> everything that holds the approval of a revision back, each said plainly; empty when it may be approved
     */
    public function problems(VideoSocialPackage $package, string $expectedHash, ?string $current, array $policy, ?string $cover): array
    {
        if (! hash_equals((string) $package->package_hash, $expectedHash)) {
            return ['Gói trên trang không phải bản này — tải lại trang.'];
        }

        if ($package->status !== VideoSocialPackage::DRAFT) {
            return [$package->status === VideoSocialPackage::APPROVED ? 'Bản này đã được duyệt.' : 'Bản này đã bị thu hồi duyệt — sửa để tạo revision mới rồi duyệt bản mới.'];
        }

        $newest = (int) VideoSocialPackage::query()->where('package_group_id', $package->package_group_id)->max('revision');

        if ((int) $package->revision !== $newest) {
            return ['Bản này (R'.$package->revision.') không phải revision mới nhất của gói — gói đã có R'.$newest.'. Chỉ duyệt bản mới nhất; muốn dùng nội dung bản này thì chép sang bản mới nhất rồi lưu.'];
        }

        $source = (array) $package->source_json;
        $document = (array) $package->document_json;
        $rules = (array) $source['policy']['rules'];
        $problems = [];

        if (! hash_equals(SocialPackageDocument::hash($document), (string) $package->package_hash)) {
            $problems[] = 'Nội dung lưu của bản này không còn khớp hash của nó — không duyệt.';
        }

        if ($current === null || ! hash_equals((string) $package->source_hash, $current)) {
            $problems[] = 'Phim đã duyệt của dự án không còn là phim gói này viết cho — bấm "Cập nhật theo nguồn hiện hành" để tạo revision cho phim mới rồi duyệt bản đó.';
        }

        if (! hash_equals((string) ($source['policy']['policy_hash'] ?? ''), (string) $policy['policy_hash']) || (string) ($source['policy']['phase_id'] ?? '') !== (string) $policy['phase_id']) {
            $problems[] = 'Chính sách nền tảng đã có revision mới so với lúc viết gói — bấm "Cập nhật theo nguồn hiện hành" rồi duyệt bản mới.';
        }

        $channel = VideoSocialChannel::query()->find($package->channel_id);

        if ($channel === null || $channel->status !== VideoSocialChannel::ACTIVE) {
            $problems[] = 'Hồ sơ kênh của gói không còn dùng.';
        } elseif (($target = SocialChannelService::targetProblem((array) $source['channel'], $channel)) !== null) {
            // An approval is of the page or channel the revision names; a revision that names another than the profile does now is moved to the profile first.
            $problems[] = $target.' — không duyệt. '.SocialChannelService::RETARGET;
        }

        if ($cover !== null) {
            $problems[] = 'Cover của gói: '.$cover.' — dựng lại cover.';
        }

        return [
            ...$problems,
            ...SocialPolicy::mediaProblems($rules, (array) $source['film']['media']),
            ...SocialPolicy::textProblems($rules, (array) $document['rendered'], (array) $document['content']['hashtags']),
        ];
    }

    /** @return ?string why the cover of a revision cannot be approved; null when it has none or its file is the one that was made */
    public static function coverProblem(VideoSocialPackage $package): ?string
    {
        $cover = $package->document_json['artwork'] ?? null;

        if (! is_array($cover)) {
            return null;
        }

        $artifact = VideoArtifact::query()->whereKey($cover['artifact_id'])->where('project_id', $package->project_id)->first();

        return match (true) {
            $artifact === null => 'không còn bản ghi file',
            ! hash_equals((string) $cover['sha256'], (string) $artifact->sha256) => 'bản ghi file không khớp hash của gói',
            default => SocialArtworkService::problem($artifact),
        };
    }
}
