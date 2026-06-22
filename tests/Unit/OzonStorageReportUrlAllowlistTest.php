<?php

namespace Tests\Unit;

use App\Domains\Ozon\Api\StorageApi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Allow-list скачивания отчётов Ozon: пускаем ozon.ru И ozone.ru (Ozon отдаёт
 * файлы с обоих), но ничего постороннего и только по https — иначе SSRF.
 */
class OzonStorageReportUrlAllowlistTest extends TestCase
{
    private function allowed(string $url): bool
    {
        $rc = new ReflectionClass(StorageApi::class);
        $api = $rc->newInstanceWithoutConstructor(); // isAllowedOzonReportUrl не трогает client
        $m = $rc->getMethod('isAllowedOzonReportUrl');
        $m->setAccessible(true);

        return $m->invoke($api, $url);
    }

    public function test_allows_ozon_and_ozone_domains(): void
    {
        $this->assertTrue($this->allowed('https://ir.ozone.ru/report.xlsx'));
        $this->assertTrue($this->allowed('https://ozone.ru/x'));
        $this->assertTrue($this->allowed('https://ozon.ru/x'));
        $this->assertTrue($this->allowed('https://cdn.ozon.ru/x'));
    }

    public function test_rejects_non_ozon_and_non_https(): void
    {
        $this->assertFalse($this->allowed('https://evil.com/x'));
        $this->assertFalse($this->allowed('http://ir.ozone.ru/x'));      // не https
        $this->assertFalse($this->allowed('https://ozone.ru.evil.com/x')); // подделка домена
    }
}
