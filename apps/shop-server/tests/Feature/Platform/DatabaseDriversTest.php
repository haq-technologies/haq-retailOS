<?php

declare(strict_types=1);

use App\Identity\Enrolment;
use App\Printing\PrintReceipt;
use App\Sales\Finish;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Counter;

/*
 * A shop keeps its cache, sessions and queued jobs in PostgreSQL; there is no Redis (spec section 4). The
 * other tests use Laravel's in-memory drivers to stay quick, so these run the real ones a shop uses.
 */

it('keeps one-time codes and locks in the PostgreSQL cache', function () {
    config(['cache.default' => 'database']);
    [$t1] = Counter::ready(items: 1)['terminals'];

    $code = app(Enrolment::class)->issueCode($t1);
    expect(DB::table('cache')->where('key', 'like', '%identity.enrol.%')->count())->toBe(1);
    expect(app(Enrolment::class)->redeem($code))->toStartWith($t1->id.'.');
    expect(app(Enrolment::class)->redeem($code))->toBeNull();

    $lock = Cache::lock('test.lock', 10);
    expect($lock->get())->toBeTrue();
    expect(Cache::lock('test.lock', 10)->get())->toBeFalse();
    $lock->release();
});

it('keeps a browser\'s session in PostgreSQL', function () {
    config(['session.driver' => 'database']);

    $this->get('/enrol')->assertOk();
    expect(DB::table('sessions')->count())->toBe(1);
});

it('queues a finished sale\'s receipt in PostgreSQL, once the sale has committed', function () {
    config(['queue.default' => 'database']);
    $counter = Counter::ready(items: 1);
    [$t1] = $counter['terminals'];
    [$item] = $counter['items'];

    app(Finish::class)->finish($t1, $counter['cashier'], Counter::request(Counter::cashSale([$item->id => [1, 5000]])));

    $job = DB::table('jobs')->where('queue', 'print')->first(['payload']);
    expect($job)->not->toBeNull();
    expect((string) $job->payload)->toContain(addslashes(PrintReceipt::class));
});
