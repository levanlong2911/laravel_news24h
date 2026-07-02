<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MediaJob;

$job = MediaJob::where('id', 'like', 'a22327b0%')->first();
if (!$job) { echo "Not found\n"; exit(1); }

echo "Before: status={$job->status} attempt={$job->attempt}\n";
$job->update(['status' => 'pending', 'attempt' => 0, 'error_message' => null]);
$job->refresh();
echo "After:  status={$job->status} attempt={$job->attempt}\n";
echo "Ready — start worker.\n";
