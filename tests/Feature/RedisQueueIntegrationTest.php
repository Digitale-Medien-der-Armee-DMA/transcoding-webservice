<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\Fixtures\FailingRedisJob;
use Tests\Fixtures\MissingModelRedisJob;
use Tests\Fixtures\RedisMarkerJob;
use Tests\TestCase;

class RedisQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = 'redis-integration';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_REDIS_INTEGRATION') !== '1' || !extension_loaded('redis')) {
            $this->markTestSkipped('Redis integration environment is not enabled.');
        }

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'default',
            'queue.connections.redis.queue' => self::QUEUE,
            'queue.connections.redis.retry_after' => 60,
            'queue.connections.redis.block_for' => 1,
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => 'transcoding_ci_' . getmypid() . '_',
            'database.redis.default.host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'database.redis.default.port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database.redis.default.database' => 15,
        ]);

        app('redis')->purge('default');
        Queue::connection('redis')->clear(self::QUEUE);
    }

    protected function tearDown(): void
    {
        if (getenv('RUN_REDIS_INTEGRATION') === '1' && extension_loaded('redis')) {
            Queue::connection('redis')->clear(self::QUEUE);
            Redis::del('redis-marker', 'redis-failure', 'redis-missing-model');
        }

        parent::tearDown();
    }

    public function test_phpredis_blocking_pop_and_worker_processing_use_typed_configuration(): void
    {
        $this->assertIsInt(config('queue.connections.redis.block_for'));
        $this->assertNull(Queue::connection('redis')->pop(self::QUEUE));

        RedisMarkerJob::dispatch('redis-marker')->onQueue(self::QUEUE);

        $metrics = app(QueueMetrics::class)->collect([self::QUEUE]);
        $this->assertSame(1, $metrics[self::QUEUE]['waiting']);
        $this->assertSame(0, $metrics[self::QUEUE]['running']);
        $this->assertNotNull($metrics[self::QUEUE]['oldest_waiting_age_seconds']);

        $this->runOneWorker();

        $this->assertSame('handled', Redis::get('redis-marker'));
        $this->assertSame(0, app(QueueMetrics::class)->collect([self::QUEUE])[self::QUEUE]['waiting']);
        $this->assertDatabaseHas('workers', ['host' => gethostname()]);
    }

    public function test_reserved_jobs_are_reported_as_running(): void
    {
        RedisMarkerJob::dispatch('redis-marker')->onQueue(self::QUEUE);
        $job = Queue::connection('redis')->pop(self::QUEUE);

        $metrics = app(QueueMetrics::class)->collect([self::QUEUE]);
        $this->assertSame(0, $metrics[self::QUEUE]['waiting']);
        $this->assertSame(1, $metrics[self::QUEUE]['running']);

        $job->delete();
    }

    public function test_failed_job_preserves_original_exception(): void
    {
        FailingRedisJob::dispatch('redis-failure')->onQueue(self::QUEUE);

        $this->runOneWorker();

        $this->assertSame('redis-integration-failure', Redis::get('redis-failure'));
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertStringContainsString(
            'redis-integration-failure',
            DB::table('failed_jobs')->value('exception')
        );
    }

    public function test_missing_serialized_model_is_discarded_without_failed_job(): void
    {
        $user = $this->createUser();
        MissingModelRedisJob::dispatch($user, 'redis-missing-model')->onQueue(self::QUEUE);
        $user->delete();

        $this->runOneWorker();

        $this->assertNull(Redis::get('redis-missing-model'));
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    private function runOneWorker(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => self::QUEUE,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ]);
    }

    private function createUser(): User
    {
        DB::table('profiles')->insert([
            'id' => 1,
            'encoder' => 'libx264',
            'fallback_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = new User();
        $user->name = 'Redis Test';
        $user->email = 'redis@example.org';
        $user->password = bcrypt('secret');
        $user->api_token = str_repeat('b', 32);
        $user->url = 'http://vimp.test';
        $user->profile_id = 1;
        $user->save();

        return $user;
    }
}
