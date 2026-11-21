<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

/**
 * A widget as one drawing of the dashboard found it: its state, and what its view prints.
 */
final class DrawnWidget
{
    /**
     * @param  array<string, mixed>  $data  what the widget's view prints; empty unless the state draws content
     * @param  list<Figure>  $figures
     * @param  string|null  $reference  the request number to give when reporting an error
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly WidgetState $state,
        public readonly string $view,
        public readonly string $width,
        public readonly bool $followsPeriod,
        public readonly string $emptyWords = '',
        public readonly array $data = [],
        public readonly array $figures = [],
        public readonly ?string $reference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<Figure>  $figures
     */
    public static function of(Widget $widget, WidgetState $state, array $data = [], array $figures = [], ?string $reference = null): self
    {
        return new self(
            key: $widget->key(),
            title: $widget->title(),
            description: $widget->description(),
            state: $state,
            view: $widget->view(),
            width: $widget->width(),
            followsPeriod: $widget->followsPeriod(),
            emptyWords: $state === WidgetState::Empty ? $widget->emptyWords($data) : '',
            data: $data,
            figures: $figures,
            reference: $reference,
        );
    }

    /** One figure by its name, or null. */
    public function figure(string $key): ?Figure
    {
        foreach ($this->figures as $figure) {
            if ($figure->key === $key) {
                return $figure;
            }
        }

        return null;
    }
}
