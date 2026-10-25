<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Asks Claude for edit operations. The answer is constrained to a JSON
 * schema of the operations the plugin understands (docs/edit-operations.md);
 * the plugin checks every operation again against Avada before writing.
 */
class AnthropicEditPlanner implements EditPlanner
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
You edit pages built with the Avada page builder for WordPress. You never write Avada shortcodes. You answer with edit operations that the Gama AI plugin applies to a draft of the page, which the site owner previews before publishing.

The page is given as a list of elements. Each element has an `id` such as "0.1.2" (its position in the layout), a `type` (the Avada shortcode name), the settings that differ from Avada's defaults in `attrs`, its text in `text`, and its `children`. Containers hold rows, rows hold columns, and columns hold content elements such as titles, text blocks, buttons and images.

When `scope` is given, only that part of the page is shown; its ids are still the page-wide ids, so use them as they are.

`elements` lists, for each element type you may use, the settings this request may need: the setting type and, for choice settings, the allowed values. It is not every setting Avada has. Use only setting names and values listed there; if the request needs a setting that is not listed, return no operations and name the setting in `summary`.

Operations:
- replace_text: replace an element's text. HTML such as <p>, <strong> and <a> is allowed. Only elements with an `element_content` setting of type tinymce, textarea or textfield have text.
- set_attribute: set one setting of an element.
- insert_element: add an element of a listed type to a parent (`parent` "" is the page root), at `index` among the parent's elements, or last when `index` is left out. Give it a `ref` name (such as "s1") to use as the `parent` or `target` of later operations in your list.
- remove_element: remove an element and everything in it.
- move_element: move an element to another position in the same parent.
- duplicate_element: copy an element with everything in it, placed right after it.

When `media` is given, it lists images from the site's media library. To show one in an image element (fusion_imageframe), set its `image_id` setting to "ID|full", such as "42|full" (set_attribute with name "image_id"); the plugin then shows that media library image. Use only ids listed in `media`. To fix a broken or wrong image, set `image_id` the same way.

Ids always refer to the page as given, even after earlier operations in your list remove or insert elements. New elements have no id; use their `ref`.

To build a new section, insert a fusion_builder_container at the page root, a fusion_builder_row in it, then one fusion_builder_column per column, setting each column's `type` to its width as a fraction: 1_1 (full), 1_2, 1_3, 2_3, 1_4, 3_4, 1_5, 2_5, 3_5, 4_5, 1_6 or 5_6, adding up to 1 per row. Then put content elements in the columns. Columns inside a column use fusion_builder_row_inner and fusion_builder_column_inner the same way. "Sub containers" or "boxes" side by side usually mean columns. A container or row you leave empty gets one full-width row and column automatically.

The site owner may attach a picture, such as a screenshot or a sketch, that shows what they mean. Use it to understand the request, and still answer only with operations on the page as given. Never put the attached picture itself on the page.

Make the smallest set of operations that does what the site owner asked, and keep the site's existing wording, style and settings unless asked to change them. If the request cannot be done with these operations, or is unclear, return no operations and say why in `summary`. Write `summary` as one or two plain sentences for the site owner, in the language of their request.
PROMPT;

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly string $effort,
        private readonly int $maxTokens,
    ) {}

    public function plan(string $instruction, array $sections, array $elements, ?string $scope = null, array $media = [], ?string $image = null): EditPlan
    {
        try {
            $message = $this->client->beta->messages->create(
                model: $this->model,
                maxTokens: $this->maxTokens,
                system: [
                    ['type' => 'text', 'text' => self::SYSTEM_PROMPT],
                ],
                messages: [[
                    'role' => 'user',
                    'content' => [
                        // The page and its element settings come first and are cached, so
                        // further requests on the same page pay the cache-read price.
                        [
                            'type' => 'text',
                            'text' => self::pageText($sections, $elements, $scope, $media),
                            'cacheControl' => ['type' => 'ephemeral'],
                        ],
                        ...($image !== null ? [self::imageBlock($image)] : []),
                        ['type' => 'text', 'text' => "Site owner's request:\n".$instruction],
                    ],
                ]],
                outputConfig: [
                    'effort' => $this->effort,
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException $e) {
            // The SDK has already retried, waiting as long as the API asked.
            $retryAfter = RetryAfter::seconds($e->response?->getHeaderLine('retry-after'));
            throw new EditPlannerException(
                'The AI service is busy. Try again '.($retryAfter ? "in {$retryAfter} seconds" : 'in a minute').'.',
                rateLimited: true,
                retryAfter: $retryAfter,
            );
        } catch (APIStatusException $e) {
            throw new EditPlannerException('The AI service returned an error ('.$e->getMessage().').');
        } catch (APIConnectionException $e) {
            throw new EditPlannerException('The AI service could not be reached.');
        }

        $usage = $message->usage;
        $plan = fn (string $summary, array $operations) => new EditPlan(
            summary: $summary,
            operations: $operations,
            model: $message->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheWriteTokens: $usage->cacheCreationInputTokens ?? 0,
            cacheReadTokens: $usage->cacheReadInputTokens ?? 0,
        );

        if ($message->stopReason === 'refusal') {
            throw new EditPlannerException('The AI declined this request.', $plan('', []));
        }
        if ($message->stopReason === 'max_tokens') {
            throw new EditPlannerException('The AI answer was cut off. Try a smaller request.', $plan('', []));
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        try {
            [$summary, $operations] = self::parse($text);
        } catch (EditPlannerException $e) {
            throw new EditPlannerException($e->getMessage(), $plan('', []));
        }

        return $plan($summary, $operations);
    }

    public function estimateTokens(string $instruction, array $sections, array $elements, ?string $scope = null, array $media = [], ?string $image = null): int
    {
        $input = strlen(self::SYSTEM_PROMPT) + strlen($instruction) + strlen(self::pageText($sections, $elements, $scope, $media));

        // About three bytes per token for JSON-heavy input, rounded up, plus the most the answer may use.
        return intdiv($input, 3) + 1 + ($image !== null ? self::IMAGE_TOKENS : 0) + $this->maxTokens;
    }

    public function maxOutputTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * The page part of the prompt.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, array<string, mixed>>  $elements
     */
    /** Input tokens one attached picture costs at most, for estimates. A 1280 px wide screenshot is about 1,200 to 1,600. */
    public const IMAGE_TOKENS = 1_600;

    /**
     * The media type and base64 data of a data URL.
     *
     * @return array{0: string, 1: string}
     */
    public static function imageParts(string $dataUrl): array
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/]++={0,2})$#', $dataUrl, $m)) {
            throw new EditPlannerException('The attached image could not be read.');
        }

        return [$m[1], $m[2]];
    }

    /** @return array<string, mixed> */
    private static function imageBlock(string $dataUrl): array
    {
        [$type, $data] = self::imageParts($dataUrl);

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $type, 'data' => $data]];
    }

    public static function pageText(array $sections, array $elements, ?string $scope, array $media = []): string
    {
        $context = ['page' => $sections, 'elements' => (object) $elements];
        if ($scope !== null && $scope !== '') {
            $context = ['scope' => $scope] + $context;
        }
        if ($media !== []) {
            $context['media'] = $media;
        }

        return (string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Read the model's JSON answer and turn it into the plugin's operation format.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     *
     * @throws EditPlannerException
     */
    public static function parse(string $text): array
    {
        $data = json_decode($text, true);
        if (! is_array($data) || ! is_string($data['summary'] ?? null) || ! is_array($data['operations'] ?? null)) {
            throw new EditPlannerException('The AI answer was not in the expected format.');
        }

        $operations = [];
        foreach ($data['operations'] as $op) {
            if (! is_array($op) || ! in_array($op['op'] ?? null, ['replace_text', 'set_attribute', 'insert_element', 'remove_element', 'move_element', 'duplicate_element'], true)) {
                throw new EditPlannerException('The AI answer contained an unknown operation.');
            }
            // The schema lists settings as name/value pairs; the plugin takes an object.
            if ($op['op'] === 'insert_element') {
                $attrs = [];
                foreach ($op['attrs'] ?? [] as $pair) {
                    if (is_array($pair) && is_string($pair['name'] ?? null) && is_scalar($pair['value'] ?? null)) {
                        $attrs[$pair['name']] = (string) $pair['value'];
                    }
                }
                $op['attrs'] = (object) $attrs;
            }
            if ($op['op'] === 'set_attribute') {
                $op = self::setAttribute($op);
            }
            $operations[] = $op;
        }

        return [$data['summary'], $operations];
    }

    /**
     * Models that only get JSON mode (no enforced schema) sometimes name the
     * setting under another key, such as {"attribute": "image_id"}, or as
     * its own key, {"image_id": "87|full"}. Read those forms; anything else
     * is not guessed at.
     *
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     *
     * @throws EditPlannerException
     */
    private static function setAttribute(array $op): array
    {
        $name = $op['name'] ?? null;
        $value = $op['value'] ?? null;
        if (! is_string($name) || $name === '' || $name === 'set_attribute') {
            $name = null;
            foreach (['setting', 'attribute', 'attr', 'key', 'param', 'param_name'] as $alias) {
                if (is_string($op[$alias] ?? null) && $op[$alias] !== '') {
                    $name = $op[$alias];
                    break;
                }
            }
        }
        $extra = array_diff_key($op, array_flip(['op', 'target', 'name', 'value', 'setting', 'attribute', 'attr', 'key', 'param', 'param_name']));
        if ($name === null && count($extra) === 1 && is_scalar(reset($extra))) {
            $name = (string) key($extra);
            $value = $value ?? (string) reset($extra);
        }
        if ($name === null || ! is_scalar($value) || ! is_string($op['target'] ?? null)) {
            throw new EditPlannerException('The AI answer was not in the expected format (a setting change without a setting name).');
        }

        return ['op' => 'set_attribute', 'target' => $op['target'], 'name' => $name, 'value' => (string) $value];
    }

    /**
     * JSON schema of the answer.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $id = ['type' => 'string', 'description' => 'Element id from the page, such as "0.1.2", or the ref of an element inserted earlier in the list.'];
        $index = ['type' => 'integer', 'minimum' => 0];
        $op = fn (string $name, array $properties, array $required) => [
            'type' => 'object',
            'properties' => ['op' => ['type' => 'string', 'enum' => [$name]]] + $properties,
            'required' => array_merge(['op'], $required),
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'operations' => [
                    'type' => 'array',
                    'items' => [
                        'anyOf' => [
                            $op('replace_text', ['target' => $id, 'text' => ['type' => 'string']], ['target', 'text']),
                            $op('set_attribute', ['target' => $id, 'name' => ['type' => 'string'], 'value' => ['type' => 'string']], ['target', 'name', 'value']),
                            $op('insert_element', [
                                'parent' => ['type' => 'string', 'description' => 'Parent element id or ref, or "" for the page root.'],
                                'index' => $index,
                                'type' => ['type' => 'string'],
                                'attrs' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => ['name' => ['type' => 'string'], 'value' => ['type' => 'string']],
                                        'required' => ['name', 'value'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                                'text' => ['type' => 'string'],
                                'ref' => ['type' => 'string', 'description' => 'Name for this new element, used as parent or target by later operations.'],
                            ], ['parent', 'type']),
                            $op('remove_element', ['target' => $id], ['target']),
                            $op('move_element', ['target' => $id, 'index' => $index], ['target', 'index']),
                            $op('duplicate_element', ['target' => $id], ['target']),
                        ],
                    ],
                ],
            ],
            'required' => ['summary', 'operations'],
            'additionalProperties' => false,
        ];
    }
}
