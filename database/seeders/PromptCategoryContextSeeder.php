<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryContext;
use App\Models\PromptFramework;
use Illuminate\Database\Seeder;

/**
 * Tầng category: 15 categories + 15 category_contexts, mỗi cái trỏ về một
 * framework theo tên.
 *
 * ── CHỈ DÀNH CHO MÁY CÀI MỚI ────────────────────────────────────────────────
 *
 * Tách khỏi [[PromptFrameworkSeeder]] vì hai tầng có tuổi thọ khác hẳn nhau.
 *
 * 8 framework là hạ tầng: mọi môi trường đều cần đúng 8 cái đó, và đã được kiểm
 * chứng là giống nhau tới từng byte giữa các môi trường.
 *
 * 15 category thì không. Chúng là biên tập — mỗi môi trường chạy một tập mảng
 * nội dung riêng và đổi theo thời gian. Đối chiếu ngày 2026-08-05 giữa DB dev và
 * bản seed sạch cho thấy 6 category ở mỗi bên mà bên kia không có: dev đã tách
 * 'nfl' thành từng đội (dallas-cowboys, green-bay-packers, pittsburgh-steelers)
 * và thêm 'dogs', 'archaeology', 'yacht'.
 *
 * Vì firstOrCreate chỉ tạo chứ không sửa, chạy tầng này trên môi trường đã sống
 * không phá gì cả — nhưng nó CHÈN THÊM những category mà môi trường đó đã cố ý
 * không dùng. Nên nó đứng riêng: gọi khi dựng máy mới, không gọi lúc deploy.
 */
class PromptCategoryContextSeeder extends Seeder
{
    /**
     * Tên 8 framework mà categoryContextDefinitions() tham chiếu tới.
     *
     * Chép lại ở đây có chủ ý. Thiếu một cái thì không chặn trước, PHP chỉ cảnh
     * báo "Undefined array key" rồi ghi framework_id = null — tức context câm
     * lặng trỏ vào hư không, và lỗi chỉ lộ ra lúc sinh bài. Danh sách này biến
     * nó thành một thông báo đọc được ngay lúc seed.
     */
    private const REQUIRED_FRAMEWORKS = [
        'nfl_sports', 'individual_sports', 'motorsport', 'luxury_assets',
        'travel_mobility', 'lifestyle_living', 'knowledge_discovery', 'entertainment_viral',
    ];

    public function run(): void
    {
        $this->seedCategoryContexts($this->frameworkIdsByName());
    }

    /**
     * Tra id framework theo tên, từ DB chứ không nhận qua tham số.
     *
     * Nhờ vậy tầng này chạy độc lập được: dựng lại category trên một DB đã có
     * sẵn framework mà không phải chạy lại tầng framework.
     *
     * @return array<string, string>
     */
    private function frameworkIdsByName(): array
    {
        $ids = PromptFramework::pluck('id', 'name')->all();

        if ($missing = array_diff(self::REQUIRED_FRAMEWORKS, array_keys($ids))) {
            throw new \RuntimeException(
                'Thiếu framework: ' . implode(', ', $missing)
                . '. Chạy PromptFrameworkSeeder trước.'
            );
        }

        return $ids;
    }

    private function seedCategoryContexts(array $frameworkIds): void
    {
        foreach ($this->categoryContextDefinitions($frameworkIds) as $def) {
            // Khớp theo slug, không theo name: slug là khoá ổn định. Đổi tên hiển thị
            // category mà vẫn khớp theo name thì lần seed sau đẻ ra bản ghi trùng.
            $category = Category::firstOrCreate(
                ['slug' => $def['slug']],
                ['name' => $def['name']]
            );

            // firstOrCreate: tone_notes / hook_style / terminology là thứ admin tinh
            // chỉnh theo hiệu quả thực tế. performance_score và sample_size do pipeline
            // ghi. Ghi đè chúng bằng hằng số trong file này là xoá dữ liệu vận hành.
            $context = CategoryContext::firstOrCreate(
                ['category_id' => $category->id],
                [
                    'framework_id'        => $def['framework_id'],
                    'domain'              => $def['domain'],
                    'audience'            => $def['audience'],
                    'terminology'         => $def['terminology'],
                    'tone_notes'          => $def['tone_notes'],
                    'hook_style'          => $def['hook_style'],
                    'custom_type_triggers'=> $def['custom_type_triggers'] ?? null,
                    'performance_score'   => 0,
                    'sample_size'         => 0,
                    'is_active'           => true,
                ]
            );

            $this->command->info(sprintf(
                '%-7s Context: %s → %s',
                $context->wasRecentlyCreated ? 'created' : 'kept',
                $def['name'],
                $def['domain'],
            ));
        }
    }

    // ── Category Context Definitions ──────────────────────────────────────────

    private function categoryContextDefinitions(array $frameworkIds): array
    {
        return [

            // ── NFL ────────────────────────────────────────────────────────────
            [
                'slug'        => 'nfl',
                'name'        => 'NFL',
                'framework_id'=> $frameworkIds['nfl_sports'],
                'domain'      => 'NFL',
                'audience'    => 'NFL fans, fantasy players, sports bettors, casual football viewers',
                'terminology' => ['salary cap', 'draft pick', 'IR', 'snap count', 'DVOA', 'EPA', 'red zone', 'two-minute drill', 'franchise tag'],
                'tone_notes'  => 'Authoritative but accessible. Heavy on stats and strategic context. Fantasy and betting angles welcome. Avoid jargon without explanation.',
                'hook_style'  => 'Lead with the most dramatic or consequential moment — a final-second play, a stunning stat, a surprising decision',
            ],

            // ── Individual Sports ──────────────────────────────────────────────
            [
                'slug'        => 'tennis',
                'name'        => 'Tennis',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Tennis',
                'audience'    => 'Tennis fans, Grand Slam followers, ATP/WTA ranking watchers',
                'terminology' => ['Grand Slam', 'ATP', 'WTA', 'tiebreak', 'bagel', 'break of serve', 'clay', 'grass', 'hardcourt', 'seeded', 'wild card'],
                'tone_notes'  => 'Narrative-driven, respectful of the mental game. Grand Slam context always relevant. Historical comparisons add depth.',
                'hook_style'  => 'Open with the match-defining moment — a crucial break, a final set comeback, a tearful acceptance speech',
            ],
            [
                'slug'        => 'boxing-mma',
                'name'        => 'Boxing & MMA',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Boxing & MMA',
                'audience'    => 'Combat sports fans, PPV buyers, betting community',
                'terminology' => ['PPV', 'purse', 'unified champion', 'TKO', 'KO', 'submission', 'rear naked choke', 'southpaw', 'clinch', 'weigh-in', 'undisputed'],
                'tone_notes'  => 'Punchy, dramatic, respect for both athletes. Pre-fight and post-fight angles equally important. Controversy sells — report it fairly.',
                'hook_style'  => 'Open with the decisive exchange — the knockout punch, the tap out, or the controversial decision',
            ],
            [
                'slug'        => 'golf',
                'name'        => 'Golf',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Golf',
                'audience'    => 'Golf enthusiasts, PGA/LIV/DP World Tour followers, golf bettors',
                'terminology' => ['major', 'birdie', 'eagle', 'bogey', 'cut', 'FedEx Cup', 'world ranking', 'stroke play', 'match play', 'driving accuracy', 'GIR'],
                'tone_notes'  => 'Respectful tone befitting the sport. Statistical depth appreciated. Major championship context always adds weight. LIV vs PGA angle relevant.',
                'hook_style'  => 'Lead with the pivotal shot, the missed putt, or the number that defines the round — statistics anchor golf storytelling',
            ],

            // ── Motorsport ─────────────────────────────────────────────────────
            [
                'slug'        => 'formula-1',
                'name'        => 'Formula 1',
                'framework_id'=> $frameworkIds['motorsport'],
                'domain'      => 'Formula 1',
                'audience'    => 'F1 fans, Drive to Survive viewers, motorsport followers globally',
                'terminology' => ['DRS', 'undercut', 'overcut', 'VSC', 'SC', 'parc fermé', 'constructors', 'quali', 'pole position', 'fastest lap', 'DNF', 'stint'],
                'tone_notes'  => 'Race-broadcast energy. Technical accuracy for hardcore fans, accessible for casual viewers. Paddock politics add intrigue.',
                'hook_style'  => 'Lead with the race-defining moment — an overtake, a pit stop call, a collision, or a championship-clinching point',
            ],
            [
                'slug'        => 'nascar',
                'name'        => 'NASCAR',
                'framework_id'=> $frameworkIds['motorsport'],
                'domain'      => 'NASCAR',
                'audience'    => 'NASCAR fans, American motorsport followers, racing betting community',
                'terminology' => ['restrictor plate', 'superspeedway', 'loose', 'tight', 'stage points', 'playoff grid', 'green flag', 'caution', 'pit road', 'drafting'],
                'tone_notes'  => 'Southern-tinged energy, respect for the tradition. Sponsor storylines important. Playoff format context helps casual readers.',
                'hook_style'  => 'Lead with the bump-and-run, the last-lap pass, or the fiery crash — NASCAR lives in its boldest moments',
            ],

            // ── Luxury Assets ──────────────────────────────────────────────────
            [
                'slug'        => 'supercars',
                'name'        => 'Supercars',
                'framework_id'=> $frameworkIds['luxury_assets'],
                'domain'      => 'Supercars',
                'audience'    => 'Supercar enthusiasts, collectors, high-net-worth aspirational readers',
                'terminology' => ['hypercar', 'naturally aspirated', 'forced induction', 'carbon fiber', 'aerodynamic downforce', 'track-only', 'homologation', 'limited series', 'bespoke'],
                'tone_notes'  => 'Enthusiast precision meets aspirational luxury. Performance specs are sacred. Never oversell — the cars sell themselves with facts.',
                'hook_style'  => 'Lead with the spec that defines the car — 0-60 time, top speed, or a record broken at a famous circuit',
            ],
            [
                'slug'        => 'superyacht',
                'name'        => 'Superyacht',
                'framework_id'=> $frameworkIds['luxury_assets'],
                'domain'      => 'Superyacht',
                'audience'    => 'HNWI, superyacht owners, charter market, nautical lifestyle enthusiasts',
                'terminology' => ['LOA', 'beam', 'draft', 'GT', 'charter rate', 'refit', 'explorer yacht', 'displacement', 'naval architect', 'flag state', 'crew'],
                // Không dùng "extraordinary" (từ cấm của phase3, mà tone_notes được
                // bơm thẳng vào đó) và cũng không thay bằng tính từ cùng nhóm —
                // danh sách cấm đang loại dần nhóm đó. Viết bằng danh từ.
                'tone_notes'  => 'World of discretion, craftsmanship, and quiet excess. Technical naval specifications matter. Lifestyle and destination narrative elevates every story.',
                'hook_style'  => 'Lead with the scale — the LOA, the price, the number of guests — then immediately paint the lifestyle',
            ],

            // ── Travel & Mobility ──────────────────────────────────────────────
            [
                'slug'        => 'airline',
                'name'        => 'Airline',
                'framework_id'=> $frameworkIds['travel_mobility'],
                'domain'      => 'Commercial Aviation',
                'audience'    => 'Frequent flyers, travel hackers, business travelers, aviation enthusiasts',
                'terminology' => ['business class', 'first class', 'lounge', 'points', 'miles', 'alliance', 'codeshare', 'wide-body', 'narrow-body', 'on-time performance', 'load factor'],
                'tone_notes'  => 'Practical intelligence meets aspirational travel. Points/miles angle whenever relevant. Safety news treated seriously, factually.',
                'hook_style'  => 'Lead with the product upgrade, the new route, or the deal — the hook must have immediate practical value for the traveler',
            ],
            [
                'slug'        => 'tiny-home',
                'name'        => 'Tiny Home',
                'framework_id'=> $frameworkIds['travel_mobility'],
                'domain'      => 'Tiny Home Living',
                'audience'    => 'Minimalists, off-grid seekers, downsizers, sustainability-minded millennials',
                'terminology' => ['THOW', 'off-grid', 'solar', 'composting toilet', 'loft', 'zoning', 'ADU', 'sq ft', 'minimalist', 'sustainable', 'tiny house community'],
                'tone_notes'  => 'Warm, community-focused, aspirational but grounded in real-world practicality. Address challenges honestly — readers respect authenticity.',
                'hook_style'  => 'Lead with the transformation — the before vs after, the freedom gained, or the specific design solution that makes tiny living work',
            ],

            // ── Lifestyle Living ───────────────────────────────────────────────
            [
                'slug'        => 'moto-harley',
                'name'        => 'Moto Harley',
                'framework_id'=> $frameworkIds['lifestyle_living'],
                'domain'      => 'Harley-Davidson & Motorcycle Culture',
                'audience'    => 'Harley owners, cruiser riders, motorcycle culture enthusiasts',
                'terminology' => ['V-Twin', 'Milwaukee-Eight', 'Revolution Max', 'Softail', 'Touring', 'Sportster', 'Dyna', 'cc', 'chrome', 'custom build', 'H.O.G.', 'bars'],
                'tone_notes'  => 'Brotherhood energy. Respect for the iron and the road. Technical specs matter but community and culture matter more.',
                // "the long road" chứ không phải "the journey": từ sau là từ cấm của
                // phase3, mà hook_style được bơm thẳng vào đó.
                'hook_style'  => 'Lead with the rider or the machine — either the thunder of the engine or the quiet dignity of the long road',
            ],

            // ── Knowledge & Discovery ──────────────────────────────────────────
            [
                'slug'        => 'science',
                'name'        => 'Science',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'General Science',
                'audience'    => 'Science-curious general readers, students, professionals seeking plain-English science news',
                'terminology' => ['peer-reviewed', 'control group', 'double-blind', 'hypothesis', 'correlation vs causation', 'statistical significance', 'p-value', 'meta-analysis'],
                'tone_notes'  => 'Evidence-first. Anti-hype. Always include limitations. Explain methodology briefly. Make complex ideas viscerally concrete.',
                'hook_style'  => 'Lead with the surprising finding — not the methodology — then immediately explain why it matters',
            ],
            [
                'slug'        => 'astronomy',
                'name'        => 'Astronomy',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Astronomy & Space',
                'audience'    => 'Space enthusiasts, amateur astronomers, science readers fascinated by the cosmos',
                'terminology' => ['light-year', 'parsec', 'redshift', 'exoplanet', 'black hole', 'neutron star', 'event horizon', 'dark matter', 'dark energy', 'spectroscopy', 'JWST'],
                'tone_notes'  => 'Awe-inspiring but grounded. Scale analogies help (a light-year = X times around the Earth). Telescope/mission context adds credibility.',
                'hook_style'  => 'Lead with the scale or the wonder — an image, a distance, an impossible fact — then ground it in what scientists learned',
                'custom_type_triggers' => [
                    'milestone' => ['JWST', 'James Webb', 'NASA', 'ESA', 'launched', 'orbit', 'landed', 'probe', 'telescope', 'space mission'],
                ],
            ],
            [
                'slug'        => 'health',
                'name'        => 'Health',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Health & Medicine',
                'audience'    => 'Health-conscious adults, patients, caregivers, wellness-minded millennials',
                'terminology' => ['clinical trial', 'randomized', 'FDA', 'WHO', 'BMI', 'cardiovascular', 'gut microbiome', 'inflammation', 'metabolic', 'placebo', 'dosage'],
                'tone_notes'  => 'Reassuring but honest. Never alarmist, never dismissive. Medical advice disclaimer where appropriate. Always cite the research institution.',
                'hook_style'  => 'Lead with the practical implication for the reader — not the lab finding — then explain the science that supports it',
                'custom_type_triggers' => [
                    'warning' => ['study warns', 'linked to', 'risk of', 'causes', 'recall', 'FDA', 'WHO'],
                ],
            ],
            [
                'slug'        => 'ai-technology',
                'name'        => 'AI Technology',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Artificial Intelligence & Technology',
                'audience'    => 'Tech-savvy professionals, AI enthusiasts, business leaders tracking AI adoption',
                'terminology' => ['LLM', 'transformer', 'neural network', 'fine-tuning', 'inference', 'training data', 'benchmark', 'hallucination', 'AGI', 'RAG', 'prompt engineering'],
                'tone_notes'  => 'Technically credible without being elitist. Avoid both hype and doom. Practical implications anchor every story. Open-source vs closed-source angle often relevant.',
                'hook_style'  => 'Lead with the capability or the use case — what this AI can do that wasn\'t possible before — then explain the how and the so-what',
            ],

            // ── Entertainment & Viral ──────────────────────────────────────────
            [
                'slug'        => 'showbiz',
                'name'        => 'Showbiz',
                'framework_id'=> $frameworkIds['entertainment_viral'],
                'domain'      => 'Entertainment & Celebrity',
                'audience'    => 'Pop culture fans, celebrity followers, entertainment news readers',
                'terminology' => ['box office', 'streaming', 'Rotten Tomatoes', 'A-list', 'showrunner', 'pilot season', 'upfronts', 'premiere', 'BAFTA', 'Grammy', 'Oscar'],
                'tone_notes'  => 'Witty, warm, culturally aware. Never mean-spirited. Fan perspective matters. Box office and streaming numbers add authority.',
                'hook_style'  => 'Lead with the moment that everyone will be talking about — the speech, the reunion, the surprise drop, the shocking twist',
            ],
            [
                'slug'        => 'weird-news',
                'name'        => 'Weird News',
                'framework_id'=> $frameworkIds['entertainment_viral'],
                'domain'      => 'Weird & Viral News',
                'audience'    => 'General online readers seeking entertainment, water-cooler conversation fodder',
                'terminology' => ['viral', 'trending', 'social media', 'Reddit', 'TikTok', 'internet', 'meme', 'reaction video'],
                'tone_notes'  => 'Playful but never cruel. The humor comes from the situation, not from mocking people. Light touch on editorializing — let the weirdness speak.',
                'hook_style'  => 'Lead with the weirdest fact first — no buildup, no preamble — the hook IS the story in weird news',
            ],

        ];
    }
}
