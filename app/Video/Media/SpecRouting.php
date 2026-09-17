<?php

namespace App\Video\Media;

use App\Services\Video\DesignImageStore;
use App\Video\Environment\EnvironmentPlatePrompt;
use InvalidArgumentException;

/**
 * Doc `provider`, `operation`, `task` tu mot spec THO — mot quy tac, hai noi goi.
 *
 * `RenderPriceBackfill` chay trong luc claim, tuc la TRUOC khi renderer chuan hoa
 * spec; con `DesignImageDirectRenderer::spec()` chuan hoa. Hai ben tu suy lay se
 * lech nhau dung o cho kho thay nhat: mot ben tra gia theo bang nay, ben kia gui
 * request theo bang khac.
 *
 * `GeminiImageClient` KHONG dung lop nay. No luon nhan spec da chuan hoa, nen no
 * chi doc `task` co san. Cho no suy lai la mo duong cho mot task sai bi loai o
 * buoc truoc song lai thanh mot task dung o buoc sau.
 */
final class SpecRouting
{
    /**
     * Mac dinh CHI khi truong vang mat hoac `null` — dung ngu nghia cua `??` ma
     * normalizer van dung.
     *
     * Chuoi rong va sai kieu thi NEM, khong mac dinh: `provider => ''` dang bi
     * `DesignImageDirectRenderer` tu choi, va bien no thanh `openai` la bien mot
     * spec dang bi chan thanh mot request that.
     *
     * @param  array<string, mixed>  $spec
     *
     * @throws InvalidArgumentException
     */
    public static function provider(array $spec): string
    {
        $value = $spec['provider'] ?? 'openai';

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('spec: `provider` khong hop le');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $spec
     *
     * @throws InvalidArgumentException
     */
    public static function operation(array $spec): string
    {
        $value = $spec['operation'] ?? 'generate';

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('spec: `operation` khong hop le');
        }

        return $value;
    }

    /**
     * Task de tra registry, hoac `null` khi duong nay khong tra registry.
     *
     * Ba truong hop, KHAC NHAU:
     *
     *   - vang mat / `null`  : du lieu cu (76/76 o hien co deu vay) — suy tu operation
     *   - da khai ma lech    : NEM. Tra `null` o day la de dau vet bi xoa, roi noi
     *                          khac suy lai thanh mot task dung — chinh la cai bay
     *                          nay dinh go. Hai task dung chung ca bon model, nen
     *                          mot task lech van tra trung mot entry CO THAT o dung
     *                          cho SAI, va phep kiem model khong thay gi bat thuong.
     *   - operation khac     : `null` — `edit`/`mirror`/`scene_keyframe` cua OpenAI
     *                          khong co ly do gi phai khai task.
     *
     * `null` KHONG co nghia la OpenAI. Nguoi goi tu quyet: OpenAI bo qua, Gemini tu
     * choi truoc HTTP. Khong bao gio dua `null` vao `MediaModelRegistry::find()` —
     * tham so do khong nullable nen se nem `TypeError`, ma `TypeError` khong bi
     * `catch (InvalidArgumentException)` bat.
     *
     * @param  array<string, mixed>  $spec
     *
     * @throws InvalidArgumentException
     */
    public static function task(array $spec): ?string
    {
        $derived = match (self::operation($spec)) {
            'environment_plate' => EnvironmentPlatePrompt::TASK,
            'generate' => DesignImageStore::ANCHOR_TYPE,
            default => null,
        };

        if (! array_key_exists('task', $spec) || $spec['task'] === null) {
            return $derived;
        }

        if ($spec['task'] !== $derived) {
            throw new InvalidArgumentException('spec: `task` khong khop `operation`');
        }

        return $derived;
    }
}
