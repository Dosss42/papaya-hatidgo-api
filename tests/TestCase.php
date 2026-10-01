<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's test client sends "Accept-Language: en-us" by default (Symfony's
        // Request::create), which would make every test an ENGLISH request. Send no language
        // instead, like the app before a language is chosen, so the default (Taglish) applies.
        // A test that wants English says so: $this->withHeader('Accept-Language', 'en').
        $this->withHeader('Accept-Language', '');
    }
}
