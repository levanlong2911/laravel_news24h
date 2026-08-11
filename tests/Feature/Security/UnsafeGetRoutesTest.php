<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class UnsafeGetRoutesTest extends TestCase
{
    public function test_destructive_admin_routes_only_accept_delete(): void
    {
        $names = [
            'admin.delete',
            'admin.category.delete',
            'tag.delete',
            'news-web.delete',
            'post.delete',
            'domain.delete',
            'ads.delete',
            'font.delete',
            'website.delete',
            'prompt-framework.delete',
        ];

        foreach ($names as $name) {
            $this->assertSame(['DELETE'], app('router')->getRoutes()->getByName($name)->methods(), $name);
        }
    }

    public function test_logout_only_accepts_post(): void
    {
        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('logout')->methods());
    }
}
