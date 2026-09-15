<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Quet nhung thu KHONG CON AI DUNG quanh mot nhom route.
 *
 * Bon cau hoi, theo thu tu nguy hiem giam dan:
 *
 *   1. Route tro toi method KHONG TON TAI — trang se 500 khi co nguoi bam, va
 *      khong test nao bat duoc neu khong ai goi dung duong do. Da xay ra that:
 *      `sceneClipFile()` bi mot lan va code xoa mat trong khi route van con.
 *   2. Method cua controller khong route nao tro toi, va khong noi nao goi.
 *   3. Ten route khong cho nao dung — link chet hoac tan tich cua mot man hinh
 *      da bo.
 *   4. View khong ai render va khong ai nhung.
 *
 * Day la quet TINH. No doc chuoi trong file, nen mot ten dung duoc dung bang
 * bien (`route($name)`) se bi bao nham la khong dung. Ket qua la DANH SACH DE
 * DOC, khong phai lenh xoa — moi dong can mot nguoi nhin lai truoc khi xoa.
 */
class ScanUnusedCode extends Command
{
    protected $signature = 'code:scan-unused
        {--prefix= : Chi xet route co ten bat dau bang chuoi nay, vi du video-projects}
        {--views= : Thu muc view can quet, tinh tu resources/views, vi du video-projects}
        {--vendor : Xet ca route va class nam trong vendor/}
        {--json= : Ghi bao cao ra file JSON}';

    protected $description = 'Tim route tro vao khoang khong, method va view khong con ai dung';

    /** @var array<string, string> */
    private array $production = [];

    /** @var array<string, string> */
    private array $tests = [];

    public function handle(): int
    {
        $prefix = trim((string) $this->option('prefix'));

        $this->production = $this->read([
            app_path(), resource_path('views'), base_path('routes'), base_path('config'),
        ]);
        $this->tests = $this->read([base_path('tests')]);

        $this->line(sprintf(
            'Doc %d file nguon va %d file test.',
            count($this->production),
            count($this->tests),
        ));

        $routes = $this->routesFor($prefix);

        if ($routes === []) {
            $this->error('Khong co route nao khop prefix: '.($prefix !== '' ? $prefix : '(tat ca)'));

            return self::FAILURE;
        }

        $report = [
            'prefix' => $prefix,
            'routes_scanned' => count($routes),
            'broken_actions' => $this->brokenActions($routes),
            'unused_route_names' => $this->unusedRouteNames($routes),
            'unused_methods' => $this->unusedMethods($routes),
            'unused_views' => $this->unusedViews(trim((string) $this->option('views'))),
        ];

        $this->render($report);

        $path = trim((string) $this->option('json'));

        if ($path !== '') {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info('Ghi bao cao: '.$path);
        }

        // Route gay la loi that, khong phai goi y: no lam trang 500 ngay khi co
        // nguoi bam. Nhung thu con lai chi la danh sach de doc.
        return $report['broken_actions'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<RoutingRoute> */
    private function routesFor(string $prefix): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                continue;
            }

            if (! $this->option('vendor') && $this->fromVendor($route)) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * Route tro toi mot method khong ton tai.
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<array<string, string>>
     */
    private function brokenActions(array $routes): array
    {
        $broken = [];

        foreach ($routes as $route) {
            [$class, $method] = $this->action($route);

            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                $broken[] = ['route' => (string) $route->getName(), 'problem' => 'class khong ton tai: '.$class];

                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = [
                    'route' => (string) $route->getName(),
                    'uri' => $route->uri(),
                    'problem' => 'thieu method '.class_basename($class).'::'.$method.'()',
                ];
            }
        }

        return $broken;
    }

    /**
     * Ten route khong cho nao goi.
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<array<string, mixed>>
     */
    private function unusedRouteNames(array $routes): array
    {
        $unused = [];

        foreach ($routes as $route) {
            $name = (string) $route->getName();

            if ($name === '') {
                continue;
            }

            $needles = ["'".$name."'", '"'.$name.'"'];
            $inProduction = $this->hits($this->production, $needles, base_path('routes'));

            if ($inProduction !== []) {
                continue;
            }

            $unused[] = [
                'name' => $name,
                'uri' => $route->uri(),
                'only_tests' => $this->hits($this->tests, $needles, null),
            ];
        }

        return $unused;
    }

    /**
     * Method public cua controller ma khong route nao tro toi.
     *
     * @param  list<RoutingRoute>  $routes
     * @return list<array<string, mixed>>
     */
    private function unusedMethods(array $routes): array
    {
        $bound = [];
        $classes = [];

        foreach ($routes as $route) {
            [$class, $method] = $this->action($route);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $classes[$class] = true;
            $bound[$class][$method] = true;
        }

        $unused = [];

        foreach (array_keys($classes) as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                $file = (string) $method->getFileName();

                if ($method->getDeclaringClass()->getName() !== $class
                    || str_starts_with($name, '__')
                    || isset($bound[$class][$name])
                    || (! $this->option('vendor') && str_contains(str_replace('\\', '/', $file), '/vendor/'))) {
                    continue;
                }

                $needles = ['->'.$name.'(', "'".$name."'", '"'.$name.'"'];
                $calls = $this->hits($this->production, $needles, $method->getFileName());

                if ($calls !== []) {
                    continue;
                }

                $unused[] = [
                    'method' => class_basename($class).'::'.$name.'()',
                    'file' => $this->relative((string) $method->getFileName()).':'.$method->getStartLine(),
                    'only_tests' => $this->hits($this->tests, $needles, null),
                ];
            }
        }

        return $unused;
    }

    /**
     * View khong ai render va khong ai nhung.
     *
     * @return list<array<string, mixed>>
     */
    private function unusedViews(string $folder): array
    {
        if ($folder === '') {
            return [];
        }

        $root = resource_path('views'.DIRECTORY_SEPARATOR.$folder);

        if (! is_dir($root)) {
            $this->warn('Khong co thu muc view: '.$root);

            return [];
        }

        $unused = [];

        foreach (Finder::create()->files()->in($root)->name('*.blade.php') as $file) {
            $dotted = $folder.'.'.str_replace(
                [DIRECTORY_SEPARATOR, '.blade.php'],
                ['.', ''],
                $file->getRelativePathname(),
            );

            $needles = ["'".$dotted."'", '"'.$dotted.'"'];
            $used = $this->hits($this->production, $needles, $file->getRealPath());

            if ($used !== []) {
                continue;
            }

            $unused[] = [
                'view' => $dotted,
                'file' => $this->relative((string) $file->getRealPath()),
                'only_tests' => $this->hits($this->tests, $needles, null),
            ];
        }

        return $unused;
    }

    /**
     * @param  array<string, string>  $haystack
     * @param  list<string>  $needles
     * @return list<string> file co chua, tru chinh no
     */
    private function hits(array $haystack, array $needles, ?string $skip): array
    {
        $found = [];

        foreach ($haystack as $path => $contents) {
            if ($skip !== null && ($path === $skip || str_starts_with($path, $skip))) {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $found[] = $this->relative($path);

                    continue 2;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    private function read(array $roots): array
    {
        $files = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (Finder::create()->files()->in($root)->name(['*.php', '*.js', '*.json']) as $file) {
                /** @var SplFileInfo $file */
                $files[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
            }
        }

        return $files;
    }

    /**
     * Route cua thu vien: class xu ly nam trong vendor/. Code cua thu vien khong
     * phai viec cua ban — bao cao chung chi lam nguoi doc phai loc bang mat.
     */
    private function fromVendor(RoutingRoute $route): bool
    {
        [$class] = $this->action($route);

        if ($class === null || ! class_exists($class)) {
            return false;
        }

        $file = (new ReflectionClass($class))->getFileName();

        return is_string($file) && str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /** @return array{0: ?string, 1: string} */
    private function action(RoutingRoute $route): array
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return [null, ''];
        }

        [$class, $method] = explode('@', $action, 2);

        return [$class, $method];
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        $this->newLine();
        $this->line('Quet '.$report['routes_scanned'].' route'
            .($report['prefix'] !== '' ? ' co ten bat dau bang "'.$report['prefix'].'"' : ''));

        $this->section('ROUTE TRO VAO KHOANG KHONG', $report['broken_actions'], function (array $row): array {
            return [$row['route'], $row['uri'] ?? '', $row['problem']];
        }, ['ROUTE', 'URI', 'VAN DE']);

        $this->section('TEN ROUTE KHONG CHO NAO GOI', $report['unused_route_names'], function (array $row): array {
            return [$row['name'], $row['uri'], $this->onlyTests($row['only_tests'])];
        }, ['TEN', 'URI', 'CHI TEST DUNG']);

        $this->section('METHOD KHONG ROUTE NAO TRO TOI', $report['unused_methods'], function (array $row): array {
            return [$row['method'], $row['file'], $this->onlyTests($row['only_tests'])];
        }, ['METHOD', 'O DAU', 'CHI TEST DUNG']);

        $this->section('VIEW KHONG AI RENDER', $report['unused_views'], function (array $row): array {
            return [$row['view'], $row['file'], $this->onlyTests($row['only_tests'])];
        }, ['VIEW', 'O DAU', 'CHI TEST DUNG']);

        $this->newLine();
        $this->warn('Day la quet TINH: ten dung bang bien se bi bao nham la khong dung.');
        $this->warn('Doc tung dong roi hay xoa — day khong phai danh sach lenh.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    private function section(string $title, array $rows, callable $map, array $headers): void
    {
        $this->newLine();

        if ($rows === []) {
            $this->info('✔ '.$title.': khong co');

            return;
        }

        $this->error('✘ '.$title.': '.count($rows));
        $this->table($headers, array_map($map, $rows));
    }

    /** @param list<string> $files */
    private function onlyTests(array $files): string
    {
        return $files === [] ? '' : 'co ('.count($files).' file test)';
    }
}
