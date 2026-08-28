<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The baseline. Nothing else in the suite means anything while these are red,
 * because a red baseline makes every later failure ambiguous: you can no
 * longer tell a real regression from a broken harness.
 *
 * These also prove the harness itself works end to end on real Postgres:
 * migrations apply, the seeder runs, and the routes a judge hits first all
 * respond.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_health_endpoint_reports_a_live_database(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'database' => 'connected',
            ]);
    }

    public function test_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_editor_page_renders(): void
    {
        $this->get('/editor')->assertOk();
    }

    public function test_the_seeder_produced_the_demo_site(): void
    {
        $this->getJson('/api/pages')
            ->assertOk()
            ->assertJsonStructure([
                'pages' => [
                    ['id', 'slug', 'title'],
                ],
            ]);
    }

    /**
     * Postgres-only behaviour, asserted on Postgres.
     *
     * MediaController::index searches tags with the array overlap operator
     * against a GIN index. That query cannot run on SQLite at all, which is
     * the whole reason phpunit.xml points at pgsql.
     */
    public function test_media_search_uses_the_postgres_array_path(): void
    {
        $this->getJson('/api/media?q=studio')
            ->assertOk()
            ->assertJsonStructure([
                'assets',
                'total',
            ]);
    }
}
