<?php

namespace Tests\Unit;

use App\Services\PostingService;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

class OzonPostingsIncrementalSinceTest extends TestCase
{
    public function test_with_watermark_uses_incremental_window_minus_overlap(): void
    {
        $watermark = Carbon::parse('2026-07-06 08:00:00');

        $since = $this->ozonPostingsSince($watermark, now()->subDays(90)->format('Y-m-d'));

        // Знак минус 5 дней overlap — а НЕ 90-дневный бэкфилл.
        $this->assertSame('2026-07-01 08:00:00', $since->format('Y-m-d H:i:s'));
    }

    public function test_without_watermark_uses_explicit_backfill_date(): void
    {
        $since = $this->ozonPostingsSince(null, '2026-04-01');

        $this->assertSame('2026-04-01', $since->format('Y-m-d'));
    }

    public function test_without_watermark_and_without_date_falls_back_to_90_days(): void
    {
        Carbon::setTestNow('2026-07-06 12:00:00');

        $since = $this->ozonPostingsSince(null, null);

        $this->assertSame('2026-04-07', $since->format('Y-m-d')); // 90 дней назад
        Carbon::setTestNow();
    }

    private function ozonPostingsSince(?Carbon $watermark, ?string $dateFrom): Carbon
    {
        $method = (new ReflectionClass(PostingService::class))->getMethod('ozonPostingsSince');
        $method->setAccessible(true);

        return $method->invoke(new PostingService, $watermark, $dateFrom);
    }
}
