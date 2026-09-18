<?php

namespace Tests\Unit\Taxonomy;

use App\Services\Taxonomy\RobotsTxtChecker;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAXV2-12: el parser de robots.txt es la pieza más propensa a bugs sutiles de todo lo escrito a
 * mano en esta fase (regla 1 de la sección 5: "revisar robots.txt antes de crawlear" - si esto
 * falla silenciosamente, se podría crawlear algo prohibido). Se prueba `parse()` directo (puro, sin
 * red) para la sintaxis estándar, y `isAllowed()`/`crawlDelaySeconds()` con `Http::fake()` para el
 * cacheo por host.
 */
class RobotsTxtCheckerTest extends TestCase
{
    #[Test]
    public function disallow_all_blocks_everything(): void
    {
        $checker = new RobotsTxtChecker();
        $rules = $checker->parse("User-agent: *\nDisallow: /");

        $this->assertSame(['/'], $rules['disallow']);
    }

    #[Test]
    public function allow_more_specific_than_disallow_wins(): void
    {
        $checker = new RobotsTxtChecker();
        $content = "User-agent: *\nDisallow: /private\nAllow: /private/public-page\n";

        Http::fake(['example.test/robots.txt' => Http::response($content, 200)]);

        $this->assertFalse($checker->isAllowed('https://example.test/private/secret'));
        $this->assertTrue($checker->isAllowed('https://example.test/private/public-page'));
        $this->assertTrue($checker->isAllowed('https://example.test/anything-else'));
    }

    #[Test]
    public function wildcard_and_end_anchor_are_supported(): void
    {
        $content = "User-agent: *\nDisallow: /*.pdf$\n";
        Http::fake(['example.test/robots.txt' => Http::response($content, 200)]);

        $checker = new RobotsTxtChecker();

        $this->assertFalse($checker->isAllowed('https://example.test/docs/manual.pdf'));
        $this->assertTrue($checker->isAllowed('https://example.test/docs/manual.pdf.html'));
    }

    #[Test]
    public function specific_user_agent_group_overrides_wildcard(): void
    {
        $content = "User-agent: *\nDisallow: /private\n\nUser-agent: TestBot/1.0\nDisallow: /\n";
        Http::fake(['example.test/robots.txt' => Http::response($content, 200)]);

        $checker = new RobotsTxtChecker();

        $this->assertFalse($checker->isAllowed('https://example.test/anything', 'TestBot/1.0'));
        // Otro bot (no listado) sigue las reglas de '*', que no bloquean /anything.
        $this->assertTrue($checker->isAllowed('https://example.test/anything', 'OtroBot/1.0'));
    }

    #[Test]
    public function grouped_user_agents_share_the_same_rules(): void
    {
        $checker = new RobotsTxtChecker();
        $rules = $checker->parse("User-agent: A\nUser-agent: B\nDisallow: /x\n", 'b');

        $this->assertSame(['/x'], $rules['disallow']);
    }

    #[Test]
    public function crawl_delay_is_parsed(): void
    {
        $content = "User-agent: *\nCrawl-delay: 12\n";
        Http::fake(['example.test/robots.txt' => Http::response($content, 200)]);

        $checker = new RobotsTxtChecker();

        $this->assertSame(12.0, $checker->crawlDelaySeconds('https://example.test/page'));
    }

    #[Test]
    public function unreachable_robots_txt_defaults_to_no_rules_but_does_not_crash(): void
    {
        Http::fake(['unreachable.test/robots.txt' => Http::response('', 500)]);

        $checker = new RobotsTxtChecker();

        $this->assertTrue($checker->isAllowed('https://unreachable.test/anything'));
        $this->assertNull($checker->crawlDelaySeconds('https://unreachable.test/anything'));
    }

    #[Test]
    public function preserves_a_non_default_port_when_fetching_robots_txt(): void
    {
        // Bug real encontrado al verificar TAXV2-12 contra un servidor de fixtures local en un
        // puerto no estándar: la clave de caché por host perdía el ":8971", así que la petición de
        // robots.txt terminaba apuntando al puerto por defecto (80/443) en vez del real - fallaba
        // en silencio (host inalcanzable) y el checker asumía "sin reglas" = todo permitido.
        Http::fake(['127.0.0.1:8971/robots.txt' => Http::response("User-agent: *\nDisallow: /secret.html\n", 200)]);

        $checker = new RobotsTxtChecker();

        $this->assertFalse($checker->isAllowed('http://127.0.0.1:8971/secret.html'));
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8971/robots.txt');
    }

    #[Test]
    public function robots_txt_is_fetched_only_once_per_host(): void
    {
        Http::fake(['example.test/robots.txt' => Http::response("User-agent: *\nDisallow: /a\n", 200)]);

        $checker = new RobotsTxtChecker();
        $checker->isAllowed('https://example.test/a');
        $checker->isAllowed('https://example.test/b');
        $checker->crawlDelaySeconds('https://example.test/c');

        Http::assertSentCount(1);
    }

    #[Test]
    public function comments_and_blank_lines_are_ignored(): void
    {
        $checker = new RobotsTxtChecker();
        $rules = $checker->parse("# comentario\nUser-agent: *\n\n# otro comentario\nDisallow: /secret # inline\n");

        $this->assertSame(['/secret'], $rules['disallow']);
    }
}
