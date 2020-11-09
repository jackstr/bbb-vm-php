<?php declare(strict_types=1);
namespace BBBondemand\Util;

use PHPUnit\Framework\TestCase;

class UrlBuilderTest extends TestCase
{
    public function testBuildUrl()
    {
        $apiServerBaseUrl = 'https://bbbondemand.com/api/v1';
        $customerId = 'someCustomerId';
        $urlBuilder = new UrlBuilder('someCustomerId', $apiServerBaseUrl);
        $params = ['myParam' => 'myVal'];
        $queryStr = 'foo=bar';
        $url = $urlBuilder->buildUrl('instances/{myParam}/start', $params, $queryStr);
        $this->assertSame($apiServerBaseUrl . '/' . $customerId . '/vm/instances/' . $params['myParam'] . '/start?' . $queryStr, $url);
    }
}
