<?php

$screenplayFiles = [
    '00_writer_contract.md',
    '03_example_guidance.txt',
    '04_worked_example.input.json',
    '04_worked_example.json',
    '04_worked_example.md',
];

return [

    /*
    | file      moi consumer doc file nhu truoc (code cu giu nguyen tung dong).
    | database  doc qua PromptResolver va pin revision vao stage khi claim.
    |           Loi o DB thi dung, KHONG lui ve file (lui ve se ghi sai nguon goc).
    | Doi ve "file" la rollback. Truoc khi bat database: prompts:sync roi prompts:verify.
    */
    'source' => env('PROMPT_SOURCE', 'file'),

    /*
    | Prompt dang chay cua pipeline va noi file nguon cua chung (gia tri la KEY
    | config, khong chep lai duong dan). prompts:sync nap cac file nay thanh
    | document + binding; prompts:verify so file voi DB tung byte.
    |   files  section_key => key config tro toi file
    |   dir    key config tro toi thu muc; files khi do la danh sach ten file
    |   version  key config cua version (mang thi noi bang "+")
    */
    'consumers' => [
        'video.storyboard' => [
            'document' => 'video.storyboard_v4',
            'version' => 'video.scene_plan.prompt_version',
            'files' => ['body' => 'video.scene_plan.prompt_path'],
        ],
        'video.screenplay.design' => [
            'document' => 'video.screenplay_design',
            'version' => 'video.screenplay.design.prompt_version',
            'dir' => 'video.screenplay.design.prompt_dir',
            'files' => $screenplayFiles,
        ],
        'video.screenplay.story' => [
            'document' => 'video.screenplay_story',
            'version' => 'video.screenplay.story.prompt_version',
            'dir' => 'video.screenplay.story.prompt_dir',
            'files' => $screenplayFiles,
        ],
        'video.screenplay.locations' => [
            'document' => 'video.screenplay_locations',
            'version' => 'video.screenplay.locations.prompt_version',
            'dir' => 'video.screenplay.locations.prompt_dir',
            'files' => $screenplayFiles,
        ],
        'video.screenplay.scenes' => [
            'document' => 'video.screenplay_scenes',
            'version' => 'video.screenplay.scenes.prompt_version',
            'dir' => 'video.screenplay.scenes.prompt_dir',
            'files' => $screenplayFiles,
        ],
        'image.geometry_reference' => [
            'document' => 'image.geometry_reference',
            'version' => 'image_prompt.prompt_version',
            'files' => ['body' => 'image_prompt.prompt_path'],
        ],
        'image.character_reference' => [
            'document' => 'image.character_reference',
            'version' => 'image_prompt.character_prompt_version',
            'files' => ['body' => 'image_prompt.character_prompt_path'],
        ],
        'image.design_anchor' => [
            'document' => 'image.design_anchor',
            'version' => ['image_prompt.design_anchor.prompt_version', 'image_prompt.design_anchor.geometry_version'],
            'files' => [
                'template' => 'image_prompt.design_anchor.prompt_path',
                'geometry_template' => 'image_prompt.design_anchor.geometry_template_path',
            ],
        ],
        'image.anchor_geometry' => [
            'document' => 'image.anchor_geometry',
            'version' => 'image_prompt.anchor_geometry.prompt_version',
            'files' => ['body' => 'image_prompt.anchor_geometry.prompt_path'],
        ],
        'image.reference_view' => [
            'document' => 'image.reference_view',
            'version' => 'image_prompt.reference.prompt_version',
            'files' => ['body' => 'image_prompt.reference.prompt_path'],
        ],
        'image.reference_plan' => [
            'document' => 'image.reference_plan',
            'version' => 'image_prompt.reference_plan.prompt_version',
            'files' => ['body' => 'image_prompt.reference_plan.prompt_path'],
        ],
    ],

    /*
    | Bump moi khi doi cach PromptCompiler ghep section (thu tu, ky tu noi, luat
    | merge). Gia tri nay vao manifest cua moi lan goi model, nen hai prompt cung
    | revision nhung khac compiler van phan biet duoc.
    */
    'compiler_version' => 'prompt-compiler-v1',

    /*
    | Thu tu lop khi ghep: lop dung sau ghi de/noi tiep lop dung truoc. `priority`
    | trong ai_prompt_scope_bindings chi so sanh trong CUNG mot loai scope.
    */
    'scope_order' => ['global', 'workflow', 'category', 'channel', 'project'],

    /*
    | Thu tu slot trong prompt cuoi cung. Section nam o slot khong co trong danh
    | sach nay thi resolver tu choi, khong tu xep cho no.
    */
    'slots' => [
        'system.identity',
        'rules.safety',
        'rules.domain',
        'instructions.task',
        'output.contract',
        'examples',
        'judge.rubric',
    ],

    /*
    | Ky tu noi giua hai section lien ke. Mot section duy nhat thi prompt bang
    | dung tung byte than section, khong them gi.
    */
    'separator' => "\n\n",

    /*
    | Section bat buoc theo tai lieu, kiem khi import (thieu thi tu choi).
    | Key la "<namespace>.<key>" cua document.
    */
    'document_requirements' => [
        'video.superyacht_v6_3' => [
            'system-prompt', 'common-standards-apply-to-every-phase',
            'phase-0', 'phase-1', 'phase-2', 'phase-3', 'phase-3p', 'phase-3r', 'phase-3b', 'phase-4', 'phase-4m',
            'phase-5', 'phase-5l', 'phase-6', 'phase-6c', 'phase-7', 'phase-7a', 'phase-8', 'phase-8p', 'phase-9',
            'phase-9h', 'phase-9q', 'phase-10',
        ],
    ],
];
