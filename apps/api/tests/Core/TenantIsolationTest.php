<?php

namespace Tests\Core;

use App\Core\Tenancy\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Core\Fixtures\ProbeRecord;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Not RefreshDatabase: on Postgres in CI the table must not survive into the next test.
        Schema::dropIfExists('probe_records');
        Schema::create('probe_records', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id');
            $table->string('label');
        });

        $this->tenant = app(TenantContext::class);

        $this->tenant->runUnscoped(function (): void {
            ProbeRecord::query()->create(['shop_id' => 'shop-a', 'label' => 'a1']);
            ProbeRecord::query()->create(['shop_id' => 'shop-a', 'label' => 'a2']);
            ProbeRecord::query()->create(['shop_id' => 'shop-b', 'label' => 'b1']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('probe_records');
        parent::tearDown();
    }

    public function test_querying_without_a_shop_throws_instead_of_returning_every_shop(): void
    {
        $this->expectException(MissingTenantContext::class);

        ProbeRecord::query()->get();
    }

    public function test_queries_see_only_the_current_shop(): void
    {
        $labels = $this->tenant->run('shop-a', fn () => ProbeRecord::query()->orderBy('label')->pluck('label')->all());

        $this->assertSame(['a1', 'a2'], $labels);
        $this->assertNull($this->tenant->run('shop-a', fn () => ProbeRecord::query()->where('label', 'b1')->first()));
    }

    public function test_created_records_take_the_current_shop(): void
    {
        $record = $this->tenant->run('shop-b', fn () => ProbeRecord::query()->create(['label' => 'b2']));

        $this->assertSame('shop-b', $record->shop_id);
    }

    public function test_creating_without_any_shop_fails(): void
    {
        $this->expectException(MissingTenantContext::class);

        $this->tenant->runUnscoped(fn () => ProbeRecord::query()->create(['label' => 'orphan']));
    }

    public function test_unscoped_mode_is_explicit_and_sees_every_shop(): void
    {
        $this->assertSame(3, $this->tenant->runUnscoped(fn () => ProbeRecord::query()->count()));
        $this->assertFalse($this->tenant->isUnscoped(), 'unscoped mode ends with the callback');
    }

    public function test_run_restores_the_previous_shop_even_when_the_callback_throws(): void
    {
        $this->tenant->set('shop-a');

        try {
            $this->tenant->run('shop-b', fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertSame('shop-a', $this->tenant->id());
    }

    public function test_each_request_gets_a_fresh_context(): void
    {
        $this->tenant->set('shop-a');
        $this->app->forgetScopedInstances();

        $this->assertNull(app(TenantContext::class)->id());
    }
}
