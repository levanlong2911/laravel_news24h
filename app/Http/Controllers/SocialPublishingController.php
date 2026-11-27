<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\Video\Social\SocialApprovalService;
use App\Services\Video\Social\SocialArtworkService;
use App\Services\Video\Social\SocialChannelService;
use App\Services\Video\Social\SocialConnectionService;
use App\Services\Video\Social\SocialContentService;
use App\Services\Video\Social\SocialExportService;
use App\Services\Video\Social\SocialMetricsService;
use App\Services\Video\Social\SocialPackageService;
use App\Services\Video\Social\SocialPublicationService;
use App\Services\Video\Social\SocialScreenService;
use App\Services\Video\Social\SocialSourceService;
use App\Services\VideoProjectService;
use App\Video\Social\SocialAbilities;
use App\Video\Social\SocialRedaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 12 — Social Publishing. This controller takes a request, checks who sent it and what they may do, and hands
 * it to a service. It builds no prompt, calls no platform and runs no ffmpeg.
 *
 * Every action is refused on the server when the feature is off, the project is not the actor's, or the actor's role
 * lacks the ability the action needs — whatever the page showed.
 */
class SocialPublishingController extends Controller
{
    private const STATE_KEY = 'video_social_oauth';

    public function index(Request $request, string $id)
    {
        $this->open($id, null);

        if (! app(SocialSourceService::class)->supports($id)) {
            abort(404);
        }

        return view('video-projects.social-publishing', [
            // What the admin layout marks the menu with, as on the other video screens.
            'route' => 'video-projects',
            'action' => 'admin-video-projects',
            'menu' => 'menu-open',
            'active' => 'active',
            'id' => $id,
            'social' => app(SocialScreenService::class)->screen($id, $request->query('package'), (int) $request->query('page', 1), auth()->user()),
        ]);
    }

    public function storeChannel(Request $request, string $id)
    {
        $this->open($id, SocialAbilities::CHANNELS);

        return $this->answer($id, null, function () use ($request): string {
            $channel = app(SocialChannelService::class)->create($request->all(), $this->actorId());

            return "Đã tạo hồ sơ kênh {$channel->display_name}. Hồ sơ dùng được ngay để viết và xuất gói; đăng qua API cần xác minh bằng kết nối.";
        });
    }

    public function updateChannel(Request $request, string $id, string $channel)
    {
        $this->open($id, SocialAbilities::CHANNELS);

        return $this->answer($id, null, function () use ($request, $channel): string {
            app(SocialChannelService::class)->update($channel, $request->all());

            return 'Đã lưu hồ sơ kênh. Thay đổi áp dụng cho gói mới; gói đã có giữ hồ sơ lúc viết.';
        });
    }

    public function archiveChannel(string $id, string $channel)
    {
        $this->open($id, SocialAbilities::CHANNELS);
        app(SocialChannelService::class)->archive($channel);

        return $this->back($id, null)->with('success', 'Đã lưu trữ hồ sơ kênh. Gói và lịch sử đăng của kênh được giữ.');
    }

    public function connect(Request $request, string $id, string $platform)
    {
        $this->open($id, SocialAbilities::CONNECT);
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, ['state' => $state, 'platform' => $platform, 'project_id' => $id]);

        try {
            return redirect()->away(app(SocialConnectionService::class)->authorizeUrl($platform, $state, route('video-social.oauth-callback', $platform)));
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, null)->with('error', SocialRedaction::text($e->getMessage()));
        }
    }

    /** Where a platform sends a person back after they granted or refused access. The state proves the answer belongs to a request this session made. */
    public function oauthCallback(Request $request, string $platform)
    {
        abort_unless(SocialSourceService::enabled(), 404);
        $pending = $request->session()->pull(self::STATE_KEY);

        if (! is_array($pending) || $pending['platform'] !== $platform || ! is_string($request->query('state')) || ! hash_equals((string) $pending['state'], $request->query('state'))) {
            abort(403, 'Yêu cầu kết nối không khớp phiên đăng nhập — bấm kết nối lại.');
        }

        $id = (string) $pending['project_id'];
        $this->open($id, SocialAbilities::CONNECT);

        if (! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->back($id, null)->with('error', 'Nền tảng không cấp quyền: '.Str::limit((string) $request->query('error_description', $request->query('error', 'không rõ lý do')), 200));
        }

        return $this->answer($id, null, function () use ($request, $platform): string {
            [$connection, $verified] = app(SocialConnectionService::class)->connect($platform, (string) $request->query('code'), route('video-social.oauth-callback', $platform), $this->actorId());

            return "Đã kết nối {$connection->label}. {$verified} hồ sơ kênh được xác minh theo đúng ID nền tảng; hồ sơ nào chưa xác minh là do ID không nằm trong tài khoản này.";
        });
    }

    public function disconnect(string $id, string $connection)
    {
        $this->open($id, SocialAbilities::CONNECT);
        app(SocialConnectionService::class)->disconnect($connection);

        return $this->back($id, null)->with('success', 'Đã ngắt kết nối và xóa token. Lịch sử đăng được giữ; các kênh của kết nối trở về chưa xác minh.');
    }

    public function storePackage(Request $request, string $id)
    {
        $this->open($id, SocialAbilities::PACKAGES);
        $data = $request->validate(['channel_id' => ['required', 'uuid'], 'format' => ['required', 'string', 'max:30'], 'language' => ['required', 'string', 'max:10'], 'goal' => ['nullable', 'string', 'max:500']]);

        try {
            [$package, $reason] = app(SocialPackageService::class)->create($id, $data['channel_id'], $data['format'], $data['language'], $data['goal'] ?? null, $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, null)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $package->id)->with('success', $reason === 'created' ? 'Đã mở gói mới (bản nháp, chưa có nội dung).' : 'Gói cho kênh, định dạng và ngôn ngữ này đã có — mở bản mới nhất.');
    }

    public function savePackage(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);
        $input = $request->only([...\App\Video\Social\SocialPackageDocument::FIELDS, 'apply_hook']);

        try {
            [$revision, $reason] = app(SocialPackageService::class)->revise($id, $package, (string) $request->input('expected_hash'), $input, $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $revision->id)->with('success', $reason === 'unchanged' ? 'Không có gì thay đổi — không tạo revision mới.' : "Đã lưu thành revision {$revision->revision} (bản nháp). Revision trước giữ nguyên.");
    }

    public function rebasePackage(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);

        try {
            [$revision, $reason] = app(SocialPackageService::class)->rebase($id, $package, (string) $request->input('expected_hash'), $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $revision->id)->with('success', "Đã tạo revision {$revision->revision} theo phim, hồ sơ kênh và chính sách hiện hành.".($reason === 'rebased_without_cover' ? ' Cover cũ cắt từ bản phim trước nên không mang sang — dựng cover mới.' : '').' Bản này cần duyệt lại.');
    }

    public function writePackage(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);
        $data = $request->validate(['expected_hash' => ['required', 'string'], 'operation_id' => ['required', 'uuid'], 'regenerate' => ['nullable', 'boolean']]);
        // A run is queued through a stage, which turns every failure into a reason; an id not of this project is a page that does not exist before that.
        app(SocialPackageService::class)->owned($id, $package);
        [$stage, $reason, $revision] = app(SocialContentService::class)->write($id, $package, $data['expected_hash'], (bool) ($data['regenerate'] ?? false), $data['operation_id'], $this->requester());

        return match (true) {
            $revision !== null => $this->back($id, (string) $revision->id)->with('success', "AI đã viết nội dung — lưu thành revision {$revision->revision} (bản nháp) để bạn sửa và duyệt."),
            $stage !== null && $reason === 'queued' => $this->back($id, $package)->with('success', 'Đã xếp lượt viết nội dung vào hàng đợi — tải lại trang sau ít phút.'),
            default => $this->back($id, $package)->with('error', $reason),
        };
    }

    public function reviewPackage(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);
        $data = $request->validate(['expected_hash' => ['required', 'string'], 'operation_id' => ['required', 'uuid'], 'regenerate' => ['nullable', 'boolean']]);
        app(SocialPackageService::class)->owned($id, $package);
        [$stage, $reason] = app(SocialContentService::class)->review($id, $package, $data['expected_hash'], (bool) ($data['regenerate'] ?? false), $data['operation_id'], $this->requester());

        return $stage === null
            ? $this->back($id, $package)->with('error', $reason)
            : $this->back($id, $package)->with('success', $reason === 'queued' ? 'Đã xếp lượt review vào hàng đợi — tải lại trang sau ít phút.' : 'Đã có kết quả review cho bản này. Review không sửa và không duyệt gói.');
    }

    public function frame(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);

        try {
            $path = app(SocialArtworkService::class)->frame($id, $package, (int) $request->query('at_ms', 0));
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            abort(422, SocialRedaction::text($e->getMessage()));
        }

        return response()->file($path, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    public function makeCover(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);

        try {
            [$revision, $reason] = app(SocialArtworkService::class)->cover($id, $package, (string) $request->input('expected_hash'), $request->only(['at_ms', 'asset_id', 'focus_x', 'focus_y', 'zoom', 'text', 'position', 'size']), $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $revision === null
            ? $this->back($id, $package)->with('error', $reason)
            : $this->back($id, (string) $revision->id)->with('success', "Đã dựng cover và gắn vào revision {$revision->revision} (bản nháp).");
    }

    public function selectCover(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PACKAGES);

        try {
            [$revision, $reason] = $request->filled('artifact_id')
                ? app(SocialArtworkService::class)->select($id, $package, (string) $request->input('expected_hash'), (string) $request->input('artifact_id'), $this->actorId())
                : app(SocialPackageService::class)->withArtwork($id, $package, (string) $request->input('expected_hash'), null, $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $revision->id)->with('success', $reason === 'unchanged' ? 'Cover không đổi.' : "Đã đổi cover — revision {$revision->revision} (bản nháp).");
    }

    public function cover(string $id, string $artifact)
    {
        $this->open($id, null);
        $path = SocialArtworkService::path(app(SocialArtworkService::class)->owned($id, $artifact));
        abort_if($path === null, 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    public function approve(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::APPROVE);
        $data = $request->validate(['expected_hash' => ['required', 'string'], 'operation_id' => ['required', 'uuid']]);

        return $this->answer($id, $package, function () use ($id, $package, $data): string {
            app(SocialApprovalService::class)->approve($id, $package, $data['expected_hash'], $data['operation_id'], $this->actorId());

            return 'Đã duyệt bản này. Duyệt không phải là đăng: bản đã duyệt mới xuất gói, ghi nhận đăng tay hoặc gửi API được.';
        });
    }

    public function revoke(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::APPROVE);
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->answer($id, $package, function () use ($id, $package, $data): string {
            [, $lines] = app(SocialApprovalService::class)->revoke($id, $package, (string) ($data['reason'] ?? ''), $data['operation_id'], $this->actorId());

            return 'Đã thu hồi duyệt. '.($lines === [] ? 'Bản này chưa có lượt đăng nào.' : implode(' | ', $lines));
        });
    }

    public function export(string $id, string $package)
    {
        $this->open($id, SocialAbilities::EXPORT);

        try {
            $export = app(SocialExportService::class)->export($id, $package, $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }

        return response()->download($export['path'], $export['name'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    public function reportManual(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::EXPORT);
        $data = $request->validate(['expected_hash' => ['required', 'string'], 'request_key' => ['required', 'uuid'], 'url' => ['required', 'string', 'max:500'], 'posted_at' => ['required', 'string', 'max:40']]);

        return $this->answer($id, $package, function () use ($id, $package, $data): string {
            [, $reason] = app(SocialPublicationService::class)->reportManual($id, $package, $data['expected_hash'], $data['request_key'], $data['url'], $data['posted_at'], $this->actorId());

            return $reason === 'replayed' ? 'Lượt này đã được ghi nhận trước đó.' : 'Đã ghi nhận: Đã đăng — xác nhận thủ công. Hệ thống không đối chiếu nội dung trên nền tảng với gói.';
        });
    }

    public function publish(Request $request, string $id, string $package)
    {
        $this->open($id, SocialAbilities::PUBLISH);
        $data = $request->validate(['expected_hash' => ['required', 'string'], 'request_key' => ['required', 'uuid'], 'scheduled_at' => ['nullable', 'string', 'max:40'], 'again' => ['nullable', 'boolean']]);

        return $this->answer($id, $package, function () use ($id, $package, $data): string {
            [$publication, $reason] = app(SocialPublicationService::class)->request($id, $package, $data['expected_hash'], $data['request_key'], $data['scheduled_at'] ?? null, (bool) ($data['again'] ?? false), $this->actorId());

            return ($reason === 'replayed' ? 'Yêu cầu này đã được nhận trước đó — không tạo lượt đăng thứ hai. ' : 'Đã nhận yêu cầu đăng. ').'Trạng thái hiện tại: '.$publication->status.($publication->error_message ? ' — '.$publication->error_message : '').'.';
        });
    }

    public function followPublication(string $id, string $publication)
    {
        $this->open($id, SocialAbilities::PUBLISH);

        try {
            [$row, $message, $failed] = app(SocialPublicationService::class)->follow($id, $publication);
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return back()->with('error', 'Chưa đối chiếu được: '.SocialRedaction::text($e->getMessage()));
        }

        // A look-up the platform refused is said as a failure, never as a success.
        return $this->back($id, (string) $row->package_revision_id)->with($failed ? 'error' : 'success', $message);
    }

    public function cancelPublication(string $id, string $publication)
    {
        $row = \App\Models\VideoSocialPublication::query()->where('project_id', $id)->findOrFail($publication);
        // Taking back a report made by hand is of the people who report; stopping an API posting is of the people who post.
        $this->open($id, $row->method === \App\Models\VideoSocialPublication::MANUAL ? SocialAbilities::EXPORT : SocialAbilities::PUBLISH);
        [$row, $message] = app(SocialPublicationService::class)->cancel($id, $publication, $this->actorId());

        return $this->back($id, (string) $row->package_revision_id)->with($row->status === \App\Models\VideoSocialPublication::CANCELLED ? 'success' : 'error', $message);
    }

    public function resolvePublication(Request $request, string $id, string $publication)
    {
        $this->open($id, SocialAbilities::PUBLISH);
        $data = $request->validate(['outcome' => ['required', 'in:published,failed,cancelled,rescheduled'], 'url' => ['nullable', 'string', 'max:500'], 'time' => ['nullable', 'string', 'max:40']]);

        try {
            $row = app(SocialPublicationService::class)->resolve($id, $publication, $data['outcome'], $data['url'] ?? null, $data['time'] ?? null, $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return back()->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $row->package_revision_id)->with('success', 'Đã ghi nhận theo xác nhận của bạn — đây là xác nhận thủ công, không phải kết quả API đối chiếu.');
    }

    public function reschedulePublication(Request $request, string $id, string $publication)
    {
        $this->open($id, SocialAbilities::PUBLISH);
        $data = $request->validate(['scheduled_at' => ['required', 'string', 'max:40']]);

        try {
            $row = app(SocialPublicationService::class)->reschedule($id, $publication, $data['scheduled_at'], $this->actorId());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return back()->with('error', SocialRedaction::text($e->getMessage()));
        }

        return $this->back($id, (string) $row->package_revision_id)->with('success', 'Đã đổi giờ đăng. Nội dung đã duyệt không đổi; thay đổi lịch được ghi thành một sự kiện riêng.');
    }

    public function syncMetrics(string $id, string $publication)
    {
        $this->open($id, SocialAbilities::PUBLISH);

        try {
            [, $message] = app(SocialMetricsService::class)->sync($id, $publication);
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return back()->with('error', SocialRedaction::text($e->getMessage()));
        }

        return back()->with('success', $message);
    }

    /**
     * The gate of every action: the feature is on, the project is one the actor may act on, and the actor's role has the ability.
     *
     * @param  ?string  $ability  null for an action that only reads
     */
    private function open(string $id, ?string $ability): void
    {
        abort_unless(SocialSourceService::enabled(), 404);
        // Only the id and the owner are read: the policy decides on admin_id alone.
        $project = app(VideoProjectService::class)->ownershipOf($id);
        abort_if($project === null, 404);
        $actor = auth()->user();
        abort_if(! $actor instanceof Admin || Gate::forUser($actor)->denies('update', $project), 403);
        abort_if($ability !== null && ! SocialAbilities::allows($actor, $ability), 403, $ability === null ? '' : SocialAbilities::refusal($ability));
    }

    /** @param \Closure(): string $act what the action does; its message goes back as success, a refusal as the error of the server */
    private function answer(string $id, ?string $package, \Closure $act)
    {
        try {
            return $this->back($id, $package)->with('success', $act());
        } catch (ModelNotFoundException $e) {
            // An id that is not of this project is a page that does not exist, never a message naming a model.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->back($id, $package)->with('error', SocialRedaction::text($e->getMessage()));
        }
    }

    private function back(string $id, ?string $package)
    {
        return redirect()->route('video-projects.social-publishing', array_filter(['id' => $id, 'package' => $package]));
    }

    private function actorId(): ?string
    {
        return auth()->id() === null ? null : (string) auth()->id();
    }

    /** @return ?array{id: string, name: string} */
    private function requester(): ?array
    {
        $actor = auth()->user();

        return $actor instanceof Admin ? ['id' => (string) $actor->id, 'name' => (string) $actor->name] : null;
    }
}
