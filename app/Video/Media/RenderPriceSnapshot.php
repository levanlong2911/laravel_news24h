<?php

namespace App\Video\Media;

/**
 * Gan snapshot gia vao tung render item, MOT CHO DUY NHAT, ngay sau khi client tra
 * ve: client chi biet bytes, request id va usage cua provider — no khong biet gi ve
 * gia, va khong duoc phep tu dat tien.
 *
 * `cost` khong bao gio bi dung toi o day: cot do chi mang tien da xac nhan.
 */
final class RenderPriceSnapshot
{
    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $renders
     * @return list<array<string, mixed>>
     */
    public function applyTo(array $spec, array $renders): array
    {
        return array_map(static function (array $item) use ($spec): array {
            // Client biet mot su that spec khong biet: lan nay khong co dong nao chay.
            if (($item['pricing'] ?? null) === 'free') {
                return array_replace($item, [
                    'pricing' => 'free',
                    'unit_cost_usd' => null,
                    'estimated_cost_usd' => null,
                    'pricing_version' => null,
                ]);
            }

            // Provider da bao tien that thi khong duoc ha xuong thanh uoc tinh.
            if (in_array($item['pricing'] ?? null, ['reported', 'reconciled'], true)) {
                return $item;
            }

            // Dung array_replace chu khong phai `+`: client da tu dat `pricing` cho
            // rieng no, va `+` se giu gia tri cu — Gemini se ket o `unpriced` du
            // registry da co gia.
            return array_replace($item, [
                'pricing' => $spec['pricing'] ?? 'estimated',
                'unit_cost_usd' => $spec['unit_cost_usd'] ?? null,
                'estimated_cost_usd' => $spec['cost_estimate'] ?? null,
                'pricing_version' => ($spec['pricing_version'] ?? '') ?: null,
                // Dong so cai tu noi duoc no sinh ra tu mot o duoc bo sung gia muon.
                'pricing_backfilled_at' => ($spec['pricing_backfilled_at'] ?? '') ?: null,
            ]);
        }, $renders);
    }
}
