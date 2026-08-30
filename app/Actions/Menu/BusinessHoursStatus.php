<?php

namespace App\Actions\Menu;

readonly class BusinessHoursStatus
{
    public function __construct(
        public bool $isOpen,
        public ?string $message = null,
    ) {}
}
