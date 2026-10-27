<?php

/*
|--------------------------------------------------------------------------
| Test kiến trúc — máy kiểm tra các rule [ARCH] trong AGENTS.md mục 4
|--------------------------------------------------------------------------
| Chạy:  php vendor/bin/pest tests/Arch
| Viết cho Pest 2 (Laravel 10, PHPUnit 10). Không dùng preset() / toHaveMethod() vì đó là tính năng Pest 3 (Laravel 11+).
|
| File luật: agent KHÔNG được sửa. Đổi file này cần label law-change-approved trên GitHub.
|
| QUAN TRỌNG — trước khi bật trong CI:
|   chạy /arch-audit trong Claude Code. Claude đọc code thật và đề xuất cho từng rule:
|   GIỮ / GIỮ + ignoring (nợ kỹ thuật cũ) / BỎ (không hợp project). Bạn quyết định, rồi tự sửa file này.
|   Dùng ->ignoring('App\\...\\ClassCu') cho code cũ thay vì xóa rule, để code MỚI vẫn bị kiểm tra.
*/

// ---------- Không để lại code debug ----------

arch('không để lại hàm debug trong app/')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'phpinfo'])
    ->not->toBeUsed();

arch('không dùng die/exit trong app/')
    ->expect(['die', 'exit'])
    ->not->toBeUsed();

// ---------- Bảo mật ----------

arch('không dùng eval / extract / unserialize thô')
    ->expect(['eval', 'extract', 'unserialize'])
    ->not->toBeUsed();

// Lệnh shell: pipeline render/convert font có thể cần. /arch-audit sẽ chỉ ra class nào đang dùng;
// giới hạn shell vào đúng namespace đó bằng ->ignoring(...) thay vì cho phép toàn bộ app/.
arch('lệnh shell chỉ nằm trong lớp hạ tầng được chỉ định')
    ->expect(['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'])
    ->not->toBeUsed()
    ->ignoring([
        // 'App\Services\Render',
    ]);

arch('env() chỉ dùng trong config/')
    ->expect('env')
    ->not->toBeUsed();

// ---------- HTTP layer ----------

arch('controller có hậu tố Controller')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('controller không truy vấn DB trực tiếp')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Query\Builder',
    ]);

arch('form request kế thừa FormRequest')
    ->expect('App\Http\Requests')
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

// ---------- Nghiệp vụ không phụ thuộc HTTP ----------
// Nếu project dùng tên khác (vd App\Pipelines, App\Domain), sửa danh sách namespace cho khớp.

arch('lớp nghiệp vụ không phụ thuộc HTTP request/session')
    ->expect(['App\Actions', 'App\Services'])
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
        'App\Http',
        'request',
        'session',
        'redirect',
        'view',
    ]);

// ---------- Model ----------

arch('model không phụ thuộc HTTP hay lớp nghiệp vụ')
    ->expect('App\Models')
    ->not->toUse(['App\Http', 'App\Actions', 'App\Jobs', 'request', 'session']);

// ---------- Job ----------

arch('job chạy bất đồng bộ phải implement ShouldQueue')
    ->expect('App\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');
