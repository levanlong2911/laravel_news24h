<?php

namespace App\Video\FinalComposition;

/**
 * Nhung con so ma `CompositionOutputVerifier` can, tach khoi cho sinh ra chung.
 *
 * `CompositionPlan` chi dung ra duoc khi CON NGUON de probe. Doi phuc hoi chay o
 * mot request khac, co the sau khi clip nguon da bi don, nhung van phai do output
 * bang dung phep do cua luot chay that.
 *
 * Tach ra lam mot gia tri rieng chu khong chep lai phep tinh: hai ban sao cua cung
 * mot cong thuc khung hinh la hai cach de lech nhau ma khong ai biet.
 */
final class CompositionExpectation
{
    public function __construct(
        public readonly CompositionProfile $profile,
        public readonly int $frames,
        public readonly int $audioSamples,
    ) {}
}
