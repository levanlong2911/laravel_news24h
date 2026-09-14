<?php

namespace App\Video\Media;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Video\Environment\EnvironmentPlatePrompt;
use InvalidArgumentException;

/**
 * O tao truoc khi co hop dong gia khong mang snapshot nao. Thay vi chan han chung,
 * ta bo sung gia CUA HOM NAY ngay tai diem claim — truoc khi bat ky request nao di,
 * va truoc khi worker nhin thay spec.
 *
 * Ba luat khong duoc pha:
 *   1. Chi dung toi `estimated` va o thieu han `pricing`. `free`, `unpriced`,
 *      `reported`, `reconciled` deu la ket luan cua nguoi khac — khong duoc dich lai.
 *   2. Moi ket qua thanh cong deu phai con giu lease. Mot o da thuoc worker khac thi
 *      ke ca truong hop "khong can lam gi" cung khong duoc bao la thanh cong.
 *   3. Ghi bang compare-and-swap theo claim, khong bao gio bang save() tran.
 */
final class RenderPriceBackfill
{
    /** @var list<string> */
    private const UNTOUCHABLE = ['free', 'unpriced', 'reported', 'reconciled'];

    public function __construct(
        private readonly ImagePriceResolver $prices = new ImagePriceResolver,
        private readonly ?MediaModelRegistry $registry = null,
    ) {}

    /**
     * @return array{0: bool, 1: string} [$ok, $reason]
     *                                   reason: not_needed|already_frozen|backfilled
     *                                   |pricing_unavailable|pricing_state_unknown
     *                                   |claim_lost_before_pricing
     */
    public function apply(VideoDesignImage $image, string $workerId, string $claimToken): array
    {
        $spec = $image->prompt_spec_json ?? [];
        $pricing = $spec['pricing'] ?? null;

        if (is_string($pricing) && in_array($pricing, self::UNTOUCHABLE, true)) {
            return $this->owns($image, $workerId, $claimToken)
                ? [true, 'not_needed']
                : [false, 'claim_lost_before_pricing'];
        }

        if ($pricing !== null && $pricing !== 'estimated') {
            return [false, 'pricing_state_unknown'];
        }

        // Provider khong co client thi khong phai chuyen cua gia: noi dung ten van de.
        if (! in_array((string) ($spec['provider'] ?? 'openai'), ['openai', 'gemini'], true)) {
            return [false, 'provider_has_no_client'];
        }

        if ($this->frozen($spec)) {
            return $this->owns($image, $workerId, $claimToken)
                ? [true, 'already_frozen']
                : [false, 'claim_lost_before_pricing'];
        }

        $price = $this->prices->forSpec($spec, $this->entryFor($spec));

        if ($price === null) {
            return [false, 'pricing_unavailable'];
        }

        // Snapshot cua LUOT BACKFILL, khong phai cua luc tao o: no de len ca snapshot
        // do dang truoc do, va `pricing_backfilled_at` la thu duy nhat phan biet.
        $spec['pricing'] = 'estimated';
        $spec['unit_cost_usd'] = $price['usd'];
        $spec['pricing_version'] = $price['version'];
        $spec['pricing_backfilled_at'] = now()->toIso8601String();

        $written = $this->claimed($image, $workerId, $claimToken)
            ->update(['prompt_spec_json' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        if ($written !== 1) {
            return [false, 'claim_lost_before_pricing'];
        }

        $image->setAttribute('prompt_spec_json', $spec);

        return [true, 'backfilled'];
    }

    /** @param array<string, mixed> $spec */
    private function frozen(array $spec): bool
    {
        $unit = $spec['unit_cost_usd'] ?? null;
        $version = $spec['pricing_version'] ?? null;

        return (is_int($unit) || is_float($unit))
            && (float) $unit > 0
            && is_finite((float) $unit)
            && is_string($version)
            && trim($version) !== '';
    }

    /** @param array<string, mixed> $spec */
    private function entryFor(array $spec): ?array
    {
        if ((string) ($spec['provider'] ?? 'openai') !== 'gemini') {
            return null;
        }

        try {
            return ($this->registry ?? new MediaModelRegistry)->find(
                EnvironmentPlatePrompt::TASK, 'gemini:'.(string) ($spec['model'] ?? ''),
            );
        } catch (InvalidArgumentException) {
            // Registry hong thi coi nhu khong co gia: chot phia tren se chan truoc HTTP.
            return null;
        }
    }

    private function owns(VideoDesignImage $image, string $workerId, string $claimToken): bool
    {
        return $this->claimed($image, $workerId, $claimToken)->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<VideoDesignImage> */
    private function claimed(VideoDesignImage $image, string $workerId, string $claimToken)
    {
        return VideoDesignImage::query()
            ->whereKey($image->id)
            ->where('worker_id', $workerId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->where('lease_expires_at', '>', now());
    }
}
