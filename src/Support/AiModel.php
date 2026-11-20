<?php

/**
 * Default OpenAI model for Potomatic and placeholder repair.
 *
 * Prices are USD per 1M tokens for the short-context standard rate.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

final class AiModel
{
    public const NAME = 'gpt-6-luna';

    public const REASONING_EFFORT = 'low';

    public const PRICE_INPUT_PER_1M = 0.10;

    public const PRICE_OUTPUT_PER_1M = 0.50;
}
