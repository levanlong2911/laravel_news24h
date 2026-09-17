<?php

namespace App\Video\FinalComposition;

use RuntimeException;

/**
 * Ban ke hoach khong dung duoc, va ly do noi duoc thanh loi.
 *
 * Nem chu khong tra `null`: mot ke hoach ghep sai khong co gia tri mac dinh nao
 * hop ly de di tiep, va bat nguoi goi doan xem `null` nghia la gi la cach nhanh
 * nhat de mot loi dau vao bien thanh mot file ra sai.
 */
final class CompositionRefused extends RuntimeException {}
