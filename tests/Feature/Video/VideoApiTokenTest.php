<?php

namespace Tests\Feature\Video;

use Illuminate\Support\Str;
use Tests\TestCase;

class VideoApiTokenTest extends TestCase
{
    /**
     * MOI route Python goi duoc. Truoc day phep kiem token nam trong
     * `VideoSessionController::checkToken()` va duoc goi lai o muoi phuong thuc,
     * trong khi test chi cham toi hai route — tam route con lai chua bao gio co
     * bang chung la chung that su doi token.
     *
     * Bang nay phai lon len cung nhom route trong `routes/api.php`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function everyPythonRoute(): array
    {
        $id = '00000000-0000-0000-0000-000000000000';

        return [
            ['post', '/api/render-plans'],
            ['get', '/api/video-sessions/composing'],
            ['get', '/api/video-sessions/test_code/design-cells'],
            ['get', '/api/video-shots/queued'],
            ['post', '/api/video-shots/claim'],
            ['post', '/api/video-shots/reclaim-expired'],
            ['patch', "/api/video-shots/{$id}/heartbeat"],
            ['patch', "/api/video-shots/{$id}/result"],
            ['get', '/api/video-finals/composing'],
            ['patch', "/api/video-finals/{$id}/result"],
            ['get', '/api/video-design-images/queued'],
            ['post', '/api/video-design-images/claim'],
            ['post', '/api/video-design-images/reclaim-expired'],
            ['patch', "/api/video-design-images/{$id}/heartbeat"],
            ['patch', "/api/video-design-images/{$id}/result"],
        ];
    }

    /** @dataProvider everyPythonRoute */
    public function test_a_python_route_refuses_a_caller_without_a_token(string $method, string $url): void
    {
        config(['video.api_token' => 'token-loaded-before-runtime']);

        $this->json($method, $url)->assertUnauthorized();
    }

    /** @dataProvider everyPythonRoute */
    public function test_a_python_route_refuses_a_caller_holding_the_wrong_token(string $method, string $url): void
    {
        config(['video.api_token' => 'token-loaded-before-runtime']);

        $this->withHeader('X-Video-Token', 'khong-phai-token-nay')
            ->json($method, $url)
            ->assertUnauthorized();
    }

    /** @dataProvider everyPythonRoute */
    public function test_a_python_route_refuses_everyone_when_no_token_is_configured(string $method, string $url): void
    {
        // Token rong KHONG duoc bien thanh "ai cung vao duoc": mot .env thieu
        // dong la mo toang ca API render.
        config(['video.api_token' => null]);

        $this->withHeader('X-Video-Token', '')->json($method, $url)->assertUnauthorized();
    }

    public function test_the_right_token_gets_through(): void
    {
        config(['video.api_token' => 'token-loaded-before-runtime']);

        $this->withHeader('X-Video-Token', 'token-loaded-before-runtime')
            ->getJson('/api/video-sessions/composing')
            ->assertOk();

        $this->withHeader('X-Video-Token', 'token-loaded-before-runtime')
            ->getJson('/api/video-design-images/queued')
            ->assertOk();
    }

    public function test_a_route_that_passed_the_gate_no_longer_answers_unauthorized(): void
    {
        // Cong da chuyen len middleware: mot id la phai ra 404/422 cua chinh
        // nghiep vu, khong con ra 401 nua.
        config(['video.api_token' => 'token-loaded-before-runtime']);

        $this->withHeader('X-Video-Token', 'token-loaded-before-runtime')
            ->patchJson('/api/video-design-images/'.Str::uuid().'/result', [
                'success' => true,
                'worker_id' => 'worker-a',
                'claim_token' => (string) Str::uuid(),
                'renders' => [],
            ])
            ->assertStatus(422);
    }
}
