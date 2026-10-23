<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Security;
use PHPUnit\Framework\Attributes\Group;

/**
 * @internal
 */
#[Group('feature')]
final class HtmxCsrfTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        service('superglobals')->setServer('HTTPS', 'on');
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function htmx(array $extra = []): array
    {
        return ['HX-Request' => 'true'] + $extra;
    }

    private function token(): string
    {
        return csrf_hash();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $post
     */
    private function post(array $headers, array $post): TestResponse
    {
        return $this->withHeaders($headers)->call('post', '/demo/calculate', $post);
    }

    public function testFullPageWithoutHtmx(): void
    {
        $result = $this->get('/demo');
        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('hx-post="/demo/calculate"', $body);
        $this->assertStringContainsString('HX-Request', $result->response()->getHeaderLine('Vary'));
    }

    public function testFragmentOnHtmxRequest(): void
    {
        $result = $this->withHeaders($this->htmx())->get('/demo');
        $result->assertStatus(200);
        $this->assertStringNotContainsString('<html', (string) $result->response()->getBody());
        $this->assertStringContainsString('hx-post=', (string) $result->response()->getBody());
    }

    public function testHistoryRestoreReturnsFullPage(): void
    {
        $result = $this->withHeaders($this->htmx(['HX-History-Restore-Request' => 'true']))->get('/demo');
        $this->assertStringContainsString('<html', (string) $result->response()->getBody());
    }

    public function testBoostedReturnsFullPage(): void
    {
        $result = $this->withHeaders($this->htmx(['HX-Boosted' => 'true']))->get('/demo');
        $this->assertStringContainsString('<html', (string) $result->response()->getBody());
    }

    public function testValidPostComputesSum(): void
    {
        $result = $this->post($this->htmx(['X-CSRF-TOKEN' => $this->token()]), ['hours' => '1,25', 'rate' => '27.00']);
        $result->assertStatus(200);
        $this->assertStringContainsString('33.75', (string) $result->response()->getBody());
        $this->assertStringNotContainsString('<html', (string) $result->response()->getBody());
    }

    public function testMissingTokenHtmxGives403Fragment(): void
    {
        $result = $this->post($this->htmx(), ['hours' => '1', 'rate' => '1']);
        $result->assertStatus(403);
        $this->assertSame('#app-alert', $result->response()->getHeaderLine('HX-Retarget'));
        $this->assertSame('innerHTML', $result->response()->getHeaderLine('HX-Reswap'));
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString(esc(lang('App.csrfFailed')), $body);
        $this->assertStringNotContainsString('<html', $body);
        $this->assertSame(view('fragments/alert', ['message' => lang('App.csrfFailed')]), $body);
    }

    /**
     * CR-01: 403 sa musi swapnut (do #app-alert cez HX-Retarget) a app.js ho
     * nesmie prepisat vseobecnou chybou. Spravanie JS overuje tests/js/app-alert.test.js
     * (node --test, v CI a v make smoke). Tu sa strazi kontrakt layout <-> app.js.
     */
    public function testLayoutSwaps403IntoVisibleAlert(): void
    {
        $body = (string) $this->get('/demo')->response()->getBody();

        $this->assertMatchesRegularExpression('/<div id="app-alert"[^>]*\shidden>/', $body);
        $this->assertSame(1, preg_match('/<meta name="htmx-config" content="([^"]+)">/', $body, $m));
        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true, 16, JSON_THROW_ON_ERROR);

        $rule = null;

        foreach ($config['responseHandling'] as $r) {
            if (preg_match('/^' . $r['code'] . '$/', '403') === 1) {
                $rule = $r;
                break;
            }
        }
        $this->assertIsArray($rule);
        $this->assertTrue($rule['swap']);

        $js = (string) file_get_contents(FCPATH . 'assets/js/app.js');
        $this->assertStringContainsString("'htmx:afterSwap'", $js);
        $this->assertMatchesRegularExpression("/'htmx:responseError', function \\(e\\) \\{\\s*if \\(targetsAlert\\(e\\)\\) \\{ return; \\}/", $js);
        $this->assertFileExists(ROOTPATH . 'tests/js/app-alert.test.js');
    }

    public function testMissingTokenWithoutHtmxGivesFullPage403NotRedirect(): void
    {
        $this->assertFalse(config(Security::class)->redirect);

        $result = $this->post([], ['hours' => '1', 'rate' => '1']);
        $result->assertStatus(403);
        $this->assertStringContainsString('<html', (string) $result->response()->getBody());
        $this->assertNotContains($result->response()->getStatusCode(), [302, 303]);
    }

    public function testInvalidTokenGives403(): void
    {
        $result = $this->post($this->htmx(['X-CSRF-TOKEN' => 'invalid']), ['hours' => '1', 'rate' => '1']);
        $result->assertStatus(403);
    }

    public function testEmptyAndNonNumericGives422(): void
    {
        $result = $this->post($this->htmx(['X-CSRF-TOKEN' => $this->token()]), ['hours' => '', 'rate' => 'abc']);
        $result->assertStatus(422);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('is-invalid', $body);
        $this->assertStringNotContainsString('field is required', $body);
    }

    public function testThreeDecimalsGives422(): void
    {
        $result = $this->post($this->htmx(['X-CSRF-TOKEN' => $this->token()]), ['hours' => '1.234', 'rate' => '1']);
        $result->assertStatus(422);
    }

    public function testOutOfRangeGives422(): void
    {
        $result = $this->post($this->htmx(['X-CSRF-TOKEN' => $this->token()]), ['hours' => '100000', 'rate' => '1']);
        $result->assertStatus(422);
    }
}
