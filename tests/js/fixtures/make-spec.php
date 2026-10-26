<?php

// Tao fixture ProxySpec cho test JS tu chinh compiler PHP (khong boot Laravel, khong DB):
//   php tests/js/fixtures/make-spec.php
// PHPUnit (ProxySpecTest::test_js_fixture_matches_the_compiler) bao loi khi file nay cu.

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';
require __DIR__.'/../../Unit/ProxySpecTest.php';

$spec = Tests\Unit\ProxySpecTest::fixtureSpec();
file_put_contents(__DIR__.'/proxy-spec.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
echo 'proxy-spec.json: ', $spec['spec_sha256'], ' ready=', json_encode($spec['ready']), "\n";
