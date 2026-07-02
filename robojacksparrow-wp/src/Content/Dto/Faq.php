<?php

declare(strict_types=1);

namespace RoboJackSparrow\Content\Dto;

final class Faq
{
    public function __construct(
        private string $question,
        private string $answer
    ) {
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }
}
