<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The server's plain address opens the download page and user manual (public/download/). */
    public function test_the_home_address_opens_the_download_page(): void
    {
        $this->get('/')->assertRedirect('/download/index.html');
        $this->get('/download')->assertRedirect('/download/index.html');
        $this->assertFileExists(public_path('download/index.html'));
    }
}
