<?php

declare(strict_types=1);

namespace Umbrellio\Jaravel\Tests\Unit\Configurations\Guzzle;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Umbrellio\Jaravel\Configurations\Guzzle\TagsResolver;

class TagsResolverTest extends TestCase
{
    public function testResolve(): void
    {
        $resolver = new TagsResolver();
        $request = new Request('post', 'https://test.com', [], 'foo=bar');

        $result = $resolver($request);
        $this->assertSame([
            'type' => 'request',
            'uri' => 'https://test.com',
            'method' => 'POST',
            'body' => 'foo=bar',
            'headers' => json_encode($request->getHeaders())
        ], $result);
    }
}
