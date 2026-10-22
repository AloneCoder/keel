<?php

declare(strict_types=1);

namespace Keel\Tests\Http;

use Keel\Container\Definitions;
use Keel\Http\ArgumentResolver;
use Keel\Http\Attribute\FromCookie;
use Keel\Http\Attribute\FromHeader;
use Keel\Http\Exception\BadRequestException;
use Keel\Http\Hydrator;
use Keel\Http\Input;
use Keel\Kernel\Kernel;
use Keel\Testing\TestClient;
use Keel\Tests\Http\Fixtures\InputController;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Input::class)]
#[CoversClass(FromHeader::class)]
#[CoversClass(FromCookie::class)]
#[CoversClass(ArgumentResolver::class)]
#[CoversClass(Hydrator::class)]
final class InputTest extends TestCase
{
    public function testMergesQueryAndBodyWithTypedReaders(): void
    {
        $request = new ServerRequest('POST', 'https://app.test/x?page=2&name=query&flag=true', ['Content-Type' => 'application/json'], '{"name":"body","qty":"3","price":"9.5","tags":["a"],"address":{"city":"Riga"}}', serverParams: ['REMOTE_ADDR' => '203.0.113.9']);
        $input = new Input($request);

        self::assertSame('body', $input->get('name'), 'the body shadows the query');
        self::assertSame(2, $input->int('page'));
        self::assertSame(3, $input->int('qty'));
        self::assertSame(9.5, $input->float('price'));
        self::assertTrue($input->bool('flag'));
        self::assertSame('Riga', $input->get('address.city'));
        self::assertNull($input->get('address.zip'));
        self::assertSame('x', $input->string('missing', 'x'));
        self::assertNull($input->int('missing'));
        self::assertTrue($input->has('tags'));
        self::assertFalse($input->has('nope'));
        self::assertTrue($input->filled('name'));
        self::assertSame(['page' => '2', 'name' => 'body'], $input->only('page', 'name', 'nope'));
        self::assertSame(['page', 'flag', 'qty', 'price', 'tags', 'address'], array_keys($input->except('name')));
        self::assertSame(['page' => '2', 'name' => 'query', 'flag' => 'true'], $input->query());
        self::assertSame(['a'], $input->array('tags'));
        self::assertSame('203.0.113.9', $input->ip());
        self::assertSame('POST', $input->method());
        self::assertSame('/x', $input->path());
        self::assertTrue($input->isSecure());
        self::assertTrue($input->isJson());
        self::assertTrue($input->wantsJson(), 'no Accept but a JSON body');
        self::assertFalse($input->isAjax());
        self::assertSame($request, $input->request());

        try {
            $input->int('name');
            self::fail('cast accepted');
        } catch (BadRequestException $e) {
            self::assertSame(['name: must be an integer'], $e->errors);
        }

        $this->expectException(BadRequestException::class);
        $input->array('name');
    }

    public function testHeadersCookiesFilesAndNegotiation(): void
    {
        $file = new UploadedFile('data', 4, \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $request = new ServerRequest('GET', 'http://app.test', ['Authorization' => 'Bearer t0k', 'Accept' => 'text/html, application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->withCookieParams(['theme' => 'dark'])
            ->withUploadedFiles(['doc' => $file, 'nested' => ['x' => $file]]);
        $input = new Input($request);

        self::assertSame('t0k', $input->bearerToken());
        self::assertSame('dark', $input->cookie('theme'));
        self::assertSame('light', $input->cookie('missing', 'light'));
        self::assertSame('text/html, application/json', $input->header('Accept'));
        self::assertNull($input->header('X-None'));
        self::assertSame($file, $input->file('doc'));
        self::assertNull($input->file('nested'));
        self::assertSame(['doc' => $file], $input->files());
        self::assertFalse($input->wantsJson(), 'HTML listed first');
        self::assertTrue($input->isAjax());
        self::assertFalse($input->isSecure());
        self::assertSame([], $input->all(), 'GET: no body is read');
        self::assertNull($input->ip());
        self::assertNull(new Input(new ServerRequest('GET', '/', ['Authorization' => 'Basic abc']))->bearerToken());
        self::assertTrue(new Input(new ServerRequest('GET', '/', ['Accept' => 'application/vnd.api+json;q=0.9']))->wantsJson());
    }

    public function testHandlersReceiveInputHeadersAndCookies(): void
    {
        $client = TestClient::forKernel(Kernel::build(new Definitions(), [InputController::class]));

        $client->json('POST', '/items/7?qty=5', ['name' => 'Ann', 'tags' => ['x'], 'address' => ['city' => 'Oslo']])
            ->assertOk()
            ->assertJson(['id' => '7', 'name' => 'Ann', 'qty' => 5, 'tags' => ['x'], 'city' => 'Oslo', 'only' => ['name' => 'Ann', 'qty' => '5'], 'json' => true, 'wants' => true]);
        $client->post('/items/7', ['qty' => 'many'])->assertProblem(400, 'Invalid request data', ['qty: must be an integer']);

        $client->get('/scalars', ['X-Count' => '4'])->assertOk()->assertJson(['count' => 4, 'theme' => 'light', 'flag' => null]);
        $client->withCookie('theme', 'dark')->get('/scalars', ['X-Count' => '1', 'X-Flag' => 'true'])->assertOk()->assertJson(['theme' => 'dark', 'flag' => true]);
        $client->get('/scalars')->assertProblem(400, 'Missing header "X-Count"');
        $client->get('/scalars', ['X-Count' => 'x'])->assertProblem(400, 'Invalid request data', ['X-Count: must be an integer']);

        $this->expectException(\InvalidArgumentException::class);
        new FromHeader('');
    }

    public function testTypedCastsFollowHydration(): void
    {
        $hydrator = new Hydrator();
        self::assertSame('5', $hydrator->string(5, 'n'));
        self::assertSame(5, $hydrator->int('5', 'n'));
        self::assertSame(1.5, $hydrator->float('1.5', 'n'));
        self::assertFalse($hydrator->bool('0', 'n'));
        $this->expectException(BadRequestException::class);
        $hydrator->bool('maybe', 'n');
    }
}
