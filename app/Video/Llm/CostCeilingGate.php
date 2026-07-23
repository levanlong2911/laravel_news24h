<?php

namespace App\Video\Llm;

/**
 * Cho phep neu chi phi UOC LUONG cua CU GOI DO khong vuot tran — an toan hon
 * "allow tat ca": van chan duoc ca bai qua dai/loop bat thuong tao 1 cu goi
 * cuc dat, nhung khong con tu choi moi thu nhu DenyByDefaultGate.
 *
 * KHONG phai mac dinh he thong — phai tiem vao tuong minh (vd
 * VideoSessionService) moi co hieu luc, dung tinh than "tieu tien la hanh
 * dong co chu y" cua GatedLlmClient.
 */
final class CostCeilingGate implements ApprovalGate
{
    public function __construct(
        private readonly float $maxCostUsdPerCall = 0.05,
    ) {
    }

    public function allows(LlmRequest $request, float $estimatedCostUsd): bool
    {
        return $estimatedCostUsd <= $this->maxCostUsdPerCall;
    }
}
