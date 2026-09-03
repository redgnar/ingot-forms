<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer\Node;

/**
 * A node that presents one declared item of the form: the control, the words
 * around it, the answer it holds, and the limits the definition puts on it.
 *
 * Every limit is carried whether or not this kind of item has one — a text item
 * has no `min`, a number no `maxLength` — because a template asks the node and
 * not the item type. What the definition did not say is null, which is the same
 * answer as "this kind of item cannot say it", and a kit draws neither.
 */
final readonly class ValueNode extends PresentedNode
{
    /**
     * @param ?string                                     $scope which entry this belongs to, if any: what makes an id unique when the same form is drawn once per entry, and what keeps one entry's radios out of another's group
     * @param 'string'|'strings'|'number'|'boolean'|'json' $type what the API expects on the wire, since a control only ever holds text
     * @param array<string, mixed>                        $options
     * @param list<array{value: string, text: string}>    $choices each option as the person picking it sees it; empty unless the item offers a choice
     * @param list<string>                                $accept
     */
    public function __construct(
        string $widget,
        ?string $label,
        ?string $hint,
        array $options,
        public string $name,
        public ?string $scope,
        public string $type,
        public ?string $placeholder,
        public bool $required,
        public mixed $value,
        public array $choices,
        public float|string|null $min,
        public float|string|null $max,
        public ?float $step,
        public ?int $maxLength,
        public ?string $pattern,
        public array $accept,
        public ?int $maxSize,
        public ?string $download,
        public ?string $upload,
    ) {
        parent::__construct('value', $widget, $label, $hint, $options);
    }
}
