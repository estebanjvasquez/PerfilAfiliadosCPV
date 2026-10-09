<?php

namespace App\Support\UserManual;

use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;

/**
 * TASK-0010B: renderiza el manual de usuario en línea (página Filament `UserManual`) a partir de un
 * único Markdown mantenido dentro de `resources/manual/` - nunca desde `docs/` (esa carpeta es
 * documentación del repositorio, no un asset de runtime).
 *
 * Seguridad: el contenido es del repo, pero igual se trata como no confiable:
 * - `html_input = strip`: todo HTML crudo del Markdown se descarta;
 * - `allow_unsafe_links = false`: `javascript:`, `vbscript:`, `file:` y `data:` no se renderizan;
 * - imágenes: se eliminan (el manual no depende de capturas - evita referencias rotas o capturas
 *   con datos reales de empresas);
 * - no se usa la extensión de Attributes de CommonMark (permitiría atributos arbitrarios como
 *   `onclick`): los únicos atributos que se agregan son `id` en títulos y `class`/`role` en las
 *   notas, y los genera este mismo código.
 *
 * Convenciones del Markdown:
 * - `## Título {#ancla-estable}`: ancla explícita y estable para enlaces contextuales futuros. Los
 *   títulos sin ancla explícita reciben una generada (slug) para que todo título sea enlazable.
 * - Los títulos de nivel 2 forman el índice (tabla de contenido).
 * - `> **IMPORTANTE:** ...`, `> **CONSEJO:** ...`, `> **NOTA:** ...` se muestran como recuadros.
 */
class UserManualRenderer
{
    public const CALLOUT_TYPES = [
        'IMPORTANTE' => 'importante',
        'CONSEJO' => 'consejo',
        'NOTA' => 'nota',
    ];

    public const TOC_ANCHOR = 'indice';

    /** @var array<int, array{id: string, title: string}> */
    private array $toc = [];

    /** @var array<string, true> */
    private array $usedIds = [];

    public static function sourcePath(): string
    {
        return resource_path('manual/manual_usuario.md');
    }

    /**
     * @return array{html: string, toc: array<int, array{id: string, title: string}>}
     */
    public function render(): array
    {
        return $this->renderMarkdown((string) file_get_contents(static::sourcePath()));
    }

    /**
     * @return array{html: string, toc: array<int, array{id: string, title: string}>}
     */
    public function renderMarkdown(string $markdown): array
    {
        $this->toc = [];
        $this->usedIds = [self::TOC_ANCHOR => true];

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)])) ?: ['localhost'],
                'open_in_new_window' => true,
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new ExternalLinkExtension);
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->decorate($event->getDocument()));

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        return ['html' => $html, 'toc' => $this->toc];
    }

    private function decorate(Document $document): void
    {
        $headings = [];
        $quotes = [];
        $images = [];
        $tables = [];

        $walker = $document->walker();
        while ($event = $walker->next()) {
            if (! $event->isEntering()) {
                continue;
            }

            $node = $event->getNode();
            match (true) {
                $node instanceof Heading => $headings[] = $node,
                $node instanceof BlockQuote => $quotes[] = $node,
                $node instanceof Image => $images[] = $node,
                $node instanceof Table => $tables[] = $node,
                default => null,
            };
        }

        foreach ($images as $image) {
            $parent = $image->parent();
            $image->detach();

            if ($parent instanceof Paragraph && $parent->firstChild() === null) {
                $parent->detach();
            }
        }

        foreach ($tables as $table) {
            $table->data->append('attributes/class', 'manual-table');
        }

        foreach ($quotes as $quote) {
            $this->decorateCallout($quote);
        }

        $firstSection = true;
        foreach ($headings as $heading) {
            $id = $this->extractExplicitId($heading) ?? Str::slug($this->textOf($heading)) ?: 'seccion';
            $id = $this->uniqueId($id);
            $heading->data->set('attributes/id', $id);

            if ($heading->getLevel() === 2) {
                $this->toc[] = ['id' => $id, 'title' => trim($this->textOf($heading))];

                if (! $firstSection) {
                    $heading->insertBefore($this->backToIndexLink());
                }
                $firstSection = false;
            }
        }
    }

    private function decorateCallout(BlockQuote $quote): void
    {
        $paragraph = $quote->firstChild();
        $strong = $paragraph instanceof Paragraph ? $paragraph->firstChild() : null;

        if (! $strong instanceof Strong) {
            return;
        }

        $marker = rtrim(mb_strtoupper(trim($this->textOf($strong))), ':');

        if (! isset(self::CALLOUT_TYPES[$marker])) {
            return;
        }

        $quote->data->set('attributes/class', 'manual-callout manual-callout--'.self::CALLOUT_TYPES[$marker]);
        $quote->data->set('attributes/role', 'note');
    }

    /** `Título {#ancla}` -> devuelve `ancla` y la quita del texto visible. */
    private function extractExplicitId(Heading $heading): ?string
    {
        $last = $heading->lastChild();

        if (! $last instanceof Text) {
            return null;
        }

        if (! preg_match('/\s*\{#([a-z0-9][a-z0-9-]*)\}\s*$/', $last->getLiteral(), $matches)) {
            return null;
        }

        $last->setLiteral(substr($last->getLiteral(), 0, -strlen($matches[0])));

        return $matches[1];
    }

    private function uniqueId(string $id): string
    {
        $candidate = $id;
        $suffix = 2;
        while (isset($this->usedIds[$candidate])) {
            $candidate = $id.'-'.$suffix++;
        }
        $this->usedIds[$candidate] = true;

        return $candidate;
    }

    private function backToIndexLink(): Paragraph
    {
        $paragraph = new Paragraph;
        $paragraph->data->set('attributes/class', 'manual-back-to-index');
        $link = new Link('#'.self::TOC_ANCHOR);
        $link->appendChild(new Text('Volver al índice'));
        $paragraph->appendChild($link);

        return $paragraph;
    }

    private function textOf(Node $node): string
    {
        $text = '';
        $walker = $node->walker();
        while ($event = $walker->next()) {
            $child = $event->getNode();
            if ($event->isEntering() && $child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }
}
