<?php

declare(strict_types=1);

namespace App\Video\ModelSheet;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Kiem CAU TRUC phan hoi QA truoc moi ep kieu: doc JSON nghiem ngat (giu phan biet object/array),
 * root phai la object, roi kiem toan bo ModelSheetQaValidator::SCHEMA bang Opis (kieu, truong bat
 * buoc, kieu phan tu, enum, thuoc tinh ngoai schema, long nhau). Khong them/bo truong, khong ep
 * kieu de "cuu" du lieu. Chi khi dat moi chuyen sang mang cho kiem nghiep vu.
 */
final class ModelSheetQaSchema
{
    public const VERSION = 'opis-2.3-draft-2020-12-v1';

    private const MAX_ERRORS = 200;

    public static function sha256(): string
    {
        return hash('sha256', (string) json_encode(ModelSheetQaValidator::SCHEMA, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Doc phan hoi: dung mot JSON object (chap nhan nguyen ca phan hoi la MOT khoi ```json duy nhat).
     *
     * @return array{0: mixed, 1: ?string} [du lieu giai ma dang object, loi doc]
     */
    public static function decode(string $raw): array
    {
        $text = trim($raw);

        if (preg_match('/\A```(?:json)?[ \t]*\r?\n(.*)\r?\n```\z/s', $text, $fenced) === 1) {
            $text = trim($fenced[1]);
        }

        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [null, 'Phản hồi không phải JSON hợp lệ: '.$e->getMessage().'.'];
        }

        return $data instanceof \stdClass ? [$data, null] : [null, 'Gốc phản hồi phải là một JSON object, nhận '.get_debug_type($data).'.'];
    }

    /**
     * @return list<string> loi theo duong dan truong (rong = dat)
     */
    public static function errors(\stdClass $data): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(self::MAX_ERRORS);
        $schema = json_decode((string) json_encode(ModelSheetQaValidator::SCHEMA, JSON_UNESCAPED_SLASHES), false);
        $result = $validator->validate($data, $schema);

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->format($result->error(), true) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = self::field((string) $pointer).': '.$message;
            }
        }

        return $errors;
    }

    /** /coverage/0/where -> coverage[0].where */
    private static function field(string $pointer): string
    {
        $out = '';

        foreach (array_filter(explode('/', $pointer), static fn (string $s): bool => $s !== '') as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $out .= ctype_digit($segment) ? "[{$segment}]" : ($out === '' ? $segment : ".{$segment}");
        }

        return $out === '' ? '(gốc)' : $out;
    }
}
