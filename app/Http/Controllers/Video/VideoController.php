<?php

namespace App\Http\Controllers\Video;

use App\Enums\VideoPlanningStageStatus;
use App\Form\AdminCustomValidator;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseV63Project;
use App\Http\Middleware\RefuseWithoutShotPlan;
use App\Models\Admin;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\ShotSourceService;
use App\Services\VideoProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

abstract class VideoController extends Controller
{
    protected const CANCELLED = 'Đã huỷ lượt đang chờ trong hàng đợi — job sẽ bỏ qua lượt này, không gọi model.';

    protected const SHOT_PLAN_APPROVED = 'Đã duyệt kế hoạch shot thành công — kế hoạch đạt kiểm nên được duyệt tự động.';

    protected VideoProjectService $videoProjectService;

    protected AdminCustomValidator $form;

    public function __construct(VideoProjectService $videoProjectService, AdminCustomValidator $form)
    {
        $this->videoProjectService = $videoProjectService;
        $this->form = $form;
    }

    /** @return void 404 when the project does not exist, 403 when the signed-in admin may not work on it; only its id and owner are read */
    protected function ownedProject(string $id): void
    {
        $project = $this->videoProjectService->ownershipOf($id);

        abort_if($project === null, 404);

        $this->authorizeProject($project);
    }

    protected function authorizeProject(VideoProject $project): void
    {
        $actor = $this->actor();

        if ($actor === null || Gate::forUser($actor)->denies('update', $project)) {
            abort(403);
        }
    }

    protected function actor(): ?Admin
    {
        $user = auth()->user();

        return $user instanceof Admin ? $user : null;
    }

    protected function actorId(): ?string
    {
        $actor = auth()->id();

        return $actor === null ? null : (string) $actor;
    }

    /** @return ?array{id: string, name: string} */
    protected function requester(): ?array
    {
        $user = auth()->user();

        return $user === null ? null : ['id' => (string) $user->getAuthIdentifier(), 'name' => (string) ($user->name ?? '')];
    }

    /** @return array<string, string> */
    protected function chrome(): array
    {
        return [
            'route' => 'video-projects',
            'action' => 'admin-video-projects',
            'menu' => 'menu-open',
            'active' => 'active',
        ];
    }

    /** @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse a run taken into the queue: 202 for a script, the page with a notice for a form */
    protected function queued(Request $request, string $id, VideoPlanningStage $stage, string $label, string $page)
    {
        return $request->expectsJson()
            ? response()->json(['status' => (string) $stage->status, 'stage_id' => (string) $stage->id, 'status_url' => route('video-projects.stage-status', [$id, $stage->id])], 202)
            : redirect()->to($page)->with('success', "Đã đưa lượt {$label} vào hàng đợi — chạy nền trên server; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.");
    }

    /** @return bool whether the page script of the Reference screen sent the form (vpAjaxPage) and takes only the panel the action changed, with its notice, instead of the whole page again */
    protected function wantsReferencePart(Request $request): bool
    {
        return $request->header('X-Vp-Ajax') === '1';
    }

    /** @return bool whether the shot run is the shot plan the project uses now: a run that passed was approved by rule pass when it finished */
    protected function shotPlanChosen(string $id, VideoPlanningStage $stage): bool
    {
        return $stage->status === VideoPlanningStageStatus::SUCCEEDED->value
            && (app(ShotSourceService::class)->selection($id)['stage_id'] ?? null) === (string) $stage->id;
    }

    /**
     * @param  class-string  $check  RefuseWithoutShotPlan or RefuseV63Project, run on the request of this action
     * @param  ?string  $parameter  what the check is told: 'clips', 'scenes' or nothing
     * @return ?Response the answer of the check when it refuses the request, null when it lets it through
     */
    protected function refusedBy(string $check, ?string $parameter = null): ?Response
    {
        $pass = response('');
        $answer = app($check)->handle(request(), static fn (): Response => $pass, $parameter);

        return $answer === $pass ? null : $answer;
    }

    /** @return ?Response the answer when the clip and film steps refuse the project — no approved shot plan, or a project of the v6.3 flow whose workflow renders no clips by shot — null when they open */
    protected function refusedClipStep(): ?Response
    {
        return $this->refusedBy(RefuseWithoutShotPlan::class, 'clips') ?? $this->refusedBy(RefuseV63Project::class, 'clips');
    }

    protected function renderOutcome(string $reason, \App\Models\VideoDesignImage $image): string
    {
        return match ($reason) {
            'rendered' => __('messages.anchor_image_rendered', ['code' => $image->image_code]),
            'failed' => __('messages.anchor_image_failed', [
                'code' => $image->image_code,
                'reason' => (string) $image->render_error,
            ]),
            'not_enqueueable' => __('messages.anchor_render_not_enqueueable', ['code' => $image->image_code]),
            'already_exists' => __('messages.reference_already_exists', ['code' => $image->image_code]),
            default => $reason,
        };
    }

    protected function anchorMessage(string $reason): string
    {
        if (str_starts_with($reason, 'film_cut_screenplay_invalid|')) {
            return __('messages.film_cut_screenplay_invalid', ['detail' => substr($reason, strlen('film_cut_screenplay_invalid|'))]);
        }

        return match ($reason) {
            'project_not_found' => __('messages.project_not_found'),
            'image_not_found' => __('messages.anchor_image_not_found'),
            'choose_prompt_settings' => __('messages.anchor_choose_prompt_settings'),
            'anchor_prompt_missing' => __('messages.anchor_prompt_missing'),
            'character_prompt_no_screenplay' => 'Chưa có bản phân cảnh được duyệt và chọn cho production — duyệt, chọn bản phân cảnh rồi mới viết prompt cho từng nhân vật.',
            'character_prompt_unknown_character' => 'Nhân vật này không có trong bản phân cảnh production.',
            'character_not_main' => 'Chỉ nhân vật chính (protagonist) mới có anchor — nhân vật phụ được mô tả bằng chữ khi render. Không gọi model, không render, không duyệt.',
            'character_prompt_v3_profile_uses_design' => 'Tàu này lấy từ bản thiết kế v3 — anchor viết từ bản thiết kế, không qua đường viết prompt nhân vật cũ. Không gọi model.',
            'anchor_prompt_other_screenplay' => 'Prompt hoặc ảnh này thuộc một bản phân cảnh khác bản production đang chọn — viết lại prompt trước.',
            'screenplay_has_no_main_object' => 'Bản phân cảnh production không có nhân vật chính loại object để làm anchor chính.',
            'character_prompt_running' => 'Đang có một lượt viết prompt chạy cho dự án này.',
            'anchor_prompt_stale' => __('messages.anchor_prompt_stale'),
            'no_inspiration_brief' => __('messages.anchor_no_inspiration_brief'),
            'inspiration_phase1_not_supported' => 'Lượt Inspiration mới nhất của dự án chạy theo Phase 1 v6.3 (phản hồi chưa kiểm nội dung) — bước này chưa hỗ trợ kết quả đó và không dùng brief cũ. Không gọi model.',
            'artifact_not_found' => __('messages.anchor_artifact_not_found'),
            'not_approvable' => __('messages.anchor_not_approvable'),
            'no_approved_anchor' => __('messages.no_approved_anchor'),
            'no_scene_profile' => __('messages.scene_no_profile'),
            'identity_summary_unreadable' => __('messages.scene_identity_unreadable'),
            'storyboard_design_source_missing', 'storyboard_design_source_invalid', 'storyboard_design_revision_mismatch',
            'storyboard_design_identity_missing', 'storyboard_film_profile_invalid', 'storyboard_source_changed', 'storyboard_recheck_input_changed',
            'storyboard_nothing_to_recheck', 'storyboard_finding_reason_required', 'storyboard_finding_not_found',
            'storyboard_finding_not_acceptable', 'storyboard_finding_after_approval' => __('messages.'.$reason),
            'storyboard_running' => __('messages.storyboard_running'),
            'storyboard_unchanged' => __('messages.storyboard_unchanged'),
            'storyboard_failed' => __('messages.storyboard_failed'),
            'storyboard_failed_unrecorded' => __('messages.storyboard_failed_unrecorded'),
            'storyboard_claim_lost' => __('messages.storyboard_claim_lost'),
            'storyboard_needs_beats' => __('messages.storyboard_needs_beats'),
            'scene_plan_misconfigured' => __('messages.scene_plan_misconfigured'),
            'film_cut_misconfigured' => __('messages.film_cut_misconfigured'),
            'film_cut_design_missing' => __('messages.film_cut_design_missing'),
            'film_cut_screenplay_outdated' => __('messages.film_cut_screenplay_outdated'),
            'storyboard_mode_unknown' => __('messages.storyboard_mode_unknown'),
            'artifact_file_not_found' => __('messages.artifact_file_not_found'),
            'artifact_checksum_mismatch' => __('messages.artifact_checksum_mismatch'),
            'approved' => __('messages.reference_approved'),
            'image_type_mismatch' => __('messages.reference_wrong_image_type'),
            'scene_keyframe_needs_scene_flow' => __('messages.scene_keyframe_needs_scene_flow'),
            'location_reference_needs_locations_flow' => 'Ô này là ảnh nền Phase 5 dựng theo ảnh chuẩn chung của địa điểm — render lại từ màn Locations & Sets, nơi kế hoạch ảnh và ảnh nguồn được kiểm trước khi gửi.',
            'no_environment_profile' => __('messages.environment_no_profile'),
            'unknown_environment_key' => __('messages.environment_unknown_key'),
            'environment_requirement_unreadable' => __('messages.environment_requirement_unreadable'),
            'environment_media_models_broken' => __('messages.environment_media_models_broken'),
            'environment_unknown_media_model' => __('messages.environment_unknown_media_model'),
            'environment_media_setting_invalid' => __('messages.environment_media_setting_invalid'),
            'no_screenplay_profile' => 'Chủ đề này chưa có profile kịch bản.',
            'screenplay_foundation_not_selectable' => __('messages.screenplay_foundation_not_selectable'),
            'screenplay_not_approvable' => 'Bản phân cảnh không thuộc dự án, chưa hoàn tất hoặc không còn hợp lệ để duyệt.',
            'approval_operation_invalid' => 'Mã thao tác duyệt không hợp lệ.',
            'approval_operation_conflict' => 'Mã thao tác duyệt đã được dùng cho một nội dung khác.',
            'screenplay_not_approved' => 'Bản phân cảnh chưa được duyệt hoặc quyết định duyệt không còn khớp nội dung.',
            'screenplay_not_selected' => __('messages.screenplay_not_selected'),
            'production_selection_conflict' => 'Bản production đã được thay đổi ở thao tác khác. Tải lại trang trước khi chọn.',
            'screenplay_profile_invalid' => __('messages.screenplay_profile_invalid'),
            'screenplay_profile_changed' => 'Nội dung kịch bản đang chọn được viết theo profile kịch bản khác với profile hiện hành — tạo lại nội dung kịch bản. Không gọi model.',
            'screenplay_launch_means_missing' => 'Nội dung kịch bản chưa nêu phương thức hạ thủy — bổ sung hoặc tạo lại nội dung kịch bản, duyệt rồi mới tạo lại địa điểm. Lượt này được ghi là thất bại để xem lại; bản địa điểm thành công trước đó vẫn giữ nguyên.',
            'story_profile_design_changed' =>'Phần profile mà bản thiết kế tàu đã dùng (yêu cầu thiết kế, danh sách phòng) đã đổi kể từ lúc thiết kế — thiết kế lại tàu trước khi viết kịch bản. Không gọi model.',
            'screenplay_profile_unknown' =>'Không xác định được profile kịch bản mà bản nội dung này đã dùng — tạo lại nội dung kịch bản. Không gọi model.',
            'screenplay_contract_unsupported' => __('messages.screenplay_contract_unsupported'),
            'screenplay_schema_invalid' => __('messages.screenplay_schema_invalid'),
            'screenplay_call_failed' => __('messages.screenplay_call_failed'),
            'screenplay_timeout' => __('messages.screenplay_timeout'),
            'screenplay_connection_failed' => __('messages.screenplay_connection_failed'),
            'screenplay_author_failed' => __('messages.screenplay_author_failed'),
            'screenplay_after_response_failed' => __('messages.screenplay_after_response_failed'),
            'screenplay_result_not_stored' => __('messages.screenplay_result_not_stored'),
            'inspiration_carries_source_facts' => 'Brief còn dữ kiện nguồn (số đo, ngày, tên) — chưa gọi model, xem log để biết trường nào.',
            'screenplay_invalid' => 'Kịch bản vi phạm hợp đồng cấu trúc — nguyên văn và chi phí đã được lưu, xem log.',
            'screenplay_running' => 'Đang có một lượt viết kịch bản chạy cho dự án này.',
            'screenplay_claim_lost' => 'Mất claim khi lưu — kết quả đã trả tiền không được ghi.',
            'screenplay_truncated' => 'Model bị cắt ở giới hạn token đầu ra — kết quả dở dang không được dùng; nguyên văn và chi phí đã được lưu.',
            'screenplay_characters_not_selectable' => 'Chưa có danh sách nhân vật tạo từ đúng bản nội dung kịch bản này — tạo nhân vật trước.',
            'screenplay_locations_not_selectable' => 'Chưa có danh sách địa điểm tạo từ đúng bản nội dung kịch bản này — tạo địa điểm trước.',
            'screenplay_characters_outdated' => 'Danh sách nhân vật này tạo theo bộ luật cũ, không khớp hồ sơ nhân vật chính hiện hành — tạo lại nhân vật.',
            'screenplay_locations_outdated' => 'Danh sách địa điểm này không tạo từ danh sách nhân vật đang dùng — tạo lại địa điểm.',
            'screenplay_locations_without_brief_spaces' => 'Bộ địa điểm đang chọn gắn không gian của yêu cầu project (brief_space) sai: trùng phòng, phòng không có trong yêu cầu hoặc không thuộc tàu — tạo lại địa điểm theo nội dung kịch bản hiện tại. Không gọi model.',
            'screenplay_film_brief_invalid' => 'Khối film_brief trong profile kịch bản không hợp lệ hoặc không khớp coverage của profile. Không gọi model — xem log để biết mục nào.',
            'screenplay_locations_unprofiled' =>'Danh sách địa điểm này tạo theo mẫu cũ (chỉ có tên và mô tả), thiếu bố cục, lối nối, thiết bị cố định và nguồn sáng — tạo lại địa điểm.',
            'workflow_not_design_first' => 'Dự án này chạy luồng cũ (kịch bản trước, thiết kế sau). Luồng thiết kế trước chỉ áp dụng cho dự án mới.',
            'screenplay_characters_from_design' => 'Nhân vật được đóng gói tự động từ bản thiết kế tàu khi tạo nội dung kịch bản, không tạo bằng model.',
            'character_prompt_no_design' => 'Chưa có bản thiết kế tàu — bấm Thiết kế tàu trước khi viết prompt anchor.',
            'anchor_prompt_other_design' => 'Prompt hoặc ảnh này thuộc một bản thiết kế khác bản thiết kế hiện hành — viết lại prompt anchor từ bản thiết kế mới.',
            'anchor_design_unresolved' => 'Bản thiết kế hiện hành còn quyết định thiết kế chưa chốt — chốt các quyết định đó trước khi viết prompt hoặc render anchor. Không gọi model.',
            'anchor_prompt_unstamped' => 'Prompt anchor này viết trước khi có dấu nguồn (bước viết, nguồn, skill, khung hình) — viết lại prompt anchor. Không render.',
            'anchor_prompt_size_unsupported' => 'Prompt anchor ghép cho Size đang chọn vượt giới hạn ký tự của model ảnh — chọn Size khác. Không render.',
            'anchor_design_placement_ambiguous' => 'Hai sàn ngoài trời nhìn thấy từ góc anchor có cùng tầng, cùng loại mà thiết kế không phân biệt được — thiếu dữ kiện định vị, cần bổ sung trong thiết kế. Không ghép prompt.',
            'anchor_design_not_canonical' => 'Bản thiết kế này không có phần hình học chuẩn (canonical) — tạo lại thiết kế trước khi ghép prompt anchor.',
            'anchor_design_missing_dimensions' => 'Thiết kế thiếu tên tàu, chiều dài hoặc chiều rộng — không ghép được prompt anchor.',
            'anchor_design_missing_decks' => 'Thiết kế chưa khai các tầng boong — không ghép được prompt anchor.',
            'anchor_design_missing_near_end' => 'Thiết kế thiếu mô tả phần mũi tàu (phía gần máy quay của ảnh anchor) — không ghép được prompt anchor.',
            'anchor_design_missing_side' => 'Thiết kế thiếu mô tả mặt mạn tàu (phía máy quay nhìn thấy) — không ghép được prompt anchor.',
            'anchor_design_opening_unplaced' => 'Có ô mở nhìn thấy từ góc anchor nhưng thiếu tầng hoặc thiếu phần chứa nó — bổ sung trong thiết kế. Không ghép prompt.',
            'anchor_design_missing_mandatory' => 'Thiết kế không có ràng buộc phải giữ (must_preserve) nào thấy được từ góc anchor — không ghép prompt.',
            'anchor_design_missing_masses' => 'Thiết kế chưa khai khối chính (primary_masses) thấy được từ góc anchor — không ghép prompt.',
            'anchor_design_moving_part_state_missing' => 'Có bộ phận chuyển động chưa khai trạng thái chuẩn (canonical_state) — bổ sung trong thiết kế, không tự chọn mở hay đóng. Không ghép prompt.',
            'anchor_prompt_too_long' => 'Prompt anchor ghép từ thiết kế vượt giới hạn ký tự của model ảnh. Không render.',
            'anchor_prompt_source_changed' => 'Thiết kế hoặc bộ ghép prompt đã đổi so với prompt đang xem — tải lại trang để ghép prompt mới. Không render.',
            'design_anchor_not_approved' => 'Chưa có ảnh anchor được duyệt cho bản thiết kế — duyệt ảnh anchor trước khi viết kịch bản. Không gọi model.',
            'design_references_not_approved' => 'Chưa chốt bộ ảnh Reference cho ảnh anchor và bản thiết kế hiện hành (hoặc anchor/thiết kế đã đổi sau khi chốt) — duyệt ít nhất một ảnh Reference và bấm "Chốt bộ Reference" trước khi viết kịch bản. Không gọi model.',
            'design_references_none_approved' => 'Chưa có ảnh Reference nào được duyệt từ ảnh anchor hiện hành — duyệt ít nhất một ảnh Reference rồi mới chốt.',
            'design_references_locked' => 'Đã chốt bộ ảnh Reference. Bước tiếp theo: viết nội dung kịch bản.',
            'design_changed_since_anchor' => 'Bản thiết kế đã đổi so với lúc duyệt ảnh anchor — viết prompt, render và duyệt lại ảnh. Không gọi model.',
            'anchor_design_incomplete' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn (có tham chiếu tới phần chưa chốt hoặc thiếu P0) — thiết kế lại tàu. Không gọi model, xem log để biết mục nào.',
            'scene_design_reference_missing' => 'Bản trích thiết kế cho phân cảnh tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'location_design_reference_missing' => 'Bản trích thiết kế cho địa điểm tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'story_design_reference_missing' => 'Bản trích thiết kế cho kịch bản tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'design_geometry_incomplete' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn — không thể giao cho bước địa điểm/phân cảnh. Không gọi model, xem log.',
            'artifact_not_verified' =>'Ảnh này chưa có mã kiểm tra (sha256) — không thể khoá làm nguồn cho kịch bản.',
            'foundation_not_design_first' => 'Bản nội dung này không dựng trên bản thiết kế tàu.',
            'anchor_prompt_not_written' => 'Chưa có prompt anchor cho bản thiết kế hiện hành — bấm "Viết prompt anchor bằng Claude".',
            'anchor_geometry_call_failed' => 'Gọi Claude viết hình học anchor thất bại — phản hồi (nếu có) và usage đã lưu, prompt đang dùng giữ nguyên.',
            'anchor_geometry_invalid' => 'Kết quả Claude không qua kiểm tra (bảng đối soát, số liệu, id hoặc lệnh camera) — raw và usage đã lưu, prompt đang dùng giữ nguyên.',
            'anchor_geometry_source_changed' => 'Bản thiết kế đã đổi trong lúc Claude chạy — kết quả được giữ để đối chiếu, không áp dụng.',
            'anchor_prompt_needs_review' => 'Prompt anchor còn mục cần xử lý (thiếu dữ liệu, mâu thuẫn hoặc xung đột chính sách) — chưa render được.',
            default => $reason,
        };
    }

    protected function referenceMessage(string $reason): string
    {
        return match ($reason) {
            'written' => 'AI đã viết prompt cho góc này — xem trước trong ô prompt rồi mới render.',
            'reference_prompt_cached' => 'Ảnh, nguồn và cấu hình không đổi — dùng lại prompt AI đã viết, không gọi model.',
            'reference_prompt_running' => 'Đang có một lượt AI viết prompt chạy cho dự án này.',
            'reference_prompt_call_failed' => 'Gọi model viết prompt thất bại — xem log.',
            'reference_prompt_invalid' => 'Kết quả AI vi phạm hợp đồng — nguyên văn, usage và chi phí đã được lưu, không render được.',
            'reference_prompt_claim_lost' => 'Mất claim khi lưu — kết quả đã trả tiền được ghi thành lượt mồ côi.',
            'reference_prompt_not_found' => 'Không tìm thấy prompt AI đã viết cho góc này.',
            'reference_prompt_stale' => 'Prompt AI không còn khớp ảnh anchor, gói nguồn hoặc góc đang chọn — viết lại prompt.',
            'reference_needs_ai_prompt' => 'Ô reference này tạo bằng prompt PHP cũ hoặc lật ngang — không render lại được. Hãy bấm "AI viết prompt" rồi Generate Reference.',
            'reference_design_reference_missing' => 'Bản trích thiết kế cho góc này tham chiếu tới bộ phận không có hoặc chưa chốt (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'reference_anchor_foreign' => 'Ảnh anchor hoặc artifact không thuộc dự án này. Không gọi model.',
            'reference_anchor_design_changed' => 'Bản thiết kế đã đổi so với lúc tạo ảnh anchor — tạo và duyệt lại anchor. Không gọi model.',
            'reference_anchor_other_design' => 'Ảnh anchor đang dùng thuộc bản thiết kế khác bản thiết kế đang khoá — chọn lại bộ nguồn khớp (anchor của đúng bản thiết kế). Không gọi model.',
            'reference_design_extract_invalid' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn — không viết được prompt Reference. Không gọi model, xem log.',
            'reference_preview_stale' => 'Prompt đã khác bản đang xem trước — tải lại trang.',
            'reference_anchor_source_unknown' => 'Ảnh anchor không ghi kịch bản và nhân vật nguồn — không ghép với kịch bản đang chọn.',
            'reference_screenplay_unavailable' => 'Bản kịch bản mà ảnh anchor được tạo từ đó không còn đọc được.',
            'reference_anchor_not_object' => 'Nhân vật của ảnh anchor không phải loại object trong kịch bản nguồn.',
            'reference_sheet_needs_design' => 'Bộ 6 góc cần bản thiết kế đã chốt (thiết kế trước) — dự án này chưa có. Không gọi model.',
            'reference_sheet_finish_incomplete' => 'Thiết kế chưa chốt đủ thân tàu, thượng tầng, kính và vật liệu — chưa dùng được "Hoàn thiện theo thiết kế". Không gọi model.',
            'reference_sheet_prompt_too_long' => 'Prompt bộ 6 góc vượt giới hạn ký tự của model ảnh. Không gọi model.',
            'reference_sheet_state_unknown' => 'Trạng thái bộ 6 góc không hợp lệ.',
            'reference_sheet_design_name_missing' => 'Bản thiết kế v3 nhắc tới một bộ phận chưa được đặt tên — không đoán tên, không viết được prompt bộ 6 góc. Không gọi model.',
            'reference_writer_v3_uses_sheet' => 'Bản thiết kế v3 dùng bộ 6 góc ghép từ thiết kế, không qua đường viết prompt Reference từng góc. Không gọi model.',
            default => str_starts_with($reason, 'reference_plan_') ? __('messages.'.$reason) : $this->anchorMessage($reason),
        };
    }

    /**
     * Reason co the mang mot chi tiet sau dau `|` — ten tam nen chang han —
     * de man hinh noi duoc "Chua duyet Paint shed" thay vi mot ma chung chung.
     */
    protected function sceneKeyframeMessage(string $reason): string
    {
        [$code, $detail] = array_pad(explode('|', $reason, 2), 2, null);

        $key = 'messages.scene_keyframe_' . $code;
        $text = __($key, $detail === null ? [] : ['name' => $detail]);

        return $text === $key ? $reason : $text;
    }
}
