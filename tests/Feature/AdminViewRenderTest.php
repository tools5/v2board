<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminViewRenderTest extends TestCase
{
    public function testAdminViewRendersSettingsAsJson(): void
    {
        $response = $this->get('/' . config('v2board.secure_path'));

        $response->assertOk();
        $response->assertSee('window.settings = {', false);
        $response->assertSee('"secure_path":"' . config('v2board.secure_path') . '"', false);
    }
}
