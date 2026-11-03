<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * So dữ liệu prompt giữa HAI database, và phân loại chỗ lệch theo mức độ.
 *
 * ── Vì sao cần, khi đã có prompt:check --fingerprint ────────────────────────
 *
 * Vân tay trả lời "hai môi trường có giống nhau không" — một chữ có/không cho
 * riêng phase3. Khi câu trả lời là "không", nó không nói khác ở đâu, và cũng
 * không nói chỗ khác đó nên chữa hay nên giữ.
 *
 * Đó mới là câu hỏi khó lúc deploy. Dữ liệu prompt trên production đi qua admin
 * UI, nên "khác bản chuẩn" có hai nghĩa hoàn toàn trái ngược:
 *
 *     tai nạn định dạng   → chữa về chuẩn
 *     tinh chỉnh có chủ ý → GIỮ, rồi port ngược vào PromptSystemSeeder
 *
 * Ghi đè nhầm nhóm thứ hai là xoá công tinh chỉnh theo hiệu quả thật. Lệnh này
 * không tự quyết — nó tách hai nhóm ra và in diff để người đọc quyết.
 *
 * ── Ranh giới giữa ĐỊNH DẠNG và NỘI DUNG ────────────────────────────────────
 *
 * ĐỊNH DẠNG = chỉ khác dấu xuống dòng, khoảng trắng cuối dòng, hoặc số dòng
 * trống liên tiếp. Đây đúng là loại rác mà <textarea> của admin form sinh ra:
 * nó luôn gửi CRLF, và migration khớp chuỗi nhiều dòng sẽ trượt trong im lặng.
 *
 * Mọi thứ khác — kể cả thêm/bớt một dòng kẻ ═ thuần trang trí — vào nhóm NỘI
 * DUNG. Cố ý chặt tay: một dòng biến mất là thay đổi cấu trúc, và công cụ này
 * không được phép tự phán đoán rằng nó vô hại.
 *
 * ── Phạm vi ─────────────────────────────────────────────────────────────────
 *
 * Không chỉ prompt_frameworks. phase3 hút dữ liệu ngược vào chính nó từ hai
 * bảng khác, và đó cũng chính là hai bảng admin hay tinh chỉnh nhất — bỏ chúng
 * ra ngoài thì báo cáo "đã khớp" mà vẫn thiếu một nửa bức tranh.
 */
class PromptDiff extends Command
{
    protected $signature = 'prompt:diff
        {baseline : Database làm bản chuẩn (vd: bản vừa migrate+seed sạch)}
        {target : Database cần soi (vd: bản sao production)}
        {--full : In trọn diff thay vì cắt bớt}
        {--context=3 : Số dòng giống nhau giữ quanh mỗi chỗ khác}';

    protected $description = 'So dữ liệu prompt giữa hai database, tách chỗ lệch thành định dạng và nội dung';

    /** Giống nhau từng byte. */
    private const SAME = 'same';

    /** Chỉ khác dấu xuống dòng / khoảng trắng — chữa về chuẩn được. */
    private const FORMATTING = 'formatting';

    /** Khác thật — cần người xem rồi quyết giữ hay chữa. */
    private const CONTENT = 'content';

    /** Số dòng diff in ra mỗi trường khi không bật --full. */
    private const DIFF_PREVIEW_LINES = 14;

    private int $formattingCount = 0;
    private int $contentCount    = 0;
    private int $onlyOneSide     = 0;

    public function handle(): int
    {
        $baselineName = (string) $this->argument('baseline');
        $targetName   = (string) $this->argument('target');

        if ($baselineName === $targetName) {
            $this->error('baseline và target trỏ cùng một database — không có gì để so.');
            return self::FAILURE;
        }

        $baseline = $this->connectTo($baselineName);
        $target   = $this->connectTo($targetName);

        $this->line("  <options=bold>chuẩn</> {$baselineName}   <options=bold>soi</> {$targetName}");

        foreach ($this->scopes() as $label => $loader) {
            $this->compareScope($label, $loader($baseline), $loader($target));
        }

        return $this->summarize();
    }

    // ── Ba phạm vi, cùng một máy so ───────────────────────────────────────────

    /**
     * Mỗi phạm vi là một hàm nạp: connection vào, map "khoá người đọc được" ra.
     *
     * Khoá phải ổn định giữa hai môi trường, nên không dùng id: id là UUID, mỗi
     * môi trường một khác, so theo nó thì mọi bản ghi đều báo "chỉ có một bên".
     *
     * @return array<string, callable(ConnectionInterface): array<string, array<string, string>>>
     */
    private function scopes(): array
    {
        return [
            'framework'    => fn (ConnectionInterface $db) => $this->loadFrameworks($db),
            'content type' => fn (ConnectionInterface $db) => $this->loadContentTypes($db),
            'context'      => fn (ConnectionInterface $db) => $this->loadContexts($db),
        ];
    }

    /** @return array<string, array<string, string>> */
    private function loadFrameworks(ConnectionInterface $db): array
    {
        $rows = $db->table('prompt_frameworks')->orderBy('name')->get();
        $out  = [];

        foreach ($rows as $row) {
            if ($this->isExcluded($row->name)) {
                continue;
            }

            $out[$row->name] = [
                'system_prompt'   => (string) $row->system_prompt,
                'phase1_analyze'  => (string) $row->phase1_analyze,
                'phase2_diagnose' => (string) $row->phase2_diagnose,
                'phase3_generate' => (string) $row->phase3_generate,
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, string>> */
    private function loadContentTypes(ConnectionInterface $db): array
    {
        $rows = $db->table('framework_content_types as t')
            ->join('prompt_frameworks as f', 'f.id', '=', 't.framework_id')
            ->orderBy('f.name')->orderBy('t.type_code')
            ->get(['f.name as framework', 't.type_code', 't.type_name', 't.structure_template', 't.trigger_keywords']);

        $out = [];

        foreach ($rows as $row) {
            if ($this->isExcluded($row->framework)) {
                continue;
            }

            $out["{$row->framework}/{$row->type_code}"] = [
                'type_name'          => (string) $row->type_name,
                'structure_template' => (string) $row->structure_template,
                'trigger_keywords'   => (string) $row->trigger_keywords,
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, string>> */
    private function loadContexts(ConnectionInterface $db): array
    {
        $rows = $db->table('category_contexts as c')
            ->join('categories as cat', 'cat.id', '=', 'c.category_id')
            ->orderBy('cat.slug')
            ->get(['cat.slug', 'c.audience', 'c.terminology', 'c.tone_notes', 'c.hook_style']);

        $out = [];

        foreach ($rows as $row) {
            // performance_score và sample_size cố ý vắng mặt: pipeline ghi chúng
            // theo số liệu chạy thật, nên hai môi trường lệch nhau là đương nhiên
            // và không có nghĩa gì. Đưa vào chỉ tạo nhiễu che mất lệch thật.
            $out[(string) $row->slug] = [
                'audience'    => (string) $row->audience,
                'terminology' => (string) $row->terminology,
                'tone_notes'  => (string) $row->tone_notes,
                'hook_style'  => (string) $row->hook_style,
            ];
        }

        return $out;
    }

    // ── Máy so ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, string>>  $baseline
     * @param  array<string, array<string, string>>  $target
     */
    private function compareScope(string $label, array $baseline, array $target): void
    {
        $this->newLine();
        $this->line("  <options=bold>── {$label} ──</>");

        foreach (array_diff(array_keys($baseline), array_keys($target)) as $key) {
            $this->onlyOneSide++;
            $this->line(sprintf('  <fg=red>%-28s THIẾU Ở BẢN SOI</>', $key));
        }

        foreach (array_diff(array_keys($target), array_keys($baseline)) as $key) {
            $this->onlyOneSide++;
            $this->line(sprintf('  <fg=red>%-28s CHỈ CÓ Ở BẢN SOI</>', $key));
        }

        $clean = 0;

        foreach ($baseline as $key => $fields) {
            if (!isset($target[$key])) {
                continue;
            }

            $drifted = false;

            foreach ($fields as $field => $baseValue) {
                $targetValue = $target[$key][$field] ?? '';
                $verdict     = $this->classify($baseValue, $targetValue);

                if ($verdict === self::SAME) {
                    continue;
                }

                $drifted = true;
                $this->reportField($key, $field, $verdict, $baseValue, $targetValue);
            }

            if (!$drifted) {
                $clean++;
            }
        }

        $this->line(sprintf('  <fg=gray>%d khớp hoàn toàn</>', $clean));
    }

    private function reportField(string $key, string $field, string $verdict, string $base, string $target): void
    {
        if ($verdict === self::FORMATTING) {
            $this->formattingCount++;
            $this->line(sprintf(
                '  <fg=yellow>%-28s ĐỊNH DẠNG</>  %s  <fg=gray>(%d → %d ký tự)</>',
                $key, $field, mb_strlen($base), mb_strlen($target),
            ));
            $this->line('     <fg=gray>chỉ khác dấu xuống dòng / khoảng trắng — chữa về chuẩn được</>');

            return;
        }

        $this->contentCount++;
        $this->line(sprintf(
            '  <fg=red>%-28s NỘI DUNG</>   %s  <fg=gray>(%d → %d ký tự)</>',
            $key, $field, mb_strlen($base), mb_strlen($target),
        ));

        $this->printDiff($base, $target);
    }

    /**
     * Chuẩn hoá rồi so: bằng nhau sau chuẩn hoá nghĩa là chỉ khác định dạng.
     */
    private function classify(string $base, string $target): string
    {
        if ($base === $target) {
            return self::SAME;
        }

        return $this->normalize($base) === $this->normalize($target)
            ? self::FORMATTING
            : self::CONTENT;
    }

    /**
     * Gỡ đúng ba thứ mà <textarea> và người gõ tay hay để lại.
     *
     * "\r\n" phải thay trước "\r" lẻ — đảo thứ tự sẽ biến mỗi CRLF thành hai LF,
     * tức tự tạo ra khác biệt mà mình đang định xoá. Cùng bẫy đã được chú thích
     * ở PromptFrameworkObserver::saving().
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Khoảng trắng cuối mỗi dòng.
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);

        // Nhiều dòng trống liên tiếp gộp về một.
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    // ── Diff theo dòng ────────────────────────────────────────────────────────

    private function printDiff(string $base, string $target): void
    {
        $lines = $this->diffLines(
            explode("\n", str_replace(["\r\n", "\r"], "\n", $base)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $target)),
        );

        $lines = $this->trimToChangedRegions($lines, max(0, (int) $this->option('context')));
        $shown = 0;

        foreach ($lines as $line) {
            if (!$this->option('full') && $shown >= self::DIFF_PREVIEW_LINES) {
                $this->line('     <fg=gray>… cắt bớt, chạy lại với --full để xem trọn</>');
                break;
            }

            $color = match ($line['sign']) {
                '-'     => 'red',
                '+'     => 'green',
                default => 'gray',
            };

            // Dòng trống hiện thành ␤ — chênh lệch dòng trống là một trong những
            // kiểu lệch hay gặp nhất ở đây, mà in ra thì không thấy gì cả.
            $text = $line['text'] === '' ? '␤' : $line['text'];

            // Vạch ngăn giữa dấu và nội dung. Không có nó thì dòng ngữ cảnh mà
            // nội dung vốn bắt đầu bằng "- " (phase3 đầy những dòng như vậy)
            // trông hệt dòng bị xoá — đúng thứ người đọc đang cần phân biệt.
            $this->line(sprintf('     <fg=%s>%s│ %s</>', $color, $line['sign'], $text));
            $shown++;
        }
    }

    /**
     * Diff theo dòng bằng LCS.
     *
     * Không so từng cặp dòng theo chỉ số: chỉ cần thêm một dòng ở đầu là mọi dòng
     * sau đó lệch chỉ số và báo khác hết, đúng lúc cần đọc nhất thì lại vô dụng.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{sign: string, text: string}>
     */
    private function diffLines(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        // lcs[i][j] = độ dài dãy con chung dài nhất của old[i..] và new[j..]
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = $j = 0;

        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $out[] = ['sign' => ' ', 'text' => $old[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['sign' => '-', 'text' => $old[$i++]];
            } else {
                $out[] = ['sign' => '+', 'text' => $new[$j++]];
            }
        }

        while ($i < $n) {
            $out[] = ['sign' => '-', 'text' => $old[$i++]];
        }

        while ($j < $m) {
            $out[] = ['sign' => '+', 'text' => $new[$j++]];
        }

        return $out;
    }

    /**
     * Giữ các dòng khác nhau cùng $context dòng giống nhau quanh chúng.
     *
     * phase3 dài hơn 200 dòng mà thường chỉ khác một hai chỗ — in cả file thì
     * chỗ khác chìm nghỉm.
     *
     * @param  list<array{sign: string, text: string}>  $lines
     * @return list<array{sign: string, text: string}>
     */
    private function trimToChangedRegions(array $lines, int $context): array
    {
        $keep = [];

        foreach ($lines as $index => $line) {
            if ($line['sign'] === ' ') {
                continue;
            }

            for ($i = $index - $context; $i <= $index + $context; $i++) {
                if (isset($lines[$i])) {
                    $keep[$i] = true;
                }
            }
        }

        ksort($keep);

        return array_values(array_intersect_key($lines, $keep));
    }

    // ── Hỗ trợ ────────────────────────────────────────────────────────────────

    /**
     * Mở connection tới một database khác, sao lại mọi thiết lập của connection
     * mặc định và chỉ đổi tên database.
     *
     * Không đổi config của chính connection mặc định rồi purge: làm vậy thì suốt
     * phần còn lại của tiến trình mọi truy vấn "mysql" đều trỏ sang chỗ khác, và
     * lệnh này cần cả hai bên sống song song.
     */
    private function connectTo(string $database): ConnectionInterface
    {
        $default = (string) config('database.default');
        $name    = "prompt_diff_{$database}";

        config([
            "database.connections.{$name}" => array_merge(
                (array) config("database.connections.{$default}"),
                ['database' => $database],
            ),
        ]);

        $connection = DB::connection($name);

        // Chạm vào DB ngay: sai tên database thì hỏng ở đây, kèm thông báo của
        // driver, chứ không phải hỏng giữa chừng khi đã in ra nửa báo cáo.
        $connection->getPdo();

        return $connection;
    }

    private function isExcluded(string $name): bool
    {
        foreach ((array) config('prompt.check.excluded_framework_prefixes', []) as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function summarize(): int
    {
        $this->newLine();

        if ($this->onlyOneSide) {
            $this->error("{$this->onlyOneSide} bản ghi chỉ tồn tại ở một bên.");
        }

        if ($this->contentCount) {
            $this->error(
                "{$this->contentCount} trường lệch NỘI DUNG — xem diff rồi quyết từng cái: "
                . 'tinh chỉnh có chủ ý thì GIỮ và port ngược vào PromptSystemSeeder, '
                . 'tai nạn thì chữa về chuẩn.'
            );
        }

        if ($this->formattingCount) {
            $this->warn("{$this->formattingCount} trường lệch ĐỊNH DẠNG — chữa về chuẩn được, không mất nội dung.");
        }

        if (!$this->onlyOneSide && !$this->contentCount && !$this->formattingCount) {
            $this->info('Hai database khớp nhau trên mọi trường prompt.');
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
